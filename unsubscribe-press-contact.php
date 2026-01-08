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
    $stmt = $conn->prepare("SELECT * FROM `press_contacts` WHERE `ID`=? ");
    $stmt->bind_param("i", $data->contact_id);

    // Execute the statement
    $stmt->execute();

    // Get the result
    $contacts = $stmt->get_result();


    if ($contacts->num_rows === 0) {
        // Email already exists
        echo json_encode(['error' => 'Contact not found']);
        exit();
    } else {
        //check if $data->key matches the contact's unsubscribe_key
        $contact = $contacts->fetch_assoc();
        $date = date('Y-m-d H:i:s');
        if ($contact['unsubscribe_key'] === (int)$data->key) {
            //update the contact's contact_list to 'Unsubscribed'
          
            $stmt = $conn->prepare("UPDATE `press_contacts` SET `subscribed`=0, `unsubscribe_date`=? WHERE `ID`=?");
            $stmt->bind_param('si', $date, $data->contact_id); // 'si' means string and integer
            $stmt->execute();
            echo json_encode(['success' => 'You are successfully unsubscribed.']);
        } else {
            echo json_encode(['error' => 'Invalid key']);
        }
    }

} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
