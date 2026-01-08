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

//create a function to store the metadata in the database of the deleted file
function storeDeletedFileInDatabase($file_id, $file_name, $file_path, $file_access_level, $file_dimensions, $user_email, $file_size ) {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    //get user info from the api get-user-data.php
    try {
        $stmt = $conn->prepare("INSERT INTO `deleted_files` (`file_id`, `file_name`, `file_path`, `file_access_level`, `file_dimensions`, `user_email`, `size`, `date` ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("isssssis", $file_id, $file_name, $file_path, $file_access_level, $file_dimensions, $user_email,  $file_size, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return json_encode(['message' => 'file moved to trash']);
      
    } catch (Exception $e) {
        error_log($e->getMessage());
        http_response_code(500);
        return json_encode(['error' => 'Error storing in database']);
    }  
}
function storeDeletedFileInTrashDatabase($file_id, $file_name, $file_path, $file_access_level, $small, $medium, $large, $dimensions, $user_email, $file_size, $original_file_path) {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    //get user info from the api get-user-data.php
    try {
        $stmt = $conn->prepare("INSERT INTO `files_in_trash` (`file_id`, `file_name`, `file_path`, `file_access_level`, `small`, `medium`, `large`, `dimensions`, `user_email`, `size`, `original_file_path`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("issssssssiss", $file_id, $file_name, $file_path, $file_access_level, $small, $medium, $large, $dimensions, $user_email, $file_size, $original_file_path, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return json_encode(['message' => 'success']);
      
    } catch (Exception $e) {
        error_log($e->getMessage());
        http_response_code(500);
        return json_encode(['error' => 'error storing metadata in database']);
       
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
// Function to move file to trash
function moveToTrash($sftp, $file, $trash_file) {
$sftp_path = "ssh2.sftp://$sftp";

// Check if the file exists
if (file_exists($sftp_path . $file)) {
    // Check if the trash file already exists
    if (file_exists($sftp_path . $trash_file)) {
        $trash_file = dirname($trash_file) . '/' . uniqid() . '-' . basename($trash_file);
    }
    // Rename (move) the file to the trash
    if (!ssh2_sftp_rename($sftp, $file, $trash_file)) {
        error_log("Failed to move $file to $trash_file");

        echo json_encode(['error' => "Failed to move $file to $trash_file"]);
    }
    } else {
        error_log("File $file does not exist");
        echo json_encode(['error' => "File $file does not exist"]);
    }
}
if ($data->id) {
    try {

        $user_roles = getUserRolesFromDatabase($user_email, $jwt);

    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
    //get file path from database and return the original file
    
        try {
           
            $stmt = $conn->prepare("SELECT * FROM `files` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                //delete the files from the server
                $file = $result->fetch_assoc();
                $file_path = $file['path'];
                $file_name = $file['file'];
                $small = $file['small'];
                $medium = $file['medium'];
                $large = $file['large'];
                $access_level = $file['access_level'];
                $pathSeparator = "/";
                if($file_path == "/"){
                    $pathSeparator = "";
                }
               
                $folder_access_level_array = json_decode($access_level);
   
                $rolesArray = json_decode($user_roles, true);
   
                if (in_array(0, $rolesArray) || in_array(1, $rolesArray)) {
                    // Establish SSH connection and SFTP
                    $sshDetails = sshConnect();
                    $sftp = $sshDetails['sftp'];

                    // Define remote file paths
                    $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files';
                    $file_path = $remote_file_path . $file_path . $pathSeparator;
                    $file_name = $file_path . $file_name;
                    $small = !empty($small) ? $file_path . $small : '';
                    $medium = !empty($medium) ? $file_path . $medium : '';
                    $large = !empty($large) ? $file_path . $large : '';
                    error_log('File to move: '.$file_name);
                 // Define remote trash paths
                    $trash_path = $_ENV['FILE_HOSTING_ROOT'] . '/trash';

                    // Check if trash directory exists, if not create it
                    $sftp_path = "ssh2.sftp://$sftp";
                   

                    $trash_file_name = $trash_path . '/' . $file['file'];
                    $trash_small = !empty($file['small']) ? $trash_path . '/' . $file['small'] : '';
                    $trash_medium = !empty($file['medium']) ? $trash_path . '/' . $file['medium'] : '';
                    $trash_large = !empty($file['large']) ? $trash_path . '/' . $file['large'] : '';
        
                    // Check if any of the files already exist
                    if (file_exists($sftp_path . $trash_file_name) || (!empty($trash_small) && file_exists($sftp_path . $trash_small)) || (!empty($trash_medium) && file_exists($sftp_path . $trash_medium)) || (!empty($trash_large) && file_exists($sftp_path . $trash_large))) {
                        $unique_id = uniqid();
                        $trash_file_name = $trash_path . '/' . $unique_id . '-' . basename($file['file']);
                        if (!empty($trash_small)) {
                            $trash_small = $trash_path . '/' . $unique_id . '-' . basename($file['small']);
                        }
                        if (!empty($trash_medium)) {
                            $trash_medium = $trash_path . '/' . $unique_id . '-' . basename($file['medium']);
                        }
                        if (!empty($trash_large)) {
                            $trash_large = $trash_path . '/' . $unique_id . '-' . basename($file['large']);
                        }
                    }

                    error_log('Destination in trash: '.$trash_file_name);
              
                   
                    // Move files to trash
                    moveToTrash($sftp, $file_name, $trash_file_name);

                    if (!empty($file['small'])) {
                        moveToTrash($sftp, $small, $trash_small);
                    }
                    
                    if (!empty($file['medium'])) {
                        moveToTrash($sftp, $medium, $trash_medium);
                    }
                    
                    if (!empty($file['large'])) {
                        moveToTrash($sftp, $large, $trash_large);
                    }

                    // Delete the file record from the database
                    $stmt = $conn->prepare("DELETE FROM `files` WHERE `ID`=?");
                    $stmt->bind_param("i", $data->id);
                    $stmt->execute();
                    $stmt->close();
                    $conn->close();

                    storeDeletedFileInDatabase($data->id, $file['file'], $file_path, $access_level, $file['dimensions'], $user_email, $file['size']);
                    echo storeDeletedFileInTrashDatabase($data->id, $file['file'], $trash_file_name, $access_level, $trash_small, $trash_medium, $trash_large, $file['dimensions'], $user_email, $file['size'], $file['path']);
                } else {
                    // Return error
                    echo json_encode(['error' => 'You\'re not authorized to delete this file']);
                }
               
            } else {
                //return error
                echo json_encode(['error' => 'File not found']);
            }
          

        } catch (Exception $e) {
            http_response_code(500); // Server error
            echo json_encode(['error' => 'Server error']);
            // Log the error if needed
            error_log($e->getMessage());
        }
   
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>