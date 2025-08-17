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

    // function login($input) {
    //     include "connection-pdo.php";
        
    //     try {
    //         // Handle both array and JSON string input
    //         $data = is_array($input) ? $input : json_decode($input, true);
            
    //         if (json_last_error() !== JSON_ERROR_NONE && !is_array($input)) {
    //             throw new Exception("Invalid JSON data");
    //         }

    //         // Validate required fields
    //         if(empty($data['email']) || empty($data['password'])) {
    //             echo json_encode([
    //                 'status' => 'error',
    //                 'message' => 'Email and password are required',
    //                 'received_data' => $data // For debugging
    //             ]);
    //             return;
    //         }

    //         $sql = "SELECT u.*, r.role_name 
    //                 FROM users u 
    //                 LEFT JOIN roles r ON u.role_id = r.role_id 
    //                 WHERE u.email = :email AND u.is_active = 1";
    //         $stmt = $conn->prepare($sql);
    //         $stmt->bindValue(":email", $data['email']);
    //         $stmt->execute();
    //         $user = $stmt->fetch(PDO::FETCH_ASSOC);

    //         if($user && password_verify($data['password'], $user['password'])) {
    //             // Remove sensitive data before returning
    //             unset($user['password']);
                
    //             echo json_encode([
    //                 'status' => 'success',
    //                 'message' => 'Login successful',
    //                 'data' => $user
    //             ]);
    //         } else {
    //             echo json_encode([
    //                 'status' => 'error',
    //                 'message' => 'Invalid email or password'
    //             ]);
    //         }
    //     } catch (PDOException $e) {
    //         echo json_encode([
    //             'status' => 'error',
    //             'message' => 'Database error: ' . $e->getMessage()
    //         ]);
    //     } catch (Exception $e) {
    //         echo json_encode([
    //             'status' => 'error',
    //             'message' => $e->getMessage()
    //         ]);
    //     }
    // }
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
                
            // For warehouse managers - check warehouse assignment
            if ($user['role_name'] === 'warehouse_manager') {
                $warehouseSql = "SELECT w.warehouse_id, w.warehouse_name 
                            FROM assign_warehouse aw
                            JOIN warehouses w ON aw.warehouse_id = w.warehouse_id
                            WHERE aw.user_id = :userId AND aw.is_active = 1
                            LIMIT 1";
                $warehouseStmt = $conn->prepare($warehouseSql);
                $warehouseStmt->bindValue(":userId", $user['user_id']);
                $warehouseStmt->execute();
                $warehouse = $warehouseStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$warehouse) {
                    throw new Exception("No warehouse assigned to this manager");
                }
                
                $user['warehouse_id'] = $warehouse['warehouse_id'];
                $user['warehouse_name'] = $warehouse['warehouse_name'];
            }
            // For cashiers - check counter assignment
            else if ($user['role_name'] === 'cashier') {
                $counterSql = "SELECT c.counter_id, c.counter_name 
                            FROM assign_sales ac
                            JOIN counters c ON ac.counter_id = c.counter_id
                            WHERE ac.user_id = :userId AND ac.is_active = 1
                            LIMIT 1";
                $counterStmt = $conn->prepare($counterSql);
                $counterStmt->bindValue(":userId", $user['user_id']);
                $counterStmt->execute();
                $counter = $counterStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$counter) {
                    throw new Exception("No sales counter assigned to this cashier");
                }
                
                $user['counter_id'] = $counter['counter_id'];
                $user['counter_name'] = $counter['counter_name'];
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
                'getAllUsers', 
                'getUser', 
                'deleteUser', 
                'checkEmail', 
                'searchUsers'
            ]
        ]);
}
    
?>