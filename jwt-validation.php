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
// error_log("Trusted Dev IPs: " . ($_ENV['TRUSTED_DEV_IPS'] ?? 'Not Set'));
// error_log("SERVER['REMOTE_ADDR']: " . $_SERVER['REMOTE_ADDR']);

if (in_array($http_origin, $allowed_http_origins)) {  
    header("Access-Control-Allow-Origin: " . $http_origin);
}

header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // The request is a preflight request. Respond successfully:
    http_response_code(200);
    exit;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Get JWT from Authorization header
$authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : null;

$jwt = null;
$user_email = null;
if ($authHeader) {
    $matches = array();
    preg_match('/Bearer (.*)/', $authHeader, $matches);
    if (isset($matches[1])) {
        $jwt = $matches[1];
    }
}

// If no JWT token was found, return an error
if (!$jwt) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized, no token found']));
}

// Decode the JWT token
$secretKey = $_ENV['SECRET_KEY'];
try {
    $decodedToken = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    // If the token was decoded, get the user email
    $user_email = $decodedToken->email;
} catch (Exception $e) {
    http_response_code(401);
    exit(json_encode(['error' => 'Error decoding token: ' . $e->getMessage()]));
}

// Continue with the rest of your code...