<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';
// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);
//create a function to store the metadata in the database of the deleted file
function storeDeletedFolderInDatabase($folder_id, $folder_name, $folder_path, $folder_access_level, $user_email) {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    //get user info from the api get-user-data.php
    try {
        $stmt = $conn->prepare("INSERT INTO `deleted_folders` (`folder_id`, `folder_name`, `folder_path`, `folder_access_level`, `user_email`, `date`) VALUES (?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("isssss", $folder_id, $folder_name, $folder_path, $folder_access_level,$user_email, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['message' => 'Folder deleted']);
      
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' =>  $e->getMessage()]);
    }  
}

function getUserRolesFromDatabase($user_email, $jwt) {
    //get user info from the api get-user-data.php
  
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $user_email);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Bearer " . $jwt . "\r\n", // Add this line
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if ($result === false) {
            // Handle the case where file_get_contents fails
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
if ($data->id) {
    try {

        $user_roles = getUserRolesFromDatabase($user_email, $jwt);
           
    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
    //get file path from database and return the original file
    
    try {
        include('./db-connection.php'); // Include the database connection code
        $stmt = $conn->prepare("SELECT * FROM `folders` WHERE `ID`=?");
        $stmt->bind_param("i", $data->id);
        $stmt->execute();
        $result = $stmt->get_result();
    
        if ($result->num_rows === 1) {
            // Fetch folder details
            $folder = $result->fetch_assoc();
            $folder_path = $folder['path'];
            $folder_name = $folder['name'];
            $folder_access_level = $folder['access_level'];
    
            $image = $folder['image'];
            $small = $folder['image_small'];
            $medium = $folder['image_medium'];
            $large = $folder['image_large'];
    
            $rolesArray = json_decode($user_roles, true);
            // Check if the user role is 0 or 1
            if (in_array(0, $rolesArray) || in_array(1, $rolesArray)) {
                try {
                    // Establish SSH connection
                    $sshDetails = sshConnect();
                    if (!$sshDetails || !isset($sshDetails['sftp'])) {
                        throw new Exception('SSH connection failed');
                    }
                    $sftp = $sshDetails['sftp'];
                    $sftp_path = "ssh2.sftp://$sftp";
                    $remote_file_path = $_ENV['FILE_HOSTING_ROOT'] . '/files';
                    $folder_path = $remote_file_path . $folder_path;
            
                    // Check if the folder exists
                    $folder_stat = ssh2_sftp_stat($sftp, $folder_path);
                    if ($folder_stat === false) {
                        throw new Exception('Folder does not exist: ' . $folder_path);
                    }
            
                    // Check if the folder is empty
                    $dir_handle = opendir($sftp_path . $folder_path);
                    if (!$dir_handle) {
                        throw new Exception('Failed to open directory: ' . $folder_path);
                    }
                    $files_length = 0;
                    while (($file = readdir($dir_handle)) !== false) {
                        if ($file != "." && $file != "..") {
                            $files_length++;
                        }
                    }
                    closedir($dir_handle);
            
                    if ($files_length > 0) {
                        echo json_encode(['error' => 'Cannot delete: folder is not empty.']);
                        exit();
                    } else {
                        // If folder is empty, delete it
                        if (!ssh2_sftp_rmdir($sftp, $folder_path)) {
                            throw new Exception('Failed to delete folder: ' . $folder_path);
                        }
            
                     // Additional cleanup if needed
                    if ($image != 'folder-placeholder.jpg') {
                        // Check if the SFTP connection is valid
                        if ($sftp) {
                            // Ensure that the variables are defined
                            if (isset($small, $medium, $large)) {
                                // Construct the file paths
                                $imagePath = $_ENV['FILE_HOSTING_ROOT'] . '/folder_images/' . $image;
                                $smallPath = $_ENV['FILE_HOSTING_ROOT'] . '/folder_images/' . $small;
                                $mediumPath = $_ENV['FILE_HOSTING_ROOT'] . '/folder_images/' . $medium;
                                $largePath = $_ENV['FILE_HOSTING_ROOT']. '/folder_images/' . $large;

                                // Attempt to delete the files
                                if (!ssh2_sftp_unlink($sftp, $imagePath)) {
                                    error_log("Failed to delete $imagePath");
                                }
                                if (!ssh2_sftp_unlink($sftp, $smallPath)) {
                                    error_log("Failed to delete $smallPath");
                                }
                                if (!ssh2_sftp_unlink($sftp, $mediumPath)) {
                                    error_log("Failed to delete $mediumPath");
                                }
                                if (!ssh2_sftp_unlink($sftp, $largePath)) {
                                    error_log("Failed to delete $largePath");
                                }
                            } else {
                                error_log("One or more image size variables are not defined.");
                            }
                        } else {
                            error_log("Invalid SFTP connection.");
                        }
                    }
            
                        // Delete folder record from database
                        $stmt = $conn->prepare("DELETE FROM `folders` WHERE `ID`=?");
                        $stmt->bind_param("i", $data->id);
                        $stmt->execute();
                        $stmt->close();
                        $conn->close();
            
                        storeDeletedFolderInDatabase($data->id, $folder_name, $folder_path, $folder_access_level, $user_email);
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
                    exit();
                }
            } else {
                // Return error
                echo json_encode(['error' => 'You\'re not authorized to delete this folder']);
            }
        } else {
            // Return error
            echo json_encode(['error' => 'Folder not found']);
        }
    } catch (Exception $e) {
        http_response_code(500); // Server error
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        // Log the error if needed
        error_log($e->getMessage());
    }
   
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>