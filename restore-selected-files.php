<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('./jwt-validation.php'); // Include the JWT validation code
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';

// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

// Function to store restored file metadata in the database
function storeFileMetadataInDatabase($user_email, $folder_path, $file_name, $small, $medium, $large, $dimensions, $access_level, $file_size) {
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

    try {
        $stmt = $conn->prepare("INSERT INTO `files` (`user`, `path`, `file`, `small`, `medium`, `large`, `dimensions`, `access_level`, `size`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("ssssssssis", $user_email, $folder_path, $file_name, $small, $medium, $large, $dimensions, $access_level_json_data, $file_size, $date);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Error in storeFileMetadataInDatabase: " . $e->getMessage());
        return false;
    }
}
// Function to get user roles from the database
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

try {
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
} catch (Exception $e) {
    error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
$userRolesArray = json_decode($user_roles, true);
    
function restoreFile($sftp, $trash_file, $original_file) {
    $sftp_path = "ssh2.sftp://$sftp";
    // Check if the file exists in the trash
    if (file_exists($sftp_path . $trash_file)) {
        // Move the file back to its original location
        if (!ssh2_sftp_rename($sftp, $trash_file, $original_file)) {
            error_log("Failed to restore $trash_file to $original_file"); 
            echo json_encode(['error' => "Failed to restore $trash_file to $original_file"]);

        }
    } else {
        error_log("Trash file $trash_file does not exist");
        echo json_encode(['error' => "Trash file $trash_file does not exist"]);
    }
}

if (in_array(0, $userRolesArray)) {
// Check if files array exists and is not empty
if (isset($data->files) && is_array($data->files) && count($data->files) > 0) {
    foreach ($data->files as $file_id) {
        if (isset($file_id)) {
            try {
                // Get user roles from the database
                $user_roles = getUserRolesFromDatabase($user_email, $jwt);

                // Check if the file exists in the trash
                $stmt = $conn->prepare("SELECT * FROM `files_in_trash` WHERE `ID`=?");
                $stmt->bind_param("i", $file_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    // Retrieve the file metadata
                    $file = $result->fetch_assoc();
                    $file_path = $file['file_path'];
                    $file_name = $file['file_name'];
                    $small = $file['small'];
                    $medium = $file['medium'];
                    $large = $file['large'];
                    $access_level = $file['file_access_level'];
                    $original_file_folder = $file['original_file_path'];

                    // Establish SSH connection and SFTP
                    $sshDetails = sshConnect();
                    $sftp = $sshDetails['sftp'];

                    // Define the remote paths for file restoration
                    $trash_path = $file_path;
                    $original_path = $_ENV['FILE_HOSTING_ROOT'] . '/files' . $original_file_folder;

                    // Check if the original directory exists
                    $sftp_path = "ssh2.sftp://$sftp";
                    if (!file_exists($sftp_path . $original_path)) {
                        echo json_encode(['error' => 'Original directory does not exist for file ' . $file_name]);
                        exit;
                    }
                    // Split the file name into parts
                    $parts = pathinfo($file_name);
                    $i = 0;

                    // Ensure the file name is unique
                    while (file_exists($sftp_path . $original_path . '/' . $file_name)) {
                        $i++;
                        $file_name = $parts["filename"] . "-" . $i . "." . $parts["extension"];
                    }
                    // Restore the main file
                    $trash_file_path = $trash_path;
                    $original_file_path = $original_path . '/' . $file_name;

                 
                   
                    // Restore main and variant files (small, medium, large)
                    restoreFile($sftp, $trash_file_path, $original_file_path);

                    if (!empty($small)) {
                        restoreFile($sftp, $small, $original_path . '/' . 'small_'.$file_name);
                    }

                    if (!empty($medium)) {
                        restoreFile($sftp, $medium, $original_path . '/' . 'medium_'.$file_name);
                    }

                    if (!empty($large)) {
                        restoreFile($sftp, $large, $original_path . '/' . 'large_'.$file_name);
                    }

                   // Store file metadata in the database
                   storeFileMetadataInDatabase(
                    $user_email,
                    $original_file_folder,
                    $file_name,
                    !empty($small) ? 'small_' . $file_name : '',
                    !empty($medium) ? 'medium_' . $file_name : '',
                    !empty($large) ? 'large_' . $file_name : '',
                    $file['dimensions'],
                    $access_level,
                    $file['size']
                );
                    // Remove the file record from the `files_in_trash` table
                    $stmt = $conn->prepare("DELETE FROM `files_in_trash` WHERE `ID`=?");
                    $stmt->bind_param("i", $file_id);
                    $stmt->execute();
                    $stmt->close();
                  
                } 

            } catch (Exception $e) {
                http_response_code(500); // Server error
                echo json_encode(['error' => 'Server error while restoring file with ID ' . $file_id]);
                error_log($e->getMessage());
            }
        } else {
            echo json_encode(['error' => 'Invalid file data provided']);
        }
    }
    echo json_encode(['success']);
} else {
    echo json_encode(['error' => 'No files to restore']);
}
} else {
    echo json_encode(['error' => 'User not authorized']);
}
?>
