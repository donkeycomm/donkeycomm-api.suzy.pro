<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./check-origin.php'); // Include the JWT validation code

// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
$data = file_get_contents('php://input');
$data = json_decode($data);

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

$user_roles = array(10);
if($data->email) {
   
    try {
        include('./jwt-validation.php');
        $user_roles = getUserRolesFromDatabase($data->email, $jwt);
     
    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
    try {
        if (is_string($user_roles)) {
            $rolesArray = json_decode($user_roles, true);
        } else {
            $rolesArray = $user_roles;
        }
        $stmt = $conn->prepare("SELECT * FROM `files` WHERE `ID`=?");
        $stmt->bind_param("s", $data->id);
        $stmt->execute();
        $result = $stmt->get_result();
       

        // Establish SSH connection
        $ssh = sshConnect();
        $sftp = $ssh['sftp'];

        if ($result->num_rows === 1) {
            $file = $result->fetch_assoc();
            $pathSeparator = "/";
            if($file['path'] == "/") {
                $pathSeparator = "";
            }
            $access_levels = json_decode($file['access_level']);
            //compare access levels with user roles
            $sharedItems = array_intersect($access_levels, $rolesArray);

            if (!empty($sharedItems)) {
                    $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $file['path'] . $pathSeparator . $file['large'];
                    // Fetch file contents from the remote server
                    $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");

                    if ($file_data === FALSE) {
                        error_log("Failed to get file contents for: $remote_file_path");
                        $file['base64'] = null;
                    } else {
                        $base64 = base64_encode($file_data);
                        $file['base64'] = $base64;
                    }
                
       
                echo json_encode($file);
            } else {
                        
                http_response_code(403); // Forbidden
                echo json_encode(['error' => 'Forbidden']);
            }

        } else {
            http_response_code(404); // User not found
            echo json_encode(['error' => 'File not found']);
        }  
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }


?>