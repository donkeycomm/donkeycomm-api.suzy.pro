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

    try {
          //loop through user roles and add them to the query to see if it matches the folder roles
          $sql = "SELECT * FROM `folders` WHERE JSON_CONTAINS(`access_level`, '10') AND `parent_path` = ? ORDER BY `rank` DESC";

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
