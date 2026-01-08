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
function storeDeletedUserInDatabase($user_id, $firstname, $lastname, $email, $phone, $groups, $deleted_by) {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    //get user info from the api get-user-data.php
    try {
        $stmt = $conn->prepare("INSERT INTO `deleted_users` (`user_id`, `firstname`, `lastname`, `email`, `phone`, `groups`, `deleted_by`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("isssssss", $user_id, $firstname, $lastname, $email, $phone, $groups, $deleted_by, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();

        echo json_encode(['message' => 'success']);
      
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Error in storeDeletedUserInDatabase: " . $e->getMessage());
        return 'Error storing metadata in database';
    }  
}

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
            $stmt = $conn->prepare("SELECT * FROM `users` WHERE `ID`=?");
            $stmt->bind_param("i", $data->id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
               
                $user = $result->fetch_assoc();
                   
                    $rolesArray = json_decode($user_roles, true);
                    $userToDeleteRoles = json_decode($user['groups'], true);
                    
                    if((in_array(0, $rolesArray) || in_array(1, $rolesArray)) && !in_array(0, $userToDeleteRoles)){
                
                        $stmt = $conn->prepare("DELETE FROM `users` WHERE `ID`=?");
                        $stmt->bind_param("i", $data->id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $stmt->close();
                        $conn->close();
                        return storeDeletedUserInDatabase($user['ID'], $user['firstname'], $user['lastname'], $user['email'], $user['phone'],$user['groups'], $user_email);

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