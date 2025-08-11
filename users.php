<?php
  header('Content-Type: application/json');
  header("Access-Control-Allow-Origin: *");

  class User {
      private function generateUUID() {
          return sprintf(
              '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
              mt_rand(0, 0xffff), mt_rand(0, 0xffff),
              mt_rand(0, 0xffff),
              mt_rand(0, 0x0fff) | 0x4000,
              mt_rand(0, 0x3fff) | 0x8000,
              mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
          );
      }

    function getAllUsers(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT u.*, r.role_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.role_id 
                ORDER BY u.full_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Users retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertUser($json){
      include "connection-pdo.php";

      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['full_name']) || empty($json['email']) || empty($json['password']) || empty($json['role_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: full_name, email, password, and role_id are required'
          ]);
          return;
        }

         $userId = $this->generateUuid();


        $sql = "INSERT INTO users(user_id, full_name, email, password, phone, role_id, is_active) 
                VALUES(:userId, :fullName, :email, :password, :phone, :roleId, :isActive)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":userId", $userId);
        $hashedPassword = password_hash($json['password'], PASSWORD_DEFAULT);
        $stmt->bindParam(":fullName", $json['full_name']);
        $stmt->bindParam(":email", $json['email']);
        $stmt->bindParam(":password", $hashedPassword);
        $stmt->bindParam(":phone", $json['phone']);
        $stmt->bindParam(":roleId", $json['role_id']);
        $stmt->bindParam(":isActive", $json['is_active']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'User created successfully',
            'data' => [
              'user_id' => $userId,
              'full_name' => $json['full_name'],
              'email' => $json['email']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create user'
          ]);
        }
      } catch (PDOException $e) {
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Email already exists. Please use a different email address.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function getUser($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['user_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: user_id is required'
          ]);
          return;
        }

        $sql = "SELECT u.*, r.role_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.role_id 
                WHERE u.user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":userId", $json['user_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'User retrieved successfully',
            'data' => $rs[0]
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

    function updateUser($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['user_id']) || empty($json['full_name']) || empty($json['email']) || empty($json['role_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: user_id, full_name, email, and role_id are required'
          ]);
          return;
        }

        $sql = "UPDATE users SET full_name = :fullName, email = :email, phone = :phone, 
                role_id = :roleId, is_active = :isActive WHERE user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":fullName", $json['full_name']);
        $stmt->bindParam(":email", $json['email']);
        $stmt->bindParam(":phone", $json['phone']);
        $stmt->bindParam(":roleId", $json['role_id']);
        $stmt->bindParam(":isActive", $json['is_active']);
        $stmt->bindParam(":userId", $json['user_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'User updated successfully',
            'data' => [
              'user_id' => $json['user_id'],
              'full_name' => $json['full_name'],
              'email' => $json['email']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'User not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Email already exists. Please use a different email address.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function deleteUser($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['user_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: user_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM users WHERE user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":userId", $json['user_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
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

    function login($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['email']) || empty($json['password'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: email and password are required'
          ]);
          return;
        }

        $sql = "SELECT u.*, r.role_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.role_id 
                WHERE u.email = :email AND u.is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":email", $json['email']);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if($user && password_verify($json['password'], $user['password'])){
          unset($user['password']); // Remove password from response
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
  }

  //submitted by the client - operation and json
  if ($_SERVER['REQUEST_METHOD'] == 'GET'){
    $operation = $_GET['operation'];
    $json = isset($_GET['json']) ? $_GET['json'] : "";
  }else if($_SERVER['REQUEST_METHOD'] == 'POST'){
    // Check if operation is in POST data
    if(isset($_POST['operation'])){
      $operation = $_POST['operation'];
      $json = isset($_POST['json']) ? $_POST['json'] : "";
    } else {
      // Handle JSON body for POST requests
      $input = file_get_contents('php://input');
      $data = json_decode($input, true);
      
      // Get operation from URL parameter
      $operation = isset($_GET['operation']) ? $_GET['operation'] : '';
      $json = $input; // Use the raw JSON input
    }
  }

  $user = new User();
  switch($operation){
    case "getAllUsers":
      echo $user->getAllUsers();
      break;
    case "insertUser":
      echo $user->insertUser($json);
      break;
    case "getUser":
      echo $user->getUser($json);
      break;
    case "updateUser":
      echo $user->updateUser($json);
      break;
    case "deleteUser":
      echo $user->deleteUser($json);
      break;
    case "login":
      echo $user->login($json);
      break;
  }
?>
