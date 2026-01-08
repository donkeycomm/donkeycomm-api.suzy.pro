<?php
include('./jwt-validation.php'); // Include the JWT validation code

// Get the folder and path name from the POST request
$data = file_get_contents('php://input');
$data = json_decode($data);

if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}
if (!isset($data->folder_id) || !isset($data->hovered_folder_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing folder IDs']);
    exit;
}
//convert both to int
$folder_id = intval($data->folder_id);
$hovered_folder_id = intval($data->hovered_folder_id);

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
function getFolderData($id, $jwt) {
    try {
        $url = $_ENV['URL_BACKEND'].'/get-folder-by-id.php';
        $data = array('id' => $id);
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
            throw new Exception("Error checking folder access level for: " . $id);
            
        }
       
        $folder = json_decode($result, true);

        if ($folder) {
            return $folder;
        } else {
            throw new Exception("Folder not found " . $id);
        }
    } catch (Exception $e) {
        error_log( $e->getMessage());
      
    }  
}
//get user access level
try {
    $user_roles = getUserRolesFromDatabase($user_email, $jwt);
} catch (Exception $e) {
    // Log the error message for debugging
    error_log("Error in getUserRoleFromDatabase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array("error: " => $e->getMessage()));
}


//check if user has permission to create a folder here
$rolesArray = json_decode($user_roles, true);
   
if(!in_array(0, $rolesArray) && !in_array(1, $rolesArray)){
    http_response_code(403);
    echo json_encode(array('error' => 'You do not have permission to update a folder.', 
    'current_path_access_level' => $current_path_access_level, 
    'user_roles' => $user_roles));
    exit;
}


// Fetch all folders with the same parent folder
function getFoldersByParentPath($parent_path) {
   
    include('./db-connection.php');
  
    try {
        $stmt = $conn->prepare("SELECT * FROM `folders` WHERE `parent_path` = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        $stmt->bind_param("s", $parent_path);
        $stmt->execute();
        $result = $stmt->get_result();
       
        if ($result) {
            $folders = $result->fetch_all(MYSQLI_ASSOC);
          
            if (empty($folders)) {
                throw new Exception("No folders found for parent path: $parent_path");
            }
            return $folders;
        } else {
            throw new Exception("Failed to fetch results: " . $stmt->error);
        }
    } catch (Exception $e) {
        http_response_code(500);
      
        error_log('Error in getFoldersByParentPath: ' . $e->getMessage()); // Log the error for debugging
        return [];
    }
}

// Update folder rank in the database or via API
function updateFolderRank($folder_id, $new_rank) {
    include('./db-connection.php'); // Include the database connection code

   try {

        $stmt = $conn->prepare("UPDATE `folders` SET `rank`=? WHERE `ID`=?");
        $stmt->bind_param("ii", $new_rank, $folder_id);
        $stmt->execute();

        return true;

    } catch (Exception $e) {
        http_response_code(500); // Server error
        echo json_encode(['error' => 'Server error']);
        // Log the error if needed
    }
}

try {
    // Get folder data
    $currentFolderData = getFolderData($folder_id, $jwt);
    $hoveredFolderData = getFolderData($hovered_folder_id, $jwt);

    if (!$currentFolderData || !$hoveredFolderData) {
        throw new Exception('Error fetching folder data');
    }

    // Get all folders with the same parent path
    $parent_path = $currentFolderData['parent_path'];

    $folders = getFoldersByParentPath($parent_path);

    if (!$folders) {
        throw new Exception('Error fetching folders with the same parent path');
    }

    // Determine the direction of rank update based on comparison of ranks
    $new_rank = $hoveredFolderData['rank'];

    // Adjust the ranks of the other folders to ensure sequential ranking
    foreach ($folders as &$folder) {
        // Skip the current folder
        if ($folder['ID'] == $folder_id) {
            $folder['rank'] = $new_rank;
            continue;
        }

        // Adjust folder ranks to maintain sequential order
        if ($hoveredFolderData['rank'] < $currentFolderData['rank']) {
            if ($folder['rank'] >= $new_rank && $folder['rank'] < $currentFolderData['rank']) {
                $folder['rank']++;
            }
        } else {
            if ($folder['rank'] <= $new_rank && $folder['rank'] > $currentFolderData['rank']) {
                $folder['rank']--;
            }
        }

        // Update the rank in the database
        updateFolderRank($folder['ID'], $folder['rank']);
    }
    unset($folder); // Unset reference to avoid unexpected behavior later

    // Update the rank of the current folder
    updateFolderRank($folder_id, $new_rank);

    // Get updated folders list
    $folders = getFoldersByParentPath($parent_path);

    // Sort folders by rank in ascending order
    usort($folders, function($a, $b) {
        return $a['rank'] - $b['rank'];
    });

    // Reset ranks to be sequential from 1 without gaps
    $rank_counter = 1;
    foreach ($folders as &$folder) {
        $folder['rank'] = $rank_counter++;
        // Update the rank in the database
        updateFolderRank($folder['ID'], $folder['rank']);
    }
    unset($folder);

    echo json_encode('success');


} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
    exit;
}

// Return success message
http_response_code(200);


