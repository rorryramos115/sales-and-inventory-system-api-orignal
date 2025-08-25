<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class SupplierProduct {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Insert a new supplier product
    function insertSupplierProduct($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            if (empty($data['supplier_id'])) {
                throw new Exception("Supplier ID is required");
            }
            
            if (empty($data['product_id'])) {
                throw new Exception("Product ID is required");
            }

            // Generate UUID
            $supplierProductId = $this->generateUuid();

            // Check if this supplier-product combination already exists
            $checkSql = "SELECT COUNT(*) as count FROM supplier_products 
                         WHERE supplier_id = :supplierId AND product_id = :productId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":supplierId", $data['supplier_id']);
            $checkStmt->bindValue(":productId", $data['product_id']);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("This product is already associated with this supplier");
            }

            // Prepare data
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            // Insert supplier product
            $sql = "INSERT INTO supplier_products(
                        supplier_product_id, supplier_id, product_id, is_active
                    ) VALUES(
                        :supplierProductId, :supplierId, :productId, :isActive
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierProductId", $supplierProductId);
            $stmt->bindValue(":supplierId", $data['supplier_id']);
            $stmt->bindValue(":productId", $data['product_id']);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create supplier product");
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Supplier product created successfully',
                'data' => [
                    'supplier_product_id' => $supplierProductId,
                    'supplier_id' => $data['supplier_id'],
                    'product_id' => $data['product_id'],
                    'is_active' => $isActive
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

    // Update supplier product
    function updateSupplierProduct($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['supplier_product_id'])) {
                throw new Exception("Supplier Product ID is required");
            }

            // Prepare data
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            $sql = "UPDATE supplier_products SET
                        is_active = :isActive
                    WHERE supplier_product_id = :supplierProductId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":supplierProductId", $data['supplier_product_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update supplier product");
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Supplier product updated successfully',
                'data' => [
                    'supplier_product_id' => $data['supplier_product_id'],
                    'is_active' => $isActive
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

    // Get all supplier products
    function getAllSupplierProducts() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT sp.*, s.supplier_name, p.product_name 
                    FROM supplier_products sp
                    JOIN suppliers s ON sp.supplier_id = s.supplier_id
                    JOIN products p ON sp.product_id = p.product_id
                    ORDER BY s.supplier_name, p.product_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $supplierProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier products retrieved successfully',
                'data' => $supplierProducts
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get active supplier products only
    function getActiveSupplierProducts() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT sp.*, s.supplier_name, p.product_name 
                    FROM supplier_products sp
                    JOIN suppliers s ON sp.supplier_id = s.supplier_id
                    JOIN products p ON sp.product_id = p.product_id
                    WHERE sp.is_active = 1
                    ORDER BY s.supplier_name, p.product_name";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $supplierProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Active supplier products retrieved successfully',
                'data' => $supplierProducts
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get products by supplier
    function getProductsBySupplier($json) {
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

            $sql = "SELECT sp.*, p.product_name, p.product_code, p.price
                    FROM supplier_products sp
                    JOIN products p ON sp.product_id = p.product_id
                    WHERE sp.supplier_id = :supplierId
                    ORDER BY p.product_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $json['supplier_id']);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier products retrieved successfully',
                'data' => $products
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get suppliers by product
    function getSuppliersByProduct($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['product_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: product_id'
                ]);
                return;
            }

            $sql = "SELECT sp.*, s.supplier_name, s.contact_person, s.phone
                    FROM supplier_products sp
                    JOIN suppliers s ON sp.supplier_id = s.supplier_id
                    WHERE sp.product_id = :productId
                    ORDER BY s.supplier_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":productId", $json['product_id']);
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Product suppliers retrieved successfully',
                'data' => $suppliers
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Delete a supplier product
    function deleteSupplierProduct($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['supplier_product_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_product_id'
                ]);
                return;
            }

            $supplierProductId = $data['supplier_product_id'];

            // First check if supplier product exists
            $checkSql = "SELECT COUNT(*) as count FROM supplier_products 
                        WHERE supplier_product_id = :supplierProductId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindParam(":supplierProductId", $supplierProductId);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($result['count'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Supplier product not found'
                ]);
                return;
            }

            // Delete the supplier product
            $sql = "DELETE FROM supplier_products 
                    WHERE supplier_product_id = :supplierProductId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":supplierProductId", $supplierProductId);
            $stmt->execute();

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier product deleted successfully',
                'data' => [
                    'supplier_product_id' => $supplierProductId
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search supplier products
    function searchSupplierProducts($json) {
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
            $sql = "SELECT sp.*, s.supplier_name, p.product_name 
                    FROM supplier_products sp
                    JOIN suppliers s ON sp.supplier_id = s.supplier_id
                    JOIN products p ON sp.product_id = p.product_id
                    WHERE s.supplier_name LIKE :searchTerm 
                    OR p.product_name LIKE :searchTerm
                    OR p.product_code LIKE :searchTerm
                    ORDER BY s.supplier_name, p.product_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier products search completed',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getSuppliersWithProducts() {
          include "connection-pdo.php";

        try {
            $sql = "SELECT s.*, COUNT(sp.product_id) as product_count
                    FROM suppliers s
                    INNER JOIN supplier_products sp ON s.supplier_id = sp.supplier_id
                    WHERE s.is_active = 1 AND sp.is_active = 1
                    GROUP BY s.supplier_id
                    HAVING product_count > 0
                    ORDER BY s.supplier_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Suppliers with products retrieved successfully',
                'data' => $suppliers,
                'count' => count($suppliers)
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
}

// Handle request
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

$supplierProduct = new SupplierProduct();

if ($operation === "insertSupplierProduct") {
    echo $supplierProduct->insertSupplierProduct($data);
} elseif ($operation === "updateSupplierProduct") {
    echo $supplierProduct->updateSupplierProduct($data);
} else {
    // Handle other operations
    switch($operation) {
        case "getAllSupplierProducts":
            $supplierProduct->getAllSupplierProducts();
            break;
        case "getActiveSupplierProducts":
            $supplierProduct->getActiveSupplierProducts();
            break;
        case "getProductsBySupplier":
            $supplierProduct->getProductsBySupplier($json);
            break;
        case "getSuppliersByProduct":
            $supplierProduct->getSuppliersByProduct($json);
            break;
        case "deleteSupplierProduct":
            $supplierProduct->deleteSupplierProduct($json);
            break;
        case "searchSupplierProducts":
            $supplierProduct->searchSupplierProducts($json);
            break;
        case "getSuppliersWithProducts":
            $supplierProduct->getSuppliersWithProducts();
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>