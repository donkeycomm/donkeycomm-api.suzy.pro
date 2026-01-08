<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Connect to the database
include('./db-connection.php'); // Include the database connection code

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

if($data->email) {
    $user_role;
   
    try {
        $user_roles = getUserRolesFromDatabase($data->email, $jwt);
     
    } catch (Exception $e) {
        // Log the error message for debugging
        error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    try {
         // check if the user role is 0
         $rolesArray = json_decode($user_roles, true);
   
         if(in_array(0, $rolesArray)){
                $stmt = $conn->prepare("SELECT COUNT(*) as `total` FROM `files_in_trash`");
                $stmt->execute();
                $result = $stmt->get_result();
                $total = $result->fetch_assoc();
                echo json_encode($total);
          } else {
                echo json_encode(['error' => 'User not authorized']);
          }
        
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }
} else {
    // Return an error if the request is not POST
    echo json_encode(['error' => 'Invalid request']);
}

?>
