<?php
ob_start(); // Start output buffering
// CORS header
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include('./db-connection.php'); // Include the database connection code
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
            error_log('Error checking user role: file_get_contents failed');
            return 'Error checking user role';
        }
       
        $user = json_decode($result, true);

        if ($user && isset($user['groups'])) {
            return $user['groups'];
        } else {
            error_log('User not found or groups not set');
            return 'User not found';
        }
    } catch (Exception $e) {
        http_response_code(500);
        error_log('Error checking user role: ' . $e->getMessage());
        return 'Error checking user role';
    }  
}

function getContactsByListId($id, $jwt) {
    //get user info from the api get-user-data.php
  
    try {
        $url = $_ENV['URL_BACKEND'].'/get-contacts-by-list-id.php';
        $data = array('id' => $id);
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
            error_log('Error getting contacts: file_get_contents failed');
            return 'Error getting contacts';
        }

        return $result;

    } catch (Exception $e) {
        http_response_code(500);
        error_log('Error getting contacts: ' . $e->getMessage());
        return 'Error getting contacts';
    }  
}

function getPressContactsByListId($id, $jwt) {
    //get user info from the api get-user-data.php
  
    try {
        $url = $_ENV['URL_BACKEND'].'/get-press-contacts-by-list-id.php';
        $data = array('id' => $id);
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
            error_log('Error getting press contacts: file_get_contents failed');
            return 'Error getting contacts';
        }
       
        return $result;

    } catch (Exception $e) {
        http_response_code(500);
        error_log('Error getting press contacts: ' . $e->getMessage());
        return 'Error getting contacts';
    }  
}

// Check if user role is 0 or 1
try {
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
    error_log('User roles: ' . print_r($user_roles, true));
} catch (Exception $e) {
    // Log the error message for debugging
    error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    ob_end_flush();
    exit();
}

$rolesArray = json_decode($user_roles, true);
error_log('Roles array: ' . print_r($rolesArray, true));

if (!in_array(0, $rolesArray) && !in_array(1, $rolesArray)) {
    error_log('Not authorized to send test mails');
    echo json_encode(['error' => 'Not authorized to send test mails']);
    exit();
}

// SEND EMAIL WITH BREVO API
$brevokey = $_ENV['BREVO_API_KEY'];
// Configure API key authorization: api-key
$config = Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', $brevokey);

$apiInstance = new Brevo\Client\Api\TransactionalEmailsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$date = new DateTime();
$dateString = $date->format('Y-m-d H:i:s');
$timestamp = $date->getTimestamp();
// Make it a string
$timestamp = strval($timestamp);

// Check if $data->contact_lists is an array and loop over it
if (is_array($data->contact_lists)) {
    foreach ($data->contact_lists as $list) {
        $contacts = getContactsByListId($list, $jwt);
        $contacts = json_decode($contacts);
        error_log('Contacts for list ' . $list . ': ' . print_r($contacts, true));
        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                //make sure $contact->subscribed is 1 otherwise skip
                if ($contact->subscribed !== 1) {
                    continue;
                }

                error_log('Sending email to: ' . $contact->email);
                $unsubscribeHtml = "<p style=\"font-size:11px;color:#000000;text-decoration:underline; text-align:center;\"> <a style=\"font-size:11px;color:#000000;text-decoration:underline; text-align:center;\" href=\"https://enterprise.suzy.pro/unsubscribe?contact_id=".$contact->ID."&key=".$contact->unsubscribe_key."&type=regular\">unsubscribe</a></p>";

                $sendSmtpEmail = new \Brevo\Client\Model\SendSmtpEmail([
                    'subject' => $data->subject,
                    'sender' => ['name' => 'Suzy.pro', 'email' => 'info@suzy.pro'],
                    'replyTo' => ['name' => 'Suzy.pro', 'email' => 'info@suzy.pro'],
                    'to' => [['email' => $contact->email]],
                    'htmlContent' => $data->message.$unsubscribeHtml,
                    'tags' => [$timestamp, 'contact_list'],
                ]); // \Brevo\Client\Model\SendSmtpEmail | Values to send a transactional email

                try {
                    $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
                    error_log("Email sent to: " . $contact->email . " Result: " . print_r($result, true));
                } catch (Exception $e) {
                    error_log('Exception when calling TransactionalEmailsApi->sendTransacEmail: ' . $e->getMessage());
                    echo 'Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
                }
            }
        }
    }
}

// Check if $data->press_lists is an array and loop over it
if (is_array($data->press_lists)) {
    foreach ($data->press_lists as $list) {
        $contacts = getPressContactsByListId($list, $jwt);
        $contacts = json_decode($contacts);
        error_log('Press contacts for list ' . $list . ': ' . print_r($contacts, true));
        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                  //make sure $contact->subscribed is 1 otherwise skip
                  if ($contact->subscribed !== 1) {
                    continue;
                }
                $unsubscribeHtml = "<p style=\"font-size:11px;text-decoration:underline; text-align:center;color:#000000;\"> <a style=\"font-size:11px;text-decoration:underline; text-align:center;color:#000000;\" href=\"https://enterprise.suzy.pro/unsubscribe?contact_id=".$contact->ID."&key=".$contact->unsubscribe_key."&type=press\">unsubscribe</a></p>";

                $sendSmtpEmail = new \Brevo\Client\Model\SendSmtpEmail([
                    'subject' => $data->subject,
                    'sender' => ['name' => 'Suzy.pro', 'email' => 'info@suzy.pro'],
                    'replyTo' => ['name' => 'Suzy.pro', 'email' => 'info@suzy.pro'],
                    'to' => [['email' => $contact->email]],
                    'htmlContent' => $data->message.$unsubscribeHtml,
                    'tags' => [$timestamp, 'press_list'],
                ]); // \Brevo\Client\Model\SendSmtpEmail | Values to send a transactional email

                try {
                    $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
                    error_log("Email sent to: " . $contact->email . " Result: " . print_r($result, true));
                } catch (Exception $e) {
                    error_log('Exception when calling TransactionalEmailsApi->sendTransacEmail: ' . $e->getMessage());
                    echo 'Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
                }
            }
        }
    }
}

// Prepare the SQL statement to insert the mailing into the database
$stmt = $conn->prepare("INSERT INTO mailings (subject, tag, date) VALUES (?, ?, ?)");

// Bind the parameters to the statement
$stmt->bind_param("sss", $data->subject, $timestamp, $dateString);

// Execute the statement
$result2 = $stmt->execute();
if ($result2) {
    error_log('Mailing record inserted successfully');
} else {
    error_log('Failed to insert mailing record: ' . $stmt->error);
}

echo json_encode(['message' => 'success']);