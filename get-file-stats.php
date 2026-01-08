<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Use the necessary namespaces
use Brevo\Client\Configuration;
use Brevo\Client\Api\TransactionalEmailsApi;
use GuzzleHttp\Client;

$data = file_get_contents('php://input');
$data = json_decode($data);

include ('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';

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

try {
    // Assuming $user_email and $jwt are set before calling this
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
    $rolesArray = json_decode($user_roles, true);

    if (in_array(0, $rolesArray) || in_array(1, $rolesArray)) {
      //get files from file_logs between two dates and sort by file_id occurances
 // Establish SSH connection
        $ssh = sshConnect();
        $sftp = $ssh['sftp'];

        $date_from = $data->date_from;
        $date_to = $data->date_to;
        // Convert dates to include times
        $date_from .= " 00:00:00"; // Start of the day
        $date_to .= " 23:59:59";   // End of the day

       
        // Prepare the SQL statement
        $stmt = $conn->prepare("
        SELECT fl.file_id, fl.file, fl.path, fl.small, fl.access_level, COUNT(fl.file_id) AS count
        FROM file_logs fl
        JOIN files f ON fl.file_id = f.ID
        WHERE fl.date BETWEEN ? AND ?
        GROUP BY fl.file_id, fl.file
        ORDER BY count DESC
        LIMIT 50
        ");

        $stmt->bind_param("ss", $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result();
        $files = array();

        while($row = $result->fetch_assoc()) {
            $pathSeparator = "/";
            if($row['path'] == "/") {
                $pathSeparator = "";
            }
            $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['small'];

            // Fetch file contents from the remote server
            $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");

            if ($file_data === FALSE) {
                error_log("Failed to get file contents for: $remote_file_path");
                $row['base64'] = null;
            } else {
                $base64 = base64_encode($file_data);
                $row['base64'] = $base64;
            }
            $files[] = $row;
        }
        echo json_encode($files);
        $stmt->close();

    } else {
        // If user is not authorized
        echo json_encode(['error' => 'Not authorized']);
        http_response_code(403);
    }
} catch (Exception $e) {
    // Handle any general exceptions
    error_log("General error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
    http_response_code(500);
}


?>
