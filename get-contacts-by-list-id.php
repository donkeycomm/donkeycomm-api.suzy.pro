<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
// Connect to the database
include('./db-connection.php'); // Include the database connection code

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the 'id' parameter from the POST data
    $id = isset($_POST['id']) ? $_POST['id'] : null;
    error_log('Received ID: ' . $id);
    
    if ($id === null) {
        echo json_encode(['error' => 'ID parameter is missing']);
        ob_end_flush();
        exit();
    }
    
    try {
        // Prepare the SQL statement
        $stmt = $conn->prepare("SELECT * FROM `contacts` WHERE JSON_CONTAINS(`contact_list`, ?, '$')");
        $json_id = json_encode((int)$id);
        error_log('JSON encoded ID: ' . $json_id);
        $stmt->bind_param('s', $json_id);
      
        // Execute the statement
        $stmt->execute();
        $result = $stmt->get_result();
        $contacts = array();
        error_log('SQL Query: ' . $stmt->error);
        // Fetch the results
        while ($row = $result->fetch_assoc()) {
            $contacts[] = $row;
        }
        
        // Log the results
        error_log('Contacts: ' . print_r($contacts, true));
        
        // Return the results as JSON
        echo json_encode($contacts);
        
    } catch (Exception $e) {
        // Return an error if something goes wrong
        error_log('Error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }

} else {
    echo json_encode(['error' => 'Invalid request method']);
}
ob_end_flush();
?>