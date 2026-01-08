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
            error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
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
            $sql .= ") AND `path` = ? ORDER BY `rank` DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $data->path);
            $stmt->execute();
            $result = $stmt->get_result();
        
            if ($result->num_rows === 1) {
                $folder = $result->fetch_assoc();
                
                // Initialize an array to hold folder objects
                $folders = [];
                
                // Loop to get each parent folder until the parent folder is '/'
                while ($folder) {
                    // Create a filtered folder object
                    $filtered_folder = [
                        'folder_name' => $folder['name'],
                        'path' => $folder['path'],
                        'parent_path' => $folder['parent_path']
                    ];
                    // Add the current folder to the folders array
                    $folders[] = $filtered_folder;
        
                    // Check if the current folder's parent path is the root '/'
                    if ($folder['parent_path'] === '/') {
                        break; // Stop if we reach the root folder
                    }
        
                    // Prepare and execute the SQL to get the parent folder using the current folder's parent_path
                    $parentPath = $folder['parent_path'];
                    $sql = "SELECT * FROM `folders` WHERE `path` = ? LIMIT 1";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $parentPath);
                    $stmt->execute();
                    $result = $stmt->get_result();
        
                    // If the parent folder is found, update the folder variable; otherwise, exit the loop
                    if ($result->num_rows === 1) {
                        $folder = $result->fetch_assoc(); // Get the parent folder
                    } else {
                        $folder = null; // No parent folder found, exit the loop
                    }
                }
        
                // Reverse the array to show folders from root to target
                echo json_encode(array_reverse($folders)); 
            } else {
                http_response_code(404); // User not found
                echo json_encode(['error' => 'Folder not found']);
            }
              
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }


?>
