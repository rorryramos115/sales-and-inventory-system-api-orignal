<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

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

    // Create a new warehouse
    function createWarehouse($data) {
        include "connection-pdo.php";
        
        try {
            if (empty($data['location_name'])) {
                throw new Exception("Warehouse name is required");
            }

            // Generate UUID for warehouse
            $locationId = $this->generateUuid();

            // Check if warehouse name already exists
            $checkSql = "SELECT COUNT(*) as count FROM locations WHERE location_name = :locationName AND location_type = 'warehouse'";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":locationName", $data['location_name']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Warehouse name already exists");
            }

            // Prepare data
            $address = $data['address'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Insert warehouse
            $sql = "INSERT INTO locations(
                        location_id, location_type, location_name, address, is_active
                    ) VALUES(
                        :locationId, 'warehouse', :locationName, :address, :isActive
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $locationId);
            $stmt->bindValue(":locationName", $data['location_name']);
            $stmt->bindValue(":address", $address);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create warehouse");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Warehouse created successfully',
                'data' => [
                    'location_id' => $locationId,
                    'location_name' => $data['location_name'],
                    'address' => $address,
                    'is_active' => $isActive,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Update warehouse
    function updateWarehouse($data) {
        include "connection-pdo.php";
        
        try {
            // Validate required fields
            if (empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            if (empty($data['location_name'])) {
                throw new Exception("Warehouse name is required");
            }

            // Check if warehouse exists
            $warehouseCheck = "SELECT COUNT(*) as count FROM locations WHERE location_id = :locationId AND location_type = 'warehouse'";
            $warehouseStmt = $conn->prepare($warehouseCheck);
            $warehouseStmt->bindValue(":locationId", $data['location_id']);
            $warehouseStmt->execute();
            $warehouseResult = $warehouseStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($warehouseResult['count'] == 0) {
                throw new Exception("Warehouse not found");
            }

            // Check if warehouse name already exists (excluding current warehouse)
            $checkSql = "SELECT COUNT(*) as count FROM locations 
                        WHERE location_name = :locationName 
                        AND location_type = 'warehouse' 
                        AND location_id != :locationId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":locationName", $data['location_name']);
            $checkStmt->bindValue(":locationId", $data['location_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Warehouse name already exists");
            }

            // Prepare data
            $address = $data['address'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Update warehouse
            $sql = "UPDATE locations SET
                        location_name = :locationName,
                        address = :address,
                        is_active = :isActive,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE location_id = :locationId AND location_type = 'warehouse'";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationName", $data['location_name']);
            $stmt->bindValue(":address", $address);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":locationId", $data['location_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update warehouse");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Warehouse updated successfully',
                'data' => [
                    'location_id' => $data['location_id'],
                    'location_name' => $data['location_name'],
                    'address' => $address,
                    'is_active' => $isActive,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get all warehouses
    function getAllWarehouses() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'warehouse' 
                    ORDER BY location_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouses retrieved successfully',
                'data' => $warehouses
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get active warehouses only
    function getActiveWarehouses() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'warehouse' 
                    AND is_active = 1 
                    ORDER BY location_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active warehouses retrieved successfully',
                'data' => $warehouses
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get a single warehouse
    function getWarehouse($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['location_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: location_id'
                ]);
                return;
            }

            $sql = "SELECT * FROM locations 
                    WHERE location_id = :locationId 
                    AND location_type = 'warehouse'";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $json['location_id']);
            $stmt->execute();
            $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);

            if($warehouse) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Warehouse retrieved successfully',
                    'data' => $warehouse
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

    // Delete a warehouse
    function deleteWarehouse($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['location_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: location_id'
                ]);
                return;
            }

            $locationId = $data['location_id'];

            // First check if warehouse exists
            $checkSql = "SELECT COUNT(*) as count FROM locations 
                        WHERE location_id = :locationId AND location_type = 'warehouse'";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindParam(":locationId", $locationId);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse not found'
                ]);
                return;
            }

            // Check if warehouse has any stock (if you have warehouse_stock table)
            // Uncomment and modify this section if you have related tables
            /*
            $stockCheck = "SELECT COUNT(*) as count FROM warehouse_stock 
                          WHERE warehouse_id = :locationId";
            $stockStmt = $conn->prepare($stockCheck);
            $stockStmt->bindParam(":locationId", $locationId);
            $stockStmt->execute();
            $stockResult = $stockStmt->fetch(PDO::FETCH_ASSOC);
            
            if($stockResult['count'] > 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Cannot delete warehouse with existing stock'
                ]);
                return;
            }
            */

            // Delete the warehouse
            $sql = "DELETE FROM locations WHERE location_id = :locationId AND location_type = 'warehouse'";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":locationId", $locationId);
            $stmt->execute();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse deleted successfully',
                'data' => [
                    'location_id' => $locationId
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Check if warehouse name exists
    function checkWarehouseName($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['location_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: location_name'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM locations 
                    WHERE location_name = :locationName AND location_type = 'warehouse'";
            $params = [':locationName' => $json['location_name']];
            
            if(isset($json['location_id'])) {
                $sql .= " AND location_id != :locationId";
                $params[':locationId'] = $json['location_id'];
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
                    'location_name' => $json['location_name']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search warehouses
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
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'warehouse' 
                    AND (location_name LIKE :searchTerm OR address LIKE :searchTerm)
                    ORDER BY location_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouses search completed',
                'data' => $warehouses
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get warehouse stock (if you have warehouse_stock table)
    function getWarehouseStock($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            // Verify warehouse exists
            $warehouseCheck = "SELECT COUNT(*) as count FROM locations 
                              WHERE location_id = :locationId AND location_type = 'warehouse'";
            $stmt = $conn->prepare($warehouseCheck);
            $stmt->bindValue(":locationId", $data['location_id']);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                throw new Exception("Warehouse not found");
            }

            // Get stock data - modify this query based on your stock table structure
            $sql = "SELECT ws.*, p.product_name, p.barcode, p.description
                    FROM warehouse_stock ws
                    JOIN products p ON ws.product_id = p.product_id
                    WHERE ws.warehouse_id = :locationId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $data['location_id']);
            $stmt->execute();
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse stock retrieved',
                'data' => $stock
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get warehouse statistics
    function getWarehouseStats() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        COUNT(*) as total_warehouses,
                        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_warehouses,
                        COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_warehouses
                    FROM locations 
                    WHERE location_type = 'warehouse'";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse statistics retrieved',
                'data' => $stats
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

$warehouse = new Warehouse();

if ($operation === "createWarehouse") {
    echo $warehouse->createWarehouse($data);
} elseif ($operation === "updateWarehouse") {
    echo $warehouse->updateWarehouse($data);
} else {
    // Handle other operations
    switch($operation) {
        case "getAllWarehouses":
            $warehouse->getAllWarehouses();
            break;
        case "getActiveWarehouses":
            $warehouse->getActiveWarehouses();
            break;
        case "getWarehouse":
            $warehouse->getWarehouse($json);
            break;
        case "deleteWarehouse":
            $warehouse->deleteWarehouse($json);
            break;
        case "checkWarehouseName":
            $warehouse->checkWarehouseName($json);
            break;
        case "searchWarehouses":
            $warehouse->searchWarehouses($json);
            break;
        case "getWarehouseStock":
            $warehouse->getWarehouseStock($json);
            break;
        case "getWarehouseStats":
            $warehouse->getWarehouseStats();
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>