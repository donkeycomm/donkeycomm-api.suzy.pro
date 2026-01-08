<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code

// Connect to the database
include('./db-connection.php'); // Include the database connection code
include './ssh_connect.php';
$data = file_get_contents('php://input');
$data = json_decode($data);

if($data->id){

    try {
        
        //get files that match the access_level and the path
        $sql = "SELECT * FROM `contacts` WHERE `ID`=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data->id);
        $stmt->execute();
        $result = $stmt->get_result();
        $contacts = array();

          // Establish SSH connection
          $ssh = sshConnect();
          $sftp = $ssh['sftp'];
          
          
        while($row = $result->fetch_assoc()) {
            $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/contact_images' . '/' .$row['image'];
            // Fetch file contents from the remote server
            $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");
            if ($file_data === FALSE) {
                error_log("Failed to get file contents for: " . $row['image']);
                $row['base64'] = null;
            } else {
                $base64 = base64_encode($file_data);
                $row['base64'] = $base64;
            }
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

?>
