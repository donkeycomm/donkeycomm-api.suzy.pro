<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('check-origin.php'); // Check CORS
// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
$data = file_get_contents('php://input');
$data = json_decode($data);

function hasBearerToken() {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return true;
        }
    }
    return false;
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
$hasToken = hasBearerToken();


    $user_roles = array(10);
    if($hasToken) {
        try {
            include('./jwt-validation.php'); // Include the JWT validation code

            $user_roles = getUserRolesFromDatabase($user_email, $jwt);
        
            } catch (Exception $e) {
                // Log the error message for debugging
                error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            
            }
        }
    try {
          //loop through user roles and add them to the query to see if it matches the folder roles
           if (is_string($user_roles)) {
                $rolesArray = json_decode($user_roles, true);
            } else {
                $rolesArray = $user_roles;
            }
            $sql = "SELECT * FROM `folders` WHERE (";
            foreach ($rolesArray as $role) {
                $sql .= "JSON_CONTAINS(`access_level`, '$role') OR ";
            }
            $sql = rtrim($sql, " OR "); // Remove the last " OR "
            $sql .= ") AND `parent_path` = ? ORDER BY `rank` DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $data->path);
            $stmt->execute();
            $result = $stmt->get_result();
            $folders = array();

              // Establish SSH connection
            $ssh = sshConnect();
            $sftp = $ssh['sftp'];
            
            while($row = $result->fetch_assoc()) {
                $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/folder_images' . '/' .$row['image_'.$data->size];

                // Fetch file contents from the remote server
                $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");

               
                if ($file_data === FALSE) {
                    error_log("Failed to get folder: /folder_images/".$row['image_'.$data->size]);
                    $row['base64'] = null;
                } else {
                    $base64 = base64_encode($file_data);
                    $row['base64'] = $base64;
                }
                $folders[] = $row;
            }
            echo json_encode($folders);
              
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }


?>
