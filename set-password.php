<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once('./vendor/autoload.php');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$request_headers        = apache_request_headers();
$http_origin            = $request_headers['Origin'];

$allowed_http_origins   = array(
    $_ENV['URL_FRONTEND'], 
   $_ENV['URL_BACKEND']
);

$trusted_dev_ips = explode(',', $_ENV['TRUSTED_DEV_IPS']);       
if (in_array($_SERVER['REMOTE_ADDR'], $trusted_dev_ips)) {
    $allowed_http_origins[] = "http://localhost:3000";
}

if (in_array($http_origin, $allowed_http_origins)){  
    @header("Access-Control-Allow-Origin: " . $http_origin);
}
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
// Include the Composer autoloader
date_default_timezone_set('Europe/Brussels');
// Include the Composer autoloader
require_once('./vendor/autoload.php');

// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

try {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code

    // Prepare the SQL statement to check if the email already exists
    $stmt = $conn->prepare("SELECT * FROM `password_reset` WHERE `email`=? AND `token`=? AND `activated`=0 AND `expires` > NOW()");
    $stmt->bind_param("ss", $data->email, $data->token);

    // Execute the statement
    $stmt->execute();

    // Get the result
    $result1 = $stmt->get_result();


    if ($result1->num_rows === 0) {
        // Email already exists
        echo json_encode(['error' => 'Invalid password reset link, please request a new one']);
        exit();
    }

    // Hash the password
    $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);

    //Update the password for the user in the database
    $stmt = $conn->prepare("UPDATE `users` SET `password`=? WHERE `email`=?");
    $stmt->bind_param("ss", $hashed_password, $data->email);
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;

   
    if ($affected_rows === 1) {
        // User successfully inserted into the database
        echo json_encode(['message' => 'success']);
        //remove the token from the database
        $stmt = $conn->prepare("UPDATE `password_reset` SET `activated`=1 WHERE `token`=?");
        $stmt->bind_param("s", $data->token);
        $stmt->execute();
    } else {
        // An error occurred
        echo json_encode(['error' => 'Error: ' . $stmt->error]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
