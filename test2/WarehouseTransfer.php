<?php
// WarehouseTransfer.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class WarehouseTransfer {
    
    // Create warehouse transfer request
    function createTransferRequest($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            // Validate required fields
            $required = ['from_warehouse_id', 'to_warehouse_id', 'created_by', 'items'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            // Validate items array
            if (!is_array($data['items']) || empty($data['items'])) {
                throw new Exception("Items must be a non-empty array");
            }

            // Generate transfer ID
            $transferId = $this->generateUUID();
            
            // Determine if auto-approval based on request type
            $requestType = $data['request_type'] ?? 'manual'; // 'auto_rop' or 'manual'
            $status = ($requestType === 'auto_rop') ? 'approved' : 'pending';
            $approvedDate = ($status === 'approved') ? date('Y-m-d') : null;

            // Create main transfer record
            $sql = "INSERT INTO warehouse_stock_transfer (
                        transfer_id, from_warehouse_id, to_warehouse_id, 
                        status, created_by, requested_date, approved_date
                    ) VALUES (
                        :transfer_id, :from_warehouse_id, :to_warehouse_id, 
                        :status, :created_by, :requested_date, :approved_date
                    )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':transfer_id' => $transferId,
                ':from_warehouse_id' => $data['from_warehouse_id'],
                ':to_warehouse_id' => $data['to_warehouse_id'],
                ':status' => $status,
                ':created_by' => $data['created_by'],
                ':requested_date' => date('Y-m-d'),
                ':approved_date' => $approvedDate
            ]);

            // Insert transfer items
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    throw new Exception("Each item must have product_id and quantity");
                }

                // Check if from_warehouse has sufficient stock
                $stockCheck = "SELECT quantity FROM warehouse_stock 
                              WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                $stockStmt = $conn->prepare($stockCheck);
                $stockStmt->execute([
                    ':warehouse_id' => $data['from_warehouse_id'],
                    ':product_id' => $item['product_id']
                ]);
                $currentStock = $stockStmt->fetchColumn();

                if ($currentStock < $item['quantity']) {
                    throw new Exception("Insufficient stock for product " . $item['product_id']);
                }

                $itemSql = "INSERT INTO warehouse_stock_transfer_items (
                               transfer_item_id, transfer_id, product_id, quantity
                           ) VALUES (?, ?, ?, ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                $itemStmt->execute([
                    $this->generateUUID(),
                    $transferId,
                    $item['product_id'],
                    $item['quantity']
                ]);
            }

            $conn->commit();

            // Get the created transfer with details
            $transferDetails = $this->getTransferDetails($transferId, $conn);

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer request created successfully',
                'data' => [
                    'transfer_id' => $transferId,
                    'status' => $status,
                    'request_type' => $requestType,
                    'transfer_details' => $transferDetails
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Check ROP and create auto transfer requests
    function checkROPAndCreateTransfers($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $warehouseId = $data['warehouse_id'] ?? null;
            $autoCreate = $data['auto_create'] ?? true;
            
            // Get warehouse stock levels that are at or below ROP
            $sql = "SELECT 
                        ws.warehouse_id,
                        ws.product_id,
                        ws.quantity as current_quantity,
                        p.product_name,
                        p.reorder_point,
                        p.min_stock_level,
                        w.warehouse_name,
                        w.is_main,
                        -- Find main warehouse for transfer source
                        (SELECT warehouse_id FROM warehouses WHERE is_main = 1 LIMIT 1) as main_warehouse_id
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id  
                    INNER JOIN warehouses w ON ws.warehouse_id = w.warehouse_id
                    WHERE w.is_main = 0 -- Only check sub-warehouses
                      AND ws.quantity <= COALESCE(p.reorder_point, p.min_stock_level, 10)
                      " . ($warehouseId ? "AND ws.warehouse_id = :warehouse_id" : "") . "
                    ORDER BY ws.warehouse_id, p.product_name";

            $stmt = $conn->prepare($sql);
            if ($warehouseId) {
                $stmt->bindValue(':warehouse_id', $warehouseId);
            }
            $stmt->execute();
            $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $transfersCreated = [];
            $summary = [
                'total_low_stock_items' => count($lowStockItems),
                'transfers_created' => 0,
                'warehouses_affected' => []
            ];

            if ($autoCreate && !empty($lowStockItems)) {
                // Group by warehouse
                $itemsByWarehouse = [];
                foreach ($lowStockItems as $item) {
                    $whId = $item['warehouse_id'];
                    if (!isset($itemsByWarehouse[$whId])) {
                        $itemsByWarehouse[$whId] = [
                            'warehouse_info' => $item,
                            'items' => []
                        ];
                    }
                    $itemsByWarehouse[$whId]['items'][] = $item;
                }

                // Create transfer requests for each warehouse
                foreach ($itemsByWarehouse as $whId => $warehouseData) {
                    $mainWarehouseId = $warehouseData['items'][0]['main_warehouse_id'];
                    
                    if (!$mainWarehouseId) {
                        continue; // Skip if no main warehouse found
                    }

                    // Prepare items for transfer
                    $transferItems = [];
                    foreach ($warehouseData['items'] as $item) {
                        $transferItems[] = [
                            'product_id' => $item['product_id'],
                            'quantity' => max(($item['reorder_point'] ?? 20) - $item['current_quantity'], 10)
                        ];
                    }

                    // Create auto transfer request
                    $transferRequest = [
                        'from_warehouse_id' => $mainWarehouseId,
                        'to_warehouse_id' => $whId,
                        'created_by' => $data['created_by'] ?? 'system',
                        'request_type' => 'auto_rop',
                        'items' => $transferItems
                    ];

                    // Use existing createTransferRequest method
                    ob_start();
                    $this->createTransferRequest(json_encode($transferRequest));
                    $result = ob_get_clean();
                    $transferResult = json_decode($result, true);

                    if ($transferResult['status'] === 'success') {
                        $transfersCreated[] = $transferResult['data'];
                        $summary['transfers_created']++;
                    }

                    $summary['warehouses_affected'][] = [
                        'warehouse_id' => $whId,
                        'warehouse_name' => $warehouseData['warehouse_info']['warehouse_name'],
                        'low_stock_items' => count($warehouseData['items'])
                    ];
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'ROP check completed',
                'data' => [
                    'summary' => $summary,
                    'low_stock_items' => $lowStockItems,
                    'transfers_created' => $transfersCreated
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'ROP check failed: ' . $e->getMessage()
            ]);
        }
    }

    // Approve transfer request
    function approveTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id']) || empty($data['approved_by'])) {
                throw new Exception("Missing required fields: transfer_id, approved_by");
            }

            // Check if transfer exists and is pending
            $checkSql = "SELECT status FROM warehouse_stock_transfer WHERE transfer_id = :transfer_id";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(':transfer_id', $data['transfer_id']);
            $checkStmt->execute();
            $currentStatus = $checkStmt->fetchColumn();

            if (!$currentStatus) {
                throw new Exception("Transfer not found");
            }

            if ($currentStatus !== 'pending') {
                throw new Exception("Transfer is not in pending status");
            }

            // Update transfer status
            $sql = "UPDATE warehouse_stock_transfer 
                    SET status = 'approved', approved_date = CURDATE() 
                    WHERE transfer_id = :transfer_id";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':transfer_id' => $data['transfer_id']]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer approved successfully',
                'data' => ['transfer_id' => $data['transfer_id']]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Dispatch transfer (from main warehouse)
    function dispatchTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id']) || empty($data['dispatched_by'])) {
                throw new Exception("Missing required fields: transfer_id, dispatched_by");
            }

            // Verify transfer is approved
            $transferSql = "SELECT wst.*, w1.warehouse_name as from_warehouse, w2.warehouse_name as to_warehouse
                           FROM warehouse_stock_transfer wst
                           LEFT JOIN warehouses w1 ON wst.from_warehouse_id = w1.warehouse_id
                           LEFT JOIN warehouses w2 ON wst.to_warehouse_id = w2.warehouse_id
                           WHERE wst.transfer_id = :transfer_id AND wst.status = 'approved'";
            
            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(':transfer_id', $data['transfer_id']);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found or not approved");
            }

            // Get transfer items
            $itemsSql = "SELECT wsti.*, p.product_name 
                        FROM warehouse_stock_transfer_items wsti
                        INNER JOIN products p ON wsti.product_id = p.product_id
                        WHERE wsti.transfer_id = :transfer_id";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(':transfer_id', $data['transfer_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Update stock quantities in from_warehouse
            foreach ($items as $item) {
                $updateStockSql = "UPDATE warehouse_stock 
                                  SET quantity = quantity - :quantity
                                  WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                
                $updateStockStmt = $conn->prepare($updateStockSql);
                $updateStockStmt->execute([
                    ':quantity' => $item['quantity'],
                    ':warehouse_id' => $transfer['from_warehouse_id'],
                    ':product_id' => $item['product_id']
                ]);
            }

            // Update transfer status
            $updateTransferSql = "UPDATE warehouse_stock_transfer 
                                 SET status = 'in_transit', dispatched_date = CURDATE() 
                                 WHERE transfer_id = :transfer_id";
            
            $updateTransferStmt = $conn->prepare($updateTransferSql);
            $updateTransferStmt->execute([':transfer_id' => $data['transfer_id']]);

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer dispatched successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'dispatch_info' => [
                        'from_warehouse' => $transfer['from_warehouse'],
                        'to_warehouse' => $transfer['to_warehouse'],
                        'total_items' => count($items),
                        'dispatched_date' => date('Y-m-d')
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Receive transfer (at destination warehouse)
    function receiveTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id']) || empty($data['received_by'])) {
                throw new Exception("Missing required fields: transfer_id, received_by");
            }

            // Verify transfer is in transit
            $transferSql = "SELECT * FROM warehouse_stock_transfer 
                           WHERE transfer_id = :transfer_id AND status = 'in_transit'";
            
            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(':transfer_id', $data['transfer_id']);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found or not in transit");
            }

            // Get transfer items with received quantities (if partial receive)
            $receivedItems = $data['received_items'] ?? [];
            
            $itemsSql = "SELECT * FROM warehouse_stock_transfer_items 
                        WHERE transfer_id = :transfer_id";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(':transfer_id', $data['transfer_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Update stock quantities in destination warehouse
            foreach ($items as $item) {
                $receivedQty = $item['quantity']; // Default to full quantity
                
                // Check if specific received quantity provided
                foreach ($receivedItems as $receivedItem) {
                    if ($receivedItem['product_id'] === $item['product_id']) {
                        $receivedQty = $receivedItem['received_quantity'];
                        break;
                    }
                }

                // Check if product already exists in destination warehouse
                $existingStockSql = "SELECT stock_id FROM warehouse_stock 
                                    WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                
                $existingStockStmt = $conn->prepare($existingStockSql);
                $existingStockStmt->execute([
                    ':warehouse_id' => $transfer['to_warehouse_id'],
                    ':product_id' => $item['product_id']
                ]);

                if ($existingStockStmt->fetchColumn()) {
                    // Update existing stock
                    $updateStockSql = "UPDATE warehouse_stock 
                                      SET quantity = quantity + :quantity
                                      WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                    
                    $updateStockStmt = $conn->prepare($updateStockSql);
                    $updateStockStmt->execute([
                        ':quantity' => $receivedQty,
                        ':warehouse_id' => $transfer['to_warehouse_id'],
                        ':product_id' => $item['product_id']
                    ]);
                } else {
                    // Create new stock record
                    $insertStockSql = "INSERT INTO warehouse_stock (stock_id, warehouse_id, product_id, quantity, unit_price) 
                                      VALUES (:stock_id, :warehouse_id, :product_id, :quantity, 0)";
                    
                    $insertStockStmt = $conn->prepare($insertStockSql);
                    $insertStockStmt->execute([
                        ':stock_id' => $this->generateUUID(),
                        ':warehouse_id' => $transfer['to_warehouse_id'],
                        ':product_id' => $item['product_id'],
                        ':quantity' => $receivedQty
                    ]);
                }
            }

            // Update transfer status
            $updateTransferSql = "UPDATE warehouse_stock_transfer 
                                 SET status = 'completed', received_date = CURDATE() 
                                 WHERE transfer_id = :transfer_id";
            
            $updateTransferStmt = $conn->prepare($updateTransferSql);
            $updateTransferStmt->execute([':transfer_id' => $data['transfer_id']]);

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer received successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'items_received' => count($items),
                    'received_date' => date('Y-m-d')
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get transfer list with filters
    function getTransferList($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            $whereConditions = [];
            $params = [];
            
            // Apply filters
            if (!empty($data['warehouse_id'])) {
                $whereConditions[] = "(wst.from_warehouse_id = :warehouse_id OR wst.to_warehouse_id = :warehouse_id)";
                $params[':warehouse_id'] = $data['warehouse_id'];
            }
            
            if (!empty($data['status'])) {
                $whereConditions[] = "wst.status = :status";
                $params[':status'] = $data['status'];
            }
            
            if (!empty($data['date_from'])) {
                $whereConditions[] = "wst.requested_date >= :date_from";
                $params[':date_from'] = $data['date_from'];
            }
            
            if (!empty($data['date_to'])) {
                $whereConditions[] = "wst.requested_date <= :date_to";
                $params[':date_to'] = $data['date_to'];
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $sql = "SELECT 
                        wst.*,
                        w1.warehouse_name as from_warehouse_name,
                        w1.is_main as from_is_main,
                        w2.warehouse_name as to_warehouse_name,  
                        w2.is_main as to_is_main,
                        u.full_name as created_by_name,
                        -- Count items in transfer
                        (SELECT COUNT(*) FROM warehouse_stock_transfer_items WHERE transfer_id = wst.transfer_id) as total_items,
                        -- Total quantity being transferred
                        (SELECT SUM(quantity) FROM warehouse_stock_transfer_items WHERE transfer_id = wst.transfer_id) as total_quantity
                    FROM warehouse_stock_transfer wst
                    LEFT JOIN warehouses w1 ON wst.from_warehouse_id = w1.warehouse_id
                    LEFT JOIN warehouses w2 ON wst.to_warehouse_id = w2.warehouse_id  
                    LEFT JOIN users u ON wst.created_by = u.user_id
                    $whereClause
                    ORDER BY wst.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get summary statistics
            $statusCounts = [];
            foreach ($transfers as $transfer) {
                $status = $transfer['status'];
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer list retrieved successfully',
                'data' => [
                    'summary' => [
                        'total_transfers' => count($transfers),
                        'status_counts' => $statusCounts
                    ],
                    'transfers' => $transfers
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Get detailed transfer information
    function getTransferDetails($transferId, $conn = null) {
        $shouldCloseConnection = false;
        
        if (!$conn) {
            include "connection-pdo.php";
            $shouldCloseConnection = true;
        }

        try {
            // Get transfer info
            $transferSql = "SELECT 
                               wst.*,
                               w1.warehouse_name as from_warehouse_name,
                               w1.address as from_warehouse_address,
                               w1.is_main as from_is_main,
                               w2.warehouse_name as to_warehouse_name,
                               w2.address as to_warehouse_address, 
                               w2.is_main as to_is_main,
                               u.full_name as created_by_name
                           FROM warehouse_stock_transfer wst
                           LEFT JOIN warehouses w1 ON wst.from_warehouse_id = w1.warehouse_id
                           LEFT JOIN warehouses w2 ON wst.to_warehouse_id = w2.warehouse_id
                           LEFT JOIN users u ON wst.created_by = u.user_id
                           WHERE wst.transfer_id = :transfer_id";

            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(':transfer_id', $transferId);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                return null;
            }

            // Get transfer items
            $itemsSql = "SELECT 
                            wsti.*,
                            p.product_name,
                            p.barcode,
                            p.selling_price,
                            c.category_name,
                            b.brand_name,
                            u.unit_name,
                            -- Get current stock in source warehouse
                            COALESCE(ws.quantity, 0) as current_stock_source
                        FROM warehouse_stock_transfer_items wsti
                        INNER JOIN products p ON wsti.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN units u ON p.unit_id = u.unit_id
                        LEFT JOIN warehouse_stock ws ON (ws.warehouse_id = :from_warehouse_id AND ws.product_id = wsti.product_id)
                        WHERE wsti.transfer_id = :transfer_id
                        ORDER BY p.product_name";

            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->execute([
                ':transfer_id' => $transferId,
                ':from_warehouse_id' => $transfer['from_warehouse_id']
            ]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $transfer['items'] = $items;
            $transfer['total_items'] = count($items);
            $transfer['total_quantity'] = array_sum(array_column($items, 'quantity'));

            return $transfer;

        } catch (Exception $e) {
            return null;
        }
    }

    // Get transfer details (public method)
    function getTransferDetailsPublic($json) {
        $data = json_decode($json, true);
        
        if (empty($data['transfer_id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required field: transfer_id'
            ]);
            return;
        }

        $details = $this->getTransferDetails($data['transfer_id']);
        
        if ($details) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer details retrieved successfully',
                'data' => $details
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Transfer not found'
            ]);
        }
    }

    // Cancel transfer
    function cancelTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id'])) {
                throw new Exception("Missing required field: transfer_id");
            }

            // Check if transfer can be cancelled (only pending or approved)
            $checkSql = "SELECT status FROM warehouse_stock_transfer WHERE transfer_id = :transfer_id";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(':transfer_id', $data['transfer_id']);
            $checkStmt->execute();
            $currentStatus = $checkStmt->fetchColumn();

            if (!$currentStatus) {
                throw new Exception("Transfer not found");
            }

            if (!in_array($currentStatus, ['pending', 'approved'])) {
                throw new Exception("Transfer cannot be cancelled in current status: $currentStatus");
            }

            // Update transfer status
            $sql = "UPDATE warehouse_stock_transfer 
                    SET status = 'cancelled' 
                    WHERE transfer_id = :transfer_id";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':transfer_id' => $data['transfer_id']]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Transfer cancelled successfully',
                'data' => ['transfer_id' => $data['transfer_id']]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Generate UUID
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Handle request methods and operations
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

$warehouseTransfer = new WarehouseTransfer();

// Handle operations
switch($operation) {
    case "createTransferRequest":
        $warehouseTransfer->createTransferRequest($json);
        break;
        
    case "checkROPAndCreateTransfers":
        $warehouseTransfer->checkROPAndCreateTransfers($json);
        break;
        
    case "approveTransfer":
        $warehouseTransfer->approveTransfer($json);
        break;
        
    case "dispatchTransfer":
        $warehouseTransfer->dispatchTransfer($json);
        break;
        
    case "receiveTransfer":
        $warehouseTransfer->receiveTransfer($json);
        break;
        
    case "getTransferList":
        $warehouseTransfer->getTransferList($json);
        break;
        
    case "getTransferDetails":
        $warehouseTransfer->getTransferDetailsPublic($json);
        break;
        
    case "cancelTransfer":
        $warehouseTransfer->cancelTransfer($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}

?>