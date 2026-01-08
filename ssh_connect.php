<?php
function sshConnect() {
    $remote_server = $_ENV['FILE_HOSTING_URL'];
    $remote_user = $_ENV['FILE_HOSTING_USER'];
    $remote_password = $_ENV['FILE_HOSTING_PASS'];

    // Establish SSH connection
    $connection = ssh2_connect($remote_server, 22);
    if (!$connection) {
        throw new Exception("Failed to establish SSH connection.");
    }

    // Authenticate
    if (!ssh2_auth_password($connection, $remote_user, $remote_password)) {
        throw new Exception("SSH authentication failed.");
    }

    // Initialize SFTP
    $sftp = ssh2_sftp($connection);
    if (!$sftp) {
        throw new Exception("Failed to initialize SFTP subsystem.");
    }

    // Return both connection and sftp as an array
    return ['connection' => $connection, 'sftp' => $sftp];
}
?>