<?php
include('./jwt-validation.php'); // Include the JWT validation code
include('./db-connection.php'); // Include the database connection code

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the 'path' parameter from the POST data
    $id = isset($_POST['id']) ? $_POST['id'] : null;

    if ($id !== null) {
        try {
            $stmt = $conn->prepare("SELECT * FROM `folders` WHERE `ID`=?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $folder = $result->fetch_assoc();
                echo json_encode($folder);
            } else {
                http_response_code(404); // User not found
                echo json_encode(['error' => 'Folder not found']);
            }
        } catch (Exception $e) {
            http_response_code(500); // Server error
            echo json_encode(['error' => 'Server error']);
            // Log the error if needed
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
