<?php
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
        $conn->beginTransaction(); // Start transaction

        try {
            // Validate required fields
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
            
            // Prepare data
            $orderDate = $data['order_date'] ?? date('Y-m-d');
            $receiveDate = $data['receive_date'] ?? date('Y-m-d');
            $notes = $data['notes'] ?? null;
            
            // Calculate total amount
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_price'])) {
                    throw new Exception("Product ID, quantity, and unit price are required for all items");
                }
                $totalAmount += $item['quantity'] * $item['unit_price'];
            }

            // Insert purchase order
            $orderSql = "INSERT INTO product_orders(
                            order_id, supplier_id, order_date, total_amount, 
                            notes, created_by
                        ) VALUES(
                            :orderId, :supplierId, :orderDate, :totalAmount, 
                            :notes, :createdBy
                        )";
            
            $orderStmt = $conn->prepare($orderSql);
            $orderStmt->bindValue(":orderId", $orderId);
            $orderStmt->bindValue(":supplierId", $data['supplier_id']);
            $orderStmt->bindValue(":orderDate", $orderDate);
            $orderStmt->bindValue(":totalAmount", $totalAmount);
            $orderStmt->bindValue(":notes", $notes);
            $orderStmt->bindValue(":createdBy", $data['created_by']);
            
            if (!$orderStmt->execute()) {
                throw new Exception("Failed to create purchase order");
            }

            // Insert stock receive record (automatic)
            $receiveSql = "INSERT INTO stock_receive(
                              receive_id, order_id, receive_date, supplier_id, 
                              warehouse_id, received_by, notes
                          ) VALUES(
                              :receiveId, :orderId, :receiveDate, :supplierId, 
                              :warehouseId, :receivedBy, :notes
                          )";
            
            $receiveStmt = $conn->prepare($receiveSql);
            $receiveStmt->bindValue(":receiveId", $receiveId);
            $receiveStmt->bindValue(":orderId", $orderId);
            $receiveStmt->bindValue(":receiveDate", $receiveDate);
            $receiveStmt->bindValue(":supplierId", $data['supplier_id']);
            $receiveStmt->bindValue(":warehouseId", $data['warehouse_id']);
            $receiveStmt->bindValue(":receivedBy", $data['created_by']);
            $receiveStmt->bindValue(":notes", $notes);
            
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

                // Update warehouse stock immediately
                $this->updateWarehouseStock($conn, $data['warehouse_id'], $item['product_id'], 
                                          $item['quantity'], $item['unit_price']);

                // Create stock movement record
                $this->createStockMovement($conn, $item['product_id'], $data['warehouse_id'], 
                                         'stock_in', $item['quantity'], $receiveId, 
                                         'stock_receive', $data['created_by']);
            }
            
            $conn->commit(); // Commit transaction
            
            return json_encode([
                'status' => 'success',
                'message' => 'Order created and stock received successfully',
                'data' => [
                    'order_id' => $orderId,
                    'receive_id' => $receiveId,
                    'order_date' => $orderDate,
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

    // Helper function to update warehouse stock only (no product_stock)
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

    // Helper function to create stock movement record
    private function createStockMovement($conn, $productId, $warehouseId, $movementType, 
                                       $quantity, $referenceId, $referenceTable, $createdBy) {
        $movementId = $this->generateUuid();
        $sql = "INSERT INTO stock_movements(
                   movement_id, product_id, warehouse_id, movement_type, 
                   quantity, reference_id, reference_table, created_by
               ) VALUES(
                   :movementId, :productId, :warehouseId, :movementType, 
                   :quantity, :referenceId, :referenceTable, :createdBy
               )";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":movementId", $movementId);
        $stmt->bindValue(":productId", $productId);
        $stmt->bindValue(":warehouseId", $warehouseId);
        $stmt->bindValue(":movementType", $movementType);
        $stmt->bindValue(":quantity", $quantity, PDO::PARAM_INT);
        $stmt->bindValue(":referenceId", $referenceId);
        $stmt->bindValue(":referenceTable", $referenceTable);
        $stmt->bindValue(":createdBy", $createdBy);
        $stmt->execute();
    }

    // Helper function to update order item received quantity (removed - not needed for direct transactions)
    // Orders are automatically fulfilled, no need to track received quantities separately

    // Get all orders with their receive status
    function getAllOrdersWithReceives() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT po.*, s.supplier_name, u.full_name as created_by_name,
                           sr.receive_id, sr.receive_date
                    FROM product_orders po
                    JOIN suppliers s ON po.supplier_id = s.supplier_id
                    JOIN users u ON po.created_by = u.user_id
                    LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
                    ORDER BY po.order_date DESC";
            $stmt = $conn->prepare($sql);
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

    // Get all stock receive records
    // function getAllStockReceives() {
    //     include "connection-pdo.php";

    //     try {
    //         $sql = "SELECT sr.*, s.supplier_name, w.warehouse_name, u.full_name as received_by_name
    //                 FROM stock_receive sr
    //                 JOIN suppliers s ON sr.supplier_id = s.supplier_id
    //                 JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
    //                 JOIN users u ON sr.received_by = u.user_id
    //                 ORDER BY sr.receive_date DESC";
    //         $stmt = $conn->prepare($sql);
    //         $stmt->execute();
    //         $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

    //         echo json_encode([
    //             'status' => 'success',
    //             'message' => 'Stock receives retrieved successfully',
    //             'data' => $receives
    //         ]);
    //     } catch (PDOException $e) {
    //         echo json_encode([
    //             'status' => 'error',
    //             'message' => 'Database error: ' . $e->getMessage()
    //         ]);
    //     }
    // }
    function getAllStockReceives() {
    include "connection-pdo.php";

    try {
        // First get all stock receives with basic info
        $sql = "SELECT sr.*, s.supplier_name, w.warehouse_name, u.full_name as received_by_name,
                       po.order_date, po.total_amount as order_total_amount
                FROM stock_receive sr
                JOIN suppliers s ON sr.supplier_id = s.supplier_id
                JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                JOIN users u ON sr.received_by = u.user_id
                LEFT JOIN product_orders po ON sr.order_id = po.order_id
                ORDER BY sr.receive_date DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $receives = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Now get items for each receive
        foreach ($receives as &$receive) {
            $itemsSql = "SELECT 
                            poi.order_item_id, poi.product_id, poi.quantity, 
                            poi.unit_price, poi.total_price,
                            p.product_name, p.product_sku, p.barcode
                         FROM product_order_items poi
                         JOIN products p ON poi.product_id = p.product_id
                         WHERE poi.order_id = :orderId";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":orderId", $receive['order_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $receive['order_items'] = $items;
            
            // Also get stock receive items if needed
            $receiveItemsSql = "SELECT 
                                   sri.receive_item_id, sri.product_id, sri.quantity, 
                                   sri.unit_price, (sri.quantity * sri.unit_price) as total_price,
                                   p.product_name, p.product_sku, p.barcode
                                FROM stock_receive_items sri
                                JOIN products p ON sri.product_id = p.product_id
                                WHERE sri.receive_id = :receiveId";
            
            $receiveItemsStmt = $conn->prepare($receiveItemsSql);
            $receiveItemsStmt->bindValue(":receiveId", $receive['receive_id']);
            $receiveItemsStmt->execute();
            $receiveItems = $receiveItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $receive['receive_items'] = $receiveItems;
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Stock receives retrieved successfully with order and receive items',
            'data' => $receives
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

    // Get stock receive with items
    function getStockReceive($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['receive_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: receive_id'
                ]);
                return;
            }

            // Get receive record
            $sql = "SELECT sr.*, s.supplier_name, w.warehouse_name, u.full_name as received_by_name
                    FROM stock_receive sr
                    JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.receive_id = :receiveId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":receiveId", $json['receive_id']);
            $stmt->execute();
            $receive = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!$receive) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Stock receive record not found'
                ]);
                return;
            }

            // Get receive items
            $itemsSql = "SELECT sri.*, p.product_name, p.product_sku
                         FROM stock_receive_items sri
                         JOIN products p ON sri.product_id = p.product_id
                         WHERE sri.receive_id = :receiveId";
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":receiveId", $json['receive_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $receive['items'] = $items;

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
            $json = json_decode($json, true);
            
            if(empty($json['supplier_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_id'
                ]);
                return;
            }

            $sql = "SELECT sr.*, s.supplier_name, w.warehouse_name, u.full_name as received_by_name
                    FROM stock_receive sr
                    JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.supplier_id = :supplierId
                    ORDER BY sr.receive_date DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $json['supplier_id']);
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
            $json = json_decode($json, true);
            
            if(empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id'
                ]);
                return;
            }

            $sql = "SELECT sr.*, s.supplier_name, w.warehouse_name, u.full_name as received_by_name
                    FROM stock_receive sr
                    JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.warehouse_id = :warehouseId
                    ORDER BY sr.receive_date DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $json['warehouse_id']);
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
            $json = json_decode($json, true);
            
            if(empty($json['start_date']) || empty($json['end_date'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: start_date and end_date'
                ]);
                return;
            }

            $sql = "SELECT sr.*, s.supplier_name, w.warehouse_name, u.full_name as received_by_name
                    FROM stock_receive sr
                    JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    JOIN users u ON sr.received_by = u.user_id
                    WHERE sr.receive_date BETWEEN :startDate AND :endDate
                    ORDER BY sr.receive_date DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":startDate", $json['start_date']);
            $stmt->bindValue(":endDate", $json['end_date']);
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
        case "getAllStockReceives":
            echo $stockIn->getAllStockReceives();
            break;
        case "getAllOrdersWithReceives":
            echo $stockIn->getAllOrdersWithReceives();
            break;
        case "getStockReceive":
            echo $stockIn->getStockReceive($json);
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
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid operation'
            ]);
    }
}
?>