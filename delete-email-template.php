<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';
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
if ($data->id) {
    try {

        $user_roles = getUserRolesFromDatabase($user_email, $jwt);

    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
    //get file path from database and return the original file
    
        try {
            include('./db-connection.php'); // Include the database connection code
            $stmt = $conn->prepare("SELECT * FROM `email_templates` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
               
                $template = $result->fetch_assoc();

                $rolesArray = json_decode($user_roles, true);
                if(in_array(0, $rolesArray) || in_array(1, $rolesArray)){
                    //delete the users from the database
                    $stmt = $conn->prepare("DELETE FROM `email_templates` WHERE `ID`=?");
                    $stmt->bind_param("i", $data->id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $stmt->close();
                    $conn->close();

                    //delete the image from the folder
                    $image = $template['template_image'];
                    // Establish SSH connection and SFTP
                    $sshDetails = sshConnect();
                    $sftp = $sshDetails['sftp'];
                    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/email_templates/';
                    //delete the image from the folder
                    ssh2_sftp_unlink($sftp, $remote_directory.''.$image);
                
                    echo json_encode(['message' => 'success']);
                } else {
                    //return error
                    echo json_encode(['error' => 'User not authorized']);
                }
               
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