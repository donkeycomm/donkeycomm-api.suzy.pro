<?php
// CORS header
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

function getUserRolesFromDatabase($user_email, $jwt) {
    //get user info from the api get-user-data.php
  
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $user_email);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Bearer " . $jwt . "\r\n", // Add this line
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if ($result === false) {
            // Handle the case where file_get_contents fails
            http_response_code(500);
            return 'Error checking user role';
        }
       
        $user = json_decode($result, true);

        if ($user && isset($user['groups'])) {
            return $user['groups'];
        } else {
            return 'User not found';
        }
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error checking user role';
    }  
}


    //check if user role is 0 or 1
    try {
        $user_roles = getUserRolesFromDatabase($user_email, $jwt);
     
    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    $rolesArray = json_decode($user_roles, true);
       

    if(!in_array(0, $rolesArray) && !in_array(1, $rolesArray)){
        echo json_encode(['error' => 'Not authorized to send test mails']);
        exit();
    }

    //SEND EMAIL WITH BREVO API
    $brevokey = $_ENV['BREVO_API_KEY'];
    // Configure API key authorization: api-key
    $config = Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', $brevokey);
    
    $apiInstance = new Brevo\Client\Api\TransactionalEmailsApi(
        // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
        // This is optional, `GuzzleHttp\Client` will be used as default.
        new GuzzleHttp\Client(),
        $config
    );



    $sendSmtpEmail = new \Brevo\Client\Model\SendSmtpEmail([
        'subject' => $data->subject,
        'sender' => ['name' => 'Suzy.pro', 'email' => 'info@suzy.pro'],
        'replyTo' => ['name' => 'Suzy.pro', 'email' => 'info@suzy.pro'],
        'to' => [[ 'email' => $data->email]],
        'htmlContent' => $data->message,
        
    ]); // \Brevo\Client\Model\SendSmtpEmail | Values to send a transactional email

    try {
        $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
        //print_r($result);
        echo json_encode(['message' => 'success']);
    } catch (Exception $e) {
        echo 'Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
    }
  
          
