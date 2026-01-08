<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';

// Function to generate a unique file name or handle duplicates as needed
function generateUniqueImageName($file_name) {
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    $file_name = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", "", $file_name);
    $i = 0;
    $parts = pathinfo($file_name);
    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/pressrelease_images/';

    while (true) {
        $remote_file_path = $remote_directory . $file_name;
        $statinfo = @ssh2_sftp_stat($sftp, $remote_file_path);
        if ($statinfo === false) {
            break;
        }
        $i++;
        $file_name = $parts["filename"] . "-" . $i . "." . $parts["extension"];
    }

    return $file_name;
}
function generateUniqueFileName($file_name) {
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    $file_name = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", "", $file_name);
    $i = 0;
    $parts = pathinfo($file_name);
    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/pressrelease_files/';

    while (true) {
        $remote_file_path = $remote_directory . $file_name;
        $statinfo = @ssh2_sftp_stat($sftp, $remote_file_path);
        if ($statinfo === false) {
            break;
        }
        $i++;
        $file_name = $parts["filename"] . "-" . $i . "." . $parts["extension"];
    }

    return $file_name;
}
// Function to store contact in the database
function storePressReleaseInDatabase($title, $text, $date, $image, $file) {
    include('./db-connection.php'); // Include the database connection code
    $creation_date = date('Y-m-d H:i:s');
    try {
        $stmt = $conn->prepare("INSERT INTO pressreleases (title, text, date, image, file, creation_date) VALUES (?, ?, ?, ?, ?, ?)");
       
        $stmt->bind_param("ssssss", $title, $text, $date, $image, $file, $creation_date);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Error in storeContactInDatabase: " . $e->getMessage());
        return false;
    }
}

// Function to get user roles from the database
function getUserRolesFromDatabase($email, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $email);
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
        return $user['groups'] ?? 'User not found';
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error checking user role';
    }
}

// Function to resize image
function resizeImage($originalPath, $targetPath, $maxWidth, $maxHeight) {
    $extension = pathinfo($originalPath, PATHINFO_EXTENSION);
    $imageInfo = getimagesize($originalPath);

    if ($imageInfo === false) {
        error_log("Error reading image file: $originalPath");
        return false;
    }

    list($width, $height) = $imageInfo;

    if ($width == 0 || $height == 0) {
        error_log("Invalid image dimensions for file: $originalPath");
        return false;
    }

    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);

    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    switch (strtolower($extension)) {
        case 'jpeg':
        case 'jpg':
            $srcImage = imagecreatefromjpeg($originalPath);
            break;
        case 'png':
            $srcImage = imagecreatefrompng($originalPath);
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            break;
        default:
            error_log("Unsupported image format: $extension");
            return false;
    }

    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $localTempPath = tempnam(sys_get_temp_dir(), 'resized_');
    switch (strtolower($extension)) {
        case 'jpeg':
        case 'jpg':
            imagejpeg($dstImage, $localTempPath, 90);
            break;
        case 'png':
            imagepng($dstImage, $localTempPath);
            break;
    }
    chmod($localTempPath, 0700);

    $sshDetails = sshConnect();
    $connection = $sshDetails['connection'];

    if (!ssh2_scp_send($connection, $localTempPath, $targetPath, 0700)) {
        error_log("Failed to upload resized image to remote server: $targetPath");
        return false;
    }

    imagedestroy($srcImage);
    imagedestroy($dstImage);
    unlink($localTempPath);

    return true;
}

// Function to generate compressed versions
function generateCompressedVersion($filePath, $fileName) {
    $newFileName = "medium-{$fileName}";
    $remoteFilePath = $_ENV['FILE_HOSTING_ROOT'] . '/pressrelease_images/' . $newFileName;

    if (resizeImage($filePath, $remoteFilePath, 1200, 1200)) {
        return $newFileName;
    } else {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user_roles = getUserRolesFromDatabase($user_email, $jwt);
    } catch (Exception $e) {
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
        exit;
    }

    $successMessages = [];
    $errorMessages = [];
    $rolesArray = json_decode($user_roles, true);

    if (in_array(0, $rolesArray) || in_array(1, $rolesArray)) {
        $image_path = 'pressrelease_images/';

        // Handle image upload
        if (isset($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name']) && !empty($_FILES['image']['name'])) {
            $file_name = $_FILES['image']['name'];
            $file_tmp = $_FILES['image']['tmp_name'];
            $unique_file_name = generateUniqueImageName($file_name);

            try {
                $ssh_details = sshConnect();
                $connection = $ssh_details['connection'];
                $sftp = $ssh_details['sftp'];
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(array('error' => $e->getMessage()));
                exit;
            }

            $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/pressrelease_images';
            $remote_file_path = $remote_directory . '/' . $unique_file_name;

            if (ssh2_scp_send($connection, $file_tmp, $remote_file_path, 0700)) {
                 $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));   

                if (in_array(strtolower($file_extension), ['jpg', 'jpeg', 'png'])) {
                    $final_image_path = generateCompressedVersion($remote_file_path, $unique_file_name);
                } else {
                    $errorMessages[] = 'Invalid file type';
                }
            } else {
                $errorMessages[] = 'File could not be uploaded: ' . $file_name;
            }
        } else {
            $final_image_path = 'image-placeholder.jpg';
        }
        // Handle file upload
        if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']) && !empty($_FILES['file']['name'])) {
            $file_name = $_FILES['file']['name'];
            $file_tmp = $_FILES['file']['tmp_name'];
            $unique_file_name = generateUniqueFileName($file_name);

            try {
                $ssh_details = sshConnect();
                $connection = $ssh_details['connection'];
                $sftp = $ssh_details['sftp'];
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(array('error' => $e->getMessage()));
                exit;
            }

            $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/pressrelease_files';
            $remote_file_path = $remote_directory . '/' . $unique_file_name;

            if (ssh2_scp_send($connection, $file_tmp, $remote_file_path, 0744)) {
                $file_path = $unique_file_name;
            } else {
                $errorMessages[] = 'File could not be uploaded: ' . $file_name;
            }
        }
      
        if (storePressReleaseInDatabase($_POST['title'], $_POST['text'], $_POST['date'], $final_image_path,  $file_path)) {
            $successMessages[] = 'Press release created successfully';
        } else {
            $errorMessages[] = 'Failed to store press release in database';
        }
    } else {
        $errorMessages[] = 'You\'re not authorized to upload files';
    }

    $response = [
        'success' => $successMessages,
        'error' => $errorMessages,
    ];
    echo json_encode($response);
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>