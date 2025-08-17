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

    // Create a new warehouse with manager assignment
    function createWarehouse($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            if (empty($data['warehouse_name'])) {
                throw new Exception("Warehouse name is required");
            }

            if (empty($data['manager_id'])) {
                throw new Exception("Warehouse manager is required");
            }

            // Generate UUID for warehouse
            $warehouseId = $this->generateUuid();

            // Check if warehouse name already exists
            $checkSql = "SELECT COUNT(*) as count FROM warehouses WHERE warehouse_name = :warehouseName";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":warehouseName", $data['warehouse_name']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Warehouse name already exists");
            }

            // Check if manager exists and is active
            $managerCheck = "SELECT COUNT(*) as count FROM users WHERE user_id = :managerId AND is_active = 1";
            $managerStmt = $conn->prepare($managerCheck);
            $managerStmt->bindValue(":managerId", $data['manager_id']);
            $managerStmt->execute();
            $managerResult = $managerStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($managerResult['count'] == 0) {
                throw new Exception("Selected manager is not valid or inactive");
            }

            // Prepare data
            $location = $data['location'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Insert warehouse
            $sql = "INSERT INTO warehouses(
                        warehouse_id, warehouse_name, location, is_active
                    ) VALUES(
                        :warehouseId, :warehouseName, :location, :isActive
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $warehouseId);
            $stmt->bindValue(":warehouseName", $data['warehouse_name']);
            $stmt->bindValue(":location", $location);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create warehouse");
            }

            // Assign manager to warehouse
            $assignId = $this->generateUuid();
            $assignDate = date('Y-m-d');
            
            $assignSql = "INSERT INTO assign_warehouse(
                            warehouse_assign_id, user_id, warehouse_id, assigned_date, is_active
                         ) VALUES(
                            :assignId, :userId, :warehouseId, :assignedDate, :isActive
                         )";
            
            $assignStmt = $conn->prepare($assignSql);
            $assignStmt->bindValue(":assignId", $assignId);
            $assignStmt->bindValue(":userId", $data['manager_id']);
            $assignStmt->bindValue(":warehouseId", $warehouseId);
            $assignStmt->bindValue(":assignedDate", $assignDate);
            $assignStmt->bindValue(":isActive", 1, PDO::PARAM_INT);
            
            if (!$assignStmt->execute()) {
                throw new Exception("Failed to assign warehouse manager");
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Warehouse created and manager assigned successfully',
                'data' => [
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $data['warehouse_name'],
                    'location' => $location,
                    'manager_id' => $data['manager_id'],
                    'assigned_date' => $assignDate
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Update warehouse (can update manager assignment)
    function updateWarehouse($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            if (empty($data['warehouse_id'])) {
                throw new Exception("Warehouse ID is required");
            }

            if (empty($data['warehouse_name'])) {
                throw new Exception("Warehouse name is required");
            }

            // Check if warehouse exists
            $warehouseCheck = "SELECT COUNT(*) as count FROM warehouses WHERE warehouse_id = :warehouseId";
            $warehouseStmt = $conn->prepare($warehouseCheck);
            $warehouseStmt->bindValue(":warehouseId", $data['warehouse_id']);
            $warehouseStmt->execute();
            $warehouseResult = $warehouseStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($warehouseResult['count'] == 0) {
                throw new Exception("Warehouse not found");
            }

            // Check if warehouse name already exists (excluding current warehouse)
            $checkSql = "SELECT COUNT(*) as count FROM warehouses 
                        WHERE warehouse_name = :warehouseName AND warehouse_id != :warehouseId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":warehouseName", $data['warehouse_name']);
            $checkStmt->bindValue(":warehouseId", $data['warehouse_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Warehouse name already exists");
            }

            // Prepare data
            $location = $data['location'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Update warehouse
            $sql = "UPDATE warehouses SET
                        warehouse_name = :warehouseName,
                        location = :location,
                        is_active = :isActive
                    WHERE warehouse_id = :warehouseId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseName", $data['warehouse_name']);
            $stmt->bindValue(":location", $location);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":warehouseId", $data['warehouse_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update warehouse");
            }

            // Handle manager assignment if provided
            if (!empty($data['manager_id'])) {
                // Check if manager exists and is active
                $managerCheck = "SELECT COUNT(*) as count FROM users WHERE user_id = :managerId AND is_active = 1";
                $managerStmt = $conn->prepare($managerCheck);
                $managerStmt->bindValue(":managerId", $data['manager_id']);
                $managerStmt->execute();
                $managerResult = $managerStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($managerResult['count'] == 0) {
                    throw new Exception("Selected manager is not valid or inactive");
                }

                // Check if this assignment already exists
                $assignCheck = "SELECT COUNT(*) as count FROM assign_warehouse 
                               WHERE user_id = :userId AND warehouse_id = :warehouseId AND is_active = 1";
                $assignStmt = $conn->prepare($assignCheck);
                $assignStmt->bindValue(":userId", $data['manager_id']);
                $assignStmt->bindValue(":warehouseId", $data['warehouse_id']);
                $assignStmt->execute();
                $assignResult = $assignStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($assignResult['count'] == 0) {
                    // Deactivate any existing active assignments for this warehouse
                    $deactivateSql = "UPDATE assign_warehouse SET is_active = 0 
                                      WHERE warehouse_id = :warehouseId AND is_active = 1";
                    $deactivateStmt = $conn->prepare($deactivateSql);
                    $deactivateStmt->bindValue(":warehouseId", $data['warehouse_id']);
                    $deactivateStmt->execute();

                    // Create new assignment
                    $assignId = $this->generateUuid();
                    $assignDate = date('Y-m-d');
                    
                    $assignSql = "INSERT INTO assign_warehouse(
                                    warehouse_assign_id, user_id, warehouse_id, assigned_date, is_active
                                 ) VALUES(
                                    :assignId, :userId, :warehouseId, :assignedDate, :isActive
                                 )";
                    
                    $assignStmt = $conn->prepare($assignSql);
                    $assignStmt->bindValue(":assignId", $assignId);
                    $assignStmt->bindValue(":userId", $data['manager_id']);
                    $assignStmt->bindValue(":warehouseId", $data['warehouse_id']);
                    $assignStmt->bindValue(":assignedDate", $assignDate);
                    $assignStmt->bindValue(":isActive", 1, PDO::PARAM_INT);
                    
                    if (!$assignStmt->execute()) {
                        throw new Exception("Failed to assign warehouse manager");
                    }
                }
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Warehouse updated successfully',
                'data' => [
                    'warehouse_id' => $data['warehouse_id'],
                    'warehouse_name' => $data['warehouse_name'],
                    'location' => $location,
                    'manager_id' => $data['manager_id'] ?? null
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get all warehouses with their current managers
    function getAllWarehouses() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT w.*, 
                       u.user_id as manager_id, 
                       u.full_name as manager_name,
                       u.email as manager_email,
                       u.phone as manager_phone
                    FROM warehouses w
                    LEFT JOIN assign_warehouse aw ON w.warehouse_id = aw.warehouse_id AND aw.is_active = 1
                    LEFT JOIN users u ON aw.user_id = u.user_id
                    ORDER BY w.warehouse_name";
            
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
            $sql = "SELECT w.*, 
                       u.user_id as manager_id, 
                       u.full_name as manager_name,
                       u.email as manager_email,
                       u.phone as manager_phone
                    FROM warehouses w
                    LEFT JOIN assign_warehouse aw ON w.warehouse_id = aw.warehouse_id AND aw.is_active = 1
                    LEFT JOIN users u ON aw.user_id = u.user_id
                    WHERE w.is_active = 1
                    ORDER BY w.warehouse_name";
            
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

    // Get a single warehouse with manager info
    function getWarehouse($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id'
                ]);
                return;
            }

            $sql = "SELECT w.*, 
                       u.user_id as manager_id, 
                       u.full_name as manager_name,
                       u.email as manager_email,
                       u.phone as manager_phone,
                       aw.assigned_date
                    FROM warehouses w
                    LEFT JOIN assign_warehouse aw ON w.warehouse_id = aw.warehouse_id AND aw.is_active = 1
                    LEFT JOIN users u ON aw.user_id = u.user_id
                    WHERE w.warehouse_id = :warehouseId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $json['warehouse_id']);
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
        $conn->beginTransaction();
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id'
                ]);
                return;
            }

            $warehouseId = $data['warehouse_id'];

            // First check if warehouse exists
            $checkSql = "SELECT COUNT(*) as count FROM warehouses WHERE warehouse_id = :warehouseId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindParam(":warehouseId", $warehouseId);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse not found'
                ]);
                return;
            }

            // Check if warehouse has any active assignments
            $assignCheck = "SELECT COUNT(*) as count FROM assign_warehouse 
                           WHERE warehouse_id = :warehouseId AND is_active = 1";
            $assignStmt = $conn->prepare($assignCheck);
            $assignStmt->bindParam(":warehouseId", $warehouseId);
            $assignStmt->execute();
            $assignResult = $assignStmt->fetch(PDO::FETCH_ASSOC);
            
            if($assignResult['count'] > 0) {
                // Deactivate all assignments first
                $deactivateSql = "UPDATE assign_warehouse SET is_active = 0 
                                  WHERE warehouse_id = :warehouseId";
                $deactivateStmt = $conn->prepare($deactivateSql);
                $deactivateStmt->bindParam(":warehouseId", $warehouseId);
                $deactivateStmt->execute();
            }

            // Delete the warehouse
            $sql = "DELETE FROM warehouses WHERE warehouse_id = :warehouseId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseId", $warehouseId);
            $stmt->execute();

            $conn->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse deleted successfully',
                'data' => [
                    'warehouse_id' => $warehouseId
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

    // Check if warehouse name exists
    function checkWarehouseName($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['warehouse_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_name'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM warehouses WHERE warehouse_name = :warehouseName";
            $params = [':warehouseName' => $json['warehouse_name']];
            
            if(isset($json['warehouse_id'])) {
                $sql .= " AND warehouse_id != :warehouseId";
                $params[':warehouseId'] = $json['warehouse_id'];
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
            $sql = "SELECT w.*, 
                      u.user_id as manager_id, 
                      u.full_name as manager_name,
                      u.email as manager_email,
                      u.phone as manager_phone
                    FROM warehouses w
                    LEFT JOIN assign_warehouse aw ON w.warehouse_id = aw.warehouse_id AND aw.is_active = 1
                    LEFT JOIN users u ON aw.user_id = u.user_id
                    WHERE w.warehouse_name LIKE :searchTerm
                    ORDER BY w.warehouse_name
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

    // Get list of potential warehouse managers (active users with appropriate role)
    function getPotentialManagers() {
      include "connection-pdo.php";

      try {
          $sql = "SELECT u.user_id, u.full_name, u.email, u.phone 
                  FROM users u
                  JOIN roles r ON u.role_id = r.role_id
                  LEFT JOIN assign_warehouse aw ON u.user_id = aw.user_id AND aw.is_active = 1
                  WHERE u.is_active = 1 
                  AND r.role_name = 'warehouse_manager'
                  AND aw.user_id IS NULL
                  ORDER BY u.full_name";
          
          $stmt = $conn->prepare($sql);
          $stmt->execute();
          $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode([
              'status' => 'success',
              'message' => 'Unassigned warehouse managers retrieved successfully',
              'data' => $managers
          ]);
      } catch (PDOException $e) {
          echo json_encode([
              'status' => 'error',
              'message' => 'Database error: ' . $e->getMessage()
          ]);
      }
   }

   // Get warehouses assigned to a specific user
    function getWarehousesByUserId($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id'
                ]);
                return;
            }

            $sql = "SELECT w.*, aw.assigned_date
                    FROM warehouses w
                    JOIN assign_warehouse aw ON w.warehouse_id = aw.warehouse_id
                    WHERE aw.user_id = :userId AND aw.is_active = 1
                    ORDER BY w.warehouse_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $json['user_id']);
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

    function getWarehouseStock($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['warehouse_id'])) {
                throw new Exception("Warehouse ID is required");
            }
            
            if(empty($data['user_id'])) {
                throw new Exception("User ID is required for authorization");
            }

            // Verify user has access to this warehouse
            $accessCheck = "SELECT COUNT(*) as count FROM assign_warehouse 
                        WHERE warehouse_id = :warehouseId 
                        AND user_id = :userId 
                        AND is_active = 1";
            $stmt = $conn->prepare($accessCheck);
            $stmt->bindValue(":warehouseId", $data['warehouse_id']);
            $stmt->bindValue(":userId", $data['user_id']);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                throw new Exception("User not authorized to access this warehouse");
            }

            $sql = "SELECT ws.*, p.product_name, p.product_sku, p.description
                    FROM warehouse_stock ws
                    JOIN products p ON ws.product_id = p.product_id
                    WHERE ws.warehouse_id = :warehouseId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $data['warehouse_id']);
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

    // Get stock for all warehouses a manager is assigned to
    function getManagerWarehouseStock($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['user_id'])) {
                throw new Exception("User ID is required");
            }

            // First get all warehouses the user manages
            $warehousesSql = "SELECT w.warehouse_id, w.warehouse_name
                            FROM warehouses w
                            JOIN assign_warehouse aw ON w.warehouse_id = aw.warehouse_id
                            WHERE aw.user_id = :userId AND aw.is_active = 1";
            
            $stmt = $conn->prepare($warehousesSql);
            $stmt->bindValue(":userId", $data['user_id']);
            $stmt->execute();
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if(empty($warehouses)) {
                throw new Exception("User is not assigned to any warehouses");
            }

            // Get stock for each warehouse
            $result = [];
            foreach($warehouses as $warehouse) {
                $stockSql = "SELECT ws.*, p.product_name, p.product_code
                            FROM warehouse_stock ws
                            JOIN products p ON ws.product_id = p.product_id
                            WHERE ws.warehouse_id = :warehouseId";
                
                $stmt = $conn->prepare($stockSql);
                $stmt->bindValue(":warehouseId", $warehouse['warehouse_id']);
                $stmt->execute();
                $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $result[] = [
                    'warehouse_id' => $warehouse['warehouse_id'],
                    'warehouse_name' => $warehouse['warehouse_name'],
                    'stock' => $stock
                ];
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse stock retrieved',
                'data' => $result
            ]);
        } catch (Exception $e) {
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
            echo $warehouse->searchWarehouses($json);
            break;
        case "getPotentialManagers":
            $warehouse->getPotentialManagers();
            break;
        case "getWarehousesByUserId":
            $warehouse->getWarehousesByUserId($json);
            break;
        case "getWarehouseStock":
            $warehouse->getWarehouseStock($json);
            break;
        case "getManagerWarehouseStock":
            $warehouse->getManagerWarehouseStock($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>