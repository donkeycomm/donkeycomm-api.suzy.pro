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
if ($data->email && $data->id) {
    try {

        $user_roles = getUserRolesFromDatabase($data->email,$jwt);

    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
    //get file path from database and return the original file
    $rolesArray = json_decode($user_roles, true);
              
    if(in_array(0, $rolesArray)){
        try {
            $stmt = $conn->prepare("SELECT * FROM `files_in_trash` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                $file = $result->fetch_assoc();
                    $file_path = $file['file_path'];
                    $file_name = $file['file_name'];
                    $file_size = filesize($file_path);

                    $sshDetails = sshConnect();
                    $sftp = $sshDetails['sftp'];

                    // Open the remote file
                    $sftp_stream = @fopen("ssh2.sftp://$sftp$file_path", 'r');
                    if ($sftp_stream === FALSE) {
                        http_response_code(404); // Not Found
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
                    
                    // Clear output buffer
                    if (ob_get_level()) {
                        ob_end_clean();
                    }

                    // Output the file contents
                    while (!feof($sftp_stream)) {
                        echo fread($sftp_stream, 8192);
                        flush();
                    }
                    fclose($sftp_stream);

                    exit;
             
              
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
                    
        http_response_code(403); // Forbidden
        echo json_encode(['error' => 'Forbidden']);
    }

} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>