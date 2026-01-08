<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./check-origin.php'); // Include the JWT validation code

// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
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

$user_roles = array(10);
$path = $data->path;
    if(isset($data->email)) {
    
        try {
            include './jwt-validation.php';
            $user_roles = getUserRolesFromDatabase($data->email, $jwt);
        
        } catch (Exception $e) {
            // Log the error message for debugging
            error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    try {
     
        if (is_string($user_roles)) {
            $rolesArray = json_decode($user_roles, true);
        } else {
            $rolesArray = $user_roles;
        }
        //loop through access levels and match files with access levels
       
        $sql = "SELECT COUNT(*) AS `file_count`, SUM(`size`) AS `total_size` FROM `files` WHERE (";
        
        foreach ($rolesArray as $role) {
            $sql .= "JSON_CONTAINS(`access_level`, '$role') OR ";
        }
        $sql = rtrim($sql, " OR "); // Remove the last " OR "
       
        $sql .= ") AND (`path` = ? OR `path` LIKE CONCAT(?, '/%')) ORDER BY `date` DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $path, $path); // Bind both parameters
        $stmt->execute();
        $result = $stmt->get_result();
        $file_count = 0;
        $total_size = 0;
        
        if ($row = $result->fetch_assoc()) {
            $file_count = $row['file_count'];
            $total_size = $row['total_size'];
        }
        
        // Output the file count and total size
        echo json_encode([
            'file_count' => $file_count,
            'total_size' => $total_size
        ]);
      
               
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }

?>