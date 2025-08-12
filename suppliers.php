<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class Supplier {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Insert a new supplier
   function insertSupplier($data) {
    include "connection-pdo.php";
    $conn->beginTransaction(); // Start transaction

    try {
        // Validate required fields
        if (empty($data['supplier_name'])) {
            throw new Exception("Supplier name is required");
        }

        // Generate UUID
        $supplierId = $this->generateUuid();

        // Check if supplier name already exists
        $checkSql = "SELECT COUNT(*) as count FROM suppliers WHERE supplier_name = :supplierName";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindValue(":supplierName", $data['supplier_name']);
        $checkStmt->execute();
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            throw new Exception("Supplier name already exists");
        }

        // Prepare data
        $contactPerson = $data['contact_person'] ?? null;
        $phone = $data['phone'] ?? null;
        $email = $data['email'] ?? null;
        $address = $data['address'] ?? null;
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        // Insert supplier
        $sql = "INSERT INTO suppliers(
                    supplier_id, supplier_name, contact_person, phone, email, 
                    address, is_active
                ) VALUES(
                    :supplierId, :supplierName, :contactPerson, :phone, :email, 
                    :address, :isActive
                )";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":supplierId", $supplierId);
        $stmt->bindValue(":supplierName", $data['supplier_name']);
        $stmt->bindValue(":contactPerson", $contactPerson);
        $stmt->bindValue(":phone", $phone);
        $stmt->bindValue(":email", $email);
        $stmt->bindValue(":address", $address);
        $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create supplier");
        }
        
        $conn->commit(); // Commit transaction
        
        return json_encode([
            'status' => 'success',
            'message' => 'Supplier created successfully',
            'data' => [
                'supplier_id' => $supplierId,
                'supplier_name' => $data['supplier_name'],
                'contact_person' => $contactPerson,
                'phone' => $phone,
                'email' => $email
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

    // Update supplier
    function updateSupplier($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['supplier_id'])) {
                throw new Exception("Supplier ID is required");
            }

            // Validate required fields
            if (empty($data['supplier_name'])) {
                throw new Exception("Supplier name is required");
            }

            // Check if supplier name already exists (excluding current supplier)
            $checkSql = "SELECT COUNT(*) as count FROM suppliers 
                        WHERE supplier_name = :supplierName AND supplier_id != :supplierId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":supplierName", $data['supplier_name']);
            $checkStmt->bindValue(":supplierId", $data['supplier_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Supplier name already exists");
            }

            // Prepare data
            $contactPerson = $data['contact_person'] ?? null;
            $phone = $data['phone'] ?? null;
            $email = $data['email'] ?? null;
            $address = $data['address'] ?? null;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            $sql = "UPDATE suppliers SET
                        supplier_name = :supplierName,
                        contact_person = :contactPerson,
                        phone = :phone,
                        email = :email,
                        address = :address,
                        is_active = :isActive
                    WHERE supplier_id = :supplierId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierName", $data['supplier_name']);
            $stmt->bindValue(":contactPerson", $contactPerson);
            $stmt->bindValue(":phone", $phone);
            $stmt->bindValue(":email", $email);
            $stmt->bindValue(":address", $address);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":supplierId", $data['supplier_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update supplier");
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Supplier updated successfully',
                'data' => [
                    'supplier_id' => $data['supplier_id'],
                    'supplier_name' => $data['supplier_name'],
                    'contact_person' => $contactPerson,
                    'phone' => $phone,
                    'email' => $email
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

    // Get all suppliers
    function getAllSuppliers() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM suppliers ORDER BY supplier_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Suppliers retrieved successfully',
                'data' => $suppliers
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get active suppliers only
    function getActiveSuppliers() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active suppliers retrieved successfully',
                'data' => $suppliers
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get a single supplier
    function getSupplier($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['supplier_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_id'
                ]);
                return;
            }

            $sql = "SELECT * FROM suppliers WHERE supplier_id = :supplierId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $json['supplier_id']);
            $stmt->execute();
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            if($supplier) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Supplier retrieved successfully',
                    'data' => $supplier
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Supplier not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Delete a supplier
   function deleteSupplier($json) {
    include "connection-pdo.php";
    
    try {
        $data = json_decode($json, true);
        
        if(empty($data['supplier_id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required field: supplier_id'
            ]);
            return;
        }

        $supplierId = $data['supplier_id'];

        // First check if supplier exists (optional but recommended)
        $checkSql = "SELECT COUNT(*) as count FROM suppliers WHERE supplier_id = :supplierId";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindParam(":supplierId", $supplierId);
        $checkStmt->execute();
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if($result['count'] == 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Supplier not found'
            ]);
            return;
        }

        // Delete the supplier
        $sql = "DELETE FROM suppliers WHERE supplier_id = :supplierId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":supplierId", $supplierId);
        $stmt->execute();

        echo json_encode([
            'status' => 'success',
            'message' => 'Supplier deleted successfully',
            'data' => [
                'supplier_id' => $supplierId
            ]
        ]);
        
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

    // Check if supplier name exists
    function checkSupplierName($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['supplier_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_name'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM suppliers WHERE supplier_name = :supplierName";
            $params = [':supplierName' => $json['supplier_name']];
            
            if(isset($json['supplier_id'])) {
                $sql .= " AND supplier_id != :supplierId";
                $params[':supplierId'] = $json['supplier_id'];
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
                    'supplier_name' => $json['supplier_name']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search suppliers by name, contact person, or phone
    function searchSuppliers($json) {
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
            $sql = "SELECT * FROM suppliers 
                    WHERE supplier_name LIKE :searchTerm 
                    OR contact_person LIKE :searchTerm
                    OR phone LIKE :searchTerm
                    ORDER BY supplier_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Suppliers search completed',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
}


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

$supplier = new Supplier();

if ($operation === "insertSupplier") {
    echo $supplier->insertSupplier($data);
} elseif ($operation === "updateSupplier") {
    echo $supplier->updateSupplier($data);
} else {
    // Handle other operations
    switch($operation) {
        case "getAllSuppliers":
            echo $supplier->getAllSuppliers();
            break;
        case "getActiveSuppliers":
            echo $supplier->getActiveSuppliers();
            break;
        case "getSupplier":
            echo $supplier->getSupplier($json);
            break;
        case "deleteSupplier":
            echo $supplier->deleteSupplier($json);
            break;
        case "checkSupplierName":
            echo $supplier->checkSupplierName($json);
            break;
        case "searchSuppliers":
            echo $supplier->searchSuppliers($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>