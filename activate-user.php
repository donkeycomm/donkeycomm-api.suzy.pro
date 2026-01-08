<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('./vendor/autoload.php');
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
//CORS header
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
header("Access-Control-Allow-Headers: Origin, X-Requested-wWith, Content-Type, Accept, Authorization");


// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);


if ($data->code && $data->email && $data->password) {
   
    //get user from db
    
        try {
            include('./db-connection.php'); // Include the database connection code
            $stmt = $conn->prepare("SELECT * FROM `users` WHERE `activation_code`=? AND `email`=?");
            $stmt->bind_param("ss", $data->code, $data->email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
               
                $user = $result->fetch_assoc();
                if($user['active'] === 1){
                    echo json_encode(['error' => 'User already activated']);
                    exit();
                }
               //update the user in the database
                try {
                    $password = $data->password;
                    $password = password_hash($password, PASSWORD_DEFAULT);
                    //update the active field and password field for the user in the database
                    $stmt = $conn->prepare("UPDATE `users` SET `password`=?, `activation_code`=NULL, `active`=1 WHERE `ID`=?");
                    $stmt->bind_param("si", $password, $user['ID']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $stmt->close();
                    $conn->close();

                    echo json_encode(['message' => 'success']);
                  
                } catch (Exception $e) {
                    echo json_encode(['error' => 'Error updating user']);
                }
              
                       
               
                } else {
                    //return error
                    echo json_encode(['error' => 'Activation link is invalid']);
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