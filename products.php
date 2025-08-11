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

    private function generateBarcode() {
        return 'PROD' . uniqid(); 
    }

    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        return "$protocol://$host/Sales-Inventory/backend";
    }

    private function handleFileUpload() {
        if (empty($_FILES['product_image']['name'])) {
            return '';
        }

        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }
        
        // Validate file type and size
        $fileInfo = getimagesize($_FILES['product_image']['tmp_name']);
        if ($fileInfo === false) {
            throw new Exception("Invalid image file");
        }
        
        if ($_FILES['product_image']['size'] > 2 * 1024 * 1024) {
            throw new Exception("File is too large. Max 2MB allowed.");
        }
        
        $extension = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("Only JPG, JPEG, PNG & GIF files are allowed.");
        }
        
        $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $_FILES['product_image']['name']);
        $targetFile = $uploadDir . $fileName;
        
        if (!move_uploaded_file($_FILES['product_image']['tmp_name'], $targetFile)) {
            throw new Exception("Failed to upload file");
        }
        
        return $fileName;
    }

    // Insert a new product (handles FormData)
    function insertProduct($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Handle file upload
            $uploadedFile = $this->handleFileUpload();
            
            // Validate required fields
            $required = ['product_name', 'product_sku', 'selling_price'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Validate price
            if (!is_numeric($data['selling_price']) || $data['selling_price'] <= 0) {
                throw new Exception("Price must be a positive number");
            }
            
            // Prepare data
            $productId = $this->generateUuid();
            $productName = $data['product_name'];
            $productSku = $data['product_sku'];
            $sellingPrice = (float)$data['selling_price'];
            $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
            $description = $data['description'] ?? '';
            $isActive = $data['is_active'] ?? 1;
            
            // Generate barcode if not provided
            $barcode = empty($data['barcode']) ? $this->generateBarcode() : $data['barcode'];

            // Insert product
            $sql = "INSERT INTO products(
                        product_id, product_sku, product_name, barcode, category_id, 
                        selling_price, is_active, description, product_image
                    ) VALUES(
                        :productId, :productSku, :productName, :barcode, :categoryId, 
                        :sellingPrice, :isActive, :description, :productImage
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":productId", $productId);
            $stmt->bindValue(":productSku", $productSku);
            $stmt->bindValue(":productName", $productName);
            $stmt->bindValue(":barcode", $barcode);
            $stmt->bindValue(":categoryId", $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(":sellingPrice", $sellingPrice);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":description", $description);
            $stmt->bindValue(":productImage", $uploadedFile);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create product");
            }
            
            $conn->commit();
            
            // Return with full image URL
            $imageUrl = $uploadedFile ? $this->getBaseUrl() . '/uploads/' . $uploadedFile : null;
            
            return json_encode([
                'status' => 'success',
                'message' => 'Product created successfully',
                'data' => [
                    'product_id' => $productId,
                    'product_sku' => $productSku,
                    'product_name' => $productName,
                    'barcode' => $barcode,
                    'selling_price' => $sellingPrice,
                    'product_image' => $uploadedFile,
                    'product_image_url' => $imageUrl
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

            // Handle file upload if new image provided
            $uploadedFile = $this->handleFileUpload();
            
            // Get existing image if no new upload
            if (empty($uploadedFile)) {
                $sql = "SELECT product_image FROM products WHERE product_id = :productId";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(":productId", $data['product_id']);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $uploadedFile = $result['product_image'] ?? '';
            }
            
            // Validate required fields
            $required = ['product_name', 'product_sku', 'selling_price'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Prepare data
            $productName = $data['product_name'];
            $productSku = $data['product_sku'];
            $sellingPrice = (float)$data['selling_price'];
            $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
            $description = $data['description'] ?? '';
            $isActive = $data['is_active'] ?? 1;

            $sql = "UPDATE products SET
                        product_sku = :productSku,
                        product_name = :productName,
                        category_id = :categoryId,
                        selling_price = :sellingPrice,
                        is_active = :isActive,
                        description = :description,
                        product_image = :productImage
                    WHERE product_id = :productId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":productSku", $productSku);
            $stmt->bindValue(":productName", $productName);
            $stmt->bindValue(":categoryId", $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(":sellingPrice", $sellingPrice);
            $stmt->bindValue(":isActive", $isActive, PDO::PARAM_INT);
            $stmt->bindValue(":description", $description);
            $stmt->bindValue(":productImage", $uploadedFile);
            $stmt->bindValue(":productId", $data['product_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update product");
            }
            
            $conn->commit();
            
            // Return with full image URL
            $imageUrl = $uploadedFile ? $this->getBaseUrl() . '/uploads/' . $uploadedFile : null;
            
            return json_encode([
                'status' => 'success',
                'message' => 'Product updated successfully',
                'data' => [
                    'product_id' => $data['product_id'],
                    'product_sku' => $productSku,
                    'product_name' => $productName,
                    'selling_price' => $sellingPrice,
                    'product_image' => $uploadedFile,
                    'product_image_url' => $imageUrl
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

    // Get all products with image URLs
    function getAllProducts() {
        include "connection-pdo.php";

        try {
            $baseUrl = $this->getBaseUrl();
            $sql = "SELECT p.*, c.category_name,
                    CASE WHEN p.product_image IS NOT NULL 
                         THEN CONCAT(:baseUrl, '/uploads/', p.product_image) 
                         ELSE NULL 
                    END as product_image_url
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    ORDER BY p.product_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":baseUrl", $baseUrl);
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

    // Get a single product with image URL
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

            $baseUrl = $this->getBaseUrl();
            $sql = "SELECT p.*, c.category_name,
                    CASE WHEN p.product_image IS NOT NULL 
                         THEN CONCAT(:baseUrl, '/uploads/', p.product_image) 
                         ELSE NULL 
                    END as product_image_url
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    WHERE p.product_id = :productId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":baseUrl", $baseUrl);
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

    // Check if barcode or SKU exists
    function checkBarcodeOrSku($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['barcode']) && empty($json['product_sku'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: either barcode or product_sku is required'
                ]);
                return;
            }

            $sql = "SELECT COUNT(*) as count FROM products WHERE ";
            $conditions = [];
            $params = [];
            
            if(!empty($json['barcode'])) {
                $conditions[] = "barcode = :barcode";
                $params[':barcode'] = $json['barcode'];
            }
            
            if(!empty($json['product_sku'])) {
                $conditions[] = "product_sku = :productSku";
                $params[':productSku'] = $json['product_sku'];
            }
            
            $sql .= implode(" OR ", $conditions);
            
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
                    'barcode' => $json['barcode'] ?? null,
                    'product_sku' => $json['product_sku'] ?? null
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search products by name, SKU or barcode
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

            $baseUrl = $this->getBaseUrl();
            $searchTerm = '%' . $json['search_term'] . '%';
            $sql = "SELECT p.*, c.category_name,
                    CASE WHEN p.product_image IS NOT NULL 
                         THEN CONCAT(:baseUrl, '/uploads/', p.product_image) 
                         ELSE NULL 
                    END as product_image_url
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.category_id 
                    WHERE p.product_name LIKE :searchTerm 
                    OR p.product_sku LIKE :searchTerm
                    OR p.barcode LIKE :searchTerm
                    ORDER BY p.product_name
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":baseUrl", $baseUrl);
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
}

// Handle request
$product = new Product();
$operation = $_REQUEST['operation'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($operation, ['insertProduct', 'updateProduct'])) {
        // For form data submissions (with potential file upload)
        $data = $_POST;
        
        // If content-type is JSON, parse the input
        if (empty($data) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
        }
        
        echo $product->$operation($data);
    } else {
        // For other operations (GET or JSON POST)
        $json = $_SERVER['REQUEST_METHOD'] === 'GET' 
            ? ($_GET['json'] ?? '') 
            : (file_get_contents('php://input') ?: '');
        
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
            case "checkBarcodeOrSku":
                echo $product->checkBarcodeOrSku($json);
                break;
            case "searchProducts":
                echo $product->searchProducts($json);
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