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

            // Update warehouse - removed updated_at manual setting as it's handled by ON UPDATE CURRENT_TIMESTAMP
            $sql = "UPDATE locations SET
                        location_name = :locationName,
                        address = :address,
                        is_active = :isActive
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

            // Check if warehouse has any stock
            $stockCheck = "SELECT COUNT(*) as count FROM stock 
                          WHERE location_id = :locationId";
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

    // Get warehouse stock
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

            // Get stock data - using correct table and column names
            $sql = "SELECT s.*, p.product_name, p.barcode, p.description
                    FROM stock s
                    JOIN products p ON s.product_id = p.product_id
                    WHERE s.location_id = :locationId";
            
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

    // Get warehouses assigned to a specific user
    function getWarehousesByUserId($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['user_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: user_id'
                ]);
                return;
            }

            // First verify user exists
            $userCheck = "SELECT COUNT(*) as count FROM users WHERE user_id = :userId";
            $userStmt = $conn->prepare($userCheck);
            $userStmt->bindValue(":userId", $data['user_id']);
            $userStmt->execute();
            $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if($userResult['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User not found'
                ]);
                return;
            }

            // Get warehouses assigned to the user
            $sql = "SELECT 
                        l.location_id,
                        l.location_name,
                        l.location_type,
                        l.address,
                        l.is_active as location_active,
                        l.created_at as location_created_at,
                        l.updated_at as location_updated_at,
                        
                        -- Assignment information
                        ua.assignment_id,
                        ua.assigned_date,
                        ua.is_active as assignment_active,
                        
                        -- User information
                        u.user_id,
                        u.full_name,
                        u.email,
                        u.phone,
                        u.is_active as user_active,
                        
                        -- Role information
                        r.role_id,
                        r.role_name
                        
                    FROM user_assignments ua
                    INNER JOIN locations l ON ua.location_id = l.location_id
                    INNER JOIN users u ON ua.user_id = u.user_id
                    INNER JOIN roles r ON u.role_id = r.role_id
                    WHERE ua.user_id = :userId 
                    AND l.location_type = 'warehouse'
                    ORDER BY l.location_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $data['user_id']);
            $stmt->execute();
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optional: Filter by active assignments only
            $activeOnly = $data['active_only'] ?? false;
            if ($activeOnly) {
                $warehouses = array_filter($warehouses, function($warehouse) {
                    return $warehouse['assignment_active'] == 1 && $warehouse['location_active'] == 1;
                });
                $warehouses = array_values($warehouses); // Re-index array
            }

            // Get user details for response
            $userDetails = null;
            if (!empty($warehouses)) {
                $userDetails = [
                    'user_id' => $warehouses[0]['user_id'],
                    'full_name' => $warehouses[0]['full_name'],
                    'email' => $warehouses[0]['email'],
                    'phone' => $warehouses[0]['phone'],
                    'role_name' => $warehouses[0]['role_name'],
                    'user_active' => $warehouses[0]['user_active']
                ];
            } else {
                // Get user details even if no warehouses assigned
                $userDetailsSql = "SELECT u.user_id, u.full_name, u.email, u.phone, u.is_active, r.role_name
                                  FROM users u
                                  INNER JOIN roles r ON u.role_id = r.role_id
                                  WHERE u.user_id = :userId";
                $userDetailsStmt = $conn->prepare($userDetailsSql);
                $userDetailsStmt->bindValue(":userId", $data['user_id']);
                $userDetailsStmt->execute();
                $userDetailsResult = $userDetailsStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userDetailsResult) {
                    $userDetails = [
                        'user_id' => $userDetailsResult['user_id'],
                        'full_name' => $userDetailsResult['full_name'],
                        'email' => $userDetailsResult['email'],
                        'phone' => $userDetailsResult['phone'],
                        'role_name' => $userDetailsResult['role_name'],
                        'user_active' => $userDetailsResult['is_active']
                    ];
                }
            }

            // Calculate summary statistics
            $totalWarehouses = count($warehouses);
            $activeAssignments = count(array_filter($warehouses, function($w) { 
                return $w['assignment_active'] == 1; 
            }));
            $activeWarehouses = count(array_filter($warehouses, function($w) { 
                return $w['location_active'] == 1; 
            }));

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouses retrieved successfully for user',
                'data' => [
                    'user_details' => $userDetails,
                    'summary' => [
                        'total_warehouses' => $totalWarehouses,
                        'active_assignments' => $activeAssignments,
                        'active_warehouses' => $activeWarehouses
                    ],
                    'warehouses' => $warehouses
                ]
            ]);

        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get users assigned to a specific warehouse
    function getUsersByWarehouseId($json) {
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

            // First verify warehouse exists
            $warehouseCheck = "SELECT COUNT(*) as count FROM locations 
                              WHERE location_id = :locationId AND location_type = 'warehouse'";
            $warehouseStmt = $conn->prepare($warehouseCheck);
            $warehouseStmt->bindValue(":locationId", $data['location_id']);
            $warehouseStmt->execute();
            $warehouseResult = $warehouseStmt->fetch(PDO::FETCH_ASSOC);
            
            if($warehouseResult['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Warehouse not found'
                ]);
                return;
            }

            // Get users assigned to the warehouse
            $sql = "SELECT 
                        u.user_id,
                        u.full_name,
                        u.email,
                        u.phone,
                        u.is_active as user_active,
                        u.created_at as user_created_at,
                        
                        -- Assignment information
                        ua.assignment_id,
                        ua.assigned_date,
                        ua.is_active as assignment_active,
                        
                        -- Role information
                        r.role_id,
                        r.role_name,
                        
                        -- Warehouse information
                        l.location_id,
                        l.location_name,
                        l.location_type,
                        l.address,
                        l.is_active as warehouse_active
                        
                    FROM user_assignments ua
                    INNER JOIN users u ON ua.user_id = u.user_id
                    INNER JOIN roles r ON u.role_id = r.role_id
                    INNER JOIN locations l ON ua.location_id = l.location_id
                    WHERE ua.location_id = :locationId 
                    AND l.location_type = 'warehouse'
                    ORDER BY u.full_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":locationId", $data['location_id']);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optional: Filter by active assignments only
            $activeOnly = $data['active_only'] ?? false;
            if ($activeOnly) {
                $users = array_filter($users, function($user) {
                    return $user['assignment_active'] == 1 && $user['user_active'] == 1;
                });
                $users = array_values($users); // Re-index array
            }

            // Get warehouse details for response
            $warehouseDetails = null;
            if (!empty($users)) {
                $warehouseDetails = [
                    'location_id' => $users[0]['location_id'],
                    'location_name' => $users[0]['location_name'],
                    'location_type' => $users[0]['location_type'],
                    'address' => $users[0]['address'],
                    'warehouse_active' => $users[0]['warehouse_active']
                ];
            } else {
                // Get warehouse details even if no users assigned
                $warehouseDetailsSql = "SELECT location_id, location_name, location_type, address, is_active
                                       FROM locations 
                                       WHERE location_id = :locationId AND location_type = 'warehouse'";
                $warehouseDetailsStmt = $conn->prepare($warehouseDetailsSql);
                $warehouseDetailsStmt->bindValue(":locationId", $data['location_id']);
                $warehouseDetailsStmt->execute();
                $warehouseDetailsResult = $warehouseDetailsStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($warehouseDetailsResult) {
                    $warehouseDetails = [
                        'location_id' => $warehouseDetailsResult['location_id'],
                        'location_name' => $warehouseDetailsResult['location_name'],
                        'location_type' => $warehouseDetailsResult['location_type'],
                        'address' => $warehouseDetailsResult['address'],
                        'warehouse_active' => $warehouseDetailsResult['is_active']
                    ];
                }
            }

            // Calculate summary statistics
            $totalUsers = count($users);
            $activeAssignments = count(array_filter($users, function($u) { 
                return $u['assignment_active'] == 1; 
            }));
            $activeUsers = count(array_filter($users, function($u) { 
                return $u['user_active'] == 1; 
            }));

            echo json_encode([
                'status' => 'success',
                'message' => 'Users retrieved successfully for warehouse',
                'data' => [
                    'warehouse_details' => $warehouseDetails,
                    'summary' => [
                        'total_users' => $totalUsers,
                        'active_assignments' => $activeAssignments,
                        'active_users' => $activeUsers
                    ],
                    'users' => $users
                ]
            ]);

        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Add this method to the Warehouse class in warehouse.php
function getUserAssignedWarehouses($json) {
    include "connection-pdo.php";
    
    try {
        $data = json_decode($json, true);
        
        if(empty($data['user_id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required field: user_id'
            ]);
            return;
        }

        // Get warehouses assigned to the user
        $sql = "SELECT 
                    l.location_id,
                    l.location_name,
                    l.location_type,
                    l.address,
                    l.is_active,
                    l.created_at,
                    l.updated_at,
                    ua.assignment_id,
                    ua.assigned_date,
                    ua.is_active as assignment_active
                FROM user_assignments ua
                INNER JOIN locations l ON ua.location_id = l.location_id
                WHERE ua.user_id = :userId 
                AND l.location_type = 'warehouse'
                AND ua.is_active = 1
                AND l.is_active = 1
                ORDER BY l.location_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":userId", $data['user_id']);
        $stmt->execute();
        $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'message' => 'User assigned warehouses retrieved successfully',
            'data' => [
                'warehouses' => $warehouses,
                'total_count' => count($warehouses)
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}


// Add this method to your Warehouse class in warehouse.php
// Alternative: Only warehouses with active warehouse manager assignments
function getTransferableWarehouses($json) {
    include "connection-pdo.php";
    
    try {
        $data = json_decode($json, true);
        
        if(empty($data['user_id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required field: user_id'
            ]);
            return;
        }

        if(empty($data['from_location_id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required field: from_location_id (current warehouse)'
            ]);
            return;
        }

        $userId = $data['user_id'];
        $fromLocationId = $data['from_location_id'];

        // Fetch warehouses managed by warehouse managers (excluding current warehouse)
        $sql = "SELECT DISTINCT
                    l.location_id,
                    l.location_name,
                    l.location_type,
                    l.address,
                    l.is_active,
                    u.full_name as manager_name,
                    u.user_id as manager_id,
                    ua.assignment_id,
                    ua.assigned_date
                FROM user_assignments ua
                INNER JOIN locations l ON ua.location_id = l.location_id
                INNER JOIN users u ON ua.user_id = u.user_id
                INNER JOIN roles r ON u.role_id = r.role_id
                WHERE r.role_name = 'warehouse_manager'
                AND l.location_type = 'warehouse'
                AND ua.is_active = 1
                AND l.is_active = 1
                AND u.is_active = 1
                AND l.location_id != :fromLocationId
                ORDER BY l.location_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":fromLocationId", $fromLocationId);
        $stmt->execute();
        $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'message' => 'Transferable warehouses retrieved successfully',
            'data' => [
                'warehouses' => $warehouses,
                'summary' => [
                    'total_warehouses' => count($warehouses)
                ]
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
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
            $warehouse->searchWarehouses($json);
            break;
            // -getWarehouseStock- get all proudcts stock in a warehouse
        case "getWarehouseStock":
            $warehouse->getWarehouseStock($json);
            break;
        case "getWarehouseStats":
            $warehouse->getWarehouseStats();
            break;
            // -getWarehousesByUserId- get warehouse assigned to a specific user with user and warehouse details
        case "getWarehousesByUserId":
            $warehouse->getWarehousesByUserId($json);
            break;
            // -getUserAssignedWarehouses- get users assigned to a specific warehouse with details
        case "getUserAssignedWarehouses":
            $warehouse->getUserAssignedWarehouses($json);
            break;
        case "getUsersByWarehouseId":
            $warehouse->getUsersByWarehouseId($json);
            break;
        // -getTransferableWarehouses- get warehouse that can be transferred to another warehouse
        case "getTransferableWarehouses":
            $warehouse->getTransferableWarehouses($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>