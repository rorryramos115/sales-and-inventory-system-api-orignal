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

    // Create a new counter with optional user assignment
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

            // Check if user exists and is active (only if user_id is provided)
            if (!empty($data['user_id'])) {
                $userCheck = "SELECT COUNT(*) as count FROM users WHERE user_id = :userId AND is_active = 1";
                $userStmt = $conn->prepare($userCheck);
                $userStmt->bindValue(":userId", $data['user_id']);
                $userStmt->execute();
                $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userResult['count'] == 0) {
                    throw new Exception("Selected user is not valid or inactive");
                }
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
                'is_active' => $isActive,
                'user_id' => null,
                'assigned_datetime' => null
            ];

            // Assign user to counter (only if user_id is provided)
            if (!empty($data['user_id'])) {
                $assignId = $this->generateUuid();
                $assignDateTime = date('Y-m-d H:i:s');
                
                $assignSql = "INSERT INTO assign_sales(
                                counter_assign_id, user_id, counter_id, assigned_datetime, is_active
                             ) VALUES(
                                :assignId, :userId, :counterId, :assignedDateTime, :isActive
                             )";
                
                $assignStmt = $conn->prepare($assignSql);
                $assignStmt->bindValue(":assignId", $assignId);
                $assignStmt->bindValue(":userId", $data['user_id']);
                $assignStmt->bindValue(":counterId", $counterId);
                $assignStmt->bindValue(":assignedDateTime", $assignDateTime);
                $assignStmt->bindValue(":isActive", 1, PDO::PARAM_INT);
                
                if (!$assignStmt->execute()) {
                    throw new Exception("Failed to assign user to counter");
                }

                $responseData['user_id'] = $data['user_id'];
                $responseData['assigned_datetime'] = $assignDateTime;
            }
            
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

    // Update counter (can update user assignment or leave it null)
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
                'is_active' => $isActive,
                'user_id' => null,
                'assigned_datetime' => null
            ];

            // Handle user assignment if provided (can be null)
            if (isset($data['user_id'])) {
                // If user_id is empty/null, unassign current user
                if (empty($data['user_id'])) {
                    // Get current active assignment
                    $currentAssignSql = "SELECT counter_assign_id FROM assign_sales 
                                        WHERE counter_id = :counterId AND is_active = 1";
                    $currentAssignStmt = $conn->prepare($currentAssignSql);
                    $currentAssignStmt->bindValue(":counterId", $data['counter_id']);
                    $currentAssignStmt->execute();
                    $currentAssign = $currentAssignStmt->fetch(PDO::FETCH_ASSOC);

                    if ($currentAssign) {
                        // Create unassign record
                        $unassignId = $this->generateUuid();
                        $unassignDateTime = date('Y-m-d H:i:s');
                        $unassignReason = $data['unassign_reason'] ?? 'Updated via API';
                        
                        $unassignSql = "INSERT INTO unassign_sales(
                                           counter_unassign_id, counter_assign_id, unassigned_datetime, unassign_reason
                                       ) VALUES(
                                           :unassignId, :counterAssignId, :unassignedDateTime, :unassignReason
                                       )";
                        
                        $unassignStmt = $conn->prepare($unassignSql);
                        $unassignStmt->bindValue(":unassignId", $unassignId);
                        $unassignStmt->bindValue(":counterAssignId", $currentAssign['counter_assign_id']);
                        $unassignStmt->bindValue(":unassignedDateTime", $unassignDateTime);
                        $unassignStmt->bindValue(":unassignReason", $unassignReason);
                        $unassignStmt->execute();

                        // Deactivate current assignment
                        $deactivateSql = "UPDATE assign_sales SET is_active = 0 
                                         WHERE counter_id = :counterId AND is_active = 1";
                        $deactivateStmt = $conn->prepare($deactivateSql);
                        $deactivateStmt->bindValue(":counterId", $data['counter_id']);
                        $deactivateStmt->execute();
                    }
                } else {
                    // Check if user exists and is active
                    $userCheck = "SELECT COUNT(*) as count FROM users WHERE user_id = :userId AND is_active = 1";
                    $userStmt = $conn->prepare($userCheck);
                    $userStmt->bindValue(":userId", $data['user_id']);
                    $userStmt->execute();
                    $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($userResult['count'] == 0) {
                        throw new Exception("Selected user is not valid or inactive");
                    }

                    // Check if this assignment already exists
                    $assignCheck = "SELECT COUNT(*) as count FROM assign_sales 
                                   WHERE user_id = :userId AND counter_id = :counterId AND is_active = 1";
                    $assignStmt = $conn->prepare($assignCheck);
                    $assignStmt->bindValue(":userId", $data['user_id']);
                    $assignStmt->bindValue(":counterId", $data['counter_id']);
                    $assignStmt->execute();
                    $assignResult = $assignStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($assignResult['count'] == 0) {
                        // First, handle any existing assignment
                        $currentAssignSql = "SELECT counter_assign_id FROM assign_sales 
                                            WHERE counter_id = :counterId AND is_active = 1";
                        $currentAssignStmt = $conn->prepare($currentAssignSql);
                        $currentAssignStmt->bindValue(":counterId", $data['counter_id']);
                        $currentAssignStmt->execute();
                        $currentAssign = $currentAssignStmt->fetch(PDO::FETCH_ASSOC);

                        if ($currentAssign) {
                            // Create unassign record for previous user
                            $unassignId = $this->generateUuid();
                            $unassignDateTime = date('Y-m-d H:i:s');
                            $unassignReason = 'Reassigned to new user via API';
                            
                            $unassignSql = "INSERT INTO unassign_sales(
                                               counter_unassign_id, counter_assign_id, unassigned_datetime, unassign_reason
                                           ) VALUES(
                                               :unassignId, :counterAssignId, :unassignedDateTime, :unassignReason
                                           )";
                            
                            $unassignStmt = $conn->prepare($unassignSql);
                            $unassignStmt->bindValue(":unassignId", $unassignId);
                            $unassignStmt->bindValue(":counterAssignId", $currentAssign['counter_assign_id']);
                            $unassignStmt->bindValue(":unassignedDateTime", $unassignDateTime);
                            $unassignStmt->bindValue(":unassignReason", $unassignReason);
                            $unassignStmt->execute();

                            // Deactivate current assignment
                            $deactivateSql = "UPDATE assign_sales SET is_active = 0 
                                             WHERE counter_id = :counterId AND is_active = 1";
                            $deactivateStmt = $conn->prepare($deactivateSql);
                            $deactivateStmt->bindValue(":counterId", $data['counter_id']);
                            $deactivateStmt->execute();
                        }

                        // Create new assignment
                        $assignId = $this->generateUuid();
                        $assignDateTime = date('Y-m-d H:i:s');
                        
                        $assignSql = "INSERT INTO assign_sales(
                                        counter_assign_id, user_id, counter_id, assigned_datetime, is_active
                                     ) VALUES(
                                        :assignId, :userId, :counterId, :assignedDateTime, :isActive
                                     )";
                        
                        $assignStmt = $conn->prepare($assignSql);
                        $assignStmt->bindValue(":assignId", $assignId);
                        $assignStmt->bindValue(":userId", $data['user_id']);
                        $assignStmt->bindValue(":counterId", $data['counter_id']);
                        $assignStmt->bindValue(":assignedDateTime", $assignDateTime);
                        $assignStmt->bindValue(":isActive", 1, PDO::PARAM_INT);
                        
                        if (!$assignStmt->execute()) {
                            throw new Exception("Failed to assign user to counter");
                        }

                        $responseData['user_id'] = $data['user_id'];
                        $responseData['assigned_datetime'] = $assignDateTime;
                    } else {
                        // Assignment already exists, get the datetime
                        $getAssignSql = "SELECT assigned_datetime FROM assign_sales 
                                        WHERE user_id = :userId AND counter_id = :counterId AND is_active = 1";
                        $getAssignStmt = $conn->prepare($getAssignSql);
                        $getAssignStmt->bindValue(":userId", $data['user_id']);
                        $getAssignStmt->bindValue(":counterId", $data['counter_id']);
                        $getAssignStmt->execute();
                        $assignData = $getAssignStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $responseData['user_id'] = $data['user_id'];
                        $responseData['assigned_datetime'] = $assignData['assigned_datetime'];
                    }
                }
            } else {
                // If user_id is not provided in update, keep current assignment
                $currentAssignSql = "SELECT user_id, assigned_datetime FROM assign_sales 
                                    WHERE counter_id = :counterId AND is_active = 1";
                $currentAssignStmt = $conn->prepare($currentAssignSql);
                $currentAssignStmt->bindValue(":counterId", $data['counter_id']);
                $currentAssignStmt->execute();
                $currentAssign = $currentAssignStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($currentAssign) {
                    $responseData['user_id'] = $currentAssign['user_id'];
                    $responseData['assigned_datetime'] = $currentAssign['assigned_datetime'];
                }
            }
            
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

    // Get all counters with their current assigned users
    function getAllCounters() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT c.*, 
                       u.user_id, 
                       u.full_name as user_name,
                       u.email as user_email,
                       u.phone as user_phone,
                       ass.assigned_datetime
                    FROM counters c
                    LEFT JOIN assign_sales ass ON c.counter_id = ass.counter_id AND ass.is_active = 1
                    LEFT JOIN users u ON ass.user_id = u.user_id
                    ORDER BY c.counter_name";
            
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
            $sql = "SELECT c.*, 
                       u.user_id, 
                       u.full_name as user_name,
                       u.email as user_email,
                       u.phone as user_phone,
                       ass.assigned_datetime
                    FROM counters c
                    LEFT JOIN assign_sales ass ON c.counter_id = ass.counter_id AND ass.is_active = 1
                    LEFT JOIN users u ON ass.user_id = u.user_id
                    WHERE c.is_active = 1
                    ORDER BY c.counter_name";
            
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

    // Get a single counter with user info
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

            $sql = "SELECT c.*, 
                       u.user_id, 
                       u.full_name as user_name,
                       u.email as user_email,
                       u.phone as user_phone,
                       ass.assigned_datetime
                    FROM counters c
                    LEFT JOIN assign_sales ass ON c.counter_id = ass.counter_id AND ass.is_active = 1
                    LEFT JOIN users u ON ass.user_id = u.user_id
                    WHERE c.counter_id = :counterId";
            
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

            // Check if counter has any active assignments
            $assignCheck = "SELECT counter_assign_id FROM assign_sales 
                           WHERE counter_id = :counterId AND is_active = 1";
            $assignStmt = $conn->prepare($assignCheck);
            $assignStmt->bindParam(":counterId", $counterId);
            $assignStmt->execute();
            $assignResult = $assignStmt->fetch(PDO::FETCH_ASSOC);
            
            if($assignResult) {
                // Create unassign record first
                $unassignId = $this->generateUuid();
                $unassignDateTime = date('Y-m-d H:i:s');
                $unassignReason = $data['unassign_reason'] ?? 'Counter deleted';
                
                $unassignSql = "INSERT INTO unassign_sales(
                                   counter_unassign_id, counter_assign_id, unassigned_datetime, unassign_reason
                               ) VALUES(
                                   :unassignId, :counterAssignId, :unassignedDateTime, :unassignReason
                               )";
                
                $unassignStmt = $conn->prepare($unassignSql);
                $unassignStmt->bindParam(":unassignId", $unassignId);
                $unassignStmt->bindParam(":counterAssignId", $assignResult['counter_assign_id']);
                $unassignStmt->bindParam(":unassignedDateTime", $unassignDateTime);
                $unassignStmt->bindParam(":unassignReason", $unassignReason);
                $unassignStmt->execute();

                // Deactivate assignment
                $deactivateSql = "UPDATE assign_sales SET is_active = 0 
                                  WHERE counter_id = :counterId";
                $deactivateStmt = $conn->prepare($deactivateSql);
                $deactivateStmt->bindParam(":counterId", $counterId);
                $deactivateStmt->execute();
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
            $sql = "SELECT c.*, 
                      u.user_id, 
                      u.full_name as user_name,
                      u.email as user_email,
                      u.phone as user_phone,
                      ass.assigned_datetime
                    FROM counters c
                    LEFT JOIN assign_sales ass ON c.counter_id = ass.counter_id AND ass.is_active = 1
                    LEFT JOIN users u ON ass.user_id = u.user_id
                    WHERE c.counter_name LIKE :searchTerm
                    ORDER BY c.counter_name
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

    // Get list of unassigned users (active users not assigned to any counter)
    function getPotentialCashiers() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT u.user_id, u.full_name, u.email, u.phone 
                    FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    LEFT JOIN assign_sales ac ON u.user_id = ac.user_id AND ac.is_active = 1
                    WHERE u.is_active = 1 
                    AND r.role_name = 'cashier'
                    AND ac.user_id IS NULL
                    ORDER BY u.full_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Unassigned cashiers retrieved successfully',
                'data' => $cashiers
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get assignment history for a counter
    function getCounterAssignmentHistory($json) {
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

            $sql = "SELECT ass.counter_assign_id, ass.assigned_datetime, ass.is_active,
                       u.user_id, u.full_name as user_name, u.email as user_email,
                       unass.unassigned_datetime, unass.unassign_reason
                    FROM assign_sales ass
                    LEFT JOIN users u ON ass.user_id = u.user_id
                    LEFT JOIN unassign_sales unass ON ass.counter_assign_id = unass.counter_assign_id
                    WHERE ass.counter_id = :counterId
                    ORDER BY ass.assigned_datetime DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":counterId", $json['counter_id']);
            $stmt->execute();
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Counter assignment history retrieved successfully',
                'data' => $history
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Manually unassign user from counter
    function unassignUser($json) {
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

            // Get current active assignment
            $assignSql = "SELECT counter_assign_id FROM assign_sales 
                         WHERE counter_id = :counterId AND is_active = 1";
            $assignStmt = $conn->prepare($assignSql);
            $assignStmt->bindValue(":counterId", $data['counter_id']);
            $assignStmt->execute();
            $assignResult = $assignStmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$assignResult) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No active assignment found for this counter'
                ]);
                return;
            }

            // Create unassign record
            $unassignId = $this->generateUuid();
            $unassignDateTime = date('Y-m-d H:i:s');
            $unassignReason = $data['unassign_reason'] ?? 'Manually unassigned';
            
            $unassignSql = "INSERT INTO unassign_sales(
                               counter_unassign_id, counter_assign_id, unassigned_datetime, unassign_reason
                           ) VALUES(
                               :unassignId, :counterAssignId, :unassignedDateTime, :unassignReason
                           )";
            
            $unassignStmt = $conn->prepare($unassignSql);
            $unassignStmt->bindValue(":unassignId", $unassignId);
            $unassignStmt->bindValue(":counterAssignId", $assignResult['counter_assign_id']);
            $unassignStmt->bindValue(":unassignedDateTime", $unassignDateTime);
            $unassignStmt->bindValue(":unassignReason", $unassignReason);
            
            if (!$unassignStmt->execute()) {
                throw new Exception("Failed to create unassign record");
            }

            // Deactivate assignment
            $deactivateSql = "UPDATE assign_sales SET is_active = 0 
                             WHERE counter_assign_id = :counterAssignId";
            $deactivateStmt = $conn->prepare($deactivateSql);
            $deactivateStmt->bindValue(":counterAssignId", $assignResult['counter_assign_id']);
            
            if (!$deactivateStmt->execute()) {
                throw new Exception("Failed to deactivate assignment");
            }

            $conn->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'User unassigned from counter successfully',
                'data' => [
                    'counter_id' => $data['counter_id'],
                    'unassigned_datetime' => $unassignDateTime,
                    'unassign_reason' => $unassignReason
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        } catch (PDOException $e) {
            $conn->rollBack();
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
        case "getPotentialCashiers":
            $counter->getPotentialCashiers();
            break;
        case "getCounterAssignmentHistory":
            $counter->getCounterAssignmentHistory($json);
            break;
        case "unassignUser":
            $counter->unassignUser($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>