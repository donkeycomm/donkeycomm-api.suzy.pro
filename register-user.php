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

try {

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
       
    $groups = $data->groups;

    if($groups){
        $groups_array_data = array_map('intval', $groups);
    } else {
        $groups_array_data = [];
    }
    if (!in_array(10, $groups_array_data)) {
        $groups_array_data[] = 10;
    }
    //sort the array
    sort($groups_array_data);

    if(!in_array(0, $rolesArray) && in_array(0, $groups_array_data)){
        echo json_encode(['error' => 'Not authorized to create admins']);
        exit();
    }

    if(in_array(0, $rolesArray) || in_array(1, $rolesArray)){
            // Connect to the database
            include('./db-connection.php'); // Include the database connection code

            // Prepare the SQL statement to check if the email already exists
            $stmt = $conn->prepare("SELECT * FROM `users` WHERE `email`=?");
            $stmt->bind_param("s", $data->email);

            // Execute the statement
            $stmt->execute();

            // Get the result
            $result1 = $stmt->get_result();

            if ($result1->num_rows > 0) {
                // Email already exists
                echo json_encode(['error' => 'Email already exists']);
                exit();
            }

        
            
            // Convert the array to a JSON string
            $groups_json_data = json_encode($groups_array_data);
  

            $activation_code = uniqid();
            // Prepare the SQL statement to insert the user into the database
            $stmt = $conn->prepare("INSERT INTO `users` (`firstname`, `lastname`, `email`, `phone`, `groups`, `activation_code`) VALUES (?, ?, ?, ?, ?, ?)");

            // Bind the parameters to the statement
            $stmt->bind_param("ssssss", $data->firstname, $data->lastname, $data->email, $data->phone, $groups_json_data, $activation_code);

            // Execute the statement
            $result2 = $stmt->execute();

            if ($result2) {
                // User successfully inserted into the database
                echo json_encode(['message' => 'User registered successfully']);
            } else {
                // An error occurred
                echo json_encode(['error' => 'Error: ' . $stmt->error]);
            }
        } else {
            echo json_encode(['error' => 'Not authorized to create users']);
        }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
