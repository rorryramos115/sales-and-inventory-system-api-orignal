<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class Counter {
    function getAllCounters(){
        include "connection-pdo.php";

        try {
            $sql = "SELECT counter_id, counter_name, is_active, 
                    created_at, updated_at FROM counters ORDER BY counter_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Counters retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function insertCounter($json){
        include "connection-pdo.php";

        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['counter_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_name is required'
                ]);
                return;
            }

            $sql = "INSERT INTO counters(counter_name, is_active) 
                    VALUES(:counter_name, :isActive)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counter_name", $json['counter_name']);
            $isActive = isset($json['is_active']) ? $json['is_active'] : true;
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->execute();

            if($stmt->rowCount() > 0){
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Counter created successfully',
                    'data' => [
                        'counter_id' => $conn->lastInsertId(),
                        'counter_name' => $json['counter_name'],
                        'is_active' => $isActive
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to create counter'
                ]);
            }
        } catch (PDOException $e) {
            if($e->getCode() == 23000) { // Duplicate entry
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function getCounter($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['counter_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_id is required'
                ]);
                return;
            }

            $sql = "SELECT counter_id, counter_name, is_active, 
                    created_at, updated_at FROM counters WHERE counter_id = :counterId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counterId", $json['counter_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(count($rs) > 0) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Counter retrieved successfully',
                    'data' => $rs[0]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function updateCounter($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['counter_id']) || empty($json['counter_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: counter_id and counter_name are required'
                ]);
                return;
            }

            $sql = "UPDATE counters SET counter_name = :counterName, is_active = :isActive 
                    WHERE counter_id = :counterId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counterName", $json['counter_name']);
            $stmt->bindParam(":isActive", $json['is_active'], PDO::PARAM_BOOL);
            $stmt->bindParam(":counterId", $json['counter_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0){
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Counter updated successfully',
                    'data' => [
                        'counter_id' => $json['counter_id'],
                        'counter_name' => $json['counter_name'],
                        'is_active' => $json['is_active']
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter not found or no changes made'
                ]);
            }
        } catch (PDOException $e) {
            if($e->getCode() == 23000) { // Duplicate entry
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function deleteCounter($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['counter_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_id is required'
                ]);
                return;
            }

            $sql = "DELETE FROM counters WHERE counter_id = :counterId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counterId", $json['counter_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0){
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Counter deleted successfully',
                    'data' => [
                        'counter_id' => $json['counter_id']
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getActiveCounters(){
        include "connection-pdo.php";

        try {
            $sql = "SELECT counter_id, counter_name FROM counters 
                    WHERE is_active = 1 ORDER BY counter_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active counters retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function toggleCounterStatus($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['counter_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_id is required'
                ]);
                return;
            }

            $sql = "UPDATE counters SET is_active = NOT is_active WHERE counter_id = :counterId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counterId", $json['counter_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0){
                // Get the updated counter to return the new status
                $sql = "SELECT is_active FROM counters WHERE counter_id = :counterId";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(":counterId", $json['counter_id']);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'status' => 'success',
                    'message' => 'Counter status toggled successfully',
                    'data' => [
                        'counter_id' => $json['counter_id'],
                        'is_active' => $result['is_active']
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function checkCounterName($json){
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            // Validate required fields
            if(empty($json['counter_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_name is required'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM counters WHERE counter_name = :counterName";
            if(isset($json['counter_id'])){
                $sql .= " AND counter_id != :counterId";
            }
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counterName", $json['counter_name']);
            if(isset($json['counter_id'])){
                $stmt->bindParam(":counterId", $json['counter_id']);
            }
            $stmt->execute();
            $rs = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Counter name check completed',
                'data' => [
                    'exists' => $rs['count'] > 0
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

if ($_SERVER['REQUEST_METHOD'] == 'GET'){
    $operation = $_GET['operation'];
    $json = isset($_GET['json']) ? $_GET['json'] : "";
} else if($_SERVER['REQUEST_METHOD'] == 'POST'){
    // Check if operation is in POST data
    if(isset($_POST['operation'])){
        $operation = $_POST['operation'];
        $json = isset($_POST['json']) ? $_POST['json'] : "";
    } else {
        // Check if operation and json are in URL parameters (for POST requests)
        if(isset($_GET['operation'])) {
            $operation = $_GET['operation'];
            $json = isset($_GET['json']) ? $_GET['json'] : "";
        } else {
            // Handle JSON body for POST requests
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            // Get operation from URL parameter
            $operation = isset($_GET['operation']) ? $_GET['operation'] : '';
            $json = $input; // Use the raw JSON input
        }
    }
}

$counter = new Counter();
switch($operation){
    case "getAllCounters":
        $counter->getAllCounters();
        break;
    case "insertCounter":
        $counter->insertCounter($json);
        break;
    case "getCounter":
        $counter->getCounter($json);
        break;
    case "updateCounter":
        $counter->updateCounter($json);
        break;
    case "deleteCounter":
        $counter->deleteCounter($json);
        break;
    case "getActiveCounters":
        $counter->getActiveCounters();
        break;
    case "toggleCounterStatus":
        $counter->toggleCounterStatus($json);
        break;
    case "checkCounterName":
        $counter->checkCounterName($json);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation specified'
        ]);
        break;
}
?>