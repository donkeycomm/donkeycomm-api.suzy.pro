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
function storeDeletedFileInDatabase($file_id, $file_name, $file_path, $file_access_level, $user_email, $file_size) {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    //get user info from the api get-user-data.php
    try {
        $stmt = $conn->prepare("INSERT INTO `deleted_from_trash` (`file_id`, `file_name`, `file_path`, `file_access_level`, `user_email`, `size`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("issssis", $file_id, $file_name, $file_path, $file_access_level, $user_email, $file_size, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return 'file deleted';
      
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error storing metadata in database';
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
        error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
    //get file path from database and return the original file
    
        try {
            include('./db-connection.php'); // Include the database connection code
            $stmt = $conn->prepare("SELECT * FROM `files_in_trash` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                //delete the files from the server
                $file = $result->fetch_assoc();
                $file_id = $file['file_id'];
                $file_path = $file['file_path'];
                $file_name = $file['file_name'];
                $file_size = $file['size'];
                $small = $file['small'];
                $medium = $file['medium'];
                $large = $file['large'];
                $access_level = $file['file_access_level'];
               
                $userRolesArray = json_decode($user_roles, true);
                  
                    if(in_array(0, $userRolesArray)){
                        $sshDetails = sshConnect();
                        $sftp = $sshDetails['sftp'];
                       
        
                        // Delete the files from the server
                        ssh2_sftp_unlink($sftp, "$file_path");
                        ssh2_sftp_unlink($sftp, "$small");
                        ssh2_sftp_unlink($sftp, "$medium");
                        ssh2_sftp_unlink($sftp, "$large");
        
                        $stmt = $conn->prepare("DELETE FROM `files_in_trash` WHERE `ID`=?");
                        $stmt->bind_param("i", $data->id);
                        $stmt->execute();
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['message' => storeDeletedFileInDatabase($file_id, $file_name, $file_path, $access_level, $user_email, $file_size)]);

                    } else {
                        //return error
                        echo json_encode(['error' => 'User not authorized']);
                    }
               
                } else {
                    //return error
                    echo json_encode(['error' => 'File not found']);
                }

        } catch (Exception $e) {
            http_response_code(500); // Server error
            echo json_encode(['error' => 'Server error']);
            // Log the error if needed
        }
   
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>