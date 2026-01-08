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

$data = file_get_contents('php://input');
$data = json_decode($data);

    try {
     
        // SQL query to get the total count of files where access_level includes 10 and matches the path
        $sql = "SELECT COUNT(*) as `total` FROM `files` WHERE JSON_CONTAINS(`access_level`, '10') AND `path` = ? ORDER BY `date` DESC";
        

        $stmt = $conn->prepare($sql);
      
        $stmt->bind_param("s", $path);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = $result->fetch_assoc();
        echo json_encode($total);
       
               
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }


?>
