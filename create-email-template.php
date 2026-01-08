<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);
error_log('Received data: ' . print_r($data, true));

try {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code

    $date = date('Y-m-d H:i:s');
    $html = (string)$data->html;
    $css = (string)$data->css;
    $image = (string)$data->image;

    error_log('Current date: ' . $date);

    // Prepare the SQL statement to insert the user into the database
    $stmt = $conn->prepare("INSERT INTO `email_templates` (`user`, `template_html`, `template_css`, `template_image`, `date`) VALUES (?,?,?,?,?)");
    if ($stmt === false) {
        error_log('Prepare failed: ' . $conn->error);
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    // Bind the parameters to the statement
    $stmt->bind_param("sssss", $user_email, $html, $css, $image, $date);
   
    // Execute the statement
    $result = $stmt->execute();
    if ($result) {
        // User successfully inserted into the database
        error_log('Template created successfully');
        echo json_encode(['message' => 'Template created successfully']);
    } else {
        // An error occurred
        error_log('Execute failed: ' . $stmt->error);
        echo json_encode(['error' => 'Error: ' . $stmt->error]);
    }
} catch (Exception $e) {
    error_log('Exception: ' . $e->getMessage());
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}