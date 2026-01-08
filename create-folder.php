<?php
include('./jwt-validation.php'); // sets $user_email, $jwt
include './ssh_connect.php';

// ---------- helpers ----------
function sanitizeFolderName($folderName) {
    $folderName = preg_replace('([^\w\s\d\-_])', '', $folderName);
    return trim($folderName, '-_');
}

function generateUniqueFileName($file_name) {
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    $file_name = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", "", $file_name);
    $i = 0;
    $parts = pathinfo($file_name);
    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/folder_images/';

    while (true) {
        $remote_file_path = $remote_directory . $file_name;
        $statinfo = @ssh2_sftp_stat($sftp, $remote_file_path);
        if ($statinfo === false) break;
        $i++;
        $file_name = $parts["filename"] . "-" . $i . "." . ($parts["extension"] ?? '');
    }
    return $file_name;
}

/**
 * Resize a *local* file to a *remote* target path.
 * Returns true on success, false otherwise.
 */
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

function getUserRolesFromDatabase($user_email, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $user_email);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\nAuthorization: Bearer ".$jwt."\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $ctx = stream_context_create($options);
        $result = file_get_contents($url, false, $ctx);
        if ($result === false) { http_response_code(500); return 'Error checking user role'; }
        $user = json_decode($result, true);
        return ($user && isset($user['groups'])) ? $user['groups'] : 'User not found';
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error checking user role';
    }
}

function getCurrentFolderAccessLevel($path, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-folder-data.php';
        $data = array('path' => $path);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\nAuthorization: Bearer ".$jwt."\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $ctx = stream_context_create($options);
        $result = file_get_contents($url, false, $ctx);
        if ($result === false) { http_response_code(500); return 'Error checking folder access level for: '.$path; }
        $folder = json_decode($result, true);
        return ($folder && isset($folder['access_level'])) ? $folder['access_level'] : 'folder not found';
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error checking folder access level for: '.$path;
    }
}

/**
 * DB write that throws on error (transactional).
 * Cleanup is handled by the caller (so we can remove the created folder on ANY error).
 */
function storeFolderMetadataInDatabase($user_email, $folder_name, $folder_path, $parent_path, $image, $image_small, $image_medium, $image_large, $access_level) {
    include('./db-connection.php');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $arr = $access_level ? array_map('intval', explode(',', $access_level)) : [];
    array_unshift($arr, 0, 1);
    $arr = array_values(array_unique($arr));
    $access_level_json = json_encode($arr);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO `folders`
          (`user`,`name`,`path`,`parent_path`,`image`,`image_small`,`image_medium`,`image_large`,`access_level`,`date`)
          VALUES (?,?,?,?,?,?,?,?,?,?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("ssssssssss", $user_email, $folder_name, $folder_path, $parent_path, $image, $image_small, $image_medium, $image_large, $access_level_json, $date);
        $stmt->execute();

        $last_id = $conn->insert_id;
        $stmt2 = $conn->prepare("UPDATE `folders` SET `rank` = ? WHERE `ID` = ?");
        $stmt2->bind_param("ii", $last_id, $last_id);
        $stmt2->execute();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("DB error in storeFolderMetadataInDatabase: ".$e->getMessage());
        throw $e; // caller will handle cleanup + response
    } finally {
        if (isset($stmt))  @$stmt->close();
        if (isset($stmt2)) @$stmt2->close();
        if (isset($conn))  @$conn->close();
    }
}

// ---------- inputs ----------
$folder_name   = $_POST['folder_name'] ?? '';
$path_name     = $_POST['folder_path'] ?? '';
$access_level  = $_POST['access_level'] ?? '';

$folder_name = sanitizeFolderName($folder_name);
$newPathName = strtolower(preg_replace('([\-_\s]+)', '-', $folder_name));

// Basic validation
if (!$folder_name) {
    http_response_code(400);
    echo json_encode(['error' => 'Folder name is required.']);
    exit;
}

// ---------- permissions ----------
try {
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
} catch (Exception $e) {
    error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Failed to resolve user role."]);
    exit;
}
$rolesArray = json_decode($user_roles, true);
if (!in_array(0, (array)$rolesArray) && !in_array(1, (array)$rolesArray)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to create a folder here.']);
    exit;
}

// Optional: check current folder ACL (ignored if backend 403s)
$getAcl = getCurrentFolderAccessLevel($path_name, $jwt); // not used, but you can validate if needed

// ---------- SSH/SFTP ----------
try {
    $ssh_details = sshConnect();
    $connection = $ssh_details['connection'];
    $sftp = $ssh_details['sftp'];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Build absolute remote path for the new folder
$pathSeparator = ($path_name === '/') ? '' : '/';
$remote_path = $_ENV['FILE_HOSTING_ROOT'].'/files'.$path_name.$pathSeparator.$newPathName;

// If folder exists, bail early
$chk = ssh2_exec($connection, "[ -d ".escapeshellarg($remote_path)." ] && echo 'exists' || echo 'not exists'");
stream_set_blocking($chk, true);
$folder_exists = trim(stream_get_contents($chk));
if ($folder_exists === 'exists') {
    http_response_code(400);
    echo json_encode(['error' => 'Folder already exists.']);
    exit;
}

// ---------- main flow with guaranteed cleanup ----------
$createdFolder = false;
$unique_file_name = 'folder-placeholder.jpg';
$versions = ['small' => '', 'medium' => '', 'large' => ''];

try {
    // 1) Create the folder
    $mk = ssh2_exec($connection, "mkdir -p ".escapeshellarg($remote_path)." && chmod 0700 ".escapeshellarg($remote_path));
    if ($mk) { stream_set_blocking($mk, true); stream_get_contents($mk); }
    $createdFolder = true;

    // 2) Handle image upload (validate type first to avoid GD fatals)
   if (!empty($_FILES['image']) && !empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $tmp = $_FILES['image']['tmp_name'];

        // Detect type from file contents (works for tmp filenames without extension)
        $type = @exif_imagetype($tmp);
        if ($type === false) {
            // Fallback via finfo if EXIF is unavailable
            if (function_exists('finfo_open')) {
                $f = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $f ? finfo_file($f, $tmp) : '';
                if ($f) finfo_close($f);
                if (!in_array($mime, ['image/jpeg','image/png'], true)) {
                    throw new RuntimeException('Unsupported image format (JPEG/PNG only).');
                }
                // Map MIME to an IMAGETYPE for downstream code, if you need it
                $type = $mime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
            } else {
                throw new RuntimeException('Unsupported image format (JPEG/PNG only).');
            }
        }
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new RuntimeException('Unsupported image format (JPEG/PNG only).');
        }

        $file_name = $_FILES['image']['name'];
        $unique_file_name = generateUniqueFileName($file_name);
        $remote_directory = rtrim($_ENV['FILE_HOSTING_ROOT'] . '/folder_images', '/');
        $remote_file_path = $remote_directory . '/' . $unique_file_name;

        // Upload original
        if (!@ssh2_scp_send($connection, $tmp, $remote_file_path, 0700)) {
            throw new RuntimeException('File could not be uploaded.');
        }

        // Generate versions from the local tmp to remote targets
        $vers = generateCompressedVersions($tmp, $remote_directory, $unique_file_name);
        if ($vers === false) {
            throw new RuntimeException('Failed to generate image versions.');
        }
        $versions = $vers;
    }


    // 3) Write DB (throws on failure)
    storeFolderMetadataInDatabase(
        $user_email,
        htmlspecialchars($folder_name, ENT_QUOTES, 'UTF-8'),
        $path_name.$pathSeparator.$newPathName, // folder_path to store
        $path_name,                              // parent_path
        $unique_file_name,
        $versions['small'],
        $versions['medium'],
        $versions['large'],
        $access_level
    );

    // 4) Success
    http_response_code(200);
    echo json_encode([
        'message' => 'Folder created successfully.',
        'folder_path' => $path_name.$pathSeparator.$newPathName
    ]);
    exit;

} catch (Throwable $e) {
    error_log("Create folder failed: ".$e->getMessage());

    // ALWAYS remove the created folder (even if non-empty)
    if ($createdFolder) {
        $rm = ssh2_exec($connection, 'rm -rf '.escapeshellarg($remote_path));
        if ($rm) { stream_set_blocking($rm, true); stream_get_contents($rm); }
    }

    http_response_code(500);
    echo json_encode(['error' => 'Failed to create folder. Make sure the image is a valid jpg or png.']);
    exit;
}
