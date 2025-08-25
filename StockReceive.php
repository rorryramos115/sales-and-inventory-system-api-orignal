<?php
// StockReceive.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit();
}

class StockReceive {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Receive stock against a purchase order
    function receiveStock($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['order_id'])) {
                throw new Exception("Order ID is required");
            }
            if (empty($data['warehouse_id'])) {
                throw new Exception("Warehouse ID is required");
            }
            if (empty($data['received_by'])) {
                throw new Exception("Received by user ID is required");
            }
            if (empty($data['supplier_receipt'])) {
                throw new Exception("Supplier receipt number is required");
            }
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Items are required");
            }

            // Verify purchase order exists
            $orderCheckSql = "SELECT order_id, supplier_id, total_amount FROM purchase_orders WHERE order_id = :orderId";
            $orderCheckStmt = $conn->prepare($orderCheckSql);
            $orderCheckStmt->bindValue(":orderId", $data['order_id']);
            $orderCheckStmt->execute();
            $order = $orderCheckStmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Purchase order not found");
            }

            // Check if already received
            $receiveCheckSql = "SELECT receive_id FROM stock_receive WHERE order_id = :orderId";
            $receiveCheckStmt = $conn->prepare($receiveCheckSql);
            $receiveCheckStmt->bindValue(":orderId", $data['order_id']);
            $receiveCheckStmt->execute();
            
            if ($receiveCheckStmt->fetch()) {
                throw new Exception("This purchase order has already been received");
            }

            // Generate UUID
            $receiveId = $this->generateUuid();
            
            // Prepare data
            $receiveDate = $data['receive_date'] ?? date('Y-m-d');
            
            // Calculate total amount from received items
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_cost'])) {
                    throw new Exception("Product ID, quantity, and unit cost are required for all items");
                }
                $totalAmount += $item['quantity'] * $item['unit_cost'];
            }

            // Insert stock receive record
            $receiveSql = "INSERT INTO stock_receive(
                            receive_id, supplier_receipt, order_id, 
                            receive_date, supplier_id, warehouse_id, received_by, total_amount
                        ) VALUES(
                            :receiveId, :supplierReceipt, :orderId, 
                            :receiveDate, :supplierId, :warehouseId, :receivedBy, :totalAmount
                        )";
            
            $receiveStmt = $conn->prepare($receiveSql);
            $receiveStmt->bindValue(":receiveId", $receiveId);
            $receiveStmt->bindValue(":supplierReceipt", $data['supplier_receipt']);
            $receiveStmt->bindValue(":orderId", $data['order_id']);
            $receiveStmt->bindValue(":receiveDate", $receiveDate);
            $receiveStmt->bindValue(":supplierId", $order['supplier_id']);
            $receiveStmt->bindValue(":warehouseId", $data['warehouse_id']);
            $receiveStmt->bindValue(":receivedBy", $data['received_by']);
            $receiveStmt->bindValue(":totalAmount", $totalAmount);
            
            if (!$receiveStmt->execute()) {
                throw new Exception("Failed to create stock receive record");
            }

            // Process each item
            foreach ($data['items'] as $item) {
                $receiveItemId = $this->generateUuid();
                
                // Insert stock receive item
                $receiveItemSql = "INSERT INTO stock_receive_items(
                                    receive_item_id, receive_id, product_id, quantity, 
                                    unit_cost
                                ) VALUES(
                                    :receiveItemId, :receiveId, :productId, :quantity, 
                                    :unitCost
                                )";
                
                $receiveItemStmt = $conn->prepare($receiveItemSql);
                $receiveItemStmt->bindValue(":receiveItemId", $receiveItemId);
                $receiveItemStmt->bindValue(":receiveId", $receiveId);
                $receiveItemStmt->bindValue(":productId", $item['product_id']);
                $receiveItemStmt->bindValue(":quantity", $item['quantity'], PDO::PARAM_INT);
                $receiveItemStmt->bindValue(":unitCost", $item['unit_cost']);
                
                if (!$receiveItemStmt->execute()) {
                    throw new Exception("Failed to create receive item");
                }

                // Update warehouse stock
                $this->updateWarehouseStock($conn, $data['warehouse_id'], $item['product_id'], 
                                        $item['quantity'], $item['unit_cost']);
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Stock received and added to warehouse successfully',
                'data' => [
                    'receive_id' => $receiveId,
                    'order_id' => $data['order_id'],
                    'supplier_receipt' => $data['supplier_receipt'],
                    'receive_date' => $receiveDate,
                    'warehouse_id' => $data['warehouse_id'],
                    'total_amount' => $totalAmount
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

    // Direct stock receive without purchase order
    function directStockReceive($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['supplier_id'])) {
                throw new Exception("Supplier ID is required");
            }
            if (empty($data['warehouse_id'])) {
                throw new Exception("Warehouse ID is required");
            }
            if (empty($data['received_by'])) {
                throw new Exception("Received by user ID is required");
            }
            if (empty($data['supplier_receipt'])) {
                throw new Exception("Supplier receipt number is required");
            }
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Items are required");
            }

            // Generate UUID
            $receiveId = $this->generateUuid();
            
            // Prepare data
            $receiveDate = $data['receive_date'] ?? date('Y-m-d');
            
            // Calculate total amount
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_cost'])) {
                    throw new Exception("Product ID, quantity, and unit cost are required for all items");
                }
                $totalAmount += $item['quantity'] * $item['unit_cost'];
            }

            // Insert stock receive record (without order_id)
            $receiveSql = "INSERT INTO stock_receive(
                            receive_id, supplier_receipt, receive_date, 
                            supplier_id, warehouse_id, received_by, total_amount
                        ) VALUES(
                            :receiveId, :supplierReceipt, :receiveDate, 
                            :supplierId, :warehouseId, :receivedBy, :totalAmount
                        )";
            
            $receiveStmt = $conn->prepare($receiveSql);
            $receiveStmt->bindValue(":receiveId", $receiveId);
            $receiveStmt->bindValue(":supplierReceipt", $data['supplier_receipt']);
            $receiveStmt->bindValue(":receiveDate", $receiveDate);
            $receiveStmt->bindValue(":supplierId", $data['supplier_id']);
            $receiveStmt->bindValue(":warehouseId", $data['warehouse_id']);
            $receiveStmt->bindValue(":receivedBy", $data['received_by']);
            $receiveStmt->bindValue(":totalAmount", $totalAmount);
            
            if (!$receiveStmt->execute()) {
                throw new Exception("Failed to create stock receive record");
            }

            // Process each item
            foreach ($data['items'] as $item) {
                $receiveItemId = $this->generateUuid();
                
                // Insert stock receive item
                $receiveItemSql = "INSERT INTO stock_receive_items(
                                    receive_item_id, receive_id, product_id, quantity, 
                                    unit_cost
                                ) VALUES(
                                    :receiveItemId, :receiveId, :productId, :quantity, 
                                    :unitCost
                                )";
                
                $receiveItemStmt = $conn->prepare($receiveItemSql);
                $receiveItemStmt->bindValue(":receiveItemId", $receiveItemId);
                $receiveItemStmt->bindValue(":receiveId", $receiveId);
                $receiveItemStmt->bindValue(":productId", $item['product_id']);
                $receiveItemStmt->bindValue(":quantity", $item['quantity'], PDO::PARAM_INT);
                $receiveItemStmt->bindValue(":unitCost", $item['unit_cost']);
                
                if (!$receiveItemStmt->execute()) {
                    throw new Exception("Failed to create receive item");
                }

                // Update warehouse stock
                $this->updateWarehouseStock($conn, $data['warehouse_id'], $item['product_id'], 
                                        $item['quantity'], $item['unit_cost']);
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Direct stock receive completed successfully',
                'data' => [
                    'receive_id' => $receiveId,
                    'supplier_receipt' => $data['supplier_receipt'],
                    'receive_date' => $receiveDate,
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'total_amount' => $totalAmount
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

    private function updateWarehouseStock($conn, $warehouseId, $productId, $quantity, $unitCost) {
        // Check if stock record exists
        $checkSql = "SELECT stock_id, quantity FROM warehouse_stock 
                     WHERE warehouse_id = :warehouseId AND product_id = :productId";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindValue(":warehouseId", $warehouseId);
        $checkStmt->bindValue(":productId", $productId);
        $checkStmt->execute();
        $existingStock = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingStock) {
            // Update existing stock
            $newQuantity = $existingStock['quantity'] + $quantity;
            $updateSql = "UPDATE warehouse_stock 
                         SET quantity = :quantity, unit_price = :unitCost, last_updated = CURRENT_TIMESTAMP
                         WHERE stock_id = :stockId";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bindValue(":quantity", $newQuantity, PDO::PARAM_INT);
            $updateStmt->bindValue(":unitCost", $unitCost);
            $updateStmt->bindValue(":stockId", $existingStock['stock_id']);
            $updateStmt->execute();
        } else {
            // Create new stock record
            $stockId = $this->generateUuid();
            $insertSql = "INSERT INTO warehouse_stock(
                             stock_id, warehouse_id, product_id, quantity, unit_price
                         ) VALUES(
                             :stockId, :warehouseId, :productId, :quantity, :unitCost
                         )";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bindValue(":stockId", $stockId);
            $insertStmt->bindValue(":warehouseId", $warehouseId);
            $insertStmt->bindValue(":productId", $productId);
            $insertStmt->bindValue(":quantity", $quantity, PDO::PARAM_INT);
            $insertStmt->bindValue(":unitCost", $unitCost);
            $insertStmt->execute();
        }
    }

    // Get all stock receives
    function getAllStockReceives() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        sr.receive_id,
                        sr.supplier_receipt,
                        sr.order_id,
                        sr.receive_date,
                        sr.total_amount,
                        sr.created_at,
                        sr.updated_at,
                        
                        -- Supplier information
                        s.supplier_id,
                        s.supplier_name,
                        s.contact_person,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        s.address as supplier_address,
                        
                        -- Warehouse information
                        w.warehouse_id,
                        w.warehouse_name,
                        w.address as warehouse_address,
                        w.is_main,
                        
                        -- Received by user information
                        u.user_id as received_by_id,
                        u.full_name as received_by_name,
                        u.email as received_by_email,
                        
                        -- Order information (if linked)
                        po.order_date,
                        po.total_amount as order_total_amount,
                        cu.full_name as order_created_by_name,
                        
                        -- Type of receive
                        CASE 
                            WHEN sr.order_id IS NOT NULL THEN 'FROM_ORDER'
                            ELSE 'DIRECT'
                        END as receive_type
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    LEFT JOIN purchase_orders po ON sr.order_id = po.order_id
                    LEFT JOIN users cu ON po.created_by = cu.user_id
                    ORDER BY sr.receive_date DESC, sr.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get receive items for each receive
            foreach ($receives as &$receive) {
                $receiveItemsSql = "SELECT 
                                    sri.receive_item_id,
                                    sri.product_id,
                                    sri.quantity,
                                    sri.unit_cost,
                                    (sri.quantity * sri.unit_cost) as total_price,
                                    sri.created_at as receive_item_created_at,
                                    
                                    -- Product information
                                    p.product_name,
                                    p.barcode,
                                    p.description as product_description,
                                    p.selling_price,
                                    
                                    -- Category information (if exists)
                                    c.category_id,
                                    c.category_name,
                                    
                                    -- Brand information
                                    b.brand_id,
                                    b.brand_name,
                                    
                                    -- Unit information
                                    un.unit_id,
                                    un.unit_name
                                    
                                    FROM stock_receive_items sri
                                    INNER JOIN products p ON sri.product_id = p.product_id
                                    LEFT JOIN categories c ON p.category_id = c.category_id
                                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                                    LEFT JOIN units un ON p.unit_id = un.unit_id
                                    WHERE sri.receive_id = :receiveId
                                    ORDER BY sri.created_at ASC";
                
                $receiveItemsStmt = $conn->prepare($receiveItemsSql);
                $receiveItemsStmt->bindValue(":receiveId", $receive['receive_id']);
                $receiveItemsStmt->execute();
                $receive['items'] = $receiveItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'All stock receives retrieved successfully',
                'data' => [
                    'total_receives' => count($receives),
                    'receives' => $receives
                ]
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

$stockReceive = new StockReceive();

// Handle operations
switch($operation) {
    case "receiveStock":
        echo $stockReceive->receiveStock($data);
        break;
        
    case "directStockReceive":
        echo $stockReceive->directStockReceive($data);
        break;
        
    case "getAllStockReceives":
        echo $stockReceive->getAllStockReceives();
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}



?>