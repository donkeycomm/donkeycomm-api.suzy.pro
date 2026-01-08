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
if($data->path){

        $user_roles = array(10);

        if (isset($data->email)) {
            try {
                include './jwt-validation.php'; 
                $user_roles = getUserRolesFromDatabase($user_email, $jwt);
            
            } catch (Exception $e) {
                // Log the error message for debugging
                error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
            
            }
        }

        try {
                // If $user_roles is a JSON string, decode it. Otherwise, assume it's already an array.
                if (is_string($user_roles)) {
                    $rolesArray = json_decode($user_roles, true);
                } else {
                    $rolesArray = $user_roles;
                }

                // Log the path and rolesArray for debugging
                error_log("Path: " . $data->path);
                error_log("Roles Array: " . print_r($rolesArray, true));

                // Construct the SQL query to select folders with matching access levels
                $sql = "SELECT * FROM `folders` WHERE `path` = ? AND (";
                foreach ($rolesArray as $role) {
                    $sql .= "JSON_CONTAINS(`access_level`, '$role') OR ";
                }
                $sql = rtrim($sql, " OR "); // Remove the last " OR "
                $sql .= ")";

                // Log the constructed SQL query for debugging
                error_log("SQL Query: " . $sql);

                // Prepare and execute the SQL statement
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $data->path);
                $stmt->execute();
                $result = $stmt->get_result();

                // Establish SSH connection
                $ssh = sshConnect();
                $sftp = $ssh['sftp'];

                if ($result->num_rows === 1) {
                    $result = $result->fetch_assoc();
                    // Only return certain fields
                    $folder = array(
                        'ID' => $result['ID'],
                        'name' => $result['name'],
                        'path' => $result['path'],
                        'date' => $result['date'],
                        'image_small' => $result['image_small'],
                        'image_medium' => $result['image_medium'],
                        'image_large' => $result['image_large'],
                        'access_level' => $result['access_level']
                    );
                    $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/folder_images' . '/' .$folder['image_large'];

                    // Fetch file contents from the remote server
                    $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");

                    if ($file_data === FALSE) {
                        error_log("Failed to get folder: /folder_images/".$folder['image_large']);
                        $folder['base64'] = null;
                    } else {
                        $base64 = base64_encode($file_data);
                        $folder['base64'] = $base64;
                    }

                    echo json_encode($folder);
                } else {
                    http_response_code(404); // Folder not found
                    echo json_encode(['error' => 'Folder not found']);
                }
            } catch (Exception $e) {
                http_response_code(500); // Server error
                echo json_encode(['error' => 'Server error']);
                // Log the error if needed
                error_log("Exception: " . $e->getMessage());
            }
} else {
    http_response_code(400); // Bad request
    echo json_encode(['error' => 'Invalid request']);
}

?>
