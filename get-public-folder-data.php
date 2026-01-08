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

include('./db-connection.php'); // Include the database connection code

$data = file_get_contents('php://input');
$data = json_decode($data);


    $path = $data->path;
    // Retrieve the 'path' parameter from the POST data
    

    if ($path !== null) {
        try {
            $sql = "SELECT * FROM `folders` WHERE JSON_CONTAINS(`access_level`, '10') AND `path` = ? ORDER BY `date` DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $path);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $folder = $result->fetch_assoc();
                $filtered_folder = [
                    'folder_name' => $folder['name'],
                    'path' => $folder['path'],
                    'parent_path' => $folder['parent_path']
                ];
                echo json_encode($filtered_folder);
            } else {
                http_response_code(404); // User not found
                echo json_encode(['error' => 'Folder not found']);
            }
        } catch (Exception $e) {
            http_response_code(500); // Server error
            echo json_encode(['error' => 'Server error']);
            // Log the error if needed
        }
    } else {
        http_response_code(400); // Bad request
        echo json_encode(['error' => 'Invalid request']);
    }

?>
