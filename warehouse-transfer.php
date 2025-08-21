<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit();
}


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

    // STEP 1: Destination warehouse creates a stock transfer request
    function createTransferRequest($data) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();

            // Validate required fields
            if (empty($data['from_location_id']) || empty($data['to_location_id'])) {
                throw new Exception("Source and destination locations are required");
            }
            
            if (empty($data['requested_date'])) {
                throw new Exception("Requested date is required");
            }
            
            if (empty($data['created_by'])) {
                throw new Exception("Created by user ID is required");
            }
            
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Transfer items are required");
            }

            // Validate that the user creating the request is assigned to the destination warehouse
            $userLocationCheck = "SELECT ua.location_id FROM user_assignments ua 
                                 WHERE ua.user_id = :userId AND ua.location_id = :locationId AND ua.is_active = 1";
            $userLocationStmt = $conn->prepare($userLocationCheck);
            $userLocationStmt->bindValue(":userId", $data['created_by']);
            $userLocationStmt->bindValue(":locationId", $data['to_location_id']);
            $userLocationStmt->execute();
            
            if (!$userLocationStmt->fetch()) {
                throw new Exception("User must be assigned to the destination warehouse to create transfer requests");
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

            if ($data['from_location_id'] === $data['to_location_id']) {
                throw new Exception("Source and destination warehouses cannot be the same");
            }

            // Generate transfer ID
            $transferId = $this->generateUuid();

            // Insert stock transfer request (status: pending)
            $sql = "INSERT INTO stock_transfers (
                        transfer_id, from_location_id, to_location_id, 
                        requested_date, created_by, status
                    ) VALUES (
                        :transferId, :fromLocationId, :toLocationId, 
                        :requestedDate, :createdBy, 'pending'
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":transferId", $transferId);
            $stmt->bindValue(":fromLocationId", $data['from_location_id']);
            $stmt->bindValue(":toLocationId", $data['to_location_id']);
            $stmt->bindValue(":requestedDate", $data['requested_date']);
            $stmt->bindValue(":createdBy", $data['created_by']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create transfer request");
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
                if (empty($item['product_id']) || empty($item['quantity_requested'])) {
                    throw new Exception("Product ID and quantity_requested are required for all items");
                }

                if ($item['quantity_requested'] <= 0) {
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

                $itemId = $this->generateUuid();
                $itemStmt->bindValue(":itemId", $itemId);
                $itemStmt->bindValue(":transferId", $transferId);
                $itemStmt->bindValue(":productId", $item['product_id']);
                $itemStmt->bindValue(":quantity", $item['quantity_requested']);
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to add transfer item");
                }

                $transferItems[] = [
                    'transfer_item_id' => $itemId,
                    'product_id' => $item['product_id'],
                    'product_name' => $product['product_name'],
                    'quantity_requested' => $item['quantity_requested'],
                    'quantity_received' => 0
                ];
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer request created successfully',
                'data' => [
                    'transfer_id' => $transferId,
                    'from_location_id' => $data['from_location_id'],
                    'to_location_id' => $data['to_location_id'],
                    'requested_date' => $data['requested_date'],
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

    // STEP 2: Source warehouse approves the transfer request
    function reviewTransferRequest($data) {
        include "connection-pdo.php";
        
        try {
            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
            }

            if (empty($data['action'])) {
                throw new Exception("Action is required (approve or cancel)");
            }

            // Get transfer details
            $transferSql = "SELECT * FROM stock_transfers WHERE transfer_id = :transferId";
            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(":transferId", $data['transfer_id']);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transfer) {
                throw new Exception("Transfer request not found");
            }
            
            if ($transfer['status'] !== 'pending') {
                throw new Exception("Transfer request is not in pending status");
            }

            // Update transfer status based on action
            if ($data['action'] === 'approve') {
                $newStatus = 'approved';
                $updateSql = "UPDATE stock_transfers SET 
                                status = 'approved', 
                                approved_date = CURDATE()
                              WHERE transfer_id = :transferId";
            } else if ($data['action'] === 'cancel') {
                $newStatus = 'cancelled';
                $updateSql = "UPDATE stock_transfers SET 
                                status = 'cancelled'
                              WHERE transfer_id = :transferId";
            } else {
                throw new Exception("Invalid action. Use 'approve' or 'cancel'");
            }
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bindValue(":transferId", $data['transfer_id']);
            $updateStmt->execute();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer request ' . $data['action'] . 'd successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'status' => $newStatus,
                    'approved_date' => $data['action'] === 'approve' ? date('Y-m-d') : null
                ]
            ]);
        
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // STEP 3: Source warehouse dispatches the stock (marks as in_transit)
    function dispatchTransfer($data) {
        include "connection-pdo.php";
        
        try {
            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
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

            if ($transfer['status'] !== 'approved') {
                throw new Exception("Transfer must be approved before dispatching");
            }

            // Update status to in_transit
            $dispatchedDate = date('Y-m-d');
            $sql = "UPDATE stock_transfers 
                   SET status = 'in_transit', dispatched_date = :dispatchedDate 
                   WHERE transfer_id = :transferId";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":dispatchedDate", $dispatchedDate);
            $stmt->bindValue(":transferId", $data['transfer_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to dispatch transfer");
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer dispatched successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'status' => 'in_transit',
                    'dispatched_date' => $dispatchedDate
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // STEP 4: Destination warehouse receives and confirms the items (completes transfer)
    function receiveTransfer($data) {
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

            if ($transfer['status'] !== 'in_transit') {
                throw new Exception("Transfer must be in transit to receive items. Current status: " . $transfer['status']);
            }

            // First, get all transfer items for this transfer to validate
            $allItemsSql = "SELECT sti.transfer_item_id, sti.product_id, sti.quantity_requested, 
                                p.product_name 
                            FROM stock_transfer_items sti
                            INNER JOIN products p ON sti.product_id = p.product_id
                            WHERE sti.transfer_id = :transferId";
            $allItemsStmt = $conn->prepare($allItemsSql);
            $allItemsStmt->bindValue(":transferId", $data['transfer_id']);
            $allItemsStmt->execute();
            $allTransferItems = $allItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create a map for quick lookup
            $transferItemsMap = [];
            foreach ($allTransferItems as $item) {
                $transferItemsMap[$item['transfer_item_id']] = $item;
            }


            // Validate all incoming items first
            foreach ($data['items'] as $item) {
                if (empty($item['transfer_item_id']) || !isset($item['quantity_received'])) {
                    throw new Exception("Transfer item ID and quantity received are required");
                }


                if (!isset($transferItemsMap[$item['transfer_item_id']])) {
                    // DEBUG: Show what's available
                    $availableIds = array_keys($transferItemsMap);
                    throw new Exception("Transfer item not found: " . $item['transfer_item_id'] . ". Available items: " . ($availableIds ? implode(", ", $availableIds) : "None"));
                }

                $quantityReceived = (int)$item['quantity_received'];
                if ($quantityReceived < 0) {
                    throw new Exception("Quantity received cannot be negative");
                }

                $transferItem = $transferItemsMap[$item['transfer_item_id']];
                if ($quantityReceived > $transferItem['quantity_requested']) {
                    throw new Exception("Cannot receive more than requested for product: " . $transferItem['product_name']);
                }
            }

            // Now process each item
            foreach ($data['items'] as $item) {
                $quantityReceived = (int)$item['quantity_received'];
                $transferItem = $transferItemsMap[$item['transfer_item_id']];

                // Update transfer item
                $updateItemSql = "UPDATE stock_transfer_items 
                                SET quantity_received = :quantityReceived 
                                WHERE transfer_item_id = :itemId";
                $updateItemStmt = $conn->prepare($updateItemSql);
                $updateItemStmt->bindValue(":quantityReceived", $quantityReceived);
                $updateItemStmt->bindValue(":itemId", $item['transfer_item_id']);
                
                if (!$updateItemStmt->execute()) {
                    throw new Exception("Failed to update transfer item: " . $item['transfer_item_id']);
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
            $receivedDate = date('Y-m-d');
            $updateStatusSql = "UPDATE stock_transfers 
                            SET status = 'completed', received_date = :receivedDate 
                            WHERE transfer_id = :transferId";
            $updateStatusStmt = $conn->prepare($updateStatusSql);
            $updateStatusStmt->bindValue(":receivedDate", $receivedDate);
            $updateStatusStmt->bindValue(":transferId", $data['transfer_id']);
            
            if (!$updateStatusStmt->execute()) {
                throw new Exception("Failed to complete transfer");
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer completed successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'status' => 'completed',
                    'received_date' => $receivedDate
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollBack();
            // DEBUG: Log the error
            error_log("Receive Transfer Error: " . $e->getMessage());
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    private function updateStock($conn, $locationId, $productId, $quantityChange, $unitCost = null) {
        // Check existing stock
        $checkSql = "SELECT stock_id, quantity, unit_cost FROM stock 
                    WHERE location_id = :locationId AND product_id = :productId";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindValue(":locationId", $locationId);
        $checkStmt->bindValue(":productId", $productId);
        $checkStmt->execute();
        $stock = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($stock) {
            // Update existing stock
            $newQuantity = $stock['quantity'] + $quantityChange;
            
            if ($newQuantity < 0) {
                throw new Exception("Insufficient stock for product " . $productId . " at location " . $locationId);
            }
            
            // Calculate new weighted average cost if unitCost is provided and quantity is being added
            if ($quantityChange > 0 && $unitCost !== null && $stock['quantity'] > 0) {
                $currentValue = $stock['quantity'] * $stock['unit_cost'];
                $incomingValue = $quantityChange * $unitCost;
                $newUnitCost = ($currentValue + $incomingValue) / $newQuantity;
            } else if ($quantityChange > 0 && $unitCost !== null) {
                // If existing quantity is 0, use the provided unit cost
                $newUnitCost = $unitCost;
            } else {
                // Preserve existing cost for reductions or when no unit cost provided
                $newUnitCost = $stock['unit_cost'];
            }
            
            $updateSql = "UPDATE stock SET quantity = :quantity, unit_cost = :unitCost, last_updated = NOW() 
                        WHERE stock_id = :stockId";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bindValue(":quantity", $newQuantity);
            $updateStmt->bindValue(":unitCost", $newUnitCost);
            $updateStmt->bindValue(":stockId", $stock['stock_id']);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update stock for product " . $productId);
            }
        } else {
            // Create new stock record (must be positive quantity)
            if ($quantityChange > 0) {
                // If unit cost is not provided, try to get it from source or use selling price
                if ($unitCost === null) {
                    $unitCost = $this->getUnitCostForTransfer($conn, $productId);
                }
                
                $insertSql = "INSERT INTO stock (stock_id, location_id, product_id, quantity, unit_cost, created_at)
                            VALUES (:stockId, :locationId, :productId, :quantity, :unitCost, NOW())";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->bindValue(":stockId", $this->generateUuid());
                $insertStmt->bindValue(":locationId", $locationId);
                $insertStmt->bindValue(":productId", $productId);
                $insertStmt->bindValue(":quantity", $quantityChange);
                $insertStmt->bindValue(":unitCost", $unitCost);
                
                if (!$insertStmt->execute()) {
                    throw new Exception("Failed to create stock record for product " . $productId);
                }
            } else {
                throw new Exception("Cannot reduce stock for non-existent product " . $productId . " at location " . $locationId);
            }
        }
    }

    // Helper method to get unit cost for transfers
    private function getUnitCostForTransfer($conn, $productId) {
        // First, try to get the most recent unit cost from any existing stock
        $stockCostSql = "SELECT unit_cost FROM stock 
                        WHERE product_id = :productId 
                        AND unit_cost IS NOT NULL 
                        AND unit_cost > 0
                        ORDER BY last_updated DESC 
                        LIMIT 1";
        $stockCostStmt = $conn->prepare($stockCostSql);
        $stockCostStmt->bindValue(":productId", $productId);
        $stockCostStmt->execute();
        $stockCost = $stockCostStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stockCost && $stockCost['unit_cost'] > 0) {
            return $stockCost['unit_cost'];
        }
        
        // If no stock unit cost available, try to get from most recent purchase
        $purchaseCostSql = "SELECT sri.unit_price 
                        FROM stock_receive_items sri
                        INNER JOIN stock_receive sr ON sri.receive_id = sr.receive_id
                        WHERE sri.product_id = :productId
                        ORDER BY sr.receive_date DESC, sr.created_at DESC
                        LIMIT 1";
        $purchaseCostStmt = $conn->prepare($purchaseCostSql);
        $purchaseCostStmt->bindValue(":productId", $productId);
        $purchaseCostStmt->execute();
        $purchaseCost = $purchaseCostStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($purchaseCost && $purchaseCost['unit_price'] > 0) {
            return $purchaseCost['unit_price'];
        }
        
        // Last resort: use 80% of selling price as estimated cost
        $sellingPriceSql = "SELECT selling_price FROM products WHERE product_id = :productId";
        $sellingPriceStmt = $conn->prepare($sellingPriceSql);
        $sellingPriceStmt->bindValue(":productId", $productId);
        $sellingPriceStmt->execute();
        $product = $sellingPriceStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product && $product['selling_price'] > 0) {
            // Use 80% of selling price as estimated cost
            return $product['selling_price'] * 0.8;
        }
        
        // Fallback to 0 if nothing else works
        return 0;
    }

    function getPendingRequestsForSource($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true) ?? [];

            if (empty($data['from_location_id'])) {
                throw new Exception("Source location ID is required");
            }

            $sql = "SELECT 
                        st.transfer_id,
                        st.requested_date,
                        st.created_at,
                        st.status,
                        to_loc.location_name as to_location_name,
                        to_loc.location_id as to_location_id,
                        u.full_name as created_by_name,
                        COUNT(sti.transfer_item_id) as total_items,
                        SUM(sti.quantity_requested) as total_quantity
                    FROM stock_transfers st
                    INNER JOIN locations to_loc ON st.to_location_id = to_loc.location_id
                    INNER JOIN users u ON st.created_by = u.user_id
                    INNER JOIN stock_transfer_items sti ON st.transfer_id = sti.transfer_id
                    WHERE st.from_location_id = :fromLocationId 
                    AND st.status = 'pending'
                    GROUP BY st.transfer_id
                    ORDER BY st.requested_date DESC, st.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":fromLocationId", $data['from_location_id']);
            $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get items for each transfer
            foreach ($transfers as &$transfer) {
                $itemsSql = "SELECT 
                                sti.transfer_item_id,
                                sti.product_id,
                                p.product_name,
                                p.barcode,
                                sti.quantity_requested,
                                sti.quantity_received
                            FROM stock_transfer_items sti
                            INNER JOIN products p ON sti.product_id = p.product_id
                            WHERE sti.transfer_id = :transferId";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bindValue(":transferId", $transfer['transfer_id']);
                $itemsStmt->execute();
                $transfer['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Pending transfer requests retrieved successfully',
                'data' => $transfers
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    function getTransferDetails($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);

            if (empty($data['transfer_id'])) {
                throw new Exception("Transfer ID is required");
            }

            $sql = "SELECT 
                        st.transfer_id,
                        st.requested_date,
                        st.approved_date,
                        st.dispatched_date,
                        st.received_date,
                        st.status,
                        st.created_at,
                        from_loc.location_id as from_location_id,
                        from_loc.location_name as from_location_name,
                        to_loc.location_id as to_location_id,
                        to_loc.location_name as to_location_name,
                        creator.full_name as created_by_name
                    FROM stock_transfers st
                    INNER JOIN locations from_loc ON st.from_location_id = from_loc.location_id
                    INNER JOIN locations to_loc ON st.to_location_id = to_loc.location_id
                    INNER JOIN users creator ON st.created_by = creator.user_id
                    WHERE st.transfer_id = :transferId";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":transferId", $data['transfer_id']);
            $stmt->execute();
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found");
            }

            // Get transfer items
            $itemsSql = "SELECT 
                            sti.transfer_item_id,
                            sti.product_id,
                            p.product_name,
                            p.barcode,
                            p.selling_price,
                            c.category_name,
                            sti.quantity_requested,
                            sti.quantity_received,
                            (sti.quantity_requested - sti.quantity_received) as quantity_pending
                        FROM stock_transfer_items sti
                        INNER JOIN products p ON sti.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        WHERE sti.transfer_id = :transferId";

            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":transferId", $data['transfer_id']);
            $itemsStmt->execute();
            $transfer['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer details retrieved successfully',
                'data' => $transfer
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    function getAllTransfersForSource($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true) ?? [];

            if (empty($data['from_location_id'])) {
                throw new Exception("Source location ID is required");
            }

            // Optional status filter
            $statusFilter = '';
            $statusParam = null;
            if (!empty($data['status'])) {
                $statusFilter = ' AND st.status = :status';
                $statusParam = $data['status'];
            }

            $sql = "SELECT 
                        st.transfer_id,
                        st.requested_date,
                        st.approved_date,
                        st.dispatched_date,
                        st.received_date,
                        st.created_at,
                        st.status,
                        to_loc.location_name as to_location_name,
                        to_loc.location_id as to_location_id,
                        u.full_name as created_by_name,
                        COUNT(sti.transfer_item_id) as total_items,
                        SUM(sti.quantity_requested) as total_quantity_requested,
                        SUM(sti.quantity_received) as total_quantity_received
                    FROM stock_transfers st
                    INNER JOIN locations to_loc ON st.to_location_id = to_loc.location_id
                    INNER JOIN users u ON st.created_by = u.user_id
                    INNER JOIN stock_transfer_items sti ON st.transfer_id = sti.transfer_id
                    WHERE st.from_location_id = :fromLocationId" . $statusFilter . "
                    GROUP BY st.transfer_id
                    ORDER BY 
                        CASE 
                            WHEN st.status = 'pending' THEN 1
                            WHEN st.status = 'approved' THEN 2
                            WHEN st.status = 'in_transit' THEN 3
                            WHEN st.status = 'completed' THEN 4
                            WHEN st.status = 'cancelled' THEN 5
                            ELSE 6
                        END,
                        st.requested_date DESC, 
                        st.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":fromLocationId", $data['from_location_id']);
            if ($statusParam) {
                $stmt->bindValue(":status", $statusParam);
            }
            $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get items for each transfer
            foreach ($transfers as &$transfer) {
                $itemsSql = "SELECT 
                                sti.transfer_item_id,
                                sti.product_id,
                                p.product_name,
                                p.barcode,
                                p.selling_price,
                                c.category_name,
                                sti.quantity_requested,
                                sti.quantity_received,
                                (sti.quantity_requested - sti.quantity_received) as quantity_pending
                            FROM stock_transfer_items sti
                            INNER JOIN products p ON sti.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            WHERE sti.transfer_id = :transferId
                            ORDER BY p.product_name";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bindValue(":transferId", $transfer['transfer_id']);
                $itemsStmt->execute();
                $transfer['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Add completion percentage
                if ($transfer['total_quantity_requested'] > 0) {
                    $transfer['completion_percentage'] = round(($transfer['total_quantity_received'] / $transfer['total_quantity_requested']) * 100, 2);
                } else {
                    $transfer['completion_percentage'] = 0;
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer requests retrieved successfully for source warehouse',
                'data' => [
                    'transfers' => $transfers,
                    'summary' => [
                        'total_transfers' => count($transfers),
                        'pending_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'pending'; })),
                        'approved_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'approved'; })),
                        'in_transit_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'in_transit'; })),
                        'completed_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'completed'; })),
                        'cancelled_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'cancelled'; }))
                    ]
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // New function for destination warehouse to monitor incoming transfers
    function getAllTransfersForDestination($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true) ?? [];

            if (empty($data['to_location_id'])) {
                throw new Exception("Destination location ID is required");
            }

            // Optional status filter
            $statusFilter = '';
            $statusParam = null;
            if (!empty($data['status'])) {
                $statusFilter = ' AND st.status = :status';
                $statusParam = $data['status'];
            }

            $sql = "SELECT 
                        st.transfer_id,
                        st.requested_date,
                        st.approved_date,
                        st.dispatched_date,
                        st.received_date,
                        st.created_at,
                        st.status,
                        from_loc.location_name as from_location_name,
                        from_loc.location_id as from_location_id,
                        creator.full_name as created_by_name,
                        COUNT(sti.transfer_item_id) as total_items,
                        SUM(sti.quantity_requested) as total_quantity_requested,
                        SUM(sti.quantity_received) as total_quantity_received
                    FROM stock_transfers st
                    INNER JOIN locations from_loc ON st.from_location_id = from_loc.location_id
                    INNER JOIN users creator ON st.created_by = creator.user_id
                    INNER JOIN stock_transfer_items sti ON st.transfer_id = sti.transfer_id
                    WHERE st.to_location_id = :toLocationId" . $statusFilter . "
                    GROUP BY st.transfer_id
                    ORDER BY 
                        CASE 
                            WHEN st.status = 'in_transit' THEN 1
                            WHEN st.status = 'approved' THEN 2
                            WHEN st.status = 'pending' THEN 3
                            WHEN st.status = 'completed' THEN 4
                            WHEN st.status = 'cancelled' THEN 5
                            ELSE 6
                        END,
                        st.requested_date DESC, 
                        st.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":toLocationId", $data['to_location_id']);
            if ($statusParam) {
                $stmt->bindValue(":status", $statusParam);
            }
            $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get items for each transfer
            foreach ($transfers as &$transfer) {
                $itemsSql = "SELECT 
                                sti.transfer_item_id,
                                sti.product_id,
                                p.product_name,
                                p.barcode,
                                p.selling_price,
                                c.category_name,
                                sti.quantity_requested,
                                sti.quantity_received,
                                (sti.quantity_requested - sti.quantity_received) as quantity_pending
                            FROM stock_transfer_items sti
                            INNER JOIN products p ON sti.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            WHERE sti.transfer_id = :transferId
                            ORDER BY p.product_name";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bindValue(":transferId", $transfer['transfer_id']);
                $itemsStmt->execute();
                $transfer['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Add completion percentage
                if ($transfer['total_quantity_requested'] > 0) {
                    $transfer['completion_percentage'] = round(($transfer['total_quantity_received'] / $transfer['total_quantity_requested']) * 100, 2);
                } else {
                    $transfer['completion_percentage'] = 0;
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer requests retrieved successfully for destination warehouse',
                'data' => [
                    'transfers' => $transfers,
                    'summary' => [
                        'total_transfers' => count($transfers),
                        'pending_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'pending'; })),
                        'approved_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'approved'; })),
                        'in_transit_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'in_transit'; })),
                        'completed_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'completed'; })),
                        'cancelled_count' => count(array_filter($transfers, function($t) { return $t['status'] === 'cancelled'; }))
                    ]
                ]
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
$operation = '';
$data = [];
$operation = $_GET['operation'] ?? ($_POST['operation'] ?? '');

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $json = $_GET['json'] ?? '{}';
    $data = json_decode($json, true) ?: [];
} else {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    
    if (empty($data) && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = $_POST;
    }
}

$transfer = new WarehouseTransfer();

// Route operations
switch($operation) {
    // WORKFLOW METHODS
    case "createTransferRequest":
        $transfer->createTransferRequest($data);
        break;
    case "reviewTransferRequest":
        $transfer->reviewTransferRequest($data);
        break;
    case "dispatchTransfer":
        $transfer->dispatchTransfer($data);
        break;
    case "receiveTransfer":
        $transfer->receiveTransfer($data);
        break;
    
    // QUERY METHODS
    case "getPendingRequestsForSource":
        $transfer->getPendingRequestsForSource($json);  
        break;
    case "getAllTransfersForSource":
        $transfer->getAllTransfersForSource($json);  
        break;
    case "getAllTransfersForDestination":
        $transfer->getAllTransfersForDestination($json);  
        break;
    case "getTransferDetails":
        $transfer->getTransferDetails($json);
        break;
    
    
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operation'
        ]);
}
?>
                       