<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';

function generateUniqueFileName($file_name, $folder_path) {
    // Establish SSH connection and SFTP
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    $file_name = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", "", $file_name);
    $i = 0;
    $pathSeparator = '/';
    if ($folder_path == '/') {
        $pathSeparator = '';
    }
    $parts = pathinfo($file_name);
    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/files' . $folder_path . $pathSeparator;

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

function storeFileMetadataInDatabase($user_email, $folder_path, $file_name, $small, $medium, $large,  $dimensions, $access_level, $file_size) {
    include('./db-connection.php'); // Include the database connection code

    if ($access_level) {
        $access_level_array_data = explode(',', $access_level);
        $access_level_array_data = array_map('intval', $access_level_array_data);
    } else {
        $access_level_array_data = [];
    }

    array_unshift($access_level_array_data, 0, 1);
    $access_level_array_data = array_unique($access_level_array_data);
    $access_level_array_data = array_values($access_level_array_data);
    $access_level_json_data = json_encode($access_level_array_data);
    $dimensions_json_data = json_encode($dimensions);

    try {
        $stmt = $conn->prepare("INSERT INTO files (user, path, file, small, medium, large, dimensions, access_level, size, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("ssssssssis", $user_email, $folder_path, $file_name, $small, $medium, $large, $dimensions_json_data, $access_level_json_data,  $file_size, $date);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Error in storeFileMetadataInDatabase: " . $e->getMessage());
        return false;
    }
}

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


function resizeImage($originalPath, $targetPath, $maxWidth, $maxHeight, $file_permissions) {
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    // Open the remote file
    $remote_file_stream = @fopen("ssh2.sftp://$sftp$originalPath", 'r');
    if (!$remote_file_stream) {
        throw new Exception("File does not exist or is not accessible: " . $originalPath);
    }

    $file_data = stream_get_contents($remote_file_stream);
    fclose($remote_file_stream);

    if ($file_data === false || strlen($file_data) === 0) {
        throw new Exception("Failed to read file or file is empty: " . $originalPath);
    }

    // Load the image into Imagick
    $image = new Imagick();
    $image->readImageBlob($file_data);

    // Check if image format is supported
    $format = strtolower($image->getImageFormat());
    if (!in_array($format, ['jpeg', 'jpg', 'png', 'gif'])) {
        throw new Exception("Unsupported image format: " . $format);
    }

    // Handle transparency for PNGs
    if ($format === 'png') {
        // Activate alpha channel for transparency
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $image->setBackgroundColor(new ImagickPixel('transparent'));
        
        // Ensure the alpha channel is preserved after resizing
        $image->mergeImageLayers(Imagick::LAYERMETHOD_OPTIMIZE);
    }
    
    // Resize while maintaining aspect ratio
    $image->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, true);

    // Ensure PNG compression and transparency settings
    if ($format === 'png') {
        $image->setImageCompressionQuality(90); // Compression quality (0-100)
        $image->setOption('png:compression-level', '9'); // Max compression
        $image->setOption('png:preserve-colormap', 'true');
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $image->setBackgroundColor(new ImagickPixel('transparent'));
    }

    // Write the image to a temporary local file
    $localTempPath = tempnam(sys_get_temp_dir(), 'resized_');
    $image->writeImage($localTempPath);

    // Cleanup Imagick object
    $image->clear();
    $image->destroy();

    // Upload resized image to the remote server
    $sshConnection = $sshDetails['connection'];
    if (!ssh2_scp_send($sshConnection, $localTempPath, $targetPath, $file_permissions)) {
        unlink($localTempPath);
        throw new Exception("Failed to upload resized image to: " . $targetPath);
    }

    // Delete the temporary file
    unlink($localTempPath);

    return true;
}

function generateCompressedVersions($filePath, $storageDirectory, $uniqueFileName, $file_permissions) {
    $sizes = [
        'small' => [600, 600],
        'medium' => [1200, 1200],
        'large' => [1600, 1600]
    ];

    $versions = [];

    foreach ($sizes as $size => [$width, $height]) {
        $newFileName = "{$size}_{$uniqueFileName}";
        $newFilePath = "{$storageDirectory}{$newFileName}";

        resizeImage($filePath, $newFilePath, $width, $height, $file_permissions);

        $versions[$size] = $newFileName;
    }

    return $versions;
}

function checkAndCreateRemoteDirectory($sftp, $remote_directory) {
    // Define the base directory that should not be checked or created
    $base_directory = $_ENV['FILE_HOSTING_ROOT'];

    // Ensure the remote_directory starts from the base directory
    if (strpos($remote_directory, $base_directory) !== 0) {
        throw new Exception("The remote directory must be within the base directory: " . $base_directory);
    }

    // Get the path relative to the base directory
    $relative_path = substr($remote_directory, strlen($base_directory));

    // Ensure the relative path is not empty and trim leading slashes
    $relative_path = trim($relative_path, '/');
    if (empty($relative_path)) {
        throw new Exception("No subdirectory specified beyond the base directory.");
    }

    // Break down the relative path into its individual parts
    $parts = explode('/', $relative_path);
    $path = $base_directory;

    foreach ($parts as $part) {
        // Append each part to the path
        $path .= "/$part";
        $current_path = "ssh2.sftp://$sftp$path";

        // Check if the directory exists
        $stat = @ssh2_sftp_stat($sftp, $path);
        if ($stat === false) {
            // Directory does not exist, try to create it
            if (!ssh2_sftp_mkdir($sftp, $path, 0700, true)) {
                throw new Exception("Failed to create remote directory: " . $path);
            } else {
                error_log("Directory created: " . $path);
            }
        } else {
            error_log("Directory already exists: " . $path);
        }
    }
}

function getImageDimensions($sftp, $remote_file_path) {
    // Open the remote file for reading
    $remote_file_stream = @fopen("ssh2.sftp://$sftp$remote_file_path", 'r');
    if (!$remote_file_stream) {
        error_log('Failed to open remote file: ' . $remote_file_path);
        return null;
    }

    // Read the file contents
    $file_data = stream_get_contents($remote_file_stream);
    fclose($remote_file_stream);

    if ($file_data === false) {
        error_log('Failed to read remote file: ' . $remote_file_path);
        return null;
    }

    // Create an Imagick instance from the file data
    $image = new Imagick();
    try {
        $image->readImageBlob($file_data);
    } catch (ImagickException $e) {
        error_log('Error reading image blob: ' . $e->getMessage());
        return null;
    }

    // Get the image dimensions
    $geometry = $image->getImageGeometry();
    $dimensions = ['width' => $geometry['width'], 'height' => $geometry['height']];

    // Clear and destroy the Imagick instance
    $image->clear();
    $image->destroy();

    return $dimensions;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    $access_level = $_POST['access_level'];
    $folder_path = $_POST['folder_path'];

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
        try {
            $ssh = sshConnect();
            $connection = $ssh['connection'];
            $sftp = $ssh['sftp'];
            $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/files' . $folder_path;
    
            // Check and create remote directory if needed
            checkAndCreateRemoteDirectory($sftp, $remote_directory);
    
            foreach ($_FILES['files']['name'] as $key => $file_name) {
                $file_tmp = $_FILES['files']['tmp_name'][$key];
                $unique_file_name = generateUniqueFileName($file_name, $folder_path);
                $pathSeparator = '/';
                if ($folder_path == '/') {
                    $pathSeparator = '';
                }
                $remote_file_path = $remote_directory . $pathSeparator . $unique_file_name;
    
                 // Determine the file permissions based on access level, if public (10) set to 0644, otherwise 0700
                 $access_level_array = explode(',', $access_level);
                 $access_level_array = array_map('intval', $access_level_array);
                 $file_permissions = in_array(10, $access_level_array) ? 0644 : 0700;
                // Upload the file to the remote server
                if (ssh2_scp_send($connection, $file_tmp, $remote_file_path, $file_permissions)) {
                    // Establish SFTP connection
                    $sftp = ssh2_sftp($connection);
                
                    // Check the file size on the remote server
                    $statinfo = ssh2_sftp_stat($sftp, $remote_file_path);
                    if ($statinfo === false) {
                        $errorMessages[] = 'Failed to get file size for: ' . $file_name;
                        $file_size = 0; // Set to 0 or handle as needed
                    } else {
                        $file_size = $statinfo['size'];
                    }
                
                     $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));   
                    $dimensions = [];
                    if ($file_extension === 'jpg' || $file_extension === 'jpeg' || $file_extension === 'png') {
                        // Generate compressed versions if the file is an image
                        $versions = generateCompressedVersions($remote_file_path, $remote_directory . '/', $unique_file_name, $file_permissions);
                         // Get dimensions for each version
                         $dimensions['original'] = getImageDimensions($sftp,$remote_file_path);
                         foreach ($versions as $size => $versionFileName) {
                             $dimensions[$size] = getImageDimensions($sftp, $remote_directory . '/' . $versionFileName);
                         }
                    } else {
                        $versions = ['small' => '', 'medium' => '', 'large' => ''];
                    }
                
                    if (storeFileMetadataInDatabase($user_email, $folder_path, $unique_file_name, $versions['small'], $versions['medium'], $versions['large'],  $dimensions, $access_level, $file_size)) {
                        $successMessages[] = $unique_file_name;
                    } else {
                        $errorMessages[] = 'File metadata could not be stored: ' . $file_name;
                    }
                } else {
                    $errorMessages[] = 'File could not be uploaded: ' . $file_name;
                }
            }
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            $errorMessages[] = 'Server error: ' . $e->getMessage();
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