<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

try {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code

    // Prepare the SQL statement to check if the email already exists
    $stmt = $conn->prepare("SELECT * FROM `contact_lists` WHERE `name`=?");
    $stmt->bind_param("s", $data->name);

    // Execute the statement
    $stmt->execute();

    // Get the result
    $result1 = $stmt->get_result();

    if ($result1->num_rows > 0) {
        // Email already exists
        echo json_encode(['error' => 'Contact list already exists']);
        exit();
    }
   
    $date = date('Y-m-d H:i:s');
    // Prepare the SQL statement to insert the user into the database
    $stmt = $conn->prepare("INSERT INTO `contact_lists` (`name`, `date`) VALUES (?,?)");

    // Bind the parameters to the statement
    $stmt->bind_param("ss", $data->name, $date);

    // Execute the statement
    $result2 = $stmt->execute();

    if ($result2) {
        // User successfully inserted into the database
        echo json_encode(['message' => 'Contact list created successfully']);
    } else {
        // An error occurred
        echo json_encode(['error' => 'Error: ' . $stmt->error]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
