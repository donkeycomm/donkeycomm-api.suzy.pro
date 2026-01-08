<?php
include('./jwt-validation.php'); // Include the JWT validation code
include('./db-connection.php'); // Include the database connection code

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the 'email' parameter from the POST data
    $user_email = isset($_POST['email']) ? $_POST['email'] : null;

    if ($user_email !== null) {
        try {
            $stmt = $conn->prepare("SELECT * FROM `users` WHERE `email`=?");
            $stmt->bind_param("s", $user_email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                echo json_encode(array('groups'=> $user['groups']));
            } else {
                http_response_code(404); // User not found
                echo json_encode(['error' => 'User not found']);
            }
        } catch (Exception $e) {
          
            http_response_code(500); // Server error
            error_log("Error in get-user-data.php: " . $e->getMessage()); // Log the exception message
            echo json_encode(['error' => 'Server error']);
           
        }
    } else {
        http_response_code(400); // Bad request
        echo json_encode(['error' => 'Invalid request']);
    }
} else {
    http_response_code(400); // Bad request
    echo json_encode(['error' => 'Invalid request']);
}
?>
