<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include('./db-connection.php');

//query to select all contacts
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
function getListName($id){
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    try {
        $stmt = $conn->prepare("SELECT `name` FROM `contact_lists` WHERE `ID` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $list = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        return $list['name'];

    } catch (Exception $e) {
        // Return an error if something goes wrong
        return 'Error getting list name';
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
        
      //get all contacts and send back a excel csv file
        $stmt = $conn->prepare("SELECT * FROM `contacts`");
        $stmt->execute();
        $result = $stmt->get_result();
        $contacts = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $conn->close();
        // Remove the 'ID' field from each contact
        foreach ($contacts as &$contact) {
            $listNames = array();
            $contactListIds = json_decode($contact['contact_list'], true);
            foreach ($contactListIds as $id) {
                //make $id int
                $id = (int)$id;
                $listNames[] = getListName($id);
            }
            $contact['contact_list'] = implode(' / ', $listNames);

            unset($contact['ID']);
            unset($contact['image']);
        }

        // Output the contacts as a CSV file
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="contacts.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, array_keys($contacts[0]));
        foreach ($contacts as $contact) {
            fputcsv($output, $contact);
        }
        fclose($output);
            
    
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'Not authorized']);
}