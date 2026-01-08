<?php
// Start output buffering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('./jwt-validation.php'); // Include the JWT validation code
include './ssh_connect.php';

// Retrieve data from the request
$data = file_get_contents('php://input');
$data = json_decode($data);

if (!isset($data->files) || !is_array($data->files)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid request: files array is required']);
    exit;
}

// Function to store the metadata in the database of the deleted file
function storeDeletedFileInDatabase($file_id, $file_name, $file_path, $file_access_level, $user_email, $file_size) {
    // Connect to the database
    include('./db-connection.php'); // Include the database connection code
    try {
        $stmt = $conn->prepare("INSERT INTO `deleted_from_trash` (`file_id`, `file_name`, `file_path`, `file_access_level`, `user_email`, `size`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $date = date('Y-m-d H:i:s');
        $stmt->bind_param("issssis", $file_id, $file_name, $file_path, $file_access_level, $user_email, $file_size, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return 'file deleted';
    } catch (Exception $e) {
        http_response_code(500);
        return 'Error storing metadata in database';
    }
}

// Function to get user roles from the database
function getUserRolesFromDatabase($user_email, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $user_email);
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Bearer " . $jwt . "\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if ($result === false) {
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
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
} catch (Exception $e) {
    error_log("Error in getUserRolesFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}

$successMessages = [];
$errorMessages = [];

try {
    include('./db-connection.php'); // Include the database connection code
    $userRolesArray = json_decode($user_roles, true);
    
    if (in_array(0, $userRolesArray)) {
        $sshDetails = sshConnect();
        $sftp = $sshDetails['sftp'];
        
        foreach ($data->files as $file_id) {
            $stmt = $conn->prepare("SELECT * FROM `files_in_trash` WHERE `ID`=?");
            $stmt->bind_param("i", $file_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $file = $result->fetch_assoc();
                $file_path = $file['file_path'];
                $file_name = $file['file_name'];
                $file_size = $file['size'];
                $small = $file['small'];
                $medium = $file['medium'];
                $large = $file['large'];
                $access_level = $file['file_access_level'];
                
              // Delete the main file from the server
              ssh2_sftp_unlink($sftp, $file_path);

              // Delete the variant files if they exist
              if (!empty($small)) {
                  ssh2_sftp_unlink($sftp, $small);
              }
              if (!empty($medium)) {
                  ssh2_sftp_unlink($sftp, $medium);
              }
              if (!empty($large)) {
                  ssh2_sftp_unlink($sftp, $large);
              }
              
                // Delete the file record from the database
                $deleteStmt = $conn->prepare("DELETE FROM `files_in_trash` WHERE `ID`=?");
                $deleteStmt->bind_param("i", $file_id);
                $deleteStmt->execute();
                $deleteStmt->close();
                
                // Store metadata of the deleted file
                storeDeletedFileInDatabase($file_id, $file_name, $file_path, $access_level, $user_email, $file_size);
                $successMessages[] = $file_name;
            } else {
                $errorMessages[] = 'File not found: ' . $file_id;
            }
        }
        
        $conn->close();
        echo json_encode(['success' => $successMessages, 'error' => $errorMessages]);
    } else {
        echo json_encode(['error' => 'User not authorized']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>