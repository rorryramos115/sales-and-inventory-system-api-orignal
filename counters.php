<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class Counter {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Create a new counter
    function createCounter($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            if (empty($data['counter_name'])) {
                throw new Exception("Counter name is required");
            }

            // Generate UUID for counter
            $counterId = $this->generateUuid();

            // Check if counter name already exists
            $checkSql = "SELECT COUNT(*) as count FROM counters WHERE counter_name = :counterName";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":counterName", $data['counter_name']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Counter name already exists");
            }

            // Prepare data
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Insert counter
            $sql = "INSERT INTO counters(
                        counter_id, counter_name, is_active
                    ) VALUES(
                        :counterId, :counterName, :isActive
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":counterId", $counterId);
            $stmt->bindValue(":counterName", $data['counter_name']);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create counter");
            }

            $responseData = [
                'counter_id' => $counterId,
                'counter_name' => $data['counter_name'],
                'is_active' => $isActive
            ];
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Counter created successfully',
                'data' => $responseData
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Update counter
    function updateCounter($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            if (empty($data['counter_id'])) {
                throw new Exception("Counter ID is required");
            }

            if (empty($data['counter_name'])) {
                throw new Exception("Counter name is required");
            }

            // Check if counter exists
            $counterCheck = "SELECT COUNT(*) as count FROM counters WHERE counter_id = :counterId";
            $counterStmt = $conn->prepare($counterCheck);
            $counterStmt->bindValue(":counterId", $data['counter_id']);
            $counterStmt->execute();
            $counterResult = $counterStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($counterResult['count'] == 0) {
                throw new Exception("Counter not found");
            }

            // Check if counter name already exists (excluding current counter)
            $checkSql = "SELECT COUNT(*) as count FROM counters 
                        WHERE counter_name = :counterName AND counter_id != :counterId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":counterName", $data['counter_name']);
            $checkStmt->bindValue(":counterId", $data['counter_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Counter name already exists");
            }

            // Prepare data
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Update counter
            $sql = "UPDATE counters SET
                        counter_name = :counterName,
                        is_active = :isActive,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE counter_id = :counterId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":counterName", $data['counter_name']);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":counterId", $data['counter_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update counter");
            }

            $responseData = [
                'counter_id' => $data['counter_id'],
                'counter_name' => $data['counter_name'],
                'is_active' => $isActive
            ];
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Counter updated successfully',
                'data' => $responseData
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get all counters
    function getAllCounters() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM counters ORDER BY counter_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $counters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Counters retrieved successfully',
                'data' => $counters
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get active counters only
    function getActiveCounters() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM counters WHERE is_active = 1 ORDER BY counter_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $counters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active counters retrieved successfully',
                'data' => $counters
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get a single counter
    function getCounter($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['counter_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_id'
                ]);
                return;
            }

            $sql = "SELECT * FROM counters WHERE counter_id = :counterId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":counterId", $json['counter_id']);
            $stmt->execute();
            $counter = $stmt->fetch(PDO::FETCH_ASSOC);

            if($counter) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Counter retrieved successfully',
                    'data' => $counter
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

    // Delete a counter
    function deleteCounter($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['counter_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_id'
                ]);
                return;
            }

            $counterId = $data['counter_id'];

            // First check if counter exists
            $checkSql = "SELECT COUNT(*) as count FROM counters WHERE counter_id = :counterId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindParam(":counterId", $counterId);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Counter not found'
                ]);
                return;
            }

            // Delete the counter
            $sql = "DELETE FROM counters WHERE counter_id = :counterId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":counterId", $counterId);
            $stmt->execute();

            $conn->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Counter deleted successfully',
                'data' => [
                    'counter_id' => $counterId
                ]
            ]);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Check if counter name exists
    function checkCounterName($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['counter_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: counter_name'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM counters WHERE counter_name = :counterName";
            $params = [':counterName' => $json['counter_name']];
            
            if(isset($json['counter_id'])) {
                $sql .= " AND counter_id != :counterId";
                $params[':counterId'] = $json['counter_id'];
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
                    'counter_name' => $json['counter_name']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search counters
    function searchCounters($json) {
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
            $sql = "SELECT * FROM counters 
                    WHERE counter_name LIKE :searchTerm
                    ORDER BY counter_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $counters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Counters search completed',
                'data' => $counters
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
}

// Handle the request
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

$counter = new Counter();

if ($operation === "createCounter") {
    echo $counter->createCounter($data);
} elseif ($operation === "updateCounter") {
    echo $counter->updateCounter($data);
} else {
    // Handle other operations
    switch($operation) {
        case "getAllCounters":
            $counter->getAllCounters();
            break;
        case "getActiveCounters":
            $counter->getActiveCounters();
            break;
        case "getCounter":
            $counter->getCounter($json);
            break;
        case "deleteCounter":
            $counter->deleteCounter($json);
            break;
        case "checkCounterName":
            $counter->checkCounterName($json);
            break;
        case "searchCounters":
            $counter->searchCounters($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>