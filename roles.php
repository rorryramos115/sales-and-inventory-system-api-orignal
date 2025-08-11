<?php
  header('Content-Type: application/json');
  header("Access-Control-Allow-Origin: *");

  class Role {
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

    function getAllRoles(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM roles ORDER BY role_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Roles retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertRole($json){
    include "connection-pdo.php";

      try {
          $json = json_decode($json, true);
          
          // Validate required fields
          if(empty($json['role_name'])) {
              echo json_encode([
                  'status' => 'error',
                  'message' => 'Missing required field: role_name is required'
              ]);
              return;
          }

          // Generate UUID
          $uuid = $this->generateUUID();

          $sql = "INSERT INTO roles(role_id, role_name, description) VALUES(:roleId, :roleName, :description)";
          $stmt = $conn->prepare($sql);
          $stmt->bindParam(":roleId", $uuid);
          $stmt->bindParam(":roleName", $json['role_name']);
          $stmt->bindParam(":description", $json['description']);
          $stmt->execute();

          if($stmt->rowCount() > 0){
              echo json_encode([
                  'status' => 'success',
                  'message' => 'Role created successfully',
                  'data' => [
                      'role_id' => $uuid,
                      'role_name' => $json['role_name'],
                      'description' => $json['description']
                  ]
              ]);
          } else {
              echo json_encode([
                  'status' => 'error',
                  'message' => 'Failed to create role'
              ]);
          }
      } catch (PDOException $e) {
          if($e->getCode() == 23000) { // Duplicate entry
              echo json_encode([
                  'status' => 'error',
                  'message' => 'Role name already exists. Please use a different role name.'
              ]);
          } else {
              echo json_encode([
                  'status' => 'error',
                  'message' => 'Database error: ' . $e->getMessage()
              ]);
          }
      }
  }


    function getRole($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['role_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: role_id is required'
          ]);
          return;
        }

        $sql = "SELECT * FROM roles WHERE role_id = :roleId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":roleId", $json['role_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'Role retrieved successfully',
            'data' => $rs[0]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Role not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function updateRole($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['role_id']) || empty($json['role_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: role_id and role_name are required'
          ]);
          return;
        }

        $sql = "UPDATE roles SET role_name = :roleName, description = :description WHERE role_id = :roleId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":roleName", $json['role_name']);
        $stmt->bindParam(":description", $json['description']);
        $stmt->bindParam(":roleId", $json['role_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Role updated successfully',
            'data' => [
              'role_id' => $json['role_id'],
              'role_name' => $json['role_name'],
              'description' => $json['description']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Role not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Role name already exists. Please use a different role name.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function deleteRole($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['role_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: role_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM roles WHERE role_id = :roleId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":roleId", $json['role_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Role deleted successfully',
            'data' => [
              'role_id' => $json['role_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Role not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function getRolesForDropdown(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT role_id, role_name FROM roles ORDER BY role_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Roles for dropdown retrieved successfully',
          'data' => $rs
        ]);
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

  $role = new Role();
  switch($operation){
    case "getAllRoles":
      echo $role->getAllRoles();
      break;
    case "insertRole":
      echo $role->insertRole($json);
      break;
    case "getRole":
      echo $role->getRole($json);
      break;
    case "updateRole":
      echo $role->updateRole($json);
      break;
    case "deleteRole":
      echo $role->deleteRole($json);
      break;
    case "getRolesForDropdown":
      echo $role->getRolesForDropdown();
      break;
  }
?>