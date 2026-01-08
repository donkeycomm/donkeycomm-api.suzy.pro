<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

//create a function to store the metadata in the database of the deleted file
if ($data->id) {
   
    
        try {
            include('./db-connection.php'); // Include the database connection code
            $stmt = $conn->prepare("SELECT * FROM `users` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();
            //check if user found
            if ($result->num_rows === 1) {
               
                $user = $result->fetch_assoc();

               //check if already activated
                if($user['active'] === 1){
                    echo json_encode(['error' => 'User already activated']);
                    exit();
                }

                //send an email to the user with a link to activate the account
                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer();
                               
                    //Recipients
                    $mail->setFrom('noreply@suzy.pro', 'Suzy');
                    $mail->addAddress($user['email']);     
                    $mail->addReplyTo('info@suzy.pro', '');
                
                    //Content
                    $mail->isHTML(true);                                  
                    $mail->Subject = 'Suzy: Activate your account';
                    $mail->Body    = "Hello " . $user['firstname'] . " " . $user['lastname'] . ",<br><br>";
                    $mail->Body   .= "Please click on the link below to activate your account:<br><br>";
                    $mail->Body   .= "<a href='".$_ENV['URL_FRONTEND']."/activate-account?code=" . $user['activation_code'] . "&email=" . $user['email'] . "'>Activate Account</a><br><br>";
                    $mail->Body   .= "Kind regards,<br><br>";
                    $mail->Body   .= "<img src='".$_ENV['URL_FRONTEND']."/Suzy-logo.png' style='height:50px; width:auto' alt='Suzy' />";
                    $mail->send();

                    echo json_encode(['message' => 'success']);

                } catch (Exception $e) {
                    echo 'Message could not be sent. Mailer Error: ', $mail->ErrorInfo;
                }
                //send mail with php mailer

               
            } else {
                //return error
                echo json_encode(['error' => 'User not found']);
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