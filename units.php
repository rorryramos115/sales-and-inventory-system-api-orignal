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

  class Unit {
     private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    function getAllUnits(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM units ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Units retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertUnit($json){
        include "connection-pdo.php";
         $conn->beginTransaction();

        try {
            
            if(empty($json['unit_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: unit_name is required'
                ]);
                return;
            }
            $unitId = $this->generateUuid();
            $isActive = isset($json['is_active']) ? $json['is_active'] : true;

            $sql = "INSERT INTO units(unit_id, unit_name, is_active) VALUES(:unitId, :unitName, :isActive)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":unitId", $unitId);
            $stmt->bindParam(":unitName", $json['unit_name']);
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->execute();

             $conn->commit(); 

            if($stmt->rowCount() > 0){
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Unit created successfully',
                    'data' => [
                        'unit_id' => $unitId,
                        'unit_name' => $json['unit_name'],
                        'is_active' => $isActive
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to create unit'
                ]);
            }
        } catch (PDOException $e) {
            $conn->rollback();
            if($e->getCode() == 23000) { 
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Unit name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function getUnit($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['unit_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: unit_id is required'
          ]);
          return;
        }

        $sql = "SELECT * FROM units WHERE unit_id = :unitId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":unitId", $json['unit_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'Unit retrieved successfully',
            'data' => $rs[0]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Unit not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function updateUnit($json){
      include "connection-pdo.php";
        $conn->beginTransaction();
      
      try {
        
        // Validate required fields
        if(empty($json['unit_id']) || empty($json['unit_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: unit_id and unit_name are required'
          ]);
          return;
        }

        $isActive = isset($json['is_active']) ? $json['is_active'] : true;

        $sql = "UPDATE units SET unit_name = :unitName, is_active = :isActive WHERE unit_id = :unitId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":unitName", $json['unit_name']);
        $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
        $stmt->bindParam(":unitId", $json['unit_id']);
        $stmt->execute();

          $conn->commit(); 

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Unit updated successfully',
            'data' => [
              'unit_id' => $json['unit_id'],
              'unit_name' => $json['unit_name'],
              'is_active' => $isActive
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Unit not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        $conn->rollback();
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Unit name already exists. Please use a different name.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function deleteUnit($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['unit_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: unit_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM units WHERE unit_id = :unitId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":unitId", $json['unit_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Unit deleted successfully',
            'data' => [
              'unit_id' => $json['unit_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Unit not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function searchUnits($json) {
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
          $sql = "SELECT * FROM units 
                  WHERE unit_name LIKE :searchTerm
                  ORDER BY unit_name
                  LIMIT 20";
          
          $stmt = $conn->prepare($sql);
          $stmt->bindValue(":searchTerm", $searchTerm);
          $stmt->execute();
          $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode([
              'status' => 'success',
              'message' => 'Units search completed',
              'data' => $rs
          ]);
      } catch (PDOException $e) {
          echo json_encode([
              'status' => 'error',
              'message' => 'Database error: ' . $e->getMessage()
          ]);
      }
  }

    function checkUnitName($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['unit_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: unit_name is required'
          ]);
          return;
        }

        $sql = "SELECT COUNT(*) as count FROM units WHERE unit_name = :unitName";
        if(isset($json['unit_id'])){
          $sql .= " AND unit_id != :unitId";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":unitName", $json['unit_name']);
        if(isset($json['unit_id'])){
          $stmt->bindParam(":unitId", $json['unit_id']);
        }
        $stmt->execute();
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Unit name check completed',
          'data' => [
            'exists' => $rs['count'] > 0,
            'unit_name' => $json['unit_name']
          ]
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function getActiveUnits(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM units WHERE is_active = 1 ORDER BY unit_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Active units retrieved successfully',
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



$unit = new Unit();
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

switch($operation){
  case "insertUnit":
    echo $unit->insertUnit($data);
    break;
  case "updateUnit":
    echo $unit->updateUnit($data);
    break;
  case "getAllUnits":
    echo $unit->getAllUnits();
    break;
  case "getUnit":
    $json = $_GET['json'] ?? '{}';
    echo $unit->getUnit($json);
    break;
  case "deleteUnit":
    $json = $_GET['json'] ?? '{}';
    echo $unit->deleteUnit($json);
    break;
  case "searchUnits":
    $json = $_GET['json'] ?? '{}';
    echo $unit->searchUnits($json);
    break;
  case "checkUnitName":
    $json = $_GET['json'] ?? '{}';
    echo $unit->checkUnitName($json);
    break;
  case "getActiveUnits":
    echo $unit->getActiveUnits();
    break;
  default:
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid operation',
        'available_operations' => [
            'insertUnit', 
            'updateUnit', 
            'getAllUnits', 
            'getUnit', 
            'deleteUnit', 
            'searchUnits', 
            'checkUnitName',
            'getActiveUnits'
        ]
    ]);
}
?>