<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class User {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Get all users with role information
    function getAllUsers() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT u.*, r.role_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.role_id 
                    ORDER BY u.full_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Users retrieved successfully',
                'data' => $users
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Insert a new user
    function insertUser($data) {
        include "connection-pdo.php";  
        $conn->beginTransaction();

        try {
            // Validate required fields
            $required = ['full_name', 'email', 'password', 'role_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            // Check if email already exists
            $checkSql = "SELECT COUNT(*) as count FROM users WHERE email = :email";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":email", $data['email']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Email already exists");
            }

            // Generate UUID
            $userId = $this->generateUuid();

            // Prepare data
            $fullName = $data['full_name'];
            $email = $data['email'];
            $password = password_hash($data['password'], PASSWORD_DEFAULT);
            $phone = $data['phone'] ?? null;
            $roleId = $data['role_id'];
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            // New users must change password on first login
            $mustChangePassword = 1;

            // Insert user
            $sql = "INSERT INTO users(
                        user_id, full_name, email, password, phone, 
                        role_id, is_active, must_change_password
                    ) VALUES(
                        :userId, :fullName, :email, :password, :phone, 
                        :roleId, :isActive, :mustChangePassword
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $userId);
            $stmt->bindValue(":fullName", $fullName);
            $stmt->bindValue(":email", $email);
            $stmt->bindValue(":password", $password);
            $stmt->bindValue(":phone", $phone);
            $stmt->bindValue(":roleId", $roleId, PDO::PARAM_STR);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":mustChangePassword", $mustChangePassword, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create user");
            }

            // Handle location assignment if provided
            if (!empty($data['location_id'])) {
                $assignmentId = $this->generateUuid();
                $assignmentSql = "INSERT INTO user_assignments(
                                    assignment_id, user_id, location_id, assigned_date
                                ) VALUES(
                                    :assignmentId, :userId, :locationId, CURDATE()
                                )";
                
                $assignmentStmt = $conn->prepare($assignmentSql);
                $assignmentStmt->bindValue(":assignmentId", $assignmentId);
                $assignmentStmt->bindValue(":userId", $userId);
                $assignmentStmt->bindValue(":locationId", $data['location_id']);
                
                if (!$assignmentStmt->execute()) {
                    throw new Exception("Failed to assign location to user");
                }
            }

            $conn->commit(); 
            
            return json_encode([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => [
                    'user_id' => $userId,
                    'full_name' => $fullName,
                    'email' => $email
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get a single user with role information and location assignment
    function getUser($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id'
                ]);
                return;
            }

            $sql = "SELECT u.*, r.role_name, 
                    l.location_id, l.location_name, l.location_type
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.role_id 
                    LEFT JOIN user_assignments ua ON u.user_id = ua.user_id AND ua.is_active = 1
                    LEFT JOIN locations l ON ua.location_id = l.location_id
                    WHERE u.user_id = :userId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $json['user_id']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if($user) {
                unset($user['password']);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'User retrieved successfully',
                    'data' => $user
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Update user information
    function updateUser($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['user_id'])) {
                throw new Exception("User ID is required");
            }

            $required = ['full_name', 'email', 'role_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $checkSql = "SELECT COUNT(*) as count FROM users WHERE email = :email AND user_id != :userId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":email", $data['email']);
            $checkStmt->bindValue(":userId", $data['user_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Email already exists");
            }

            $fullName = $data['full_name'];
            $email = $data['email'];
            $phone = $data['phone'] ?? null;
            $roleId = $data['role_id'];
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            $sql = "UPDATE users SET
                        full_name = :fullName,
                        email = :email,
                        phone = :phone,
                        role_id = :roleId,
                        is_active = :isActive
                    WHERE user_id = :userId";
            
            if (!empty($data['password'])) {
                $sql = str_replace("SET", "SET password = :password,", $sql);
            }

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":fullName", $fullName);
            $stmt->bindValue(":email", $email);
            $stmt->bindValue(":phone", $phone);
            $stmt->bindValue(":roleId", $roleId, PDO::PARAM_STR);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!empty($data['password'])) {
                $password = password_hash($data['password'], PASSWORD_DEFAULT);
                $stmt->bindValue(":password", $password);
            }
            
            $stmt->bindValue(":userId", $data['user_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update user");
            }
            
            // Handle location assignment if provided
            if (isset($data['location_id'])) {
                // First, deactivate any existing assignments
                $deactivateSql = "UPDATE user_assignments 
                                 SET is_active = 0 
                                 WHERE user_id = :userId";
                $deactivateStmt = $conn->prepare($deactivateSql);
                $deactivateStmt->bindValue(":userId", $data['user_id']);
                $deactivateStmt->execute();
                
                // If a new location is provided, create a new assignment
                if (!empty($data['location_id'])) {
                    $assignmentId = $this->generateUuid();
                    $assignmentSql = "INSERT INTO user_assignments(
                                        assignment_id, user_id, location_id, assigned_date
                                    ) VALUES(
                                        :assignmentId, :userId, :locationId, CURDATE()
                                    )";
                    
                    $assignmentStmt = $conn->prepare($assignmentSql);
                    $assignmentStmt->bindValue(":assignmentId", $assignmentId);
                    $assignmentStmt->bindValue(":userId", $data['user_id']);
                    $assignmentStmt->bindValue(":locationId", $data['location_id']);
                    
                    if (!$assignmentStmt->execute()) {
                        throw new Exception("Failed to assign location to user");
                    }
                }
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => [
                    'user_id' => $data['user_id'],
                    'full_name' => $fullName,
                    'email' => $email
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Delete a user
    function deleteUser($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id'
                ]);
                return;
            }

            // First delete user assignments
            $deleteAssignmentsSql = "DELETE FROM user_assignments WHERE user_id = :userId";
            $deleteAssignmentsStmt = $conn->prepare($deleteAssignmentsSql);
            $deleteAssignmentsStmt->bindParam(":userId", $json['user_id']);
            $deleteAssignmentsStmt->execute();

            // Then delete the user
            $sql = "DELETE FROM users WHERE user_id = :userId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":userId", $json['user_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0) {
                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'User deleted successfully',
                    'data' => [
                        'user_id' => $json['user_id']
                    ]
                ]);
            } else {
                $conn->rollBack();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User not found'
                ]);
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function login($input) {
        include "connection-pdo.php";
        
        try {
            $data = is_array($input) ? $input : json_decode($input, true);
            
            if(empty($data['email']) || empty($data['password'])) {
                throw new Exception("Email and password are required");
            }

            $sql = "SELECT u.*, r.role_name 
                    FROM users u 
                    JOIN roles r ON u.role_id = r.role_id 
                    WHERE u.email = :email";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":email", $data['email']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check if user exists
            if(!$user) {
                throw new Exception("Invalid email or password");
            }

            if(!$user['is_active']) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Account inactive'
                ]);
                return; 
            }
            
            // Verify password
            if(!password_verify($data['password'], $user['password'])) {
                throw new Exception("Invalid email or password");
            }

            // For warehouse managers and store staff - check location assignment
            if ($user['role_name'] === 'warehouse_manager' || $user['role_name'] === 'store_staff') {
                $locationSql = "SELECT l.location_id, l.location_name, l.location_type
                                FROM user_assignments ua
                                JOIN locations l ON ua.location_id = l.location_id
                                WHERE ua.user_id = :userId AND ua.is_active = 1
                                LIMIT 1";
                $locationStmt = $conn->prepare($locationSql);
                $locationStmt->bindValue(":userId", $user['user_id']);
                $locationStmt->execute();
                $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$location) {
                    throw new Exception("No location assigned to this user");
                }
                
                $user['location_id'] = $location['location_id'];
                $user['location_name'] = $location['location_name'];
                $user['location_type'] = $location['location_type'];
            }

            // Check if user must change password
            if ($user['must_change_password']) {
                unset($user['password']);
                echo json_encode([
                    'status' => 'password_change_required',
                    'message' => 'Password change required for first login',
                    'data' => $user
                ]);
                return;
            }

            unset($user['password']);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => $user
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Change password for first login
    function changeFirstLoginPassword($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            $required = ['user_id', 'current_password', 'new_password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            // Get user data
            $sql = "SELECT * FROM users WHERE user_id = :userId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $data['user_id']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception("User not found");
            }

            // Verify current password
            if (!password_verify($data['current_password'], $user['password'])) {
                throw new Exception("Current password is incorrect");
            }

            // Validate new password
            if (strlen($data['new_password']) < 8) {
                throw new Exception("New password must be at least 8 characters long");
            }

            // Check if new password is different from current
            if (password_verify($data['new_password'], $user['password'])) {
                throw new Exception("New password must be different from current password");
            }

            // Hash new password
            $hashedPassword = password_hash($data['new_password'], PASSWORD_DEFAULT);

            // Get updated user with role information for login
            $sql = "SELECT u.*, r.role_name 
                    FROM users u 
                    JOIN roles r ON u.role_id = r.role_id 
                    WHERE u.user_id = :userId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $data['user_id']);
            $stmt->execute();
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // For warehouse managers and store staff - get location assignment
            if ($updatedUser['role_name'] === 'warehouse_manager' || $updatedUser['role_name'] === 'store_staff') {
                $locationSql = "SELECT l.location_id, l.location_name, l.location_type
                                FROM user_assignments ua
                                JOIN locations l ON ua.location_id = l.location_id
                                WHERE ua.user_id = :userId AND ua.is_active = 1
                                LIMIT 1";
                $locationStmt = $conn->prepare($locationSql);
                $locationStmt->bindValue(":userId", $updatedUser['user_id']);
                $locationStmt->execute();
                $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($location) {
                    $updatedUser['location_id'] = $location['location_id'];
                    $updatedUser['location_name'] = $location['location_name'];
                    $updatedUser['location_type'] = $location['location_type'];
                }
            }

             // Update password and clear must_change_password flag
            $updateSql = "UPDATE users SET 
                            password = :newPassword, 
                            must_change_password = 0,
                            updated_at = CURRENT_TIMESTAMP
                          WHERE user_id = :userId";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bindValue(":newPassword", $hashedPassword);
            $updateStmt->bindValue(":userId", $data['user_id']);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update password");
            }

            $conn->commit();

            unset($updatedUser['password']);

            echo json_encode([
                'status' => 'success',
                'message' => 'Password changed successfully',
                'data' => $updatedUser
            ]);

        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Check if email exists
    function checkEmail($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['email'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Email is required'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM users WHERE email = :email";
            $params = [':email' => $json['email']];
            
            if(isset($json['user_id'])) {
                $sql .= " AND user_id != :userId";
                $params[':userId'] = $json['user_id'];
            }
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $rs = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Check completed',
                'data' => [
                    'exists' => $rs['count'] > 0,
                    'email' => $json['email']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search users
    function searchUsers($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['search_term'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: search_term'
                ]);
                return;
            }

            $searchTerm = '%' . $json['search_term'] . '%';
            $sql = "SELECT u.*, r.role_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.role_id
                    WHERE u.full_name LIKE :searchTerm 
                    OR u.email LIKE :searchTerm
                    OR u.phone LIKE :searchTerm
                    ORDER BY u.full_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Remove passwords from the results
            foreach ($users as &$user) {
                unset($user['password']);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Users search completed',
                'data' => $users
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Count user status with role information
    function countUserStatus() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active_users,
                        SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
                        SUM(CASE WHEN r.role_name = 'admin' THEN 1 ELSE 0 END) as admin_users,
                        COUNT(*) as total_users
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.role_id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'User counts retrieved successfully',
                'data' => $counts
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
}

$user = new User();
$operation = '';
$data = [];
$operation = $_GET['operation'] ?? ($_POST['operation'] ?? '');

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $json = $_GET['json'] ?? '{}';
    $data = json_decode($json, true) ?: [];
} else {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    
    if (empty($data) && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = $_POST;
    }
}

switch ($operation) {
    case "insertUser":
        echo $user->insertUser($data);
        break;
    case "updateUser":
        echo $user->updateUser($data);
        break;
    case "login":
        echo $user->login($data);
        break;
    case "changeFirstLoginPassword":
        echo $user->changeFirstLoginPassword($data);
        break;
    case "countUserStatus":
        echo $user->countUserStatus();
        break;
    case "getAllUsers":
        echo $user->getAllUsers();
        break;
    case "getUser":
        $json = $_GET['json'] ?? '{}';
        echo $user->getUser($json);
        break;
    case "deleteUser":
        $json = $_GET['json'] ?? '{}';
        echo $user->deleteUser($json);
        break;
    case "checkEmail":
        $json = $_GET['json'] ?? '{}';
        echo $user->checkEmail($json);
        break;
    case "searchUsers":
        $json = $_GET['json'] ?? '{}';
        echo $user->searchUsers($json);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation',
            'available_operations' => [
                'insertUser', 
                'updateUser', 
                'login', 
                'changeFirstLoginPassword',
                'getAllUsers', 
                'getUser', 
                'deleteUser', 
                'checkEmail', 
                'searchUsers'
            ]
        ]);
}
?>