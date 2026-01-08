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
require_once('./vendor/autoload.php');
// Connect to the database


// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

if($data->email){

    include('./db-connection.php'); // Include the database connection code
    $stmt = $conn->prepare("SELECT * FROM `users` WHERE `email`=?");
    $stmt->bind_param("s", $data->email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        //Generate a unique token
        $token = bin2hex(random_bytes(20)); 

        // Store the token in the database
        $stmt = $conn->prepare("INSERT INTO `password_reset` (`token`, `expires`, `email`) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)");
        $stmt->bind_param("ss", $token, $data->email);
        $stmt->execute();

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer();
                    
            //Recipients
            $mail->setFrom('noreply@enterprise.suzy.pro', 'Suzy');
            $mail->addAddress($data->email);     
            $mail->addReplyTo('info@suzy.pro', '');

            //Content
            $mail->isHTML(true);                                  
            $mail->Subject = 'Suzy: Reset your password';
            $mail->Body    = "Hi,<br><br>";
            $mail->Body   .= "Please click on the link below to reset your password:<br><br>";
            $mail->Body   .= "<a href='".$_ENV['URL_FRONTEND']."/reset-password?token=" . $token. "&email=".$data->email."'>Reset password</a><br><br>";
            $mail->Body   .= "Kind regards,<br><br>";
            $mail->Body   .= "<img src='".$_ENV['URL_FRONTEND']."/Suzy-logo.png' style='height:50px; width:auto' alt='Suzy' />";
            $mail->send();

            echo json_encode(['message' => 'success']);

        } catch (Exception $e) {
            echo json_encode(['error' => 'Message could not be sent. Mailer Error: ', $mail->ErrorInfo]);
        }
    } else {
        //return error
        echo json_encode(['error' => 'User not found']);
    }
 
} else {
    echo json_encode(['error' => 'No email found']);
}


?>