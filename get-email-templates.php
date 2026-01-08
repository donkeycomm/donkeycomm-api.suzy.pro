<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';
// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

try {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code

    // Prepare the SQL statement to insert the user into the database
    $stmt = $conn->prepare("SELECT * FROM `email_templates` WHERE `user` = ? ORDER BY `date` DESC");
    // Bind the parameters to the statement
    $stmt->bind_param("s", $user_email);

    // Execute the statement
    $result = $stmt->execute();

    //get all results and send through json_encode
    $result = $stmt->get_result();
    $templates = array();

    // Establish SSH connection
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];
    $remote_directory = $_ENV['FILE_HOSTING_ROOT'] . '/email_templates';

    while($row = $result->fetch_assoc()) {
        $remote_file_path = "$remote_directory/" . $row['template_image'];
        $sftp_file = @fopen("ssh2.sftp://$sftp$remote_file_path", 'r');

         if ($sftp_file === FALSE) {
            error_log("Failed to get file contents for: " . $row['template_image']);
            $row['base64'] = null;
        } else {
            $file_data = stream_get_contents($sftp_file);
            fclose($sftp_file);
            $base64 = base64_encode($file_data);
            $row['base64'] = $base64;
        }
        $templates[] = $row;
    }
   
    echo json_encode($templates);

} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
