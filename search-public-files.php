<?php
require_once('./vendor/autoload.php');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

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
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';

$data = file_get_contents('php://input');
$data = json_decode($data);

$path = $data->path;
$text = $data->text;

if ($path !== null && $text !== null) {
   

    try {
        
        //check if a number in $rolesArray matches a number in json database field array of numbers called access_level
     
        // Define the JSON search condition to check for '10' in access_level
        $jsonSearchCondition = "JSON_CONTAINS(access_level, '10')";

        // Prepare the SQL statement
        $stmt = $conn->prepare("SELECT * FROM `files` WHERE `path` LIKE CONCAT('%', ?, '%') AND `file` LIKE CONCAT('%', ?, '%') AND $jsonSearchCondition");
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
