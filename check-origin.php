<?php
// Include the Composer autoloader
require_once('./vendor/autoload.php');

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// CORS header
$request_headers = apache_request_headers();
$http_origin = '';

if (isset($request_headers['Origin'])) {
    $http_origin = $request_headers['Origin'];
}

$allowed_http_origins = array(
    $_ENV['URL_FRONTEND'], 
    $_ENV['URL_BACKEND'],
    $_ENV['URL_FILE_HOSTING']
);

$trusted_dev_ips = explode(',', $_ENV['TRUSTED_DEV_IPS']);       
if (in_array($_SERVER['REMOTE_ADDR'], $trusted_dev_ips)) {
    $allowed_http_origins[] = "http://localhost:3000";
}

if (in_array($http_origin, $allowed_http_origins)) {  
    header("Access-Control-Allow-Origin: " . $http_origin);
} else {
    // Respond with 403 Forbidden if the origin is not allowed
    http_response_code(403);
    echo json_encode(['error' => 'Origin not allowed']);
    exit;
}

header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // The request is a preflight request. Respond successfully:
    http_response_code(200);
    exit;
}

// Continue with the rest of your code...