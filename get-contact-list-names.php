<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Connect to the database
include('./db-connection.php'); // Include the database connection code

$data = file_get_contents('php://input');
$data = json_decode($data);

    try {
        // Check if listIds is set and is an array
        if (isset($data->ids) && is_array($data->ids)) {

            $listIds = $data->ids;
            $idListString = implode(',', $listIds);

            //get files that match the access_level and the path
            $sql = "SELECT * FROM `contact_lists` WHERE `ID` IN ($idListString) ORDER BY `ID` ASC";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            $lists = array();
            while($row = $result->fetch_assoc()) {
                $lists[] = $row;
            }
            echo json_encode($lists);
        } else {
                // Handle the case where listIds is not set or not an array
                echo json_encode(['error' => 'Invalid or missing listIds']);
        }   
    
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }



?>
