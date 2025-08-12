<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

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
                    ORDER BY u.created_at DESC";
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

            // Insert user
            $sql = "INSERT INTO users(
                        user_id, full_name, email, password, phone, 
                        role_id, is_active
                    ) VALUES(
                        :userId, :fullName, :email, :password, :phone, 
                        :roleId, :isActive
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $userId);
            $stmt->bindValue(":fullName", $fullName);
            $stmt->bindValue(":email", $email);
            $stmt->bindValue(":password", $password);
            $stmt->bindValue(":phone", $phone);
            $stmt->bindValue(":roleId", $roleId, PDO::PARAM_STR);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create user");
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

    // Get a single user with role information
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

            $sql = "SELECT u.*, r.role_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.role_id 
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
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id'
                ]);
                return;
            }

            $sql = "DELETE FROM users WHERE user_id = :userId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":userId", $json['user_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'User deleted successfully',
                    'data' => [
                        'user_id' => $json['user_id']
                    ]
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

    // User login
    function login($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['email']) || empty($json['password'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Email and password are required'
                ]);
                return;
            }

            $sql = "SELECT u.*, r.role_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.role_id 
                    WHERE u.email = :email AND u.is_active = 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":email", $json['email']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if($user && password_verify($json['password'], $user['password'])) {
                // Remove sensitive data before returning
                unset($user['password']);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Login successful',
                    'data' => $user
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid email or password'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
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

    // Add this method to your User class
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
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $operation = $_GET['operation'] ?? '';
    $json = $_GET['json'] ?? '{}';
} else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operation = $_GET['operation'] ?? '';
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    
    if (empty($data) && !empty($_POST)) {
        $data = $_POST;
    }
    
    if (empty($operation)) {
        $operation = $_POST['operation'] ?? '';
    }
}


$user = new User();
if ($operation === "insertUser") {
    echo $user->insertUser($data);
} elseif ($operation === "updateUser") {
    echo $user->updateUser($data);
} else {
    switch($operation) {
        case "getAllUsers":
            echo $user->getAllUsers();
            break;
        case "getUser":
            echo $user->getUser($json);
            break;
        case "deleteUser":
            echo $user->deleteUser($json);
            break;
        case "checkEmail":
            echo $user->checkEmail($json);
            break;
        case "login":
            echo $user->login($json);
            break;
        case "searchUsers":
            echo $user->searchUsers($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
    
?>