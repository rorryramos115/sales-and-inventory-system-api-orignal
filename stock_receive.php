<?php
// stock_receive.php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

class StockIn {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Create purchase order and automatically receive stock (direct transaction)
    function createOrderAndReceiveStock($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['supplier_id'])) {
                throw new Exception("Supplier ID is required");
            }
            if (empty($data['warehouse_id'])) {
                throw new Exception("Warehouse ID is required");
            }
            if (empty($data['created_by'])) {
                throw new Exception("Created by user ID is required");
            }
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Items are required");
            }

            // Generate UUIDs
            $orderId = $this->generateUuid();
            $receiveId = $this->generateUuid();
            
            $receiptCode = 'RC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            
            // Prepare data
            $orderDate = $data['order_date'] ?? date('Y-m-d');
            $receiveDate = $data['receive_date'] ?? date('Y-m-d');
            $supplierInvoice = $data['reference'] ?? null;
            
            // Calculate total amount
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_price'])) {
                    throw new Exception("Product ID, quantity, and unit cost are required for all items");
                }
                $totalAmount += $item['quantity'] * $item['unit_price'];
            }

            // Insert purchase order
            $orderSql = "INSERT INTO product_orders(
                            order_id, supplier_id, order_date, total_amount, 
                            created_by
                        ) VALUES(
                            :orderId, :supplierId, :orderDate, :totalAmount, 
                            :createdBy
                        )";
            
            $orderStmt = $conn->prepare($orderSql);
            $orderStmt->bindValue(":orderId", $orderId);
            $orderStmt->bindValue(":supplierId", $data['supplier_id']);
            $orderStmt->bindValue(":orderDate", $orderDate);
            $orderStmt->bindValue(":totalAmount", $totalAmount);
            $orderStmt->bindValue(":createdBy", $data['created_by']);
            
            if (!$orderStmt->execute()) {
                throw new Exception("Failed to create purchase order");
            }

            // Insert stock receive record (automatic)
            $receiveSql = "INSERT INTO stock_receive(
                            receive_id, receipt_code, supplier_invoice, order_id, 
                            receive_date, supplier_id, warehouse_id, received_by, total_amount
                        ) VALUES(
                            :receiveId, :receiptCode, :supplierInvoice, :orderId, 
                            :receiveDate, :supplierId, :warehouseId, :receivedBy, :totalAmount
                        )";
            
            $receiveStmt = $conn->prepare($receiveSql);
            $receiveStmt->bindValue(":receiveId", $receiveId);
            $receiveStmt->bindValue(":receiptCode", $receiptCode);
            $receiveStmt->bindValue(":supplierInvoice", $supplierInvoice);
            $receiveStmt->bindValue(":orderId", $orderId);
            $receiveStmt->bindValue(":receiveDate", $receiveDate);
            $receiveStmt->bindValue(":supplierId", $data['supplier_id']);
            $receiveStmt->bindValue(":warehouseId", $data['warehouse_id']);
            $receiveStmt->bindValue(":receivedBy", $data['created_by']);
            $receiveStmt->bindValue(":totalAmount", $totalAmount);
            
            if (!$receiveStmt->execute()) {
                throw new Exception("Failed to create stock receive record");
            }

            // Process each item
            foreach ($data['items'] as $item) {
                $orderItemId = $this->generateUuid();
                $receiveItemId = $this->generateUuid();
                $itemTotal = $item['quantity'] * $item['unit_price'];
                
                // Insert order item
                $orderItemSql = "INSERT INTO product_order_items(
                                    order_item_id, order_id, product_id, quantity, 
                                    unit_price, total_price
                                ) VALUES(
                                    :orderItemId, :orderId, :productId, :quantity, 
                                    :unitPrice, :totalPrice
                                )";
                
                $orderItemStmt = $conn->prepare($orderItemSql);
                $orderItemStmt->bindValue(":orderItemId", $orderItemId);
                $orderItemStmt->bindValue(":orderId", $orderId);
                $orderItemStmt->bindValue(":productId", $item['product_id']);
                $orderItemStmt->bindValue(":quantity", $item['quantity'], PDO::PARAM_INT);
                $orderItemStmt->bindValue(":unitPrice", $item['unit_price']);
                $orderItemStmt->bindValue(":totalPrice", $itemTotal);
                
                if (!$orderItemStmt->execute()) {
                    throw new Exception("Failed to create order item");
                }
                
                // Insert stock receive item
                $receiveItemSql = "INSERT INTO stock_receive_items(
                                    receive_item_id, receive_id, product_id, quantity, 
                                    unit_price
                                ) VALUES(
                                    :receiveItemId, :receiveId, :productId, :quantity, 
                                    :unitPrice
                                )";
                
                $receiveItemStmt = $conn->prepare($receiveItemSql);
                $receiveItemStmt->bindValue(":receiveItemId", $receiveItemId);
                $receiveItemStmt->bindValue(":receiveId", $receiveId);
                $receiveItemStmt->bindValue(":productId", $item['product_id']);
                $receiveItemStmt->bindValue(":quantity", $item['quantity'], PDO::PARAM_INT);
                $receiveItemStmt->bindValue(":unitPrice", $item['unit_price']);
                
                if (!$receiveItemStmt->execute()) {
                    throw new Exception("Failed to create receive item");
                }

                $this->updateWarehouseStock($conn, $data['warehouse_id'], $item['product_id'], 
                                        $item['quantity'], $item['unit_price']);
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Order created and stock received successfully',
                'data' => [
                    'order_id' => $orderId,
                    'receive_id' => $receiveId,
                    'receipt_code' => $receiptCode,
                    'order_date' => $orderDate,
                    'receive_date' => $receiveDate,
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'total_amount' => $totalAmount,
                    'supplier_invoice' => $supplierInvoice
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
                         SET quantity = :quantity, unit_cost = :unitCost 
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
                             stock_id, warehouse_id, product_id, quantity, unit_cost
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

     // Get all product orders with complete details and items
    function getAllProductOrders() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        po.order_id,
                        po.order_date,
                        po.total_amount,
                        po.created_at,
                        po.updated_at,
                        
                        -- Supplier information
                        s.supplier_id,
                        s.supplier_name,
                        s.contact_person,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        s.address as supplier_address,
                        
                        -- Created by user information
                        u.user_id as created_by_id,
                        u.full_name as created_by_name,
                        u.email as created_by_email,
                        u.phone as created_by_phone
                        
                    FROM product_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN users u ON po.created_by = u.user_id
                    ORDER BY po.order_date DESC, po.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get order items for each order
            foreach ($orders as &$order) {
                $itemsSql = "SELECT 
                                poi.order_item_id,
                                poi.product_id,
                                poi.quantity,
                                poi.unit_price,
                                poi.total_price,
                                poi.created_at as order_item_created_at,
                                
                                -- Product information
                                p.product_name,
                                p.barcode,
                                p.description as product_description,
                                p.selling_price,
                                
                                -- Category information (if exists)
                                c.category_id,
                                c.category_name
                                
                            FROM product_order_items poi
                            INNER JOIN products p ON poi.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            WHERE poi.order_id = :orderId
                            ORDER BY poi.created_at ASC";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bindValue(":orderId", $order['order_id']);
                $itemsStmt->execute();
                $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'All product orders retrieved successfully',
                'data' => [
                    'total_orders' => count($orders),
                    'orders' => $orders
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get single product order with items
    function getProductOrder($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['order_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: order_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        po.order_id,
                        po.order_date,
                        po.total_amount,
                        po.created_at,
                        po.updated_at,
                        
                        -- Supplier information
                        s.supplier_id,
                        s.supplier_name,
                        s.contact_person,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        s.address as supplier_address,
                        
                        -- Created by user information
                        u.user_id as created_by_id,
                        u.full_name as created_by_name,
                        u.email as created_by_email
                        
                    FROM product_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN users u ON po.created_by = u.user_id
                    WHERE po.order_id = :orderId";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":orderId", $data['order_id']);
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Order not found'
                ]);
                return;
            }

            // Get order items
            $itemsSql = "SELECT 
                            poi.order_item_id,
                            poi.product_id,
                            poi.quantity,
                            poi.unit_price,
                            poi.total_price,
                            
                            -- Product information
                            p.product_name,
                            p.barcode,
                            p.description as product_description,
                            p.selling_price
                            
                        FROM product_order_items poi
                        INNER JOIN products p ON poi.product_id = p.product_id
                        WHERE poi.order_id = :orderId
                        ORDER BY poi.created_at ASC";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":orderId", $data['order_id']);
            $itemsStmt->execute();
            $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Product order retrieved successfully',
                'data' => $order
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get orders by supplier
    function getOrdersBySupplier($json) {
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

            $sql = "SELECT 
                        po.order_id,
                        po.order_date,
                        po.total_amount,
                        po.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        
                        -- Created by user information
                        u.full_name as created_by_name
                        
                    FROM product_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN users u ON po.created_by = u.user_id
                    WHERE po.supplier_id = :supplierId
                    ORDER BY po.order_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $data['supplier_id']);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Orders retrieved successfully',
                'data' => $orders
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get all stock receives with complete details and items
    function getAllStockReceives() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        sr.receive_id,
                        sr.receipt_code,
                        sr.supplier_invoice,
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
                        
                        -- Received by user information
                        u.user_id as received_by_id,
                        u.full_name as received_by_name,
                        u.email as received_by_email,
                        
                        -- Order information (if linked)
                        po.order_date,
                        po.total_amount as order_total_amount
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    LEFT JOIN product_orders po ON sr.order_id = po.order_id
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
                                    sri.unit_price,
                                    (sri.quantity * sri.unit_price) as total_price,
                                    sri.batch_number,
                                    sri.created_at as receive_item_created_at,
                                    
                                    -- Product information
                                    p.product_name,
                                    p.barcode,
                                    p.description as product_description,
                                    p.selling_price,
                                    
                                    -- Category information (if exists)
                                    c.category_id,
                                    c.category_name
                                    
                                    FROM stock_receive_items sri
                                    INNER JOIN products p ON sri.product_id = p.product_id
                                    LEFT JOIN categories c ON p.category_id = c.category_id
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

    // Get single stock receive with items
    function getStockReceive($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['receive_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: receive_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        sr.receive_id,
                        sr.receipt_code,
                        sr.supplier_invoice,
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
                        
                        -- Warehouse information
                        w.warehouse_id,
                        w.warehouse_name,
                        
                        -- Received by user information
                        u.user_id as received_by_id,
                        u.full_name as received_by_name,
                        u.email as received_by_email
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.receive_id = :receiveId";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":receiveId", $data['receive_id']);
            $stmt->execute();
            $receive = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$receive) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Stock receive record not found'
                ]);
                return;
            }

            // Get receive items
            $itemsSql = "SELECT 
                            sri.receive_item_id,
                            sri.product_id,
                            sri.quantity,
                            sri.unit_price,
                            (sri.quantity * sri.unit_price) as total_price,
                            sri.batch_number,
                            
                            -- Product information
                            p.product_name,
                            p.barcode,
                            p.description as product_description
                            
                        FROM stock_receive_items sri
                        INNER JOIN products p ON sri.product_id = p.product_id
                        WHERE sri.receive_id = :receiveId
                        ORDER BY sri.created_at ASC";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":receiveId", $data['receive_id']);
            $itemsStmt->execute();
            $receive['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock receive retrieved successfully',
                'data' => $receive
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock receives by supplier
    function getStockReceivesBySupplier($json) {
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

            $sql = "SELECT 
                        sr.receive_id,
                        sr.receipt_code,
                        sr.supplier_invoice,
                        sr.receive_date,
                        sr.total_amount,
                        
                        -- Supplier information
                        s.supplier_name,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        
                        -- Received by user information
                        u.full_name as received_by_name
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.supplier_id = :supplierId
                    ORDER BY sr.receive_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $data['supplier_id']);
            $stmt->execute();
            $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock receives retrieved successfully',
                'data' => $receives
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock receives by warehouse
    function getStockReceivesByWarehouse($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        sr.receive_id,
                        sr.receipt_code,
                        sr.supplier_invoice,
                        sr.receive_date,
                        sr.total_amount,
                        
                        -- Supplier information
                        s.supplier_name,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        
                        -- Received by user information
                        u.full_name as received_by_name
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.warehouse_id = :warehouseId
                    ORDER BY sr.receive_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $data['warehouse_id']);
            $stmt->execute();
            $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock receives retrieved successfully',
                'data' => $receives
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock receives by date range
    function getStockReceivesByDateRange($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['start_date']) || empty($data['end_date'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: start_date and end_date'
                ]);
                return;
            }

            $sql = "SELECT 
                        sr.receive_id,
                        sr.receipt_code,
                        sr.supplier_invoice,
                        sr.receive_date,
                        sr.total_amount,
                        
                        -- Supplier information
                        s.supplier_name,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        
                        -- Received by user information
                        u.full_name as received_by_name
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.receive_date BETWEEN :startDate AND :endDate
                    ORDER BY sr.receive_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":startDate", $data['start_date']);
            $stmt->bindValue(":endDate", $data['end_date']);
            $stmt->execute();
            $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock receives retrieved successfully',
                'data' => $receives
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get orders with their receive status
    function getOrdersWithReceiveStatus() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        po.order_id,
                        po.order_date,
                        po.total_amount as order_total_amount,
                        
                        -- Supplier information
                        s.supplier_name,
                        
                        -- Created by user information
                        u.full_name as created_by_name,
                        
                        -- Stock receive information (if exists)
                        sr.receive_id,
                        sr.receipt_code,
                        sr.receive_date,
                        sr.total_amount as receive_total_amount,
                        
                        -- Warehouse information (from stock receive)
                        w.warehouse_name,
                        
                        -- Received by user information
                        ru.full_name as received_by_name
                        
                    FROM product_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN users u ON po.created_by = u.user_id
                    LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
                    LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    LEFT JOIN users ru ON sr.received_by = ru.user_id
                    ORDER BY po.order_date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add status flags
            foreach ($orders as &$order) {
                $order['is_received'] = !empty($order['receive_id']);
                $order['receive_status'] = $order['is_received'] ? 'RECEIVED' : 'PENDING';
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Orders with receive status retrieved successfully',
                'data' => $orders
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock receives by user (who received the stock)
    function getStockReceivesByUser($json) {
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

            $sql = "SELECT 
                        sr.receive_id,
                        sr.receipt_code,
                        sr.supplier_invoice,
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
                        
                        -- Received by user information
                        u.user_id as received_by_id,
                        u.full_name as received_by_name,
                        u.email as received_by_email,
                        u.phone as received_by_phone,
                        
                        -- Order information (if linked)
                        po.order_date,
                        po.total_amount as order_total_amount,
                        
                        -- Order creator information
                        cu.user_id as order_created_by_id,
                        cu.full_name as order_created_by_name
                        
                    FROM stock_receive sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.received_by = u.user_id
                    LEFT JOIN product_orders po ON sr.order_id = po.order_id
                    LEFT JOIN users cu ON po.created_by = cu.user_id
                    WHERE sr.received_by = :userId
                    ORDER BY sr.receive_date DESC, sr.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":userId", $data['user_id']);
            $stmt->execute();
            $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get receive items for each receive (optional - only if detailed view is needed)
            $includeItems = $data['include_items'] ?? false;
            
            if ($includeItems) {
                foreach ($receives as &$receive) {
                    $receiveItemsSql = "SELECT 
                                        sri.receive_item_id,
                                        sri.product_id,
                                        sri.quantity,
                                        sri.unit_price,
                                        (sri.quantity * sri.unit_price) as total_price,
                                        sri.batch_number,
                                        sri.created_at as receive_item_created_at,
                                        
                                        -- Product information
                                        p.product_name,
                                        p.barcode,
                                        p.description as product_description,
                                        p.selling_price,
                                        
                                        -- Category information (if exists)
                                        c.category_id,
                                        c.category_name
                                        
                                        FROM stock_receive_items sri
                                        INNER JOIN products p ON sri.product_id = p.product_id
                                        LEFT JOIN categories c ON p.category_id = c.category_id
                                        WHERE sri.receive_id = :receiveId
                                        ORDER BY sri.created_at ASC";
                    
                    $receiveItemsStmt = $conn->prepare($receiveItemsSql);
                    $receiveItemsStmt->bindValue(":receiveId", $receive['receive_id']);
                    $receiveItemsStmt->execute();
                    $receive['items'] = $receiveItemsStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // Calculate summary statistics
            $totalReceives = count($receives);
            $totalAmount = array_sum(array_column($receives, 'total_amount'));
            $uniqueSuppliers = count(array_unique(array_column($receives, 'supplier_id')));
            $uniqueWarehouses = count(array_unique(array_column($receives, 'warehouse_id')));

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock receives retrieved successfully for user',
                'data' => [
                    'user_id' => $data['user_id'],
                    'user_name' => !empty($receives) ? $receives[0]['received_by_name'] : null,
                    'summary' => [
                        'total_receives' => $totalReceives,
                        'total_amount' => $totalAmount,
                        'unique_suppliers' => $uniqueSuppliers,
                        'unique_warehouses' => $uniqueWarehouses
                    ],
                    'receives' => $receives
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

$stockIn = new StockIn();

if ($operation === "createOrderAndReceiveStock") {
    echo $stockIn->createOrderAndReceiveStock($data);
} else {
    // Handle other operations
    switch($operation) {
        case "getAllProductOrders":
            echo $stockIn->getAllProductOrders();
            break;
        case "getAllStockReceives":
            echo $stockIn->getAllStockReceives();
            break;
        case "getProductOrder":
            echo $stockIn->getProductOrder($json);
            break;
        case "getStockReceive":
            echo $stockIn->getStockReceive($json);
            break;
        case "getOrdersBySupplier":
            echo $stockIn->getOrdersBySupplier($json);
            break;
        case "getStockReceivesBySupplier":
            echo $stockIn->getStockReceivesBySupplier($json);
            break;
        case "getStockReceivesByWarehouse":
            echo $stockIn->getStockReceivesByWarehouse($json);
            break;
        case "getStockReceivesByDateRange":
            echo $stockIn->getStockReceivesByDateRange($json);
            break;
        case "getStockReceivesByUser":
            echo $stockIn->getStockReceivesByUser($json);
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>