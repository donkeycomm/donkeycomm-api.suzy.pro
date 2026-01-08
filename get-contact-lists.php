<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Connect to the database
include('./db-connection.php'); // Include the database connection code

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
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
    
} catch (Exception $e) {
    // Log the error message for debugging
    error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

    $rolesArray = json_decode($user_roles, true);

    if(in_array(0, $rolesArray) || in_array(1, $rolesArray)){
    try {
        
        //get files that match the access_level and the path
        $sql = "SELECT * FROM `contact_lists` ORDER BY `ID` ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $contacts = array();
        while($row = $result->fetch_assoc()) {
            $contacts[] = $row;
        }
        echo json_encode($contacts);
            
    
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'Not authorized']);
}


?>
