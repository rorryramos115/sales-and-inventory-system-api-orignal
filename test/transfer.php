<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

class WarehouseTransfer {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Create a new stock transfer
    function createTransfer($data) {
        include "connection-pdo.php";
        
        try {
            // Start transaction
            $conn->beginTransaction();

            // Validate required fields
            if (empty($data['from_location_id']) || empty($data['to_location_id'])) {
                throw new Exception("Source and destination locations are required");
            }
            
            if (empty($data['transfer_date'])) {
                throw new Exception("Transfer date is required");
            }
            
            if (empty($data['created_by'])) {
                throw new Exception("Created by user ID is required");
            }
            
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Transfer items are required");
            }

            // Validate locations exist and are warehouses
            $locationCheck = "SELECT location_id, location_name FROM locations 
                            WHERE location_id IN (:fromId, :toId) AND location_type = 'warehouse' AND is_active = 1";
            $stmt = $conn->prepare($locationCheck);
            $stmt->bindValue(":fromId", $data['from_location_id']);
            $stmt->bindValue(":toId", $data['to_location_id']);
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($locations) != 2) {
                throw new Exception("Invalid source or destination warehouse");
            }

            // Validate user exists
            $userCheck = "SELECT COUNT(*) as count FROM users WHERE user_id = :userId AND is_active = 1";
            $userStmt = $conn->prepare($userCheck);
            $userStmt->bindValue(":userId", $data['created_by']);
            $userStmt->execute();
            $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userResult['count'] == 0) {
                throw new Exception("Invalid user ID");
            }

            // Generate transfer ID
            $transferId = $this->generateUuid();

            // Insert stock transfer
            $sql = "INSERT INTO stock_transfers (
                        transfer_id, from_location_id, to_location_id, 
                        transfer_date, created_by, status
                    ) VALUES (
                        :transferId, :fromLocationId, :toLocationId, 
                        :transferDate, :createdBy, 'pending'
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":transferId", $transferId);
            $stmt->bindValue(":fromLocationId", $data['from_location_id']);
            $stmt->bindValue(":toLocationId", $data['to_location_id']);
            $stmt->bindValue(":transferDate", $data['transfer_date']);
            $stmt->bindValue(":createdBy", $data['created_by']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create transfer");
            }

            // Insert transfer items
            $itemSql = "INSERT INTO stock_transfer_items (
                           transfer_item_id, transfer_id, product_id, 
                           quantity_requested, quantity_received
                       ) VALUES (
                           :itemId, :transferId, :productId, :quantity, 0
                       )";
            $itemStmt = $conn->prepare($itemSql);

            $transferItems = [];
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    throw new Exception("Product ID and quantity are required for all items");
                }

                if ($item['quantity'] <= 0) {
                    throw new Exception("Quantity must be greater than 0");
                }

                // Check if product exists
                $productCheck = "SELECT product_name FROM products WHERE product_id = :productId AND is_active = 1";
                $productStmt = $conn->prepare($productCheck);
                $productStmt->bindValue(":productId", $item['product_id']);
                $productStmt->execute();
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception("Product not found: " . $item['product_id']);
                }

                // Check stock availability in source warehouse
                $stockCheck = "SELECT quantity FROM stock 
                              WHERE location_id = :locationId AND product_id = :productId";
                $stockStmt = $conn->prepare($stockCheck);
                $stockStmt->bindValue(":locationId", $data['from_location_id']);
                $stockStmt->bindValue(":productId", $item['product_id']);
                $stockStmt->execute();
                $stockResult = $stockStmt->fetch(PDO::FETCH_ASSOC);
                
                $availableStock = $stockResult ? $stockResult['quantity'] : 0;
                if ($availableStock < $item['quantity']) {
                    throw new Exception("Insufficient stock for product: " . $product['product_name'] . 
                                      " (Available: {$availableStock}, Requested: {$item['quantity']})");
                }

                $itemId = $this->generateUuid();
                $itemStmt->bindValue(":itemId", $itemId);
                $itemStmt->bindValue(":transferId", $transferId);
                $itemStmt->bindValue(":productId", $item['product_id']);
                $itemStmt->bindValue(":quantity", $item['quantity']);
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to add transfer item");
                }

                $transferItems[] = [
                    'transfer_item_id' => $itemId,
                    'product_id' => $item['product_id'],
                    'product_name' => $product['product_name'],
                    'quantity_requested' => $item['quantity'],
                    'quantity_received' => 0
                ];
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer created successfully',
                'data' => [
                    'transfer_id' => $transferId,
                    'from_location_id' => $data['from_location_id'],
                    'to_location_id' => $data['to_location_id'],
                    'transfer_date' => $data['transfer_date'],
                    'status' => 'pending',
                    'items' => $transferItems,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get all transfers
    function getAllTransfers($json = '{}') {
        include "connection-pdo.php";
        
        try {
            $filters = json_decode($json, true) ?? [];
            
            $sql = "SELECT 
                        st.transfer_id,
                        st.transfer_date,
                        st.status,
                        st.created_at,
                        
                        -- From location
                        from_loc.location_name as from_location_name,
                        from_loc.location_id as from_location_id,
                        
                        -- To location  
                        to_loc.location_name as to_location_name,
                        to_loc.location_id as to_location_id,
                        
                        -- Created by user
                        u.full_name as created_by_name,
                        u.user_id as created_by
                        
                    FROM stock_transfers st
                    INNER JOIN locations from_loc ON st.from_location_id = from_loc.location_id
                    INNER JOIN locations to_loc ON st.to_location_id = to_loc.location_id
                    INNER JOIN users u ON st.created_by = u.user_id
                    WHERE 1=1";
            
            $params = [];
            
            // Apply filters
            if (!empty($filters['status'])) {
                $sql .= " AND st.status = :status";
                $params[':status'] = $filters['status'];
            }
            
            if (!empty($filters['from_location_id'])) {
                $sql .= " AND st.from_location_id = :fromLocationId";
                $params[':fromLocationId'] = $filters['from_location_id'];
            }
            
            if (!empty($filters['to_location_id'])) {
                $sql .= " AND st.to_location_id = :toLocationId";
                $params[':toLocationId'] = $filters['to_location_id'];
            }
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND st.transfer_date >= :dateFrom";
                $params[':dateFrom'] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND st.transfer_date <= :dateTo";
                $params[':dateTo'] = $filters['date_to'];
            }
            
            $sql .= " ORDER BY st.created_at DESC";
            
            // Add limit if specified
            $limit = $filters['limit'] ?? 50;
            $sql .= " LIMIT :limit";
            $params[':limit'] = (int)$limit;
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfers retrieved successfully',
                'data' => $transfers
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get single transfer with items
    function getTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
            }

            // Get transfer details
            $sql = "SELECT 
                        st.transfer_id,
                        st.transfer_date,
                        st.status,
                        st.created_at,
                        
                        -- From location
                        from_loc.location_name as from_location_name,
                        from_loc.location_id as from_location_id,
                        from_loc.address as from_address,
                        
                        -- To location  
                        to_loc.location_name as to_location_name,
                        to_loc.location_id as to_location_id,
                        to_loc.address as to_address,
                        
                        -- Created by user
                        u.full_name as created_by_name,
                        u.user_id as created_by
                        
                    FROM stock_transfers st
                    INNER JOIN locations from_loc ON st.from_location_id = from_loc.location_id
                    INNER JOIN locations to_loc ON st.to_location_id = to_loc.location_id
                    INNER JOIN users u ON st.created_by = u.user_id
                    WHERE st.transfer_id = :transferId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":transferId", $data['transfer_id']);
            $stmt->execute();
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found");
            }

            // Get transfer items
            $itemSql = "SELECT 
                           sti.transfer_item_id,
                           sti.product_id,
                           sti.quantity_requested,
                           sti.quantity_received,
                           p.product_name,
                           p.barcode,
                           p.selling_price,
                           
                           -- Current stock at source
                           COALESCE(stock_from.quantity, 0) as current_stock_from,
                           
                           -- Current stock at destination
                           COALESCE(stock_to.quantity, 0) as current_stock_to
                           
                       FROM stock_transfer_items sti
                       INNER JOIN products p ON sti.product_id = p.product_id
                       LEFT JOIN stock stock_from ON (stock_from.product_id = sti.product_id 
                                                     AND stock_from.location_id = :fromLocationId)
                       LEFT JOIN stock stock_to ON (stock_to.product_id = sti.product_id 
                                                   AND stock_to.location_id = :toLocationId)
                       WHERE sti.transfer_id = :transferId
                       ORDER BY p.product_name";
            
            $itemStmt = $conn->prepare($itemSql);
            $itemStmt->bindValue(":transferId", $data['transfer_id']);
            $itemStmt->bindValue(":fromLocationId", $transfer['from_location_id']);
            $itemStmt->bindValue(":toLocationId", $transfer['to_location_id']);
            $itemStmt->execute();
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $transfer['items'] = $items;

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer retrieved successfully',
                'data' => $transfer
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Update transfer status
    function updateTransferStatus($data) {
        include "connection-pdo.php";
        
        try {
            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
            }
            
            if (empty($data['status'])) {
                throw new Exception("Status is required");
            }

            $validStatuses = ['pending', 'approved', 'in_transit', 'completed', 'cancelled'];
            if (!in_array($data['status'], $validStatuses)) {
                throw new Exception("Invalid status. Valid statuses: " . implode(', ', $validStatuses));
            }

            // Check if transfer exists
            $checkSql = "SELECT status FROM stock_transfers WHERE transfer_id = :transferId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":transferId", $data['transfer_id']);
            $checkStmt->execute();
            $currentTransfer = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentTransfer) {
                throw new Exception("Transfer not found");
            }

            // Update status
            $sql = "UPDATE stock_transfers SET status = :status WHERE transfer_id = :transferId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":status", $data['status']);
            $stmt->bindValue(":transferId", $data['transfer_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update transfer status");
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer status updated successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'old_status' => $currentTransfer['status'],
                    'new_status' => $data['status'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Process transfer (move stock)
    function processTransfer($data) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();

            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
            }

            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Items with received quantities are required");
            }

            // Get transfer details
            $transferSql = "SELECT * FROM stock_transfers WHERE transfer_id = :transferId";
            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(":transferId", $data['transfer_id']);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found");
            }

            if ($transfer['status'] === 'completed') {
                throw new Exception("Transfer already completed");
            }

            if ($transfer['status'] === 'cancelled') {
                throw new Exception("Cannot process cancelled transfer");
            }

            // Update transfer items and stock
            foreach ($data['items'] as $item) {
                if (empty($item['transfer_item_id']) || !isset($item['quantity_received'])) {
                    throw new Exception("Transfer item ID and quantity received are required");
                }

                $quantityReceived = (int)$item['quantity_received'];
                if ($quantityReceived < 0) {
                    throw new Exception("Quantity received cannot be negative");
                }

                // Get transfer item details
                $itemSql = "SELECT sti.*, p.product_name 
                           FROM stock_transfer_items sti
                           INNER JOIN products p ON sti.product_id = p.product_id
                           WHERE sti.transfer_item_id = :itemId AND sti.transfer_id = :transferId";
                $itemStmt = $conn->prepare($itemSql);
                $itemStmt->bindValue(":itemId", $item['transfer_item_id']);
                $itemStmt->bindValue(":transferId", $data['transfer_id']);
                $itemStmt->execute();
                $transferItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

                if (!$transferItem) {
                    throw new Exception("Transfer item not found");
                }

                if ($quantityReceived > $transferItem['quantity_requested']) {
                    throw new Exception("Cannot receive more than requested for product: " . $transferItem['product_name']);
                }

                // Update transfer item
                $updateItemSql = "UPDATE stock_transfer_items 
                                 SET quantity_received = :quantityReceived 
                                 WHERE transfer_item_id = :itemId";
                $updateItemStmt = $conn->prepare($updateItemSql);
                $updateItemStmt->bindValue(":quantityReceived", $quantityReceived);
                $updateItemStmt->bindValue(":itemId", $item['transfer_item_id']);
                
                if (!$updateItemStmt->execute()) {
                    throw new Exception("Failed to update transfer item");
                }

                // Only process stock movement if quantity received > 0
                if ($quantityReceived > 0) {
                    // Decrease stock at source location
                    $this->updateStock($conn, $transfer['from_location_id'], $transferItem['product_id'], -$quantityReceived);
                    
                    // Increase stock at destination location
                    $this->updateStock($conn, $transfer['to_location_id'], $transferItem['product_id'], $quantityReceived);
                }
            }

            // Update transfer status to completed
            $updateStatusSql = "UPDATE stock_transfers SET status = 'completed' WHERE transfer_id = :transferId";
            $updateStatusStmt = $conn->prepare($updateStatusSql);
            $updateStatusStmt->bindValue(":transferId", $data['transfer_id']);
            
            if (!$updateStatusStmt->execute()) {
                throw new Exception("Failed to update transfer status");
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer processed successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'processed_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Helper function to update stock
    private function updateStock($conn, $locationId, $productId, $quantityChange) {
        // Check if stock record exists
        $checkStockSql = "SELECT stock_id, quantity FROM stock 
                         WHERE location_id = :locationId AND product_id = :productId";
        $checkStockStmt = $conn->prepare($checkStockSql);
        $checkStockStmt->bindValue(":locationId", $locationId);
        $checkStockStmt->bindValue(":productId", $productId);
        $checkStockStmt->execute();
        $stockRecord = $checkStockStmt->fetch(PDO::FETCH_ASSOC);

        if ($stockRecord) {
            // Update existing stock
            $newQuantity = $stockRecord['quantity'] + $quantityChange;
            if ($newQuantity < 0) {
                throw new Exception("Insufficient stock for product");
            }

            $updateStockSql = "UPDATE stock SET quantity = :quantity WHERE stock_id = :stockId";
            $updateStockStmt = $conn->prepare($updateStockSql);
            $updateStockStmt->bindValue(":quantity", $newQuantity);
            $updateStockStmt->bindValue(":stockId", $stockRecord['stock_id']);
            
            if (!$updateStockStmt->execute()) {
                throw new Exception("Failed to update stock");
            }
        } else if ($quantityChange > 0) {
            // Create new stock record for positive quantity
            $stockId = $this->generateUuid();
            $insertStockSql = "INSERT INTO stock (stock_id, location_id, product_id, quantity) 
                              VALUES (:stockId, :locationId, :productId, :quantity)";
            $insertStockStmt = $conn->prepare($insertStockSql);
            $insertStockStmt->bindValue(":stockId", $stockId);
            $insertStockStmt->bindValue(":locationId", $locationId);
            $insertStockStmt->bindValue(":productId", $productId);
            $insertStockStmt->bindValue(":quantity", $quantityChange);
            
            if (!$insertStockStmt->execute()) {
                throw new Exception("Failed to create stock record");
            }
        } else {
            throw new Exception("Cannot reduce stock that doesn't exist");
        }
    }

    // Cancel transfer
    function cancelTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
            }

            // Check transfer status
            $checkSql = "SELECT status FROM stock_transfers WHERE transfer_id = :transferId";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(":transferId", $data['transfer_id']);
            $checkStmt->execute();
            $transfer = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found");
            }

            if ($transfer['status'] === 'completed') {
                throw new Exception("Cannot cancel completed transfer");
            }

            if ($transfer['status'] === 'cancelled') {
                throw new Exception("Transfer already cancelled");
            }

            // Update status to cancelled
            $sql = "UPDATE stock_transfers SET status = 'cancelled' WHERE transfer_id = :transferId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":transferId", $data['transfer_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to cancel transfer");
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer cancelled successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'cancelled_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get transfer statistics
    function getTransferStats($json = '{}') {
        include "connection-pdo.php";
        
        try {
            $filters = json_decode($json, true) ?? [];
            
            $sql = "SELECT 
                        COUNT(*) as total_transfers,
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_transfers,
                        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_transfers,
                        COUNT(CASE WHEN status = 'in_transit' THEN 1 END) as in_transit_transfers,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_transfers,
                        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_transfers
                    FROM stock_transfers WHERE 1=1";
            
            $params = [];
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND transfer_date >= :dateFrom";
                $params[':dateFrom'] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND transfer_date <= :dateTo";
                $params[':dateTo'] = $filters['date_to'];
            }
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (Exception $e) {
            echo json_encode([
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

$transfer = new WarehouseTransfer();

// Route operations
switch($operation) {
    case "createTransfer":
        $transfer->createTransfer($data);
        break;
    case "getAllTransfers":
        $transfer->getAllTransfers($json);
        break;
    case "getTransfer":
        $transfer->getTransfer($json);
        break;
    case "updateTransferStatus":
        $transfer->updateTransferStatus($data);
        break;
    case "processTransfer":
        $transfer->processTransfer($data);
        break;
    case "cancelTransfer":
        $transfer->cancelTransfer($json);
        break;
    case "getTransferStats":
        $transfer->getTransferStats($json);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations: createTransfer, getAllTransfers, getTransfer, updateTransferStatus, processTransfer, cancelTransfer, getTransferStats'
        ]);
}
?>