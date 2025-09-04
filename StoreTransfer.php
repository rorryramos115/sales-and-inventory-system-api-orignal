<?php
// StoreTransfer.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class StoreTransfer {
    // Create store transfer request
    function createTransferRequest($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            // Validate required fields
            $required = ['from_warehouse_id', 'to_store_id', 'created_by', 'items'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            // Validate items array
            if (!is_array($data['items']) || empty($data['items'])) {
                throw new Exception("Items must be a non-empty array");
            }

            // Validate that to_store_id exists in store table
            $storeCheck = "SELECT store_id FROM store WHERE store_id = :store_id";
            $storeStmt = $conn->prepare($storeCheck);
            $storeStmt->execute([':store_id' => $data['to_store_id']]);
            if (!$storeStmt->fetchColumn()) {
                throw new Exception("Invalid store ID");
            }

            // Generate transfer ID
            $transferId = $this->generateUUID();
            
            // Determine if auto-approval based on request type
            $requestType = $data['request_type'] ?? 'manual'; // 'auto_rop' or 'manual'
            $status = ($requestType === 'auto_rop') ? 'approved' : 'pending';
            $approvedDate = ($status === 'approved') ? date('Y-m-d') : null;

            // Create main transfer record
            $sql = "INSERT INTO store_stock_transfer (
                        transfer_id, from_warehouse_id, to_store_id, 
                        status, created_by, requested_date, approved_date
                    ) VALUES (
                        :transfer_id, :from_warehouse_id, :to_store_id, 
                        :status, :created_by, :requested_date, :approved_date
                    )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':transfer_id' => $transferId,
                ':from_warehouse_id' => $data['from_warehouse_id'],
                ':to_store_id' => $data['to_store_id'],
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
                $stockCheck = "SELECT quantity, unit_price FROM warehouse_stock 
                              WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                $stockStmt = $conn->prepare($stockCheck);
                $stockStmt->execute([
                    ':warehouse_id' => $data['from_warehouse_id'],
                    ':product_id' => $item['product_id']
                ]);
                $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$stockData) {
                    throw new Exception("Product not found in source warehouse: " . $item['product_id']);
                }
                
                $currentStock = $stockData['quantity'];
                $unitPrice = $stockData['unit_price'];

                if ($currentStock < $item['quantity']) {
                    throw new Exception("Insufficient stock for product " . $item['product_id']);
                }

                $itemSql = "INSERT INTO store_stock_transfer_items (
                               transfer_item_id, transfer_id, product_id, quantity, unit_price
                           ) VALUES (?, ?, ?, ?, ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                $itemStmt->execute([
                    $this->generateUUID(),
                    $transferId,
                    $item['product_id'],
                    $item['quantity'],
                    $unitPrice
                ]);
            }

            $conn->commit();

            // Get the created transfer with details
            $transferDetails = $this->getTransferDetails($transferId, $conn);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store transfer request created successfully',
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

    // Internal version of createTransferRequest that returns data instead of outputting
    private function createTransferRequestInternal($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            $required = ['from_warehouse_id', 'to_store_id', 'created_by', 'items'];
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
            $requestType = $data['request_type'] ?? 'manual';
            $status = ($requestType === 'auto_rop') ? 'approved' : 'pending';
            $approvedDate = ($status === 'approved') ? date('Y-m-d') : null;

            // Create main transfer record
            $sql = "INSERT INTO store_stock_transfer (
                        transfer_id, from_warehouse_id, to_store_id, 
                        status, created_by, requested_date, approved_date
                    ) VALUES (
                        :transfer_id, :from_warehouse_id, :to_store_id, 
                        :status, :created_by, :requested_date, :approved_date
                    )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':transfer_id' => $transferId,
                ':from_warehouse_id' => $data['from_warehouse_id'],
                ':to_store_id' => $data['to_store_id'],
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
                $stockCheck = "SELECT quantity, unit_price FROM warehouse_stock 
                            WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                $stockStmt = $conn->prepare($stockCheck);
                $stockStmt->execute([
                    ':warehouse_id' => $data['from_warehouse_id'],
                    ':product_id' => $item['product_id']
                ]);
                $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$stockData) {
                    throw new Exception("Product not found in source warehouse: " . $item['product_id']);
                }
                
                $currentStock = $stockData['quantity'];
                $unitPrice = $stockData['unit_price'];

                if ($currentStock < $item['quantity']) {
                    throw new Exception("Insufficient stock for product " . $item['product_id']);
                }

                $itemSql = "INSERT INTO store_stock_transfer_items (
                            transfer_item_id, transfer_id, product_id, quantity, unit_price
                        ) VALUES (?, ?, ?, ?, ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                $itemStmt->execute([
                    $this->generateUUID(),
                    $transferId,
                    $item['product_id'],
                    $item['quantity'],
                    $unitPrice
                ]);
            }

            $conn->commit();

            // Get the created transfer with details
            $transferDetails = $this->getTransferDetails($transferId, $conn);

            return [
                'status' => 'success',
                'message' => 'Store transfer request created successfully',
                'data' => [
                    'transfer_id' => $transferId,
                    'status' => $status,
                    'request_type' => $requestType,
                    'transfer_details' => $transferDetails
                ]
            ];

        } catch (Exception $e) {
            $conn->rollback();
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // Check ROP and create store transfers (simplified since only one store)
    function checkROPAndCreateTransfers($json = null) {
        include "connection-pdo.php";
        
        try {
            $data = $json ? json_decode($json, true) : [];
            $storeId = $data['store_id'] ?? null;
            $triggerType = $data['trigger_type'] ?? 'auto_monitor';
            $forceCheck = $data['force_check'] ?? false;
            
            // Get main warehouse ID first
            $mainWarehouseSql = "SELECT warehouse_id FROM warehouses WHERE is_main = 1 AND is_active = 1 LIMIT 1";
            $mainWarehouseStmt = $conn->prepare($mainWarehouseSql);
            $mainWarehouseStmt->execute();
            $mainWarehouseId = $mainWarehouseStmt->fetchColumn();
            
            if (!$mainWarehouseId) {
                throw new Exception("No active main warehouse found");
            }

            // Get store ID if not provided (since there's only one store)
            if (!$storeId) {
                $storeSql = "SELECT store_id FROM store LIMIT 1";
                $storeStmt = $conn->prepare($storeSql);
                $storeStmt->execute();
                $storeId = $storeStmt->fetchColumn();
                
                if (!$storeId) {
                    throw new Exception("No store found in the system");
                }
            }
            
            // Get admin user for auto-created transfers (since store has no assigned managers)
            $adminSql = "SELECT u.user_id FROM users u 
                        INNER JOIN roles r ON u.role_id = r.role_id 
                        WHERE r.role_name IN ('admin', 'super_admin') 
                        AND u.is_active = 1 
                        ORDER BY r.role_name = 'super_admin' DESC 
                        LIMIT 1";
            $adminStmt = $conn->prepare($adminSql);
            $adminStmt->execute();
            $adminUserId = $adminStmt->fetchColumn();
            
            if (!$adminUserId) {
                throw new Exception("No active admin user found to create transfers");
            }
            
            $sql = "SELECT 
                        ss.store_id,
                        ss.product_id,
                        ss.quantity as current_quantity,
                        ss.unit_price as current_unit_price,
                        p.product_name,
                        p.barcode,
                        p.reorder_point,
                        p.min_stock_level,
                        p.max_stock_level,
                        s.store_name,
                        c.category_name,
                        b.brand_name,
                        u.unit_name,
                        -- Main warehouse stock info
                        COALESCE(mw_stock.quantity, 0) as main_warehouse_stock,
                        COALESCE(mw_stock.unit_price, ss.unit_price) as main_warehouse_unit_price,
                        -- Check for existing pending transfers to avoid duplicates
                        COALESCE(pending_transfers.pending_quantity, 0) as pending_transfer_quantity,
                        -- Check for any existing transfer (pending or approved) for this product
                        COALESCE(existing_transfers.existing_transfer_count, 0) as existing_transfer_count,
                        -- Calculate stock status
                        CASE 
                            WHEN ss.quantity <= p.min_stock_level THEN 'critical'
                            WHEN ss.quantity <= p.reorder_point THEN 'low' 
                            ELSE 'sufficient'
                        END as stock_status,
                        -- Calculate recommended transfer quantity
                        GREATEST(
                            p.reorder_point - ss.quantity, -- Bring to reorder point
                            15 -- Minimum efficient transfer quantity
                        ) as recommended_transfer_qty
                    FROM store_stock ss
                    INNER JOIN store s ON ss.store_id = s.store_id
                    INNER JOIN products p ON ss.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id  
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    -- Get main warehouse stock for the same product
                    LEFT JOIN warehouse_stock mw_stock ON (
                        mw_stock.warehouse_id = :main_warehouse_id 
                        AND mw_stock.product_id = ss.product_id
                    )
                    -- Check for existing pending transfers to avoid duplicates
                    LEFT JOIN (
                        SELECT 
                            sst.to_store_id,
                            ssti.product_id,
                            SUM(ssti.quantity) as pending_quantity
                        FROM store_stock_transfer sst
                        INNER JOIN store_stock_transfer_items ssti ON sst.transfer_id = ssti.transfer_id
                        WHERE sst.status IN ('pending', 'approved', 'in_transit')
                        AND sst.from_warehouse_id = :main_warehouse_id2
                        GROUP BY sst.to_store_id, ssti.product_id
                    ) pending_transfers ON (
                        ss.store_id = pending_transfers.to_store_id 
                        AND ss.product_id = pending_transfers.product_id
                    )
                    -- Check for any existing transfer for this product to the same store
                    LEFT JOIN (
                        SELECT 
                            sst.to_store_id,
                            ssti.product_id,
                            COUNT(*) as existing_transfer_count
                        FROM store_stock_transfer sst
                        INNER JOIN store_stock_transfer_items ssti ON sst.transfer_id = ssti.transfer_id
                        WHERE sst.status IN ('pending', 'approved', 'in_transit')
                        AND sst.from_warehouse_id = :main_warehouse_id3
                        GROUP BY sst.to_store_id, ssti.product_id
                    ) existing_transfers ON (
                        ss.store_id = existing_transfers.to_store_id 
                        AND ss.product_id = existing_transfers.product_id
                    )
                    WHERE ss.store_id = :store_id
                    AND p.is_active = 1
                    AND ss.quantity <= p.reorder_point -- At or below reorder point
                    ORDER BY 
                        stock_status DESC, -- Critical first
                        p.product_name";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':main_warehouse_id', $mainWarehouseId);
            $stmt->bindValue(':main_warehouse_id2', $mainWarehouseId);
            $stmt->bindValue(':main_warehouse_id3', $mainWarehouseId);
            $stmt->bindValue(':store_id', $storeId);
            $stmt->execute();
            $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Initialize summary
            $summary = [
                'trigger_type' => $triggerType,
                'main_warehouse_id' => $mainWarehouseId,
                'store_id' => $storeId,
                'admin_user_id' => $adminUserId,
                'check_time' => date('Y-m-d H:i:s'),
                'total_products_checked' => count($lowStockItems),
                'critical_stock_items' => 0,
                'low_stock_items' => 0,
                'transfers_created' => 0,
                'transfers_skipped' => 0,
                'store_processed' => null,
                'warnings' => [],
                'errors' => []
            ];

            // If no items need attention
            if (empty($lowStockItems)) {
                $result = [
                    'status' => 'success',
                    'message' => 'Store stock monitoring completed - all products above reorder point',
                    'data' => $summary
                ];
                
                if ($json !== null) {
                    echo json_encode($result);
                }
                return $result;
            }

            // Count stock status
            foreach ($lowStockItems as $item) {
                if ($item['stock_status'] === 'critical') {
                    $summary['critical_stock_items']++;
                } elseif ($item['stock_status'] === 'low') {
                    $summary['low_stock_items']++;
                }
            }

            // Process store
            $storeProcessed = [
                'store_id' => $storeId,
                'store_name' => $lowStockItems[0]['store_name'] ?? 'Store',
                'admin_user_id' => $adminUserId,
                'total_low_stock_products' => count($lowStockItems),
                'transfer_created' => false,
                'transfer_id' => null,
                'items_processed' => [],
                'warnings' => []
            ];

            $transferItems = [];
            $insufficientStockWarnings = [];

            // Process each product
            foreach ($lowStockItems as $item) {
                $currentStock = (int)$item['current_quantity'];
                $reorderPoint = (int)$item['reorder_point'];
                $maxStockLevel = (int)$item['max_stock_level'];
                $minStockLevel = (int)$item['min_stock_level'];
                $mainWarehouseStock = (int)$item['main_warehouse_stock'];
                $pendingQty = (int)$item['pending_transfer_quantity'];
                $existingTransferCount = (int)$item['existing_transfer_count'];
                $recommendedQty = (int)$item['recommended_transfer_qty'];

                $itemProcessed = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'current_stock' => $currentStock,
                    'reorder_point' => $reorderPoint,
                    'main_stock_available' => $mainWarehouseStock,
                    'pending_transfers' => $pendingQty,
                    'existing_transfer_count' => $existingTransferCount,
                    'recommended_qty' => $recommendedQty,
                    'action' => 'none',
                    'transfer_qty' => 0,
                    'reason' => ''
                ];

                // Skip if there's already a transfer for this product to this store
                if ($existingTransferCount > 0 && !$forceCheck) {
                    $itemProcessed['action'] = 'skipped';
                    $itemProcessed['reason'] = "Existing transfer already created for this product";
                    $storeProcessed['items_processed'][] = $itemProcessed;
                    continue;
                }

                // Check pending transfers
                if ($pendingQty > 0 && !$forceCheck) {
                    if (($currentStock + $pendingQty) > $reorderPoint) {
                        $itemProcessed['action'] = 'skipped';
                        $itemProcessed['reason'] = "Sufficient pending transfer exists ({$pendingQty} units)";
                        $storeProcessed['items_processed'][] = $itemProcessed;
                        continue;
                    }
                }

                // Calculate target stock level for store (usually lower than warehouse)
                $targetStockLevel = min(100, $maxStockLevel); // Stores typically hold less stock

                if ($maxStockLevel < 100) {
                    $targetStockLevel = floor($maxStockLevel * 0.8);
                }

                if ($targetStockLevel <= $reorderPoint) {
                    $targetStockLevel = $reorderPoint + 15; 
                }

                // Calculate quantity needed to reach target level
                $neededQty = $targetStockLevel - $currentStock - $pendingQty;

                // Ensure minimum efficient transfer (at least 10 units for store)
                $neededQty = max($neededQty, 10);

                // Don't exceed max stock level
                $maxTransferQty = $maxStockLevel - $currentStock - $pendingQty;
                $transferQty = min($neededQty, $maxTransferQty);
                $transferQty = max(0, $transferQty);

                // Calculate available stock from main warehouse
                $mainWarehouseBuffer = max(15, $minStockLevel * 0.3); // Smaller buffer for store transfers
                $availableFromMain = max(0, $mainWarehouseStock - $mainWarehouseBuffer);
                
                if ($availableFromMain >= $transferQty) {
                    // Full transfer possible
                    $transferItems[] = [
                        'product_id' => $item['product_id'],
                        'quantity' => $transferQty
                    ];
                    
                    $itemProcessed['action'] = 'transfer';
                    $itemProcessed['transfer_qty'] = $transferQty;
                    $itemProcessed['reason'] = 'Stock below reorder point';
                    
                } elseif ($availableFromMain > 0) {
                    // Partial transfer
                    $transferItems[] = [
                        'product_id' => $item['product_id'],
                        'quantity' => $availableFromMain
                    ];
                    
                    $itemProcessed['action'] = 'partial_transfer';
                    $itemProcessed['transfer_qty'] = $availableFromMain;
                    $itemProcessed['reason'] = 'Limited main warehouse stock';
                    
                    $insufficientStockWarnings[] = [
                        'product_name' => $item['product_name'],
                        'needed' => $transferQty,
                        'available' => $availableFromMain,
                        'main_stock' => $mainWarehouseStock
                    ];
                } else {
                    // Cannot transfer - insufficient stock
                    $itemProcessed['action'] = 'insufficient';
                    $itemProcessed['reason'] = 'Insufficient main warehouse stock';
                    
                    $insufficientStockWarnings[] = [
                        'product_name' => $item['product_name'],
                        'needed' => $transferQty,
                        'available' => 0,
                        'main_stock' => $mainWarehouseStock
                    ];
                }

                $storeProcessed['items_processed'][] = $itemProcessed;
            }

            // Create transfer if we have items to transfer
            if (!empty($transferItems)) {
                try {
                    $transferRequest = [
                        'from_warehouse_id' => $mainWarehouseId,
                        'to_store_id' => $storeId,
                        'created_by' => $adminUserId,
                        'request_type' => 'auto_rop',
                        'items' => $transferItems
                    ];

                    $transferResult = $this->createTransferRequestInternal(json_encode($transferRequest));
                    
                    if ($transferResult && $transferResult['status'] === 'success') {
                        $storeProcessed['transfer_created'] = true;
                        $storeProcessed['transfer_id'] = $transferResult['data']['transfer_id'];
                        $summary['transfers_created']++;
                    } else {
                        $error = "Failed to create transfer for store: " . 
                                ($transferResult['message'] ?? 'Unknown error');
                        $summary['errors'][] = $error;
                        $storeProcessed['warnings'][] = $error;
                    }
                    
                } catch (Exception $e) {
                    $error = "Exception creating transfer for store: " . $e->getMessage();
                    $summary['errors'][] = $error;
                    $storeProcessed['warnings'][] = $error;
                }
            } else {
                $summary['transfers_skipped']++;
                $storeProcessed['warnings'][] = 'No transferable items - all products have insufficient main stock or pending transfers';
            }

            // Add insufficient stock warnings
            if (!empty($insufficientStockWarnings)) {
                $storeProcessed['insufficient_stock_warnings'] = $insufficientStockWarnings;
            }

            $summary['store_processed'] = $storeProcessed;

            // Prepare final result
            $message = "Store stock monitoring completed";
            if ($summary['transfers_created'] > 0) {
                $message .= " - {$summary['transfers_created']} transfer(s) created";
            }
            if ($summary['transfers_skipped'] > 0) {
                $message .= " - {$summary['transfers_skipped']} transfer(s) skipped";
            }

            $result = [
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'summary' => $summary,
                    'low_stock_analysis' => $lowStockItems
                ]
            ];

            // Output or return based on call type
            if ($json !== null) {
                echo json_encode($result);
            }
            return $result;

        } catch (Exception $e) {
            $errorResult = [
                'status' => 'error',
                'message' => 'Store stock monitoring failed: ' . $e->getMessage(),
                'data' => [
                    'error_details' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                ]
            ];
            
            if ($json !== null) {
                echo json_encode($errorResult);
            }
            return $errorResult;
        }
    }

    // Helper method for batch store stock monitoring (can be called by cron job)
    function batchStockMonitoring() {
        try {
            // Monitor store
            $result = $this->checkROPAndCreateTransfers();
            
            // Log summary for monitoring
            if (isset($result['data']['summary'])) {
                $summary = $result['data']['summary'];
                $logMessage = sprintf(
                    "Batch store stock monitoring - Checked: %d products, Created: %d transfers, Skipped: %d, Critical: %d, Low: %d",
                    $summary['total_products_checked'],
                    $summary['transfers_created'], 
                    $summary['transfers_skipped'],
                    $summary['critical_stock_items'],
                    $summary['low_stock_items']
                );
                error_log($logMessage);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Batch store stock monitoring failed: " . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // Event handler for store stock updates
    function handleStockUpdateEvent($json) {
        try {
            $data = json_decode($json, true);
            $storeId = $data['store_id'] ?? null;
            
            if ($storeId) {
                // Check specific store after stock update
                $checkData = [
                    'store_id' => $storeId,
                    'trigger_type' => 'stock_update',
                    'force_check' => false
                ];
                
                return $this->checkROPAndCreateTransfers(json_encode($checkData));
            }
            
            return [
                'status' => 'error',
                'message' => 'No store_id provided in stock update event'
            ];
            
        } catch (Exception $e) {
            error_log("Store stock update event handling failed: " . $e->getMessage());
            return [
                'status' => 'error', 
                'message' => $e->getMessage()
            ];
        }
    }

    // Scheduled monitoring for cron jobs
    function scheduledStockMonitoring() {
        $checkData = [
            'trigger_type' => 'scheduled',
            'force_check' => false
        ];
        
        return $this->checkROPAndCreateTransfers(json_encode($checkData));
    }

    // Approve transfer request
    function approveTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id'])) {
                throw new Exception("Missing required fields: transfer_id");
            }

            // Check if transfer exists and is pending
            $checkSql = "SELECT status FROM store_stock_transfer WHERE transfer_id = :transfer_id";
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
            $sql = "UPDATE store_stock_transfer 
                    SET status = 'approved', approved_date = CURDATE() 
                    WHERE transfer_id = :transfer_id";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':transfer_id' => $data['transfer_id']]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store transfer approved successfully',
                'data' => ['transfer_id' => $data['transfer_id']]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }


    // Dispatch transfer (from warehouse to store)
    function dispatchTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id'])) {
                throw new Exception("Missing required fields: transfer_id");
            }

            // Verify transfer is approved
            $transferSql = "SELECT sst.*, w.warehouse_name as from_warehouse, s.store_name as to_store
                           FROM store_stock_transfer sst
                           LEFT JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                           LEFT JOIN store s ON sst.to_store_id = s.store_id
                           WHERE sst.transfer_id = :transfer_id AND sst.status = 'approved'";
            
            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(':transfer_id', $data['transfer_id']);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found or not approved");
            }

            // Get transfer items with unit prices
            $itemsSql = "SELECT ssti.*, p.product_name, ssti.unit_price
                        FROM store_stock_transfer_items ssti
                        INNER JOIN products p ON ssti.product_id = p.product_id
                        WHERE ssti.transfer_id = :transfer_id";
            
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
            $updateTransferSql = "UPDATE store_stock_transfer 
                                 SET status = 'in_transit', dispatched_date = CURDATE() 
                                 WHERE transfer_id = :transfer_id";
            
            $updateTransferStmt = $conn->prepare($updateTransferSql);
            $updateTransferStmt->execute([':transfer_id' => $data['transfer_id']]);

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Store transfer dispatched successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'dispatch_info' => [
                        'from_warehouse' => $transfer['from_warehouse'],
                        'to_store' => $transfer['to_store'],
                        'total_items' => count($items),
                        'total_value' => array_sum(array_map(function($item) {
                            return $item['quantity'] * $item['unit_price'];
                        }, $items)),
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

    // Receive transfer (at destination store)
    function receiveTransfer($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
            if (empty($data['transfer_id'])) {
                throw new Exception("Missing required fields: transfer_id");
            }

            // Verify transfer is in transit
            $transferSql = "SELECT * FROM store_stock_transfer 
                           WHERE transfer_id = :transfer_id AND status = 'in_transit'";
            
            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(':transfer_id', $data['transfer_id']);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception("Transfer not found or not in transit");
            }

            // Get transfer items with unit prices
            $itemsSql = "SELECT ssti.*, p.product_name, ssti.unit_price
                        FROM store_stock_transfer_items ssti
                        INNER JOIN products p ON ssti.product_id = p.product_id
                        WHERE ssti.transfer_id = :transfer_id";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(':transfer_id', $data['transfer_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get received quantities (if partial receive)
            $receivedItems = $data['received_items'] ?? [];
            
            $totalValue = 0;
            $itemsReceived = [];

            // Update stock quantities in destination store
            foreach ($items as $item) {
                $receivedQty = $item['quantity']; // Default to full quantity
                
                // Check if specific received quantity provided
                foreach ($receivedItems as $receivedItem) {
                    if ($receivedItem['product_id'] === $item['product_id']) {
                        $receivedQty = $receivedItem['received_quantity'];
                        break;
                    }
                }

                // Calculate item value
                $itemValue = $receivedQty * $item['unit_price'];
                $totalValue += $itemValue;
                
                // Record item details
                $itemsReceived[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $receivedQty,
                    'unit_price' => $item['unit_price'],
                    'total_value' => $itemValue
                ];

                // Check if product exists in store stock
                $existingStockSql = "SELECT store_stock_id, quantity, unit_price FROM store_stock 
                                    WHERE store_id = :store_id AND product_id = :product_id";
                
                $existingStockStmt = $conn->prepare($existingStockSql);
                $existingStockStmt->execute([
                    ':store_id' => $transfer['to_store_id'],
                    ':product_id' => $item['product_id']
                ]);
                
                $existingStock = $existingStockStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingStock) {
                    // Use the actual quantity from the database
                    $existingQty = (float)$existingStock['quantity'];
                    $existingUnitPrice = (float)$existingStock['unit_price'];
                    $newQty = (float)$receivedQty;
                    $newUnitPrice = (float)$item['unit_price'];
                    
                    // Calculate weighted average unit price
                    $existingValue = $existingQty * $existingUnitPrice;
                    $newValue = $newQty * $newUnitPrice;
                    
                    $totalQty = $existingQty + $newQty;
                    $averageUnitPrice = ($existingValue + $newValue) / $totalQty;
                    
                    // Update existing stock with weighted average price
                    $updateStockSql = "UPDATE store_stock 
                                      SET quantity = quantity + :quantity, 
                                          unit_price = :unit_price,
                                          last_updated = NOW()
                                      WHERE store_id = :store_id AND product_id = :product_id";
                    
                    $updateStockStmt = $conn->prepare($updateStockSql);
                    $updateStockStmt->execute([
                        ':quantity' => $receivedQty,
                        ':unit_price' => $averageUnitPrice,
                        ':store_id' => $transfer['to_store_id'],
                        ':product_id' => $item['product_id']
                    ]);
                } else {
                    // Create new stock record with transferred unit price
                    $insertStockSql = "INSERT INTO store_stock 
                                      (store_stock_id, store_id, product_id, quantity, unit_price, last_updated) 
                                      VALUES (:store_stock_id, :store_id, :product_id, :quantity, :unit_price, NOW())";
                    
                    $insertStockStmt = $conn->prepare($insertStockSql);
                    $insertStockStmt->execute([
                        ':store_stock_id' => $this->generateUUID(),
                        ':store_id' => $transfer['to_store_id'],
                        ':product_id' => $item['product_id'],
                        ':quantity' => $receivedQty,
                        ':unit_price' => $item['unit_price']
                    ]);
                }
            }

            // Update transfer status
            $updateTransferSql = "UPDATE store_stock_transfer 
                                 SET status = 'completed', received_date = CURDATE() 
                                 WHERE transfer_id = :transfer_id";
            
            $updateTransferStmt = $conn->prepare($updateTransferSql);
            $updateTransferStmt->execute([':transfer_id' => $data['transfer_id']]);

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Store transfer received successfully',
                'data' => [
                    'transfer_id' => $data['transfer_id'],
                    'items_received' => count($items),
                    'total_value' => $totalValue,
                    'received_date' => date('Y-m-d'),
                    'items' => $itemsReceived
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
                $whereConditions[] = "sst.from_warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $data['warehouse_id'];
            }
            
            if (!empty($data['store_id'])) {
                $whereConditions[] = "sst.to_store_id = :store_id";
                $params[':store_id'] = $data['store_id'];
            }
            
            if (!empty($data['status'])) {
                $whereConditions[] = "sst.status = :status";
                $params[':status'] = $data['status'];
            }
            
            if (!empty($data['date_from'])) {
                $whereConditions[] = "sst.requested_date >= :date_from";
                $params[':date_from'] = $data['date_from'];
            }
            
            if (!empty($data['date_to'])) {
                $whereConditions[] = "sst.requested_date <= :date_to";
                $params[':date_to'] = $data['date_to'];
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $sql = "SELECT 
                        sst.*,
                        w.warehouse_name as from_warehouse_name,
                        w.is_main as from_is_main,
                        s.store_name as to_store_name,
                        u.full_name as created_by_name,
                        -- Count items in transfer
                        (SELECT COUNT(*) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_items,
                        -- Total quantity being transferred
                        (SELECT SUM(quantity) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_quantity
                    FROM store_stock_transfer sst
                    LEFT JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                    LEFT JOIN store s ON sst.to_store_id = s.store_id  
                    LEFT JOIN users u ON sst.created_by = u.user_id
                    $whereClause
                    ORDER BY sst.created_at DESC";

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
                'message' => 'Store transfer list retrieved successfully',
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
        $externalConn = false;
        
        if ($conn === null) {
            include "connection-pdo.php";
            $externalConn = true;
        }

        try {
            // Get transfer info
            $transferSql = "SELECT 
                            sst.*,
                            w.warehouse_name as from_warehouse_name,
                            w.address as from_warehouse_address,
                            w.is_main as from_is_main,
                            s.store_name as to_store_name,
                            s.address as to_store_address,
                            u.full_name as created_by_name
                        FROM store_stock_transfer sst
                        LEFT JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                        LEFT JOIN store s ON sst.to_store_id = s.store_id
                        LEFT JOIN users u ON sst.created_by = u.user_id
                        WHERE sst.transfer_id = :transfer_id";

            $transferStmt = $conn->prepare($transferSql);
            $transferStmt->bindValue(':transfer_id', $transferId);
            $transferStmt->execute();
            $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                if ($externalConn) {
                    $conn = null;
                }
                return null;
            }

            // Get transfer items with unit prices
            $itemsSql = "SELECT 
                            ssti.*,
                            p.product_name,
                            p.barcode,
                            p.selling_price,
                            c.category_name,
                            b.brand_name,
                            u.unit_name,
                            -- Get current stock in source warehouse
                            COALESCE(ws.quantity, 0) as current_stock_source,
                            ws.unit_price as current_unit_price_source,
                            -- Calculate item value
                            (ssti.quantity * ssti.unit_price) as item_value
                        FROM store_stock_transfer_items ssti
                        INNER JOIN products p ON ssti.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN units u ON p.unit_id = u.unit_id
                        LEFT JOIN warehouse_stock ws ON (ws.warehouse_id = :from_warehouse_id AND ws.product_id = ssti.product_id)
                        WHERE ssti.transfer_id = :transfer_id
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
            $transfer['total_value'] = array_sum(array_column($items, 'item_value'));

            if ($externalConn) {
                $conn = null;
            }
            
            return $transfer;

        } catch (Exception $e) {
            if ($externalConn) {
                $conn = null;
            }
            error_log("Error getting transfer details: " . $e->getMessage());
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
                'message' => 'Store transfer details retrieved successfully',
                'data' => $details
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Store transfer not found'
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
            $checkSql = "SELECT status FROM store_stock_transfer WHERE transfer_id = :transfer_id";
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
            $sql = "UPDATE store_stock_transfer 
                    SET status = 'cancelled' 
                    WHERE transfer_id = :transfer_id";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':transfer_id' => $data['transfer_id']]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store transfer cancelled successfully',
                'data' => ['transfer_id' => $data['transfer_id']]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // Add this method to the StoreTransfer class
function getAllTransferDetails($json) {
    include "connection-pdo.php";
    
    try {
        $data = json_decode($json, true);
        
        // Get all transfer information with details
        $transferSql = "SELECT 
                            sst.*,
                            w.warehouse_name as from_warehouse_name,
                            w.address as from_warehouse_address,
                            s.store_name as to_store_name,
                            s.address as to_store_address,
                            uc.full_name as created_by_name,
                            -- Calculate totals
                            (SELECT COUNT(*) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_items,
                            (SELECT SUM(quantity) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_quantity,
                            (SELECT SUM(quantity * unit_price) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_value
                        FROM store_stock_transfer sst
                        LEFT JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                        LEFT JOIN store s ON sst.to_store_id = s.store_id
                        LEFT JOIN users uc ON sst.created_by = uc.user_id
                        ORDER BY sst.requested_date DESC, sst.created_at DESC";

        $transferStmt = $conn->prepare($transferSql);
        $transferStmt->execute();
        $transfers = $transferStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items for each transfer
        $result = [];
        foreach ($transfers as $transfer) {
            $transferId = $transfer['transfer_id'];
            
            // Get transfer items
            $itemsSql = "SELECT 
                            ssti.*,
                            p.product_name,
                            p.barcode,
                            c.category_name,
                            b.brand_name,
                            u.unit_name,
                            (ssti.quantity * ssti.unit_price) as item_value
                        FROM store_stock_transfer_items ssti
                        INNER JOIN products p ON ssti.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN units u ON p.unit_id = u.unit_id
                        WHERE ssti.transfer_id = :transfer_id
                        ORDER BY p.product_name";

            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(':transfer_id', $transferId);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Add items to transfer data
            $transfer['items'] = $items;
            $result[] = $transfer;
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'All store transfer details retrieved successfully',
            'data' => [
                'total_transfers' => count($result),
                'transfers' => $result
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}

// Add this method to the StoreTransfer class
function getStoreTransferRequests($json) {
    include "connection-pdo.php";
    
    try {
        $data = json_decode($json, true);
        
        // Optional store_id filter
        $storeId = $data['store_id'] ?? null;
        
        $whereConditions = [];
        $params = [];
        
        if ($storeId) {
            $whereConditions[] = "sst.to_store_id = :store_id";
            $params[':store_id'] = $storeId;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Get only store transfer requests (from warehouse to store)
        $transferSql = "SELECT 
                            sst.transfer_id,
                            sst.from_warehouse_id,
                            sst.to_store_id,
                            sst.status,
                            sst.created_by,
                            sst.requested_date,
                            sst.approved_date,
                            sst.dispatched_date,
                            sst.received_date,
                            sst.created_at,
                            w.warehouse_name as from_warehouse_name,
                            s.store_name as to_store_name,
                            uc.full_name as created_by_name,
                            -- Calculate totals
                            (SELECT COUNT(*) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_items,
                            (SELECT SUM(quantity) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_quantity,
                            (SELECT SUM(quantity * unit_price) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_value,
                            -- Status descriptions for store perspective
                            CASE sst.status
                                WHEN 'pending' THEN 'Request Sent'
                                WHEN 'approved' THEN 'Approved by Warehouse'
                                WHEN 'in_transit' THEN 'In Transit to Store'
                                WHEN 'completed' THEN 'Received at Store'
                                WHEN 'cancelled' THEN 'Cancelled'
                                ELSE sst.status
                            END as status_description,
                            -- Estimated timeline
                            CASE 
                                WHEN sst.status = 'pending' THEN 'Awaiting warehouse approval'
                                WHEN sst.status = 'approved' THEN 'Preparing for dispatch'
                                WHEN sst.status = 'in_transit' THEN 'On the way to store'
                                WHEN sst.status = 'completed' THEN 'Successfully received'
                                WHEN sst.status = 'cancelled' THEN 'Transfer cancelled'
                                ELSE 'Status unknown'
                            END as status_timeline
                        FROM store_stock_transfer sst
                        LEFT JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                        LEFT JOIN store s ON sst.to_store_id = s.store_id
                        LEFT JOIN users uc ON sst.created_by = uc.user_id
                        $whereClause
                        ORDER BY sst.requested_date DESC, sst.created_at DESC";

        $transferStmt = $conn->prepare($transferSql);
        $transferStmt->execute($params);
        $transfers = $transferStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items for each transfer
        $result = [];
        foreach ($transfers as $transfer) {
            $transferId = $transfer['transfer_id'];
            
            // Get transfer items (only basic info for store view)
            $itemsSql = "SELECT 
                            ssti.product_id,
                            ssti.quantity,
                            ssti.unit_price,
                            p.product_name,
                            p.barcode,
                            c.category_name,
                            u.unit_name,
                            (ssti.quantity * ssti.unit_price) as item_value
                        FROM store_stock_transfer_items ssti
                        INNER JOIN products p ON ssti.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN units u ON p.unit_id = u.unit_id
                        WHERE ssti.transfer_id = :transfer_id
                        ORDER BY p.product_name";

            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(':transfer_id', $transferId);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Add items to transfer data
            $transfer['items'] = $items;
            $result[] = $transfer;
        }

        // Get summary statistics
        $statusCounts = [
            'pending' => 0,
            'approved' => 0,
            'in_transit' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => count($result)
        ];

        foreach ($result as $transfer) {
            $status = $transfer['status'];
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Store transfer requests retrieved successfully',
            'data' => [
                'summary' => $statusCounts,
                'transfers' => $result
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}

// Add this method to the StoreTransfer class
function getWarehouseStoreRequests($json) {
    include "connection-pdo.php";
    
    try {
        $data = json_decode($json, true);
        
        if (empty($data['warehouse_id'])) {
            throw new Exception("Missing required field: warehouse_id");
        }

        $warehouseId = $data['warehouse_id'];
        $status = $data['status'] ?? null;
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;
        
        $whereConditions = ["sst.from_warehouse_id = :warehouse_id"];
        $params = [':warehouse_id' => $warehouseId];
        
        // Apply status filter if provided
        if (!empty($status)) {
            $whereConditions[] = "sst.status = :status";
            $params[':status'] = $status;
        }
        
        // Apply date filters if provided
        if (!empty($dateFrom)) {
            $whereConditions[] = "sst.requested_date >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        
        if (!empty($dateTo)) {
            $whereConditions[] = "sst.requested_date <= :date_to";
            $params[':date_to'] = $dateTo;
        }
        
        $whereClause = implode(' AND ', $whereConditions);

        // Get warehouse store transfer requests
        $transferSql = "SELECT 
                            sst.transfer_id,
                            sst.from_warehouse_id,
                            sst.to_store_id,
                            sst.status,
                            sst.created_by,
                            sst.requested_date,
                            sst.approved_date,
                            sst.dispatched_date,
                            sst.received_date,
                            sst.created_at,
                            w.warehouse_name as from_warehouse_name,
                            s.store_name as to_store_name,
                            uc.full_name as created_by_name,
                            -- Calculate totals
                            (SELECT COUNT(*) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_items,
                            (SELECT SUM(quantity) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_quantity,
                            (SELECT SUM(quantity * unit_price) FROM store_stock_transfer_items WHERE transfer_id = sst.transfer_id) as total_value
                        FROM store_stock_transfer sst
                        LEFT JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                        LEFT JOIN store s ON sst.to_store_id = s.store_id
                        LEFT JOIN users uc ON sst.created_by = uc.user_id
                        WHERE $whereClause
                        ORDER BY sst.requested_date DESC, sst.created_at DESC";

        $transferStmt = $conn->prepare($transferSql);
        $transferStmt->execute($params);
        $transfers = $transferStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items for each transfer
        $result = [];
        foreach ($transfers as $transfer) {
            $transferId = $transfer['transfer_id'];
            
            // Get transfer items with product info
            $itemsSql = "SELECT 
                            ssti.*,
                            p.product_name,
                            p.barcode,
                            c.category_name,
                            u.unit_name,
                            -- Current stock in warehouse
                            COALESCE(ws.quantity, 0) as current_warehouse_stock
                        FROM store_stock_transfer_items ssti
                        INNER JOIN products p ON ssti.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN units u ON p.unit_id = u.unit_id
                        LEFT JOIN warehouse_stock ws ON (
                            ws.warehouse_id = :warehouse_id 
                            AND ws.product_id = ssti.product_id
                        )
                        WHERE ssti.transfer_id = :transfer_id
                        ORDER BY p.product_name";

            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->execute([
                ':transfer_id' => $transferId,
                ':warehouse_id' => $warehouseId
            ]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Add items to transfer data
            $transfer['items'] = $items;
            $result[] = $transfer;
        }

        // Get summary statistics
        $statusCounts = [
            'pending' => 0,
            'approved' => 0,
            'in_transit' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => count($result)
        ];

        foreach ($result as $transfer) {
            $status = $transfer['status'];
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Warehouse store transfer requests retrieved successfully',
            'data' => [
                'summary' => [
                    'status_counts' => $statusCounts,
                    'total_requests' => count($result)
                ],
                'transfers' => $result
            ]
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

$storeTransfer = new StoreTransfer();

// Handle operations
switch($operation) {
    case "createTransferRequest":
        $storeTransfer->createTransferRequest($json);
        break;

    case "getAllTransferDetails":
        $storeTransfer->getAllTransferDetails($json);
        break;

    case "getStoreTransferRequests":
        $storeTransfer->getStoreTransferRequests($json);
        break;

    case "getWarehouseStoreRequests":
        $storeTransfer->getWarehouseStoreRequests($json);
        break;
        
    case "checkROPAndCreateTransfers":
        $storeTransfer->checkROPAndCreateTransfers($json);
        break;
        
    case "approveTransfer":
        $storeTransfer->approveTransfer($json);
        break;
        
    case "dispatchTransfer":
        $storeTransfer->dispatchTransfer($json);
        break;
        
    case "receiveTransfer":
        $storeTransfer->receiveTransfer($json);
        break;
        
    case "getTransferList":
        $storeTransfer->getTransferList($json);
        break;
        
    case "getTransferDetailsPublic":
        $storeTransfer->getTransferDetailsPublic($json);
        break;
        
    case "cancelTransfer":
        $storeTransfer->cancelTransfer($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations: createTransferRequest, checkROPAndCreateTransfers, approveTransfer, dispatchTransfer, receiveTransfer, getTransferList, getTransferDetails, cancelTransfer'
        ]);
}

?>
    