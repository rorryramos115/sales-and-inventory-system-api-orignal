<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

class UserAssignment {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Get all assignments
    function getAllAssignments() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        ua.assignment_id,
                        ua.user_id,
                        ua.location_id,
                        ua.assigned_date,
                        ua.is_active as assignment_active,
                        u.full_name,
                        u.email,
                        u.phone,
                        u.is_active as user_active,
                        l.location_name,
                        l.location_type,
                        l.address,
                        r.role_name
                    FROM user_assignments ua
                    JOIN users u ON ua.user_id = u.user_id
                    JOIN locations l ON ua.location_id = l.location_id
                    JOIN roles r ON u.role_id = r.role_id
                    ORDER BY ua.assigned_date DESC, u.full_name ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'All assignments retrieved successfully',
                'data' => $assignments
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Assign a user to a location (warehouse or store)
    function assignUser($data) {
        include "connection-pdo.php";
        
        try {
            // Validate required fields
            if (empty($data['user_id'])) {
                throw new Exception("User ID is required");
            }

            if (empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            if (empty($data['assigned_date'])) {
                throw new Exception("Assigned date is required");
            }

            // Check if location exists and get its type
            $locationSql = "SELECT location_type FROM locations WHERE location_id = :location_id";
            $locationStmt = $conn->prepare($locationSql);
            $locationStmt->bindValue(":location_id", $data['location_id']);
            $locationStmt->execute();
            $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$location) {
                throw new Exception("Location not found");
            }

            $locationType = $location['location_type'];
            
            // Check if user exists and has the correct role for the location type
            $userSql = "SELECT u.*, r.role_name 
                        FROM users u 
                        JOIN roles r ON u.role_id = r.role_id 
                        WHERE u.user_id = :user_id";
            $userStmt = $conn->prepare($userSql);
            $userStmt->bindValue(":user_id", $data['user_id']);
            $userStmt->execute();
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            // Verify user has the correct role for the location type
            $requiredRole = ($locationType === 'warehouse') ? 'warehouse_manager' : 'store_manager';
            if ($user['role_name'] !== $requiredRole) {
                throw new Exception("User must have the role: " . $requiredRole);
            }

            // Check if user is already assigned to this location on the same date
            $checkSql = "SELECT COUNT(*) as count FROM user_assignments 
                         WHERE user_id = :user_id 
                         AND location_id = :location_id 
                         AND assigned_date = :assigned_date";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":user_id", $data['user_id']);
            $checkStmt->bindValue(":location_id", $data['location_id']);
            $checkStmt->bindValue(":assigned_date", $data['assigned_date']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("User is already assigned to this location on the specified date");
            }

            // Generate UUID for assignment
            $assignmentId = $this->generateUuid();
            $isActive = 1; 
            if (isset($data['is_active'])) {
                $isActive = (int)$data['is_active'];
            }

            // Insert assignment
            $sql = "INSERT INTO user_assignments(
                        assignment_id, user_id, location_id, assigned_date, is_active
                    ) VALUES(
                        :assignment_id, :user_id, :location_id, :assigned_date, :is_active
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":assignment_id", $assignmentId);
            $stmt->bindValue(":user_id", $data['user_id']);
            $stmt->bindValue(":location_id", $data['location_id']);
            $stmt->bindValue(":assigned_date", $data['assigned_date']);
            $stmt->bindValue(":is_active", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to assign user to location");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'User assigned successfully',
                'data' => [
                    'assignment_id' => $assignmentId,
                    'user_id' => $data['user_id'],
                    'location_id' => $data['location_id'],
                    'location_type' => $locationType,
                    'assigned_date' => $data['assigned_date'],
                    'is_active' => $isActive
                ]
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get UNASSIGNED locations by type (warehouse or store)
    function getLocationsByType($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['location_type'])) {
                throw new Exception("Location type is required (warehouse or store)");
            }

            $locationType = $data['location_type'];
            
            // Validate location type
            if ($locationType !== 'warehouse' && $locationType !== 'store') {
                throw new Exception("Location type must be either 'warehouse' or 'store'");
            }

            // Get only locations that are NOT assigned to any user
            $sql = "SELECT l.location_id, l.location_name, l.address 
                    FROM locations l 
                    WHERE l.location_type = :location_type 
                    AND l.is_active = 1
                    AND l.location_id NOT IN (
                        SELECT DISTINCT ua.location_id 
                        FROM user_assignments ua 
                        WHERE ua.is_active = 1
                    )
                    ORDER BY l.location_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":location_type", $locationType);
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'Unassigned locations retrieved successfully',
                'data' => $locations
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get UNASSIGNED users (users with correct role for location type who are not assigned)
    function getAvailableUsers($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['location_type'])) {
                throw new Exception("Location type is required (warehouse or store)");
            }

            $locationType = $data['location_type'];
            
            // Validate location type
            if ($locationType !== 'warehouse' && $locationType !== 'store') {
                throw new Exception("Location type must be either 'warehouse' or 'store'");
            }
            
            // Determine required role based on location type
            $requiredRole = ($locationType === 'warehouse') ? 'warehouse_manager' : 'store_manager';
            
            // Get users with the required role who are active and NOT currently assigned
            $sql = "SELECT u.user_id, u.full_name, u.email, u.phone, r.role_name
                    FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE r.role_name = :role_name
                    AND u.is_active = 1
                    AND u.user_id NOT IN (
                        SELECT DISTINCT ua.user_id 
                        FROM user_assignments ua 
                        WHERE ua.is_active = 1
                    )
                    ORDER BY u.full_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":role_name", $requiredRole);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'Unassigned users retrieved successfully',
                'data' => $users
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get assignments by location
    function getAssignmentsByLocation($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            $location_id = $data['location_id'];
            
            // Get all assignments for this location
            $sql = "SELECT ua.*, u.full_name, u.email, l.location_name, l.location_type
                    FROM user_assignments ua
                    JOIN users u ON ua.user_id = u.user_id
                    JOIN locations l ON ua.location_id = l.location_id
                    WHERE ua.location_id = :location_id
                    ORDER BY ua.assigned_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":location_id", $location_id);
            $stmt->execute();
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'Assignments retrieved successfully',
                'data' => $assignments
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Check if location is available (no assignments exist)
    function checkLocationAvailability($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['location_id'])) {
                throw new Exception("Location ID is required");
            }

            $location_id = $data['location_id'];
            
            // Check if this location has any active assignments
            $checkSql = "SELECT COUNT(*) as assignment_count 
                         FROM user_assignments 
                         WHERE location_id = :location_id 
                         AND is_active = 1";
            
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":location_id", $location_id);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['assignment_count'] > 0) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Location has assignments',
                    'data' => [
                        'available' => false,
                        'assignment_count' => $result['assignment_count']
                    ]
                ]);
            }

            // If no assignments exist, return location info
            $locationSql = "SELECT * FROM locations WHERE location_id = :location_id";
            $locationStmt = $conn->prepare($locationSql);
            $locationStmt->bindValue(":location_id", $location_id);
            $locationStmt->execute();
            $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'Location is available',
                'data' => [
                    'available' => true,
                    'location' => $location
                ]
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get assignments by user
    function getAssignmentsByUser($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['user_id'])) {
                throw new Exception("User ID is required");
            }

            $sql = "SELECT ua.*, l.location_name, l.location_type, l.address
                    FROM user_assignments ua
                    JOIN locations l ON ua.location_id = l.location_id
                    WHERE ua.user_id = :user_id
                    ORDER BY ua.assigned_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":user_id", $data['user_id']);
            $stmt->execute();
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'Assignments retrieved successfully',
                'data' => $assignments
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Update assignment
    function updateAssignment($data) {
        include "connection-pdo.php";
        
        try {
            if (empty($data['assignment_id'])) {
                throw new Exception("Assignment ID is required");
            }

            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : null;

            // Build update query based on provided fields
            $sql = "UPDATE user_assignments SET ";
            $params = [];
            
            if (!is_null($isActive)) {
                $sql .= "is_active = :is_active, ";
                $params[':is_active'] = $isActive;
            }
            
            // Remove trailing comma and space
            $sql = rtrim($sql, ", ");
            
            $sql .= " WHERE assignment_id = :assignment_id";
            $params[':assignment_id'] = $data['assignment_id'];
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update assignment");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Assignment updated successfully'
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Delete assignment
    function deleteAssignment($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['assignment_id'])) {
                throw new Exception("Assignment ID is required");
            }

            $sql = "DELETE FROM user_assignments WHERE assignment_id = :assignment_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":assignment_id", $data['assignment_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete assignment");
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Assignment deleted successfully'
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get a single assignment by ID
    function getAssignment($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['assignment_id'])) {
                throw new Exception("Assignment ID is required");
            }

            $sql = "SELECT 
                        ua.assignment_id,
                        ua.user_id,
                        ua.location_id,
                        ua.assigned_date,
                        ua.is_active,
                        u.full_name,
                        u.email,
                        l.location_name,
                        l.location_type,
                        r.role_name
                    FROM user_assignments ua
                    JOIN users u ON ua.user_id = u.user_id
                    JOIN locations l ON ua.location_id = l.location_id
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE ua.assignment_id = :assignment_id";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":assignment_id", $data['assignment_id']);
            $stmt->execute();
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$assignment) {
                throw new Exception("Assignment not found");
            }

            return json_encode([
                'status' => 'success',
                'message' => 'Assignment retrieved successfully',
                'data' => $assignment
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Search assignments
    function searchAssignments($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            $searchTerm = $data['search_term'] ?? '';
            $locationFilter = $data['location_filter'] ?? '';
            
            $sql = "SELECT 
                        ua.assignment_id,
                        ua.user_id,
                        ua.location_id,
                        ua.assigned_date,
                        ua.is_active as assignment_active,
                        u.full_name,
                        u.email,
                        u.phone,
                        u.is_active as user_active,
                        l.location_name,
                        l.location_type,
                        l.address,
                        r.role_name
                    FROM user_assignments ua
                    JOIN users u ON ua.user_id = u.user_id
                    JOIN locations l ON ua.location_id = l.location_id
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE 1=1";
            
            $params = [];
            
            if (!empty($searchTerm)) {
                $sql .= " AND (u.full_name LIKE :search_term 
                         OR u.email LIKE :search_term 
                         OR l.location_name LIKE :search_term)";
                $params[':search_term'] = '%' . $searchTerm . '%';
            }
            
            if (!empty($locationFilter)) {
                $sql .= " AND l.location_type = :location_filter";
                $params[':location_filter'] = $locationFilter;
            }
            
            $sql .= " ORDER BY ua.assigned_date DESC, u.full_name ASC";
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'message' => 'Search results retrieved successfully',
                'data' => $assignments
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Check assignment conflict
    function checkAssignmentConflict($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['user_id']) || empty($data['location_id']) || empty($data['assigned_date'])) {
                throw new Exception("User ID, Location ID, and Assigned Date are required");
            }

            $sql = "SELECT COUNT(*) as count FROM user_assignments 
                    WHERE user_id = :user_id 
                    AND location_id = :location_id 
                    AND assigned_date = :assigned_date";
                    
            $params = [
                ':user_id' => $data['user_id'],
                ':location_id' => $data['location_id'],
                ':assigned_date' => $data['assigned_date']
            ];
            
            // Exclude current assignment if updating
            if (!empty($data['assignment_id'])) {
                $sql .= " AND assignment_id != :assignment_id";
                $params[':assignment_id'] = $data['assignment_id'];
            }
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return json_encode([
                'status' => 'success',
                'message' => 'Conflict check completed',
                'data' => [
                    'has_conflict' => $result['count'] > 0,
                    'conflict_count' => $result['count']
                ]
            ]);
            
        } catch (Exception $e) {
            return json_encode([
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

$assignment = new UserAssignment();

switch($operation) {
    case "getAllAssignments":
        echo $assignment->getAllAssignments();
        break;
    case "getAssignment":
        echo $assignment->getAssignment($json);
        break;
    case "assignUser":
        echo $assignment->assignUser($data);
        break;
    case "getAvailableUsers":
        echo $assignment->getAvailableUsers($json);
        break;
    case "getLocationsByType":
        echo $assignment->getLocationsByType($json);
        break;
    case "getAssignmentsByLocation":
        echo $assignment->getAssignmentsByLocation($json);
        break;
    case "checkLocationAvailability":
        echo $assignment->checkLocationAvailability($json);
        break;
    case "getAssignmentsByUser":
        echo $assignment->getAssignmentsByUser($json);
        break;
    case "updateAssignment":
        echo $assignment->updateAssignment($data);
        break;
    case "deleteAssignment":
        echo $assignment->deleteAssignment($json);
        break;
    case "searchAssignments":
        echo $assignment->searchAssignments($json);
        break;
    case "checkAssignmentConflict":
        echo $assignment->checkAssignmentConflict($json);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation'
        ]);
}
?>