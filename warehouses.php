<?php
  header('Content-Type: application/json');
  header("Access-Control-Allow-Origin: *");

  class Warehouse {
    function getAllWarehouses(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM warehouses ORDER BY warehouse_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Warehouses retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertWarehouse($json){
      include "connection-pdo.php";

      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['warehouse_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: warehouse_name is required'
          ]);
          return;
        }

        // Default to active if not provided
        $isActive = isset($json['is_active']) ? (int)!!$json['is_active'] : 1;

        $sql = "INSERT INTO warehouses(warehouse_name, location, is_active) 
                VALUES(:warehouseName, :warehouseLocation, :isActive)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":warehouseName", $json['warehouse_name']);
        $stmt->bindParam(":warehouseLocation", $json['location']);
        $stmt->bindParam(":isActive", $isActive);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          $newId = $conn->lastInsertId();
          echo json_encode([
            'status' => 'success',
            'message' => 'Warehouse created successfully',
            'data' => [
              'warehouse_id' => $newId,
              'warehouse_name' => $json['warehouse_name'],
              'location' => $json['location'],
              'is_active' => $isActive
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create warehouse'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function getWarehouse($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['warehouse_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: warehouse_id is required'
          ]);
          return;
        }

        $sql = "SELECT * FROM warehouses WHERE warehouse_id = :warehouseId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":warehouseId", $json['warehouse_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'Warehouse retrieved successfully',
            'data' => $rs[0]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Warehouse not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function updateWarehouse($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['warehouse_id']) || empty($json['warehouse_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: warehouse_id and warehouse_name are required'
          ]);
          return;
        }

        // Coerce is_active if provided; if not, preserve current value
        $hasIsActive = array_key_exists('is_active', $json);
        $isActive = $hasIsActive ? (int)!!$json['is_active'] : null;

        if($hasIsActive){
          $sql = "UPDATE warehouses SET warehouse_name = :warehouseName, location = :warehouseLocation, 
                  is_active = :isActive WHERE warehouse_id = :warehouseId";
        } else {
          $sql = "UPDATE warehouses SET warehouse_name = :warehouseName, location = :warehouseLocation
                  WHERE warehouse_id = :warehouseId";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":warehouseName", $json['warehouse_name']);
        $stmt->bindParam(":warehouseLocation", $json['location']);
        if($hasIsActive){ $stmt->bindParam(":isActive", $isActive); }
        $stmt->bindParam(":warehouseId", $json['warehouse_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          // Return current record including is_active
          $stmt2 = $conn->prepare("SELECT warehouse_id, warehouse_name, location, is_active FROM warehouses WHERE warehouse_id = :warehouseId");
          $stmt2->bindParam(":warehouseId", $json['warehouse_id']);
          $stmt2->execute();
          $row = $stmt2->fetch(PDO::FETCH_ASSOC);

          echo json_encode([
            'status' => 'success',
            'message' => 'Warehouse updated successfully',
            'data' => $row
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Warehouse not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function deleteWarehouse($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['warehouse_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: warehouse_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM warehouses WHERE warehouse_id = :warehouseId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":warehouseId", $json['warehouse_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Warehouse deleted successfully',
            'data' => [
              'warehouse_id' => $json['warehouse_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Warehouse not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function getActiveWarehouses(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM warehouses WHERE is_active = 1 ORDER BY warehouse_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Active warehouses retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function toggleWarehouseStatus($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['warehouse_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: warehouse_id is required'
          ]);
          return;
        }

        $sql = "UPDATE warehouses SET is_active = NOT is_active WHERE warehouse_id = :warehouseId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":warehouseId", $json['warehouse_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Warehouse status toggled successfully',
            'data' => [
              'warehouse_id' => $json['warehouse_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Warehouse not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function checkWarehouseName($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['warehouse_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: warehouse_name is required'
          ]);
          return;
        }

        $sql = "SELECT COUNT(*) as count FROM warehouses WHERE warehouse_name = :warehouseName";
        if(isset($json['warehouse_id'])){
          $sql .= " AND warehouse_id != :warehouseId";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":warehouseName", $json['warehouse_name']);
        if(isset($json['warehouse_id'])){
          $stmt->bindParam(":warehouseId", $json['warehouse_id']);
        }
        $stmt->execute();
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Warehouse name check completed',
          'data' => [
            'exists' => $rs['count'] > 0,
            'warehouse_name' => $json['warehouse_name']
          ]
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
    // Prefer POST params, then URL params, then raw JSON body
    if(isset($_POST['operation'])){
      $operation = $_POST['operation'];
    } else if(isset($_GET['operation'])){
      $operation = $_GET['operation'];
    } else {
      $operation = '';
    }

    if(isset($_POST['json'])){
      $json = $_POST['json'];
    } else if(isset($_GET['json'])){
      $json = $_GET['json'];
    } else {
      // Fallback to raw body
      $json = file_get_contents('php://input');
    }
  }

  $warehouse = new Warehouse();
  switch($operation){
    case "getAllWarehouses":
      echo $warehouse->getAllWarehouses();
      break;
    case "insertWarehouse":
      echo $warehouse->insertWarehouse($json);
      break;
    case "getWarehouse":
      echo $warehouse->getWarehouse($json);
      break;
    case "updateWarehouse":
      echo $warehouse->updateWarehouse($json);
      break;
    case "deleteWarehouse":
      echo $warehouse->deleteWarehouse($json);
      break;
    case "getActiveWarehouses":
      echo $warehouse->getActiveWarehouses();
      break;
    case "toggleWarehouseStatus":
      echo $warehouse->toggleWarehouseStatus($json);
      break;
    case "checkWarehouseName":
      echo $warehouse->checkWarehouseName($json);
      break;
  }
?>
