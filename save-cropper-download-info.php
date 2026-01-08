<?php
include('./check-origin.php'); // Include the JWT validation code
 
// Start output buffering
ob_start();

// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
// Retrieve data from the request
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

function storeMetaInDatabase($file_id, $user, $path, $file, $small, $medium, $large, $type, $access_level) {
    global $conn;
    $date = date('Y-m-d H:i:s');
    if($type === 'file'){
        $type = 'original';
    }
    $stmt = $conn->prepare("INSERT INTO `file_logs` (`file_id`, `user`, `path`, `file`, `small`, `medium`, `large`, `type`, `access_level`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssss", $file_id, $user, $path, $file, $small, $medium, $large, $type, $access_level, $date);
    $stmt->execute();
    $stmt->close();
}

    $user_roles = array(10);

    if ($data->email) {
        try {
            include './jwt-validation.php'; 
            $user_roles = getUserRolesFromDatabase($data->email, $jwt);

        } catch (Exception $e) {
            // Log the error message for debugging
            error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Server error']);
        }
    }
    //get file path from database and return the original file
    
        try {
             // If $user_roles is a JSON string, decode it. Otherwise, assume it's already an array.
             if (is_string($user_roles)) {
                $rolesArray = json_decode($user_roles, true);
            } else {
                $rolesArray = $user_roles;
            }
            $stmt = $conn->prepare("SELECT * FROM `files` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $file = $result->fetch_assoc();
                $pathSeparator = "/";
                if($file['path'] == ""){
                    $pathSeparator = "";
                }
                $access_levels = json_decode($file['access_level']);
                //compare access levels with user roles
                $sharedItems = array_intersect($access_levels, $rolesArray);

                if (!empty($sharedItems)) {
                    // Establish SSH connection and SFTP
                    $active_user = 'public';
                    if($data->email){
                        $active_user = $data->email;
                    }
                    //store the file download in the database
                    storeMetaInDatabase($data->id, $active_user, $file['path'], $file['file'], $file['small'], $file['medium'], $file['large'], 'cropped', $file['access_level']);
                    exit;
                 
                } else {
                    http_response_code(403); // Forbidden
                    error_log('error Forbidden');
                }
            }  else {
                http_response_code(404); // User not found
                error_log('error file not found');
            }
        } catch (Exception $e) {
            http_response_code(500); // Server error
            error_log('error server error');
            // Log the error if needed
        }
   

?>