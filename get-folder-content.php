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

function getUserRolesFromDatabase($email, $jwt) {
    //get user info from the api get-user-data.php
  
    try {
        $url = $_ENV['URL_BACKEND'].'/get-user-data.php';
        $data = array('email' => $email);
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
            $user_roles = getUserRolesFromDatabase($user_email, $jwt);
            error_log("User roles: " . $user_roles);
        } catch (Exception $e) {
            // Log the error message for debugging
            error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    try {
       $offset = $data->page * 20;
       if (is_string($user_roles)) {
            $rolesArray = json_decode($user_roles, true);
        } else {
            $rolesArray = $user_roles;
        }
        //loop through access levels and match files with access levels
        $sql = "SELECT * FROM `files` WHERE (";
     
        foreach ($rolesArray as $role) {
            $sql .= "JSON_CONTAINS(`access_level`, '$role') OR ";
        }
        $sql = rtrim($sql, " OR "); // Remove the last " OR "
        $sql .= ") AND `path` = ? ORDER BY `date` DESC LIMIT 20 OFFSET $offset";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s",  $path);
        $stmt->execute();
        $result = $stmt->get_result();
        $files = array();

        // Establish SSH connection
        $ssh = sshConnect();
        $sftp = $ssh['sftp'];

        while($row = $result->fetch_assoc()) {
            $pathSeparator = "/";
            if($row['path'] == "/") {
                $pathSeparator = "";
            }
            $remote_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row[$data->size];
            $original_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['file'];
            // Fetch file contents from the remote server
            $file_data = @file_get_contents("ssh2.sftp://$sftp$remote_file_path");
           
          
            if ($file_data === FALSE) {
                // Check if the file is a PDF
               
                    $row['base64'] = null;
               
            } else {
                $pdf_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['file'];
                  // Check if the file is a PDF
                if (mime_content_type("ssh2.sftp://$sftp$pdf_file_path") === 'application/pdf') {
                   
                    try {
                                        // Create a local temporary file

                        $temp_file = tempnam(sys_get_temp_dir(), 'pdf_');
                        file_put_contents($temp_file, file_get_contents("ssh2.sftp://$sftp$pdf_file_path"));

                        // Create a new Imagick instance
                        $imagick = new Imagick();
        
                        // Read the first page of the PDF
                        $imagick->readImage($temp_file . '[0]');
        
                        // Set the format to PNG (or any other image format)
                        $imagick->setImageFormat('png');
        
                        // Get the image as a string
                        $imageData = $imagick->getImageBlob();
        
                        // Encode the image data to base64
                        $base64 = base64_encode($imageData);
        
                        // Clear the Imagick instance
                        $imagick->clear();
                        $row['base64'] = $base64;
        
                    } catch (Exception $e) {
                        error_log('Error processing PDF: ' . $e->getMessage());
                        $row['base64'] = null;
                    }
                } else if (mime_content_type("ssh2.sftp://$sftp$original_file_path") === 'image/svg+xml') {
                    try {
                        // Read the SVG file content
                        $svg_content = file_get_contents("ssh2.sftp://$sftp$original_file_path");
            
                        // Create a new Imagick instance
                        $imagick = new Imagick();
            
                        // Read the SVG content
                        $imagick->readImageBlob($svg_content);
            
                        // Set the format to PNG (or any other image format)
                        $imagick->setImageFormat('png');
            
                        // Get the image as a string
                        $imageData = $imagick->getImageBlob();
            
                        // Encode the image data to base64
                        $base64 = base64_encode($imageData);
            
                        // Clear the Imagick instance
                        $imagick->clear();
                        $row['base64'] = $base64;
            
                    } catch (Exception $e) {
                        error_log('Error processing SVG: ' . $e->getMessage());
                        $row['base64'] = null;
                    }
                } else {
                    $base64 = base64_encode($file_data);
                    $row['base64'] = $base64;
                }
            }
            $files[] = $row;
        }
        echo json_encode($files);
               
     
    } catch (Exception $e) {
        // Return an error if something goes wrong
        echo json_encode(['error' =>  $e->getMessage()]);
    }


?>