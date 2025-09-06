<?php
// POSAPI.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class POSAPI {
    private $conn;
    
    public function __construct() {
        include "connection-pdo.php";
        $this->conn = $conn;
    }

    // Get product by barcode or product ID
    // Get product by barcode or product ID - only if available in store stock
    public function getProduct($json) {
        try {
            $data = json_decode($json, true);
            
            if (empty($data['barcode']) && empty($data['product_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: barcode or product_id'
                ]);
                return;
            }

            // Check if store_id is provided
            if (empty($data['store_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        p.selling_price,
                        p.barcode,
                        p.description,
                        c.category_name,
                        b.brand_name,
                        u.unit_name,
                        COALESCE(ss.quantity, 0) as stock_quantity
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    LEFT JOIN store_stock ss ON p.product_id = ss.product_id AND ss.store_id = :store_id
                    WHERE p.is_active = 1
                    AND COALESCE(ss.quantity, 0) > 0"; // Only products with stock > 0

            if (!empty($data['barcode'])) {
                $sql .= " AND p.barcode = :barcode";
            } else {
                $sql .= " AND p.product_id = :product_id";
            }

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":store_id", $data['store_id']);
            
            if (!empty($data['barcode'])) {
                $stmt->bindValue(":barcode", $data['barcode']);
            } else {
                $stmt->bindValue(":product_id", $data['product_id']);
            }
            
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                $message = empty($data['barcode']) 
                    ? "Product ID {$data['product_id']} not found or out of stock in store" 
                    : "Barcode {$data['barcode']} not found or out of stock in store";
                
                echo json_encode([
                    'status' => 'error',
                    'message' => $message
                ]);
                return;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Product retrieved successfully',
                'data' => $product
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Search products by name
        public function searchProducts($json) {
        try {
            $data = json_decode($json, true);
            
            if (empty($data['search_term'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: search_term'
                ]);
                return;
            }

            // Check if store_id is provided
            if (empty($data['store_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $limit = $data['limit'] ?? 20;
            $store_id = $data['store_id'];

            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        p.selling_price,
                        p.barcode,
                        c.category_name,
                        b.brand_name,
                        u.unit_name,
                        ss.quantity as stock_quantity
                    FROM store_stock ss
                    INNER JOIN products p ON ss.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    WHERE ss.store_id = :store_id 
                    AND ss.quantity > 0
                    AND p.is_active = 1
                    AND (p.product_name LIKE :search_term 
                        OR b.brand_name LIKE :search_term
                        OR p.barcode LIKE :search_term)
                    ORDER BY p.product_name ASC
                    LIMIT :limit";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":store_id", $store_id);
            $stmt->bindValue(":search_term", '%' . $data['search_term'] . '%');
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Products retrieved successfully',
                'data' => [
                    'products' => $products,
                    'count' => count($products)
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
   
    // Create new sale
    public function createSale($json) {
        try {
            $data = json_decode($json, true);
            
            // Validate required fields
            $required_fields = ['store_id', 'counter_id', 'user_id', 'items'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Missing required field: $field"
                    ]);
                    return;
                }
            }

            if (empty($data['items']) || !is_array($data['items'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Items must be a non-empty array'
                ]);
                return;
            }

            $this->conn->beginTransaction();

            // Generate sale ID and code
            $sale_id = $this->generateUUID();
            $sale_code = $this->generateSaleCode();
            
            // Calculate totals
            $total_items = 0;
            $subtotal = 0;
            
            foreach ($data['items'] as $item) {
                $total_items += $item['quantity'];
                $subtotal += ($item['quantity'] * $item['unit_price']);
            }
            
            $total_amount = $subtotal; // Add tax calculation if needed

            // Insert sale record
            $sql = "INSERT INTO sales (
                        sale_id, sale_code, store_id, sale_date, counter_id, 
                        user_id, total_items, subtotal, total_amount
                    ) VALUES (
                        :sale_id, :sale_code, :store_id, NOW(), :counter_id,
                        :user_id, :total_items, :subtotal, :total_amount
                    )";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":sale_id", $sale_id);
            $stmt->bindValue(":sale_code", $sale_code);
            $stmt->bindValue(":store_id", $data['store_id']);
            $stmt->bindValue(":counter_id", $data['counter_id']);
            $stmt->bindValue(":user_id", $data['user_id']);
            $stmt->bindValue(":total_items", $total_items);
            $stmt->bindValue(":subtotal", $subtotal);
            $stmt->bindValue(":total_amount", $total_amount);
            $stmt->execute();

            // Insert sale items and update stock
            foreach ($data['items'] as $item) {
                // Insert sale item
                $sale_item_id = $this->generateUUID();
                $sql = "INSERT INTO sales_items (
                            sale_item_id, sale_id, product_id, quantity, unit_price, total_price
                        ) VALUES (
                            :sale_item_id, :sale_id, :product_id, :quantity, :unit_price, :total_price
                        )";

                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(":sale_item_id", $sale_item_id);
                $stmt->bindValue(":sale_id", $sale_id);
                $stmt->bindValue(":product_id", $item['product_id']);
                $stmt->bindValue(":quantity", $item['quantity']);
                $stmt->bindValue(":unit_price", $item['unit_price']);
                $stmt->bindValue(":total_price", $item['quantity'] * $item['unit_price']);
                $stmt->execute();

                // Update store stock
                $sql = "UPDATE store_stock 
                        SET quantity = quantity - :quantity,
                            last_updated = NOW()
                        WHERE store_id = :store_id AND product_id = :product_id";

                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(":quantity", $item['quantity']);
                $stmt->bindValue(":store_id", $data['store_id']);
                $stmt->bindValue(":product_id", $item['product_id']);
                $stmt->execute();
            }

            // Create receipt
            $receipt_id = $this->generateUUID();
            $receipt_number = $this->generateReceiptNumber();
            
            $sql = "INSERT INTO receipts (
                        receipt_id, receipt_number, sale_id, receipt_date, amount, created_by
                    ) VALUES (
                        :receipt_id, :receipt_number, :sale_id, NOW(), :amount, :created_by
                    )";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":receipt_id", $receipt_id);
            $stmt->bindValue(":receipt_number", $receipt_number);
            $stmt->bindValue(":sale_id", $sale_id);
            $stmt->bindValue(":amount", $total_amount);
            $stmt->bindValue(":created_by", $data['user_id']);
            $stmt->execute();

            $this->conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Sale created successfully',
                'data' => [
                    'sale_id' => $sale_id,
                    'sale_code' => $sale_code,
                    'receipt_id' => $receipt_id,
                    'receipt_number' => $receipt_number,
                    'total_amount' => $total_amount,
                    'total_items' => $total_items
                ]
            ]);
            
        } catch (PDOException $e) {
            $this->conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get sale details
    public function getSale($json) {
        try {
            $data = json_decode($json, true);
            
            if (empty($data['sale_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: sale_id'
                ]);
                return;
            }

            // Get sale details
            $sql = "SELECT 
                        s.*,
                        st.store_name,
                        c.counter_name,
                        u.full_name as cashier_name,
                        r.receipt_number
                    FROM sales s
                    LEFT JOIN store st ON s.store_id = st.store_id
                    LEFT JOIN counters c ON s.counter_id = c.counter_id
                    LEFT JOIN users u ON s.user_id = u.user_id
                    LEFT JOIN receipts r ON s.sale_id = r.sale_id
                    WHERE s.sale_id = :sale_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":sale_id", $data['sale_id']);
            $stmt->execute();
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Sale not found'
                ]);
                return;
            }

            // Get sale items
            $sql = "SELECT 
                        si.*,
                        p.product_name,
                        p.barcode,
                        c.category_name,
                        b.brand_name,
                        u.unit_name
                    FROM sales_items si
                    LEFT JOIN products p ON si.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    WHERE si.sale_id = :sale_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":sale_id", $data['sale_id']);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sale['items'] = $items;

            echo json_encode([
                'status' => 'success',
                'message' => 'Sale retrieved successfully',
                'data' => $sale
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get counters by store
    public function getCountersByStore($json) {
        try {
            $data = json_decode($json, true);
            
            if (empty($data['store_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        counter_id,
                        counter_name,
                        is_active
                    FROM counters
                    WHERE store_id = :store_id AND is_active = 1
                    ORDER BY counter_name ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":store_id", $data['store_id']);
            $stmt->execute();
            $counters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Counters retrieved successfully',
                'data' => [
                    'counters' => $counters,
                    'count' => count($counters)
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get sales by date range
    public function getSalesByDateRange($json) {
        try {
            $data = json_decode($json, true);
            
            $start_date = $data['start_date'] ?? date('Y-m-d');
            $end_date = $data['end_date'] ?? date('Y-m-d');
            $store_id = $data['store_id'] ?? null;
            $counter_id = $data['counter_id'] ?? null;

            $sql = "SELECT 
                        s.sale_id,
                        s.sale_code,
                        s.sale_date,
                        s.total_items,
                        s.total_amount,
                        st.store_name,
                        c.counter_name,
                        u.full_name as cashier_name,
                        r.receipt_number
                    FROM sales s
                    LEFT JOIN store st ON s.store_id = st.store_id
                    LEFT JOIN counters c ON s.counter_id = c.counter_id
                    LEFT JOIN users u ON s.user_id = u.user_id
                    LEFT JOIN receipts r ON s.sale_id = r.sale_id
                    WHERE DATE(s.sale_date) BETWEEN :start_date AND :end_date";

            if ($store_id) {
                $sql .= " AND s.store_id = :store_id";
            }
            
            if ($counter_id) {
                $sql .= " AND s.counter_id = :counter_id";
            }

            $sql .= " ORDER BY s.sale_date DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":start_date", $start_date);
            $stmt->bindValue(":end_date", $end_date);
            
            if ($store_id) {
                $stmt->bindValue(":store_id", $store_id);
            }
            
            if ($counter_id) {
                $stmt->bindValue(":counter_id", $counter_id);
            }
            
            $stmt->execute();
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $total_sales = count($sales);
            $total_amount = array_sum(array_column($sales, 'total_amount'));
            $total_items = array_sum(array_column($sales, 'total_items'));

            echo json_encode([
                'status' => 'success',
                'message' => 'Sales retrieved successfully',
                'data' => [
                    'sales' => $sales,
                    'summary' => [
                        'total_sales' => $total_sales,
                        'total_amount' => $total_amount,
                        'total_items' => $total_items,
                        'average_sale' => $total_sales > 0 ? $total_amount / $total_sales : 0
                    ]
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Process return
    public function processReturn($json) {
        try {
            $data = json_decode($json, true);
            
            // Validate required fields
            $required_fields = ['original_sale_id', 'counter_id', 'user_id', 'warehouse_id', 'items'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Missing required field: $field"
                    ]);
                    return;
                }
            }

            $this->conn->beginTransaction();

            // Generate return ID
            $return_id = $this->generateUUID();
            
            // Calculate totals
            $total_items = 0;
            $total_amount = 0;
            
            foreach ($data['items'] as $item) {
                $total_items += $item['quantity'];
                $total_amount += ($item['quantity'] * $item['unit_price']);
            }

            // Insert return record
            $sql = "INSERT INTO sales_returns (
                        return_id, receipt_id, original_sale_id, return_date, counter_id,
                        user_id, warehouse_id, total_items, total_amount, reason
                    ) VALUES (
                        :return_id, :receipt_id, :original_sale_id, NOW(), :counter_id,
                        :user_id, :warehouse_id, :total_items, :total_amount, :reason
                    )";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":return_id", $return_id);
            $stmt->bindValue(":receipt_id", $data['receipt_id'] ?? null);
            $stmt->bindValue(":original_sale_id", $data['original_sale_id']);
            $stmt->bindValue(":counter_id", $data['counter_id']);
            $stmt->bindValue(":user_id", $data['user_id']);
            $stmt->bindValue(":warehouse_id", $data['warehouse_id']);
            $stmt->bindValue(":total_items", $total_items);
            $stmt->bindValue(":total_amount", $total_amount);
            $stmt->bindValue(":reason", $data['reason'] ?? '');
            $stmt->execute();

            // Insert return items and update stock
            foreach ($data['items'] as $item) {
                // Insert return item
                $return_item_id = $this->generateUUID();
                $sql = "INSERT INTO sales_return_items (
                            return_item_id, return_id, product_id, sale_item_id,
                            quantity, unit_price, total_price, condition, notes
                        ) VALUES (
                            :return_item_id, :return_id, :product_id, :sale_item_id,
                            :quantity, :unit_price, :total_price, :condition, :notes
                        )";

                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(":return_item_id", $return_item_id);
                $stmt->bindValue(":return_id", $return_id);
                $stmt->bindValue(":product_id", $item['product_id']);
                $stmt->bindValue(":sale_item_id", $item['sale_item_id']);
                $stmt->bindValue(":quantity", $item['quantity']);
                $stmt->bindValue(":unit_price", $item['unit_price']);
                $stmt->bindValue(":total_price", $item['quantity'] * $item['unit_price']);
                $stmt->bindValue(":condition", $item['condition'] ?? 'new');
                $stmt->bindValue(":notes", $item['notes'] ?? '');
                $stmt->execute();

                // Update store stock (add back to inventory)
                $sql = "INSERT INTO store_stock (store_stock_id, store_id, product_id, quantity, last_updated)
                        VALUES (:stock_id, :store_id, :product_id, :quantity, NOW())
                        ON DUPLICATE KEY UPDATE 
                        quantity = quantity + :quantity,
                        last_updated = NOW()";

                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(":stock_id", $this->generateUUID());
                $stmt->bindValue(":store_id", $data['warehouse_id']); // Using warehouse_id as store_id
                $stmt->bindValue(":product_id", $item['product_id']);
                $stmt->bindValue(":quantity", $item['quantity']);
                $stmt->execute();
            }

            $this->conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Return processed successfully',
                'data' => [
                    'return_id' => $return_id,
                    'total_amount' => $total_amount,
                    'total_items' => $total_items
                ]
            ]);
            
        } catch (PDOException $e) {
            $this->conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Helper functions
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function generateSaleCode() {
        return 'SALE-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function generateReceiptNumber() {
        return 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

// Handle request method and get parameters
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
    
    $json = json_encode($data);
}

$posAPI = new POSAPI();

// Handle operations
switch($operation) {
    case "getProduct":
        $posAPI->getProduct($json);
        break;
        
    case "searchProducts":
        $posAPI->searchProducts($json);
        break;
        
    case "createSale":
        $posAPI->createSale($json);
        break;
        
    case "getSale":
        $posAPI->getSale($json);
        break;
        
    case "getCountersByStore":
        $posAPI->getCountersByStore($json);
        break;
        
    case "getSalesByDateRange":
        $posAPI->getSalesByDateRange($json);
        break;
        
    case "processReturn":
        $posAPI->processReturn($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations: getProduct, searchProducts, createSale, getSale, getCountersByStore, getSalesByDateRange, processReturn'
        ]);
}
?>