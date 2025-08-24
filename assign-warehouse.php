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

class AssignWarehouse {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    function getAllAssignments(){
        include "connection-pdo.php";

        try {
            $sql = "SELECT aw.assign_id, aw.user_id, aw.warehouse_id, aw.assigned_date, aw.is_active,
                    u.full_name, u.email, u.role_id,
                    r.role_name,
                    w.warehouse_name, w.address
            FROM assign_warehouse aw
            INNER JOIN users u ON aw.user_id = u.user_id
            INNER JOIN roles r ON u.role_id = r.role_id
            INNER JOIN warehouses w ON aw.warehouse_id = w.warehouse_id
            ORDER BY aw.assigned_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse assignments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getAssignment($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['assign_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: assign_id is required'
                ]);
                return;
            }

            $sql = "SELECT aw.assign_id, aw.user_id, aw.warehouse_id, aw.assigned_date, aw.is_active,
                    u.full_name, u.email,
                    w.warehouse_name, w.address
            FROM assign_warehouse aw
            INNER JOIN users u ON aw.user_id = u.user_id
            INNER JOIN warehouses w ON aw.warehouse_id = w.warehouse_id
            WHERE aw.assign_id = :assignId";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":assignId", $json['assign_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(count($rs) > 0) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Assignment retrieved successfully',
                    'data' => $rs[0]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Assignment not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function insertAssignment($json){
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if(empty($json['user_id']) || empty($json['warehouse_id']) || empty($json['assigned_date'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: user_id, warehouse_id, and assigned_date are required'
                ]);
                return;
            }

            // Check if assignment already exists
            $checkSql = "SELECT COUNT(*) as count FROM assign_warehouse 
                         WHERE user_id = :userId AND warehouse_id = :warehouseId AND is_active = TRUE";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindParam(":userId", $json['user_id']);
            $checkStmt->bindParam(":warehouseId", $json['warehouse_id']);
            $checkStmt->execute();
            $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($checkResult['count'] > 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User is already assigned to this warehouse'
                ]);
                return;
            }

            $assignId = $this->generateUuid();
            $isActive = isset($json['is_active']) ? $json['is_active'] : true;

            $sql = "INSERT INTO assign_warehouse(assign_id, user_id, warehouse_id, assigned_date, is_active) 
                    VALUES(:assignId, :userId, :warehouseId, :assignedDate, :isActive)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":assignId", $assignId);
            $stmt->bindParam(":userId", $json['user_id']);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->bindParam(":assignedDate", $json['assigned_date']);
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->execute();

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse assignment created successfully',
                'data' => [
                    'assign_id' => $assignId,
                    'user_id' => $json['user_id'],
                    'warehouse_id' => $json['warehouse_id'],
                    'assigned_date' => $json['assigned_date'],
                    'is_active' => $isActive
                ]
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function updateAssignment($json){
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            if(empty($json['assign_id']) || empty($json['assigned_date'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: assign_id and assigned_date are required'
                ]);
                return;
            }

            $isActive = isset($json['is_active']) ? $json['is_active'] : true;

            $sql = "UPDATE assign_warehouse 
                    SET assigned_date = :assignedDate, is_active = :isActive 
                    WHERE assign_id = :assignId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":assignedDate", $json['assigned_date']);
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->bindParam(":assignId", $json['assign_id']);
            $stmt->execute();

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Assignment updated successfully',
                'data' => [
                    'assign_id' => $json['assign_id'],
                    'assigned_date' => $json['assigned_date'],
                    'is_active' => $isActive
                ]
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function deleteAssignment($json){
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['assign_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: assign_id is required'
                ]);
                return;
            }

            $sql = "DELETE FROM assign_warehouse WHERE assign_id = :assignId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":assignId", $json['assign_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0){
                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Assignment deleted successfully',
                    'data' => [
                        'assign_id' => $json['assign_id']
                    ]
                ]);
            } else {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Assignment not found'
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

    function getUserAssignments($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id is required'
                ]);
                return;
            }

            $sql = "SELECT aw.assign_id, aw.user_id, aw.warehouse_id, aw.assigned_date, aw.is_active,
                    w.warehouse_name, w.address, w.is_main
            FROM assign_warehouse aw
            INNER JOIN warehouses w ON aw.warehouse_id = w.warehouse_id
            WHERE aw.user_id = :userId
            ORDER BY aw.assigned_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":userId", $json['user_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'User warehouse assignments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getWarehouseAssignments($json){
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

            $sql = "SELECT aw.assign_id, aw.user_id, aw.warehouse_id, aw.assigned_date, aw.is_active,
                    u.full_name, u.email, u.phone,
                    r.role_name
            FROM assign_warehouse aw
            INNER JOIN users u ON aw.user_id = u.user_id
            INNER JOIN roles r ON u.role_id = r.role_id
            WHERE aw.warehouse_id = :warehouseId
            ORDER BY u.full_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse user assignments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getActiveUserAssignments($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id is required'
                ]);
                return;
            }

            $sql = "SELECT aw.assign_id, aw.user_id, aw.warehouse_id, aw.assigned_date, aw.is_active,
                    w.warehouse_name, w.address, w.is_main
            FROM assign_warehouse aw
            INNER JOIN warehouses w ON aw.warehouse_id = w.warehouse_id
            WHERE aw.user_id = :userId AND aw.is_active = TRUE AND w.is_active = TRUE
            ORDER BY w.warehouse_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":userId", $json['user_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active user warehouse assignments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function toggleAssignmentStatus($json){
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            if(empty($json['assign_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: assign_id is required'
                ]);
                return;
            }

            // Get current status
            $getSql = "SELECT is_active FROM assign_warehouse WHERE assign_id = :assignId";
            $getStmt = $conn->prepare($getSql);
            $getStmt->bindParam(":assignId", $json['assign_id']);
            $getStmt->execute();
            $current = $getStmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Assignment not found'
                ]);
                return;
            }

            $newStatus = !$current['is_active'];

            $updateSql = "UPDATE assign_warehouse SET is_active = :isActive WHERE assign_id = :assignId";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bindParam(":isActive", $newStatus, PDO::PARAM_BOOL);
            $updateStmt->bindParam(":assignId", $json['assign_id']);
            $updateStmt->execute();

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Assignment status updated successfully',
                'data' => [
                    'assign_id' => $json['assign_id'],
                    'is_active' => $newStatus
                ]
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function checkExistingAssignment($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id']) || empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: user_id and warehouse_id are required'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM assign_warehouse 
                    WHERE user_id = :userId AND warehouse_id = :warehouseId";
            
            if(isset($json['assign_id'])){
                $sql .= " AND assign_id != :assignId";
            }

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":userId", $json['user_id']);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            
            if(isset($json['assign_id'])){
                $stmt->bindParam(":assignId", $json['assign_id']);
            }
            
            $stmt->execute();
            $rs = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Assignment check completed',
                'data' => [
                    'exists' => $rs['count'] > 0,
                    'user_id' => $json['user_id'],
                    'warehouse_id' => $json['warehouse_id']
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

$assignWarehouse = new AssignWarehouse();
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
    case "getAllAssignments":
        echo $assignWarehouse->getAllAssignments();
        break;
    case "getAssignment":
        $json = $_GET['json'] ?? '{}';
        echo $assignWarehouse->getAssignment($json);
        break;
    case "insertAssignment":
        echo $assignWarehouse->insertAssignment($data);
        break;
    case "updateAssignment":
        echo $assignWarehouse->updateAssignment($data);
        break;
    case "deleteAssignment":
        $json = $_GET['json'] ?? '{}';
        echo $assignWarehouse->deleteAssignment($json);
        break;
    case "getUserAssignments":
        $json = $_GET['json'] ?? '{}';
        echo $assignWarehouse->getUserAssignments($json);
        break;
    case "getWarehouseAssignments":
        $json = $_GET['json'] ?? '{}';
        echo $assignWarehouse->getWarehouseAssignments($json);
        break;
    case "getActiveUserAssignments":
        $json = $_GET['json'] ?? '{}';
        echo $assignWarehouse->getActiveUserAssignments($json);
        break;
    case "toggleAssignmentStatus":
        echo $assignWarehouse->toggleAssignmentStatus($data);
        break;
    case "checkExistingAssignment":
        $json = $_GET['json'] ?? '{}';
        echo $assignWarehouse->checkExistingAssignment($json);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation',
            'available_operations' => [
                'getAllAssignments',
                'getAssignment',
                'insertAssignment',
                'updateAssignment',
                'deleteAssignment',
                'getUserAssignments',
                'getWarehouseAssignments',
                'getActiveUserAssignments',
                'toggleAssignmentStatus',
                'checkExistingAssignment'
            ]
        ]);
}
?>