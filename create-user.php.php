<?php
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
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept"); // Include 'Authorization' in the allowed headers

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
    $stmt = $conn->prepare("SELECT * FROM `users` WHERE `email`=?");
    $stmt->bind_param("s", $data->email);

    // Execute the statement
    $stmt->execute();

    // Get the result
    $result1 = $stmt->get_result();

    if ($result1->num_rows > 0) {
        // Email already exists
        echo json_encode(['error' => 'Email already exists']);
        exit();
    }

    // Hash the password
    $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);

    // Prepare the SQL statement to insert the user into the database
    $stmt = $conn->prepare("INSERT INTO `users` (`firstname`, `lastname`, `email`, `phone`, `access_level`, `password`, `contact`) VALUES (?, ?, ?, ?, ?, ?, ?)");

    // Bind the parameters to the statement
    $stmt->bind_param("ssssisi", $data->firstname, $data->lastname, $data->email, $data->phone, $access_level, $hashed_password, $contact);

    // Execute the statement
    $result2 = $stmt->execute();

    if ($result2) {
        // User successfully inserted into the database
        echo json_encode(['message' => 'User registered successfully']);
    } else {
        // An error occurred
        echo json_encode(['error' => 'Error: ' . $stmt->error]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
