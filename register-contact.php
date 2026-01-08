<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';

// Function to generate a unique file name or handle duplicates as needed
function generateUniqueFileName($file_name) {
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    $file_name = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", "", $file_name);
    $i = 0;
    $parts = pathinfo($file_name);
    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/contact_images/';

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
function storeContactInDatabase($firstname, $lastname, $email, $phone, $company, $title, $contact_list, $description, $image, $unsubscribe_key) {
    include('./db-connection.php'); // Include the database connection code

    try {
        $stmt = $conn->prepare("INSERT INTO contacts (firstname, lastname, email, phone, company, title, contact_list, description, image, date, unsubscribe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("ssssssssssi", $firstname, $lastname, $email, $phone, $company, $title, $contact_list, $description, $image, $date, $unsubscribe_key);
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


//function resize image
function resizeImage($originalPath, $targetPath, $maxWidth, $maxHeight) {
    // Connect to the remote server
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    // Check if the file exists on the remote server
    $remote_file_stream = @fopen("ssh2.sftp://$sftp$originalPath", 'r');
    if (!$remote_file_stream) {
        error_log('File does not exist or is not accessible: ' . $originalPath);
        return false;
    }

    // Read the file contents
    $file_data = stream_get_contents($remote_file_stream);
    fclose($remote_file_stream);

    if ($file_data === false) {
        error_log('Failed to read file from remote server: ' . $originalPath);
        return false;
    }

    // Create an image resource from the binary data
    $srcImage = imagecreatefromstring($file_data);
    if (!$srcImage) {
        error_log('Failed to create image resource from file: ' . $originalPath);
        return false;
    }

    // Get the original dimensions
    $width = imagesx($srcImage);
    $height = imagesy($srcImage);

    if ($width <= 0 || $height <= 0) {
        imagedestroy($srcImage);
        error_log('Invalid image dimensions for file: ' . $originalPath);
        return false;
    }

    // Calculate new dimensions to maintain aspect ratio
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);

    // Create a new true color image with the new dimensions
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    // Maintain transparency for PNGs
    if (imageistruecolor($srcImage) && (imagesx($srcImage) > 0 && imagesy($srcImage) > 0)) {
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
    }

    // Resize the original image
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save the resized image locally
    $localTempPath = tempnam(sys_get_temp_dir(), 'resized_');
    $extension = pathinfo($originalPath, PATHINFO_EXTENSION);

    switch (strtolower($extension)) {
        case 'jpeg':
        case 'jpg':
            imagejpeg($dstImage, $localTempPath, 90); // Quality setting
            break;
        case 'png':
            imagepng($dstImage, $localTempPath);
            break;
        default:
            imagedestroy($srcImage);
            imagedestroy($dstImage);
            error_log('Unsupported file type: ' . $extension);
            unlink($localTempPath);
            return false;
    }

    // Upload the resized image to the remote server
    $connection = $sshDetails['connection'];
    if (!ssh2_scp_send($connection, $localTempPath, $targetPath, 0700)) {
        imagedestroy($srcImage);
        imagedestroy($dstImage);
        unlink($localTempPath);
        error_log('Failed to upload the file to the remote server: ' . $targetPath);
        return false;
    }

    // Clean up
    imagedestroy($srcImage);
    imagedestroy($dstImage);
    unlink($localTempPath);

    return true;
}

// Function to generate compressed versions
function generateCompressedVersion($filePath, $fileName) {
    $newFileName = "medium-{$fileName}";
    $remoteFilePath = $_ENV['FILE_HOSTING_ROOT'] . '/contact_images/' . $newFileName;

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
        $image_path = 'contact_images/';

        if (isset($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name']) && !empty($_FILES['image']['name'])) {
            $file_name = $_FILES['image']['name'];
            $file_tmp = $_FILES['image']['tmp_name'];
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

            $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/contact_images';
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
            $final_image_path = 'contact-image-placeholder.jpg';
        }

        $contact_list = $_POST['contact_list'];
        $contact_list_array_data = json_decode($contact_list, true);
        $contact_list_json = json_encode($contact_list_array_data);
        //random number 5 digits long
        $unsibscribe_key = rand(10000, 99999);
        if (storeContactInDatabase($_POST['firstname'], $_POST['lastname'], $_POST['email'], $_POST['phone'], $_POST['company'], $_POST['title'], $contact_list_json, $_POST['description'], $final_image_path,  $unsibscribe_key)) {
            $successMessages[] = 'Contact created successfully';
        } else {
            $errorMessages[] = 'Failed to store contact in database';
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