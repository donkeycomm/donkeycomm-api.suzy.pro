<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
// Connect to the database
include('./db-connection.php'); // Include the database connection code

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the 'email' parameter from the POST data
    $id = isset($_POST['id']) ? $_POST['id'] : null;

    try {
        
        //get files that match the access_level and the path
       //select from contacts where $id is in field contact_lists which is a JSON field
       $sql = "SELECT * FROM `press_contacts` WHERE `press_list`=?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
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
    echo json_encode(['error' => 'No contact found']);
}
ob_end_flush();
?>
