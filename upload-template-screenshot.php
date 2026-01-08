<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';

if (isset($_FILES['file'])) {
    $targetDirectory = "email_templates/"; // Make sure this directory exists and is writable
    $targetFile = $targetDirectory . basename($_FILES['file']['name']);

    // Establish SSH connection and SFTP
    $sshDetails = sshConnect();
    $sftp = $sshDetails['sftp'];

    // Open a file handle for writing on the remote server
    $remoteFile = "ssh2.sftp://$sftp" . $_ENV['FILE_HOSTING_ROOT'] . '/' . $targetFile;
    $stream = fopen($remoteFile, 'w');

    if ($stream) {
        // Read the uploaded file and write it to the remote server
        $localFile = $_FILES['file']['tmp_name'];
        $data = file_get_contents($localFile);
        if (fwrite($stream, $data) !== false) {
            echo json_encode(["message" => "The file has been uploaded."]);
        } else {
            echo json_encode(["error" => "Sorry, there was an error uploading your file."]);
        }
        fclose($stream);
    } else {
        echo json_encode(["error" => "Could not open remote file for writing."]);
    }
} else {
    echo json_encode(["error" => "No file received."]);
}
?>