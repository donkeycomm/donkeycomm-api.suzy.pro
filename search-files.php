<?php
include('./check-origin.php'); // Include the JWT validation code
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';

$data = file_get_contents('php://input');
$data = json_decode($data);

$path = $data->path;
$text = $data->text;

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
if ($path !== null && $text !== null) {
    $user_roles = array(10);

 
    $hasToken = hasBearerToken();


    $user_roles = array(10);
    if($hasToken) {
        try {
            include('./jwt-validation.php');
            $user_roles = getUserRolesFromDatabase($user_email, $jwt);
            
        } catch (Exception $e) {
            // Log the error message for debugging
            error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
        try {
            if (is_string($user_roles)) {
                $rolesArray = json_decode($user_roles, true);
            } else {
                $rolesArray = $user_roles;
            }
          
            $jsonSearchConditions = array_map(function($role) {
                return "JSON_CONTAINS(`access_level`, '$role')";
            }, $rolesArray);
            $jsonSearchCondition = implode(' OR ', $jsonSearchConditions);
            
            $stmt = $conn->prepare("SELECT * FROM `files` WHERE `path` LIKE CONCAT('%', ?, '%') AND `file` LIKE CONCAT('%', ?, '%') AND ($jsonSearchCondition)");
            $stmt->bind_param("ss", $path,$text);
            $stmt->execute();
            $result = $stmt->get_result();

            $searchResults = [];
            // Establish SSH connection and SFTP
            $ssh = sshConnect();
            $sftp = $ssh['sftp'];

            while($row = $result->fetch_assoc()) {
                //get the file
                $pathSeparator = "/";
                $path = $row['path'];
                if($row['path'] === '/'){
                    $path = '';
                }
                $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['small'];

                // Fetch file contents from the remote server
                $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");
                
                
                if ($file_data === FALSE) {
                    error_log("Failed to get files");
                    $row['base64'] = null;
                } else {
                    $base64 = base64_encode($file_data);
                    $row['base64'] = $base64;
                }
                $searchResults[] = $row;
            }
            echo json_encode($searchResults);
                            
                    
        } catch (Exception $e) {
            http_response_code(500); // Server error
            echo json_encode(['error' => 'Server error', 'errorMessage' => $e->getMessage()]);
            // Log the error if needed
        }
} else {
    http_response_code(400); // Bad request
    echo json_encode(['error' => 'Invalid request']);
}

?>
