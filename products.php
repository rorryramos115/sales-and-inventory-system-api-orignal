<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class Product {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Insert a new product (handles FormData)
    function insertProduct($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            $required = ['product_name', 'barcode', 'selling_price'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Validate price
            if (!is_numeric($data['selling_price']) || $data['selling_price'] <= 0) {
                throw new Exception("Price must be a positive number");
            }
            
            // Validate barcode
            if (empty($data['barcode'])) {
                throw new Exception("Barcode is required");
            }
            
            // Prepare data
            $productId = $this->generateUuid();
            $productName = $data['product_name'];
            $barcode = $data['barcode'];
            $sellingPrice = (float)$data['selling_price'];
            $categoryId = !empty($data['category_id']) ? $data['category_id'] : null;
            $description = $data['description'] ?? '';
            $isActive = $data['is_active'] ?? 1;
            
            // Insert product
            $sql = "INSERT INTO products(
                        product_id, product_name, barcode, category_id, 
                        selling_price, is_active, description
                    ) VALUES(
                        :productId, :productName, :barcode, :categoryId, 
                        :sellingPrice, :isActive, :description
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":productId", $productId);
            $stmt->bindValue(":productName", $productName);
            $stmt->bindValue(":barcode", $barcode);
            $stmt->bindValue(":categoryId", $categoryId, PDO::PARAM_STR);
            $stmt->bindValue(":sellingPrice", $sellingPrice);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":description", $description);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create product");
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Product created successfully',
                'data' => [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'barcode' => $barcode,
                    'selling_price' => $sellingPrice
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

    // Update product (handles FormData)
    function updateProduct($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['product_id'])) {
                throw new Exception("Product ID is required");
            }
            
            // Validate required fields
            $required = ['product_name', 'barcode', 'selling_price'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Prepare data
            $productName = $data['product_name'];
            $barcode = $data['barcode'];
            $sellingPrice = (float)$data['selling_price'];
            $categoryId = !empty($data['category_id']) ? $data['category_id'] : null;
            $description = $data['description'] ?? '';
            $isActive = $data['is_active'] ?? 1;

            $sql = "UPDATE products SET
                        product_name = :productName,
                        barcode = :barcode,
                        category_id = :categoryId,
                        selling_price = :sellingPrice,
                        is_active = :isActive,
                        description = :description
                    WHERE product_id = :productId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":productName", $productName);
            $stmt->bindValue(":barcode", $barcode);
            $stmt->bindValue(":categoryId", $categoryId, PDO::PARAM_STR);
            $stmt->bindValue(":sellingPrice", $sellingPrice);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":description", $description);
            $stmt->bindValue(":productId", $data['product_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update product");
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Product updated successfully',
                'data' => [
                    'product_id' => $data['product_id'],
                    'product_name' => $productName,
                    'barcode' => $barcode,
                    'selling_price' => $sellingPrice
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

    // Get all products
    function getAllProducts() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT p.*, c.category_name
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    ORDER BY p.product_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Products retrieved successfully',
                'data' => $products
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get a single product
    function getProduct($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['product_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: product_id is required'
                ]);
                return;
            }

            $sql = "SELECT p.*, c.category_name
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    WHERE p.product_id = :productId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":productId", $json['product_id']);
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if($product) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Product retrieved successfully',
                    'data' => $product
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Product not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Delete a product
    function deleteProduct($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['product_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: product_id is required'
                ]);
                return;
            }

            $sql = "DELETE FROM products WHERE product_id = :productId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":productId", $json['product_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Product deleted successfully',
                    'data' => [
                        'product_id' => $json['product_id']
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Product not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Check if barcode exists
    function checkBarcode($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['barcode'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: barcode is required'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM products WHERE barcode = :barcode";
            $params = [':barcode' => $json['barcode']];
            
            if(isset($json['product_id'])) {
                $sql .= " AND product_id != :productId";
                $params[':productId'] = $json['product_id'];
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
                    'barcode' => $json['barcode']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search products by name or barcode
    function searchProducts($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['search_term'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: search_term is required'
                ]);
                return;
            }

            $searchTerm = '%' . $json['search_term'] . '%';
            $sql = "SELECT p.*, c.category_name
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    WHERE p.product_name LIKE :searchTerm 
                    OR p.barcode LIKE :searchTerm
                    ORDER BY p.product_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Products search completed',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Add this method to your Product class
    function getAvailableProductsForSupplier($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['supplier_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_id is required'
                ]);
                return;
            }

            $sql = "SELECT p.*, c.category_name
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    WHERE p.is_active = 1
                    AND p.product_id NOT IN (
                        SELECT product_id 
                        FROM supplier_products 
                        WHERE supplier_id = :supplierId
                        AND is_active = 1
                    )
                    ORDER BY p.product_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $json['supplier_id']);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Available products retrieved successfully',
                'data' => $products
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Add this method to your Product class
    function getSupplierProducts($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['supplier_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_id is required'
                ]);
                return;
            }

            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        p.barcode,
                        p.selling_price,
                        p.description,
                        p.is_active as product_active,
                        c.category_name,
                        sp.supplier_product_id,
                        sp.is_active as supplier_product_active
                    FROM supplier_products sp
                    JOIN products p ON sp.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
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
}

// Request handling remains the same as before
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

// Handle request
$product = new Product();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($operation, ['insertProduct', 'updateProduct'])) {
        $data = $_POST;
        
        if (empty($data) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
        }
        
        echo $product->$operation($data);
    } else {
        switch($operation) {
            case "getAllProducts":
                echo $product->getAllProducts();
                break;
            case "getProduct":
                echo $product->getProduct($json);
                break;
            case "deleteProduct":
                echo $product->deleteProduct($json);
                break;
            case "checkBarcode":
                echo $product->checkBarcode($json);
                break;
            case "searchProducts":
                echo $product->searchProducts($json);
                break;
            case "getAvailableProductsForSupplier":
                echo $product->getAvailableProductsForSupplier($json);
                break;
            case "getSupplierProducts":
                echo $product->getSupplierProducts($json);
                break;
            default:
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid operation'
                ]);
        }
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>