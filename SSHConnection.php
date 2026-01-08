<?php
class SSHConnection {
    private static $instance = null;
    private $connection;
    private $sftp;
    private $lastUsed;

    private function __construct() {
        $this->connect();
    }

    private function connect() {
        $remote_server = $_ENV['FILE_HOSTING_URL'];
        $remote_user = $_ENV['FILE_HOSTING_USER'];
        $remote_password = $_ENV['FILE_HOSTING_PASS'];

        // Establish SSH connection
        $this->connection = ssh2_connect($remote_server, 22);
        if (!$this->connection) {
            throw new Exception("Failed to establish SSH connection.");
        }

        // Authenticate
        if (!ssh2_auth_password($this->connection, $remote_user, $remote_password)) {
            throw new Exception("SSH authentication failed.");
        }

        // Initialize SFTP
        $this->sftp = ssh2_sftp($this->connection);
        if (!$this->sftp) {
            throw new Exception("Failed to initialize SFTP subsystem.");
        }

        $this->lastUsed = time();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new SSHConnection();
        } else {
            // Reconnect if the connection is older than 5 minutes
            if (time() - self::$instance->lastUsed > 300) {
                self::$instance->connect();
            }
        }
        self::$instance->lastUsed = time();
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function getSFTP() {
        return $this->sftp;
    }
}
?>