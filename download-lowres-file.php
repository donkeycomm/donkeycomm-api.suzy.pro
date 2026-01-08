<?php
include('./jwt-validation.php'); // Include the JWT validation code
 
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

function storeMetaInDatabase($file_id, $user, $path, $file, $small, $medium, $large, $access_level) {
    global $conn;
    $date = date('Y-m-d H:i:s');
    $type = 'low_res';
    $stmt = $conn->prepare("INSERT INTO `file_logs` (`file_id`, `user`, `path`, `file`, `small`, `medium`, `large`, `type`, `access_level`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssss", $file_id, $user, $path, $file, $small, $medium, $large, $type, $access_level, $date);
    $stmt->execute();
    $stmt->close();
}

if ($data->email && $data->id) {
    try {

        $user_roles = getUserRolesFromDatabase($data->email,$jwt);

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
                $file = $result->fetch_assoc();
            $pathSeparator = "/";
            if($file['path'] == ""){
                $pathSeparator = "";
            }
            $access_levels = json_decode($file['access_level']);
            $userRolesArray = json_decode($user_roles, true);
            //compare access levels with user roles
            $sharedItems = array_intersect($access_levels, $userRolesArray);

            if (!empty($sharedItems)) {
                  // Establish SSH connection and SFTP
                $sshDetails = sshConnect();
                $sftp = $sshDetails['sftp'];
                $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/files';
                $file_path = $remote_directory . $file['path'] . $pathSeparator . $file['large'];
                $file_name = $file['large'];

                // Open the remote file
                $sftp_stream = @fopen("ssh2.sftp://$sftp$file_path", 'r');
                if ($sftp_stream === FALSE) {
                        http_response_code(404); // File not found
                        echo json_encode(['error' => 'File not found']);
                        exit;
                    }

                    // Get the file size
                    $statinfo = fstat($sftp_stream);
                    $file_size = $statinfo['size'];

                    // CORS header
                    $request_headers = apache_request_headers();
                    $http_origin = $request_headers['Origin'];
                    $allowed_http_origins = array(
                        $_ENV['URL_FRONTEND'], $_ENV['URL_BACKEND']
                    );
                   
                    $trusted_dev_ips = explode(',', $_ENV['TRUSTED_DEV_IPS']);
                    if (in_array($_SERVER['REMOTE_ADDR'], $trusted_dev_ips)) {
                        $allowed_http_origins[] = "http://localhost:3000";
                    }
                    if (in_array($http_origin, $allowed_http_origins)) {
                        @header("Access-Control-Allow-Origin: " . $http_origin);
                    }
                    header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename=' . $file_name);
                    header('Content-Length: ' . $file_size);
                    ob_end_flush(); // End output buffering and send output to browser

                    // Output the file contents
                    fpassthru($sftp_stream);
                    fclose($sftp_stream);

                    storeMetaInDatabase($data->id, $user_email, $file['path'], $file['file'], $file['small'], $file['medium'], $file['large'],$file['access_level']);

                    exit;
                } else {
                    http_response_code(403); // Forbidden
                    echo json_encode(['error' => 'Forbidden']);
                }

              
            } else {
                http_response_code(404); // User not found
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