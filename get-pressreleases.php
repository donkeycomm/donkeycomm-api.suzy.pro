<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//include('./jwt-validation.php'); // Include the JWT validation code
include('./check-origin.php'); // Include the CORS headers
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

// if($user_email) {
//     $user_roles;
//     try {
//         $user_roles = getUserRolesFromDatabase($user_email, $jwt);
     
//     } catch (Exception $e) {
//         // Log the error message for debugging
//         error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
//         http_response_code(500);
//         echo json_encode(['error' => $e->getMessage()]);
//     }
     try {
     
            // Set a default limit if not provided
            $limit = isset($data->limit) ? (int)$data->limit : 10; // Default limit to 10 if not provided


            $sql = "SELECT * FROM `pressreleases` 
            ORDER BY 
            CASE 
                WHEN `date` LIKE '%/%/%' THEN STR_TO_DATE(`date`, '%d/%m/%Y')
                WHEN `date` LIKE '%-%-%' THEN STR_TO_DATE(`date`, '%d-%m-%Y')
            END DESC 
            LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $limit); // Bind the limit parameter as an integer

            $stmt->execute();
            $result = $stmt->get_result();
            $pressreleases = array();

              // Establish SSH connection
            $ssh = sshConnect();
            $sftp = $ssh['sftp'];
            
            while($row = $result->fetch_assoc()) {
                $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/pressrelease_images' . '/' .$row['image'];

                // Fetch file contents from the remote server
                $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");

               
                if ($file_data === FALSE) {
                    error_log("Failed to get folder: /pressrelease_images/".$row['image']);
                    $row['base64'] = null;
                } else {
                    $base64 = base64_encode($file_data);
                    $row['base64'] = $base64;
                }
                $pressreleases[] = $row;
            }
            echo json_encode($pressreleases);
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }
// } else {
//     // Return an error if the request is not POST
//     echo json_encode(['error' => 'Invalid request']);
// }

?>
