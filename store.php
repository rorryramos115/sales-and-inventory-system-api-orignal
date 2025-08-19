<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class Store {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Create a new store
    function createStore($data) {
        include "connection-pdo.php";
        
        try {
            // Validate required fields
            if (empty($data['location_name'])) {
                throw new Exception("Store name is required");
            }

            // Generate UUID for store
            $locationId = $this->generateUuid();

            // Check if store name already exists
            $checkSql = "SELECT COUNT(*) as count FROM locations WHERE location_name = :locationName AND location_type = 'store'";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":locationName", $data['location_name']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Store name already exists");
            }

            // Prepare data
            $address = $data['address'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Insert store
            $sql = "INSERT INTO locations(
                        location_id, location_type, location_name, address, is_active
                    ) VALUES(
                        :locationId, 'store', :locationName, :address, :isActive
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $locationId);
            $stmt->bindValue(":locationName", $data['location_name']);
            $stmt->bindValue(":address", $address);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create store");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Store created successfully',
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

    // Update store
    function updateStore($data) {
        include "connection-pdo.php";
        
        try {
            // Validate required fields
            if (empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            if (empty($data['location_name'])) {
                throw new Exception("Store name is required");
            }

            // Check if store exists
            $storeCheck = "SELECT COUNT(*) as count FROM locations WHERE location_id = :locationId AND location_type = 'store'";
            $storeStmt = $conn->prepare($storeCheck);
            $storeStmt->bindValue(":locationId", $data['location_id']);
            $storeStmt->execute();
            $storeResult = $storeStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($storeResult['count'] == 0) {
                throw new Exception("Store not found");
            }

            // Check if store name already exists (excluding current store)
            $checkSql = "SELECT COUNT(*) as count FROM locations 
                        WHERE location_name = :locationName 
                        AND location_type = 'store' 
                        AND location_id != :locationId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":locationName", $data['location_name']);
            $checkStmt->bindValue(":locationId", $data['location_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Store name already exists");
            }

            // Prepare data
            $address = $data['address'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Update store
            $sql = "UPDATE locations SET
                        location_name = :locationName,
                        address = :address,
                        is_active = :isActive,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE location_id = :locationId AND location_type = 'store'";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationName", $data['location_name']);
            $stmt->bindValue(":address", $address);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":locationId", $data['location_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update store");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Store updated successfully',
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

    // Get all stores
    function getAllStores() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'store' 
                    ORDER BY location_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stores retrieved successfully',
                'data' => $stores
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get active stores only
    function getActiveStores() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'store' 
                    AND is_active = 1 
                    ORDER BY location_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active stores retrieved successfully',
                'data' => $stores
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get a single store
    function getStore($json) {
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
                    AND location_type = 'store'";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $json['location_id']);
            $stmt->execute();
            $store = $stmt->fetch(PDO::FETCH_ASSOC);

            if($store) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Store retrieved successfully',
                    'data' => $store
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Store not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Delete a store
    function deleteStore($json) {
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

            // First check if store exists
            $checkSql = "SELECT COUNT(*) as count FROM locations 
                        WHERE location_id = :locationId AND location_type = 'store'";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindParam(":locationId", $locationId);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Store not found'
                ]);
                return;
            }

            // Check if store has any inventory (if you have store_inventory table)
            // Uncomment and modify this section if you have related tables
            /*
            $inventoryCheck = "SELECT COUNT(*) as count FROM store_inventory 
                              WHERE store_id = :locationId";
            $inventoryStmt = $conn->prepare($inventoryCheck);
            $inventoryStmt->bindParam(":locationId", $locationId);
            $inventoryStmt->execute();
            $inventoryResult = $inventoryStmt->fetch(PDO::FETCH_ASSOC);
            
            if($inventoryResult['count'] > 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Cannot delete store with existing inventory'
                ]);
                return;
            }
            */

            // Delete the store
            $sql = "DELETE FROM locations WHERE location_id = :locationId AND location_type = 'store'";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":locationId", $locationId);
            $stmt->execute();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Store deleted successfully',
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

    // Check if store name exists
    function checkStoreName($json) {
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
                    WHERE location_name = :locationName AND location_type = 'store'";
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

    // Search stores
    function searchStores($json) {
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
                    WHERE location_type = 'store' 
                    AND (location_name LIKE :searchTerm OR address LIKE :searchTerm)
                    ORDER BY location_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stores search completed',
                'data' => $stores
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get store inventory (if you have store_inventory table)
    function getStoreInventory($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            // Verify store exists
            $storeCheck = "SELECT COUNT(*) as count FROM locations 
                          WHERE location_id = :locationId AND location_type = 'store'";
            $stmt = $conn->prepare($storeCheck);
            $stmt->bindValue(":locationId", $data['location_id']);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                throw new Exception("Store not found");
            }

            // Get inventory data - modify this query based on your inventory table structure
            $sql = "SELECT si.*, p.product_name, p.barcode, p.description
                    FROM store_inventory si
                    JOIN products p ON si.product_id = p.product_id
                    WHERE si.store_id = :locationId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $data['location_id']);
            $stmt->execute();
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store inventory retrieved',
                'data' => $inventory
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get store statistics
    function getStoreStats() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        COUNT(*) as total_stores,
                        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_stores,
                        COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_stores
                    FROM locations 
                    WHERE location_type = 'store'";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store statistics retrieved',
                'data' => $stats
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stores by area/region (useful for retail chain management)
    function getStoresByArea($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['area_term'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: area_term'
                ]);
                return;
            }

            $areaTerm = '%' . $data['area_term'] . '%';
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'store' 
                    AND address LIKE :areaTerm
                    ORDER BY location_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":areaTerm", $areaTerm);
            $stmt->execute();
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stores by area retrieved successfully',
                'data' => $stores
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get recent stores (created in last 30 days)
    function getRecentStores() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT * FROM locations 
                    WHERE location_type = 'store' 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Recent stores retrieved successfully',
                'data' => $stores
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Bulk activate/deactivate stores
    function bulkUpdateStoreStatus($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['location_ids']) || !is_array($data['location_ids'])) {
                throw new Exception("Location IDs array is required");
            }

            if(!isset($data['is_active'])) {
                throw new Exception("Status (is_active) is required");
            }

            $locationIds = $data['location_ids'];
            $isActive = (int)$data['is_active'];
            
            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';
            
            $sql = "UPDATE locations SET 
                        is_active = ?, 
                        updated_at = CURRENT_TIMESTAMP
                    WHERE location_id IN ($placeholders) 
                    AND location_type = 'store'";
            
            $stmt = $conn->prepare($sql);
            $params = array_merge([$isActive], $locationIds);
            $stmt->execute($params);
            
            $affectedRows = $stmt->rowCount();
            
            $conn->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => "Successfully updated $affectedRows stores",
                'data' => [
                    'affected_count' => $affectedRows,
                    'is_active' => $isActive
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
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

$store = new Store();

if ($operation === "createStore") {
    echo $store->createStore($data);
} elseif ($operation === "updateStore") {
    echo $store->updateStore($data);
} else {
    // Handle other operations
    switch($operation) {
        case "getAllStores":
            $store->getAllStores();
            break;
        case "getActiveStores":
            $store->getActiveStores();
            break;
        case "getStore":
            $store->getStore($json);
            break;
        case "deleteStore":
            $store->deleteStore($json);
            break;
        case "checkStoreName":
            $store->checkStoreName($json);
            break;
        case "searchStores":
            $store->searchStores($json);
            break;
        case "getStoreInventory":
            $store->getStoreInventory($json);
            break;
        case "getStoreStats":
            $store->getStoreStats();
            break;
        case "getStoresByArea":
            $store->getStoresByArea($json);
            break;
        case "getRecentStores":
            $store->getRecentStores();
            break;
        case "bulkUpdateStoreStatus":
            $store->bulkUpdateStoreStatus($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>