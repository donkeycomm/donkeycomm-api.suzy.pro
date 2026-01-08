<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Include the Composer autoloader
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
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

date_default_timezone_set('Europe/Brussels');


// Include the JWT library
use Firebase\JWT\JWT;
// Set the secret key for the JWT
$secret_key = $_ENV['SECRET_KEY'];

$data = file_get_contents('php://input');
$data = json_decode($data);

// Connect to the database
include('./db-connection.php'); // Include the database connection code

if (is_object($data) && isset($data->email)) {
    // Lookup the username in the database
    $stmt = $conn->prepare("SELECT * FROM `users` WHERE `email` = ?");
    $stmt->bind_param("s", $data->email);

  // Execute the statement
  $stmt->execute();

  // Get the result
  $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        //check if user is active
        if($row['active'] == 0){
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode(["error"=>"User is not active"]);
            exit();
        }

        // Verify the password
        if (password_verify($data->password, $row['password'])) {
            // Create the payload for the JWT
            $payload = array(
                "email" => $data->email,
               "exp" => time() + (7 * 24 * 60 * 60) // JWT will expire in 1 week
            );

            // Add the payload to the JWT
            $userInfo = array(
                "email" => $row['email'],
                "firstname" => $row['firstname'],
                "lastname" =>$row['lastname'],
                "phone" => $row['phone'],
                "groups" => $row['groups']
            );
            // Generate the JWT
            try {
                $jwt = JWT::encode($payload, $secret_key, 'HS256');

               //set the httponly cookie
               setcookie('jwtToken', $jwt, [
                    'expires' => time() + (7 * 24 * 60 * 60), // 1 week
                    'path' => '/',
                    'domain' => 'donkeycomm.suzy.pro', // Your domain here
                    'secure' => true, // This requires HTTPS
                    'httponly' => true,
                    'samesite' => 'None', // Set SameSite attribute to None for cross-origin requests
                ]);
                // Return the JWT in JSON format
                header('Content-Type: application/json');
               echo json_encode(["jwt"=>$jwt, "user" => $userInfo]);

            } catch (Exception $e) {
                // Error during JWT encoding
                http_response_code(500); // Internal Server Error status code
                echo json_encode(["error" => "JWT generation error: " . $e->getMessage()]);
            }
        } else {

            // Return an error message if the password is incorrect
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode(["error"=>"Wrong password"]);
        }
    } else {
        // Return an error message if the username is not found
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode(["error"=>"User doesn't exist"]);
    }
} else {
    echo json_encode('no data');
}
$conn->close();
