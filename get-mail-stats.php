<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Use the necessary namespaces
use Brevo\Client\Configuration;
use Brevo\Client\Api\TransactionalEmailsApi;
use GuzzleHttp\Client;

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

try {
    // Assuming $user_email and $jwt are set before calling this
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
    $rolesArray = json_decode($user_roles, true);
    if (in_array(0, $rolesArray) || in_array(1, $rolesArray)) {
        // Set up Brevo (formerly Sendinblue) API configuration
        $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $_ENV['BREVO_API_KEY']);
        $apiInstance = new TransactionalEmailsApi(new Client(), $config);

        // Fetch aggregated SMTP report
        try {
            $result = $apiInstance->getAggregatedSmtpReport(null, null, null, $data->tag);
            echo $result;
        } catch (Exception $e) {
            // Handle API-specific errors
            error_log("Exception in getAggregatedSmtpReport: " . $e->getMessage());
            echo json_encode(['error' => 'Error fetching SMTP report: ' . $e->getMessage()]);
            http_response_code(500);
        }
    } else {
        // If user is not authorized
        echo json_encode(['error' => 'Not authorized']);
        http_response_code(403);
    }
} catch (Exception $e) {
    // Handle any general exceptions
    error_log("General error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
    http_response_code(500);
}


?>
