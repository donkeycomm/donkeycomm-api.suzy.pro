<?php
include('./jwt-validation.php'); // Include the JWT validation code
include('./db-connection.php'); // Include the database connection code


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





try {
    $offset = $data->page * 20;
    $rolesArray = json_decode($user_roles, true);
     //loop through access levels and match files with access levels
     $sql = "SELECT * FROM `files` WHERE `ID`=?";
  
    
     $stmt = $conn->prepare($sql);
     $stmt->bind_param("s",  $data->id);
     $stmt->execute();
     $result = $stmt->get_result();
     $dimensions = array();

     // Establish SSH connection
     $ssh = sshConnect();
     $sftp = $ssh['sftp'];

     while($row = $result->fetch_assoc()) {
        $pathSeparator = "/";
        if($row['path'] == "/") {
            $pathSeparator = "";
        }
        //get image dimensions in pixels from small, medium, large and original(file) using Imagick
        $original_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['file'];
        $large_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['large'];
        $medium_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['medium'];
        $small_file_path = $_ENV['FILE_HOSTING_ROOT'].'/files' . $row['path'] . $pathSeparator . $row['small'];

        // Fetch file contents from the remote server
        $original = @file_get_contents("ssh2.sftp://$sftp$original_file_path");
        $large = @file_get_contents("ssh2.sftp://$sftp$large_file_path");
        $medium = @file_get_contents("ssh2.sftp://$sftp$medium_file_path");
        $small = @file_get_contents("ssh2.sftp://$sftp$small_file_path");
        //check if its an image jpg or png
        $originalImage = new Imagick();
        $originalImage->readImageBlob($original);
        $largeImage = new Imagick();
        $largeImage->readImageBlob($large);
        $mediumImage = new Imagick();
        $mediumImage->readImageBlob($medium);
        $smallImage = new Imagick();
        $smallImage->readImageBlob($small);

        $dimensions['original'] = $originalImage->getImageWidth()/'x'.$originalImage->getImageHeight();
        $dimensions['large'] = $largeImage->getImageWidth()/'x'.$largeImage->getImageHeight();
        $dimensions['medium'] = $mediumImage->getImageWidth()/'x'.$mediumImage->getImageHeight();
        $dimensions['small'] = $smallImage->getImageWidth()/'x'.$smallImage->getImageHeight();

      

        $originalImage->clear();
        $originalImage->destroy();
        $largeImage->clear();
        $largeImage->destroy();
        $mediumImage->clear();
        $mediumImage->destroy();
        $smallImage->clear();
        $smallImage->destroy();
    }
    echo json_encode($dimensions);
  
 } catch (Exception $e) {
     // Return an error if something goes wrong
     echo json_encode(['error' =>  $e->getMessage()]);
 }