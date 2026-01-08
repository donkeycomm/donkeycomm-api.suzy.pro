<?php
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php'; // Include the SSH connection code
// Get the folder and path name from the POST request
$folder_name = $_POST['folder_name'];
$folder_id = $_POST['id'];
// Sanitize the folder name to prevent directory traversal attacks
$folder_name = sanitizeFolderName($folder_name);
$access_level = $_POST['access_level'];
// Establish SSH connection using sshConnect function
$sshDetails = sshConnect();
if (!$sshDetails || !isset($sshDetails['sftp'])) {
    throw new Exception('SSH connection failed');
}
$sftp = $sshDetails['sftp'];

// Create a function to generate a unique file name or handle duplicates as needed
function generateUniqueFileName($file_name, $sftp, $storage_directory) {
    $file_name = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", "", $file_name);
    $i = 0;
    $parts = pathinfo($file_name);
    while (true) {
        $remote_file_path = "$storage_directory/$file_name";
        $sftp_path = "ssh2.sftp://$sftp/$remote_file_path";
        if (!@ssh2_sftp_stat($sftp, $remote_file_path)) {
            break;
        }
        $i++;
        $file_name = $parts["filename"] . "-" . $i . "." . $parts["extension"];
    }
    return $file_name;
}

// Function to resize image
function resizeImage($srcLocalPath, $dstRemotePath, $maxWidth, $maxHeight) {
    // Detect type from file contents (works for tmp files with no extension)
    $type = @exif_imagetype($srcLocalPath); // returns IMAGETYPE_* or false
    if ($type === false) {
        error_log("Unsupported/invalid image (no type): $srcLocalPath");
        return false;
    }

    $dims = @getimagesize($srcLocalPath);
    if ($dims === false) {
        error_log('Failed to get image dimensions: ' . $srcLocalPath);
        return false;
    }
    [$width, $height] = $dims;
    if ($width <= 0 || $height <= 0) {
        error_log('Invalid image dimensions: ' . $srcLocalPath);
        return false;
    }

    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newW = max(1, (int)($width * $ratio));
    $newH = max(1, (int)($height * $ratio));

    $dst = imagecreatetruecolor($newW, $newH);

    // Load source by detected type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($srcLocalPath);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($srcLocalPath);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            break;
        default:
            error_log("Unsupported image type (only JPEG/PNG): $type");
            return false;
    }
    if (!$src) {
        error_log("Failed to create source image: $srcLocalPath");
        return false;
    }

    if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height)) {
        imagedestroy($src);
        imagedestroy($dst);
        error_log('imagecopyresampled failed: ' . $srcLocalPath);
        return false;
    }

    // Write to a local temp file, then SCP to remote
    $tmpOut = tempnam(sys_get_temp_dir(), 'resized_');
    $okWrite = ($type === IMAGETYPE_JPEG)
        ? imagejpeg($dst, $tmpOut, 90)
        : imagepng($dst, $tmpOut);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$okWrite) {
        @unlink($tmpOut);
        error_log('Failed to write resized temp file.');
        return false;
    }

    @chmod($tmpOut, 0700);
    $sshDetails = sshConnect();
    $conn = $sshDetails['connection'];
    $okSend = @ssh2_scp_send($conn, $tmpOut, $dstRemotePath, 0700);
    @unlink($tmpOut);

    if (!$okSend) {
        error_log('Failed to upload resized file to remote: ' . $dstRemotePath);
        return false;
    }

    return true;
}


// Function to generate compressed versions
function generateCompressedVersions($srcLocalPath, $remoteDirAbs, $uniqueFileName) {
    $sizes = ['small'=>[600,600], 'medium'=>[1200,1200], 'large'=>[1600,1600]];
    $versions = ['small'=>'', 'medium'=>'', 'large'=>''];

    foreach ($sizes as $name => [$w,$h]) {
        $remotePath = rtrim($remoteDirAbs, '/')."/{$name}_{$uniqueFileName}";
        if (!resizeImage($srcLocalPath, $remotePath, $w, $h)) {
            error_log("Failed to generate {$name} version for file: {$srcLocalPath}");
            return false; // fail fast so you can clean up
        }
        $versions[$name] = "{$name}_{$uniqueFileName}";
    }
    return $versions;
}

function sanitizeFolderName($folderName) {
    $folderName = preg_replace('([^\w\s\d\-_])', '', $folderName);
    $folderName = trim($folderName, '-_');
    return $folderName;
}

function getUserRolesFromDatabase($user_email, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $user_email);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Bearer " . $jwt . "\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            http_response_code(500);
            return 'Error checking user role';
        }
        $user = json_decode($result, true);
        if ($user && isset($user['groups'])) {
            return $user['groups'];
        } else {
            return 'User not found';
        }
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error checking user role';
    }
}

function getFolderData($id, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-folder-by-id.php';
        $data = array('id' => $id);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Bearer " . $jwt . "\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            http_response_code(500);
            return 'Error checking folder access level for: '.$id;
        }
        $folder = json_decode($result, true);
        if ($folder) {
            return $folder;
        } else {
            return 'folder not found';
        }
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error checking folder access level for: '.$id;
    }
}

function storeFolderMetadataInDatabase($folder_name, $image, $image_small, $image_medium, $image_large, $id, $access_level) {

      // Convert the string to an array
      if($access_level){
        //explode the string into an array of numbers
        $access_level_array_data = explode(',', $access_level);
        $access_level_array_data = array_map('intval', $access_level_array_data);
    } else {
        $access_level_array_data = [];
    }
   
    //add 0 and 1 to the beginning of $access_level_array_data

    array_unshift($access_level_array_data, 0, 1);
     //make content unique by removing duplicates
     $access_level_array_data = array_unique($access_level_array_data);
     // Reindex the array
     $access_level_array_data = array_values($access_level_array_data);
     // Convert the array to a JSON string
     $access_level_json_data = json_encode($access_level_array_data);

    include('./db-connection.php');
    try {
        $query = "UPDATE folders SET ";
        $params = [];
        $types = "";
        if ($folder_name !== '') {
            $query .= "name = ?, ";
            $params[] = $folder_name;
            $types .= "s";
        }
        if ($image !== '') {
            $query .= "image = ?, image_small = ?, image_medium = ?, image_large = ?,  ";
            $params = array_merge($params, [$image, $image_small, $image_medium, $image_large]);
            $types .= "ssss";
        }
        if ($access_level !== '') {
            $query .= "access_level = ?, ";
            $params[] = $access_level_json_data;
            $types .= "s";
        }
        $query = rtrim($query, ", ") . " WHERE ID = ?";
        $params[] = $id;
        $types .= "s";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Error in storeFolderMetadataInDatabase: " . $e->getMessage());
        return false;
    }
}

try {
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
} catch (Exception $e) {
    error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array("error: " => $e->getMessage()));
}

$rolesArray = json_decode($user_roles, true);
if (!in_array(0, $rolesArray) && !in_array(1, $rolesArray)) {
    http_response_code(403);
    echo json_encode(array('error' => 'You do not have permission to update a folder.', 
    'current_path_access_level' => $current_path_access_level, 
    'user_roles' => $user_roles));
    exit;
}

$storage_directory = $_ENV['FILE_HOSTING_ROOT'].'/folder_images';
$file_name = '';
$unique_file_name = '';
$versions['small'] = '';
$versions['medium'] = '';
$versions['large'] = '';
$id = $_POST['id'];

if ($_FILES['image'] !== null && is_uploaded_file($_FILES['image']['tmp_name'])) {
    $folder_data = getFolderData($folder_id, $jwt);
    if ($folder_data === 'folder not found') {
        http_response_code(404);
        echo json_encode(array('error' => 'Folder not found.'));
        exit;
    }
    $old_image = $folder_data['image'];
    $old_image_small = $folder_data['image_small'];
    $old_image_medium = $folder_data['image_medium'];
    $old_image_large = $folder_data['image_large'];
    if ($old_image !== 'folder-placeholder.jpg') {
        ssh2_sftp_unlink($sftp, "$storage_directory/$old_image");
    }
    if ($old_image_small !== '') {
        ssh2_sftp_unlink($sftp, "$storage_directory/$old_image_small");
    }
    if ($old_image_medium !== '') {
        ssh2_sftp_unlink($sftp, "$storage_directory/$old_image_medium");
    }
    if ($old_image_large !== '') {
        ssh2_sftp_unlink($sftp, "$storage_directory/$old_image_large");
    }
    $file_name = $_FILES['image']['name'];
    $file_tmp = $_FILES['image']['tmp_name'];
    $unique_file_name = generateUniqueFileName($file_name, $sftp, $storage_directory);
    $file_path = $storage_directory . '/'.$unique_file_name;
     $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));   

    // Ensure the remote directory exists
    if (!@ssh2_sftp_stat($sftp, $storage_directory)) {
        ssh2_sftp_mkdir($sftp, $storage_directory, 0755, true);
    }

    if (!ssh2_scp_send($sshDetails['connection'], $file_tmp, $file_path)) {
        $error = error_get_last();
        throw new Exception('Failed to send file via SCP: ' . $error['message']);
    }

    // Set permissions on the uploaded file using ssh2_sftp_chmod
    if (!ssh2_sftp_chmod($sftp, $file_path, 0700)) {
        throw new Exception('Failed to set permissions on the uploaded file');
    }

    if ($file_extension === 'jpg' || $file_extension === 'jpeg' || $file_extension === 'png') {
        $versions = generateCompressedVersions($file_path, $storage_directory, $unique_file_name, $sftp);
    }
}

storeFolderMetadataInDatabase($folder_name, $unique_file_name, $versions['small'], $versions['medium'], $versions['large'], $id, $access_level);
http_response_code(200);
echo json_encode(array('message' => 'Folder updated successfully.', 'folder_name' => $folder_name));
?>