<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
$data = file_get_contents('php://input');
$data = json_decode($data);


if($data->id) {
   
    
    try {
        $stmt = $conn->prepare("SELECT * FROM `files` WHERE JSON_CONTAINS(`access_level`, '10') AND `ID`=?");
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
            $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $file['path'] . $pathSeparator . $file['file'];
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
            http_response_code(404); // User not found
            echo json_encode(['error' => 'File not found']);
        }  
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }
} else {
    // Return an error if the request is not POST
    echo json_encode(['error' => 'Invalid request']);
}

?>