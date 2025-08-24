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

class Warehouse {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    function getAllWarehouses(){
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM warehouses ORDER BY created_at DESC";
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
        $conn->beginTransaction();

        try {
            if(empty($json['warehouse_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_name is required'
                ]);
                return;
            }

            $warehouseId = $this->generateUuid();
            $isActive = isset($json['is_active']) ? $json['is_active'] : true;
            $address = isset($json['address']) ? $json['address'] : null;
            $isMain = isset($json['is_main']) ? $json['is_main'] : false;

            // If setting as main warehouse, ensure only one main warehouse exists
            if ($isMain) {
                $sqlResetMain = "UPDATE warehouses SET is_main = FALSE WHERE is_main = TRUE";
                $stmtResetMain = $conn->prepare($sqlResetMain);
                $stmtResetMain->execute();
            }

            // Insert into warehouses table
            $sql = "INSERT INTO warehouses(warehouse_id, warehouse_name, address, is_active, is_main) 
                    VALUES(:warehouseId, :warehouseName, :address, :isActive, :isMain)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseId", $warehouseId);
            $stmt->bindParam(":warehouseName", $json['warehouse_name']);
            $stmt->bindParam(":address", $address);
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->bindParam(":isMain", $isMain, PDO::PARAM_BOOL);
            $stmt->execute();

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse created successfully',
                'data' => [
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $json['warehouse_name'],
                    'address' => $address,
                    'is_active' => $isActive,
                    'is_main' => $isMain
                ]
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            if($e->getCode() == 23000) { 
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function getWarehouse($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
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
        $conn->beginTransaction();
        
        try {
            if(empty($json['warehouse_id']) || empty($json['warehouse_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: warehouse_id and warehouse_name are required'
                ]);
                return;
            }

            $isActive = isset($json['is_active']) ? $json['is_active'] : true;
            $address = isset($json['address']) ? $json['address'] : null;
            $isMain = isset($json['is_main']) ? $json['is_main'] : false;

            // If setting as main warehouse, ensure only one main warehouse exists
            if ($isMain) {
                $sqlResetMain = "UPDATE warehouses SET is_main = FALSE WHERE is_main = TRUE AND warehouse_id != :warehouseId";
                $stmtResetMain = $conn->prepare($sqlResetMain);
                $stmtResetMain->bindParam(":warehouseId", $json['warehouse_id']);
                $stmtResetMain->execute();
            }

            // Update warehouses table
            $sql = "UPDATE warehouses SET warehouse_name = :warehouseName, address = :address, 
                    is_active = :isActive, is_main = :isMain 
                    WHERE warehouse_id = :warehouseId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseName", $json['warehouse_name']);
            $stmt->bindParam(":address", $address);
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->bindParam(":isMain", $isMain, PDO::PARAM_BOOL);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->execute();

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse updated successfully',
                'data' => [
                    'warehouse_id' => $json['warehouse_id'],
                    'warehouse_name' => $json['warehouse_name'],
                    'address' => $address,
                    'is_active' => $isActive,
                    'is_main' => $isMain
                ]
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            if($e->getCode() == 23000) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function deleteWarehouse($json){
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id is required'
                ]);
                return;
            }

            // Delete from warehouses
            $sql = "DELETE FROM warehouses WHERE warehouse_id = :warehouseId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0){
                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Warehouse deleted successfully',
                    'data' => [
                        'warehouse_id' => $json['warehouse_id']
                    ]
                ]);
            } else {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse not found'
                ]);
            }
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function searchWarehouses($json) {
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
            $sql = "SELECT * FROM warehouses 
                    WHERE warehouse_name LIKE :searchTerm OR address LIKE :searchTerm
                    ORDER BY warehouse_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouses search completed',
                'data' => $rs
            ]);
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

    function getActiveWarehouses(){
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM warehouses WHERE is_active = 1 ORDER BY warehouse_name ASC";
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

    function getMainWarehouse(){
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM warehouses WHERE is_main = TRUE LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(count($rs) > 0) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Main warehouse retrieved successfully',
                    'data' => $rs[0]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No main warehouse found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function setMainWarehouse($json){
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if(empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id is required'
                ]);
                return;
            }

            // First, reset all main warehouses
            $sqlReset = "UPDATE warehouses SET is_main = FALSE";
            $stmtReset = $conn->prepare($sqlReset);
            $stmtReset->execute();

            // Set the specified warehouse as main
            $sqlSetMain = "UPDATE warehouses SET is_main = TRUE WHERE warehouse_id = :warehouseId";
            $stmtSetMain = $conn->prepare($sqlSetMain);
            $stmtSetMain->bindParam(":warehouseId", $json['warehouse_id']);
            $stmtSetMain->execute();

            if($stmtSetMain->rowCount() > 0) {
                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Main warehouse set successfully',
                    'data' => [
                        'warehouse_id' => $json['warehouse_id']
                    ]
                ]);
            } else {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse not found'
                ]);
            }
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getUnassignedWarehouses() {
    include "connection-pdo.php";

    try {
        $sql = "SELECT w.* 
                FROM warehouses w 
                WHERE w.is_active = TRUE
                AND w.warehouse_id NOT IN (
                    SELECT DISTINCT aw.warehouse_id 
                    FROM assign_warehouse aw
                    INNER JOIN users u ON aw.user_id = u.user_id
                    INNER JOIN roles r ON u.role_id = r.role_id
                    WHERE aw.is_active = TRUE
                    AND u.is_active = TRUE
                    AND r.role_name = 'warehouse_manager'
                )
                ORDER BY w.warehouse_name";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rs) > 0) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Unassigned warehouses retrieved successfully',
                'data' => $rs
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => 'No unassigned warehouses found',
                'data' => []
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

$warehouse = new Warehouse();
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
    case "insertWarehouse":
        echo $warehouse->insertWarehouse($data);
        break;
    case "updateWarehouse":
        echo $warehouse->updateWarehouse($data);
        break;
    case "getAllWarehouses":
        echo $warehouse->getAllWarehouses();
        break;
    case "getWarehouse":
        $json = $_GET['json'] ?? '{}';
        echo $warehouse->getWarehouse($json);
        break;
    case "deleteWarehouse":
        $json = $_GET['json'] ?? '{}';
        echo $warehouse->deleteWarehouse($json);
        break;
    case "searchWarehouses":
        $json = $_GET['json'] ?? '{}';
        echo $warehouse->searchWarehouses($json);
        break;
    case "checkWarehouseName":
        $json = $_GET['json'] ?? '{}';
        echo $warehouse->checkWarehouseName($json);
        break;
    case "getActiveWarehouses":
        echo $warehouse->getActiveWarehouses();
        break;
    case "getMainWarehouse":
        echo $warehouse->getMainWarehouse();
        break;
    case "setMainWarehouse":
        echo $warehouse->setMainWarehouse($data);
        break;
    case "getUnassignedWarehouses":
      echo $warehouse->getUnassignedWarehouses();
      break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation',
            'available_operations' => [
                'insertWarehouse', 
                'updateWarehouse', 
                'getAllWarehouses', 
                'getWarehouse', 
                'deleteWarehouse', 
                'searchWarehouses', 
                'checkWarehouseName',
                'getActiveWarehouses',
                'getMainWarehouse',
                'setMainWarehouse'
            ]
        ]);
}
?>