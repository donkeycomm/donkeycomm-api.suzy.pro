<?php
$request_headers = apache_request_headers();
$http_origin = '';

// If Origin header exists, capture it
if (isset($request_headers['Origin'])) {
    $http_origin = $request_headers['Origin'];
}

// Allow all origins
header("Access-Control-Allow-Origin: *");

// Allow the specified methods
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

// Allow the specified headers
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // The request is a preflight request. Respond successfully:
    http_response_code(200);
    exit;
}
// Start output buffering
ob_start();

// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);



function storeMetaInDatabase($file_id, $user, $path, $file, $small, $medium, $large, $access_level) {
    global $conn;
    $date = date('Y-m-d H:i:s');
    $type = 'original';
    $stmt = $conn->prepare("INSERT INTO `file_logs` (`file_id`, `user`, `path`, `file`, `small`, `medium`, `large`, `type`, `access_level`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssss", $file_id, $user, $path, $file, $small, $medium, $large, $type, $access_level, $date);
    $stmt->execute();
    $stmt->close();
}
if ($data->id) {

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
              
                if (in_array('10', $access_levels)) {
                    // Establish SSH connection and SFTP
                    $sshDetails = sshConnect();
                    $sftp = $sshDetails['sftp'];
                    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/files';
                    $file_path = $remote_directory . $file['path'] . $pathSeparator . $file['file'];
                    $file_name = $file['file'];

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
                    header("Access-Control-Allow-Origin: *");
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
                    //store the file download in the database
                    storeMetaInDatabase($data->id, 'public_user', $file['path'], $file['file'], $file['small'], $file['medium'], $file['large'],$file['access_level']);

                    exit;
                 
                } else {
                
                    http_response_code(403); // Forbidden
                    echo json_encode(['error' => 'Forbidden']);
                }
            }  else {
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