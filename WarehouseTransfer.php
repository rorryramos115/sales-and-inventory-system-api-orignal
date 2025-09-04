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

                $itemSql = "INSERT INTO warehouse_stock_transfer_items (
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

    // Internal version of createTransferRequest that returns data instead of outputting

    private function createTransferRequestInternal($json) {
        include "connection-pdo.php";
        
        try {
            $conn->beginTransaction();
            $data = json_decode($json, true);
            
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

                $itemSql = "INSERT INTO warehouse_stock_transfer_items (
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

            // Get the created transfer with details - THIS WAS MISSING
            $transferDetails = $this->getTransferDetails($transferId, $conn);

            return [
                'status' => 'success',
                'message' => 'Transfer request created successfully',
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


function checkROPAndCreateTransfers($json = null) {
    include "connection-pdo.php";
    
    try {
        $data = $json ? json_decode($json, true) : [];
        $warehouseId = $data['warehouse_id'] ?? null;
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
        
        // Build warehouse filter condition
        $warehouseCondition = $warehouseId ? "AND sw.warehouse_id = :warehouse_id" : "";
        
        // FIXED: Added missing comma in reorder_point column definition
        $sql = "SELECT 
                    sw.warehouse_id,
                    sw.product_id,
                    sw.quantity as current_quantity,
                    sw.unit_price as current_unit_price,
                    p.product_name,
                    p.barcode,
                    p.reorder_point,
                    p.min_stock_level,
                    p.max_stock_level,
                    w.warehouse_name,
                    c.category_name,
                    b.brand_name,
                    u.unit_name,
                    -- Main warehouse stock info
                    COALESCE(mw_stock.quantity, 0) as main_warehouse_stock,
                    COALESCE(mw_stock.unit_price, sw.unit_price) as main_warehouse_unit_price,
                    -- Warehouse manager info
                    COALESCE(wm.user_id, '') as warehouse_manager_id,
                    COALESCE(wm_user.full_name, 'No Manager') as warehouse_manager_name,
                    -- Check for existing pending transfers to avoid duplicates
                    COALESCE(pending_transfers.pending_quantity, 0) as pending_transfer_quantity,
                    -- Check for any existing transfer (pending or approved) for this product
                    COALESCE(existing_transfers.existing_transfer_count, 0) as existing_transfer_count,
                    -- Calculate stock status
                    CASE 
                        WHEN sw.quantity <= p.min_stock_level THEN 'critical'
                        WHEN sw.quantity <= p.reorder_point THEN 'low' 
                        ELSE 'sufficient'
                    END as stock_status,
                    -- Calculate recommended transfer quantity
                    GREATEST(
                        p.reorder_point - sw.quantity, -- Bring to reorder point
                        15 -- Minimum efficient transfer quantity
                    ) as recommended_transfer_qty
                FROM warehouse_stock sw
                INNER JOIN warehouses w ON sw.warehouse_id = w.warehouse_id
                INNER JOIN products p ON sw.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN brands b ON p.brand_id = b.brand_id  
                LEFT JOIN units u ON p.unit_id = u.unit_id
                -- Get main warehouse stock for the same product
                LEFT JOIN warehouse_stock mw_stock ON (
                    mw_stock.warehouse_id = :main_warehouse_id 
                    AND mw_stock.product_id = sw.product_id
                )
                -- Get assigned warehouse manager
                LEFT JOIN (
                    SELECT DISTINCT 
                        aw.warehouse_id,
                        aw.user_id
                    FROM assign_warehouse aw
                    INNER JOIN users u ON aw.user_id = u.user_id
                    INNER JOIN roles r ON u.role_id = r.role_id
                    WHERE r.role_name = 'warehouse_manager'
                    AND aw.is_active = 1
                    AND u.is_active = 1
                ) wm ON sw.warehouse_id = wm.warehouse_id
                LEFT JOIN users wm_user ON wm.user_id = wm_user.user_id
                -- Check for existing pending transfers to avoid duplicates
                LEFT JOIN (
                    SELECT 
                        wst.to_warehouse_id,
                        wsti.product_id,
                        SUM(wsti.quantity) as pending_quantity
                    FROM warehouse_stock_transfer wst
                    INNER JOIN warehouse_stock_transfer_items wsti ON wst.transfer_id = wsti.transfer_id
                    WHERE wst.status IN ('pending', 'approved', 'in_transit')
                    AND wst.from_warehouse_id = :main_warehouse_id2
                    GROUP BY wst.to_warehouse_id, wsti.product_id
                ) pending_transfers ON (
                    sw.warehouse_id = pending_transfers.to_warehouse_id 
                    AND sw.product_id = pending_transfers.product_id
                )
                -- NEW: Check for any existing transfer for this product to the same warehouse
                LEFT JOIN (
                    SELECT 
                        wst.to_warehouse_id,
                        wsti.product_id,
                        COUNT(*) as existing_transfer_count
                    FROM warehouse_stock_transfer wst
                    INNER JOIN warehouse_stock_transfer_items wsti ON wst.transfer_id = wsti.transfer_id
                    WHERE wst.status IN ('pending', 'approved', 'in_transit')
                    AND wst.from_warehouse_id = :main_warehouse_id3
                    GROUP BY wst.to_warehouse_id, wsti.product_id
                ) existing_transfers ON (
                    sw.warehouse_id = existing_transfers.to_warehouse_id 
                    AND sw.product_id = existing_transfers.product_id
                )
                WHERE w.is_main = 0 -- Only sub-warehouses
                AND w.is_active = 1
                AND p.is_active = 1
                AND sw.quantity <= p.reorder_point -- At or below reorder point
                $warehouseCondition
                ORDER BY 
                    stock_status DESC, -- Critical first
                    sw.warehouse_id, 
                    p.product_name";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':main_warehouse_id', $mainWarehouseId);
        $stmt->bindValue(':main_warehouse_id2', $mainWarehouseId);
        $stmt->bindValue(':main_warehouse_id3', $mainWarehouseId);
        if ($warehouseId) {
            $stmt->bindValue(':warehouse_id', $warehouseId);
        }
        $stmt->execute();
        $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize summary
        $summary = [
            'trigger_type' => $triggerType,
            'main_warehouse_id' => $mainWarehouseId,
            'check_time' => date('Y-m-d H:i:s'),
            'total_products_checked' => count($lowStockItems),
            'critical_stock_items' => 0,
            'low_stock_items' => 0,
            'transfers_created' => 0,
            'transfers_skipped' => 0,
            'warehouses_processed' => [],
            'warnings' => [],
            'errors' => []
        ];

        // If no items need attention
        if (empty($lowStockItems)) {
            $result = [
                'status' => 'success',
                'message' => 'Stock monitoring completed - all products above reorder point',
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

        // Group items by warehouse for processing
        $itemsByWarehouse = [];
        foreach ($lowStockItems as $item) {
            $whId = $item['warehouse_id'];
            if (!isset($itemsByWarehouse[$whId])) {
                $itemsByWarehouse[$whId] = [
                    'warehouse_info' => [
                        'warehouse_id' => $item['warehouse_id'],
                        'warehouse_name' => $item['warehouse_name'],
                        'manager_id' => $item['warehouse_manager_id'],
                        'manager_name' => $item['warehouse_manager_name']
                    ],
                    'items' => []
                ];
            }
            $itemsByWarehouse[$whId]['items'][] = $item;
        }

        // Process each warehouse
        foreach ($itemsByWarehouse as $whId => $warehouseData) {
            $warehouseInfo = $warehouseData['warehouse_info'];
            $warehouseProcessed = [
                'warehouse_id' => $whId,
                'warehouse_name' => $warehouseInfo['warehouse_name'],
                'manager_name' => $warehouseInfo['manager_name'],
                'total_low_stock_products' => count($warehouseData['items']),
                'transfer_created' => false,
                'transfer_id' => null,
                'items_processed' => [],
                'warnings' => []
            ];

            // Skip warehouses without managers
            if (empty($warehouseInfo['manager_id'])) {
                // Try to find an admin user as fallback
                $adminSql = "SELECT u.user_id FROM users u 
                            INNER JOIN roles r ON u.role_id = r.role_id 
                            WHERE r.role_name IN ('admin', 'super_admin') 
                            AND u.is_active = 1 LIMIT 1";
                $adminStmt = $conn->prepare($adminSql);
                $adminStmt->execute();
                $adminUserId = $adminStmt->fetchColumn();
                
                if ($adminUserId) {
                    $warehouseInfo['manager_id'] = $adminUserId;
                    $warehouseInfo['manager_name'] = 'System Admin (Auto-assigned)';
                } else {
                    $warning = "Warehouse '{$warehouseInfo['warehouse_name']}' has no assigned manager and no admin found - skipping auto transfer creation";
                    $summary['warnings'][] = $warning;
                    $warehouseProcessed['warnings'][] = $warning;
                    $summary['transfers_skipped']++;
                    $summary['warehouses_processed'][] = $warehouseProcessed;
                    continue;
                }
            }

            $transferItems = [];
            $insufficientStockWarnings = [];

            // Process each product
            foreach ($warehouseData['items'] as $item) {
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

                // NEW: Skip if there's already a transfer for this product to this warehouse
                if ($existingTransferCount > 0 && !$forceCheck) {
                    $itemProcessed['action'] = 'skipped';
                    $itemProcessed['reason'] = "Existing transfer already created for this product";
                    $warehouseProcessed['items_processed'][] = $itemProcessed;
                    continue;
                }

                // Check pending transfers
                if ($pendingQty > 0 && !$forceCheck) {
                    // Only skip if pending transfer would bring stock above reorder point
                    if (($currentStock + $pendingQty) > $reorderPoint) {
                        $itemProcessed['action'] = 'skipped';
                        $itemProcessed['reason'] = "Sufficient pending transfer exists ({$pendingQty} units)";
                        $warehouseProcessed['items_processed'][] = $itemProcessed;
                        continue;
                    }
                }

                $targetStockLevel = 150;

                if ($maxStockLevel < 150) {
                    $targetStockLevel = floor($maxStockLevel * 0.75);
                }

                if ($targetStockLevel <= $reorderPoint) {
                    $targetStockLevel = $reorderPoint + 20; 
                }

                // Calculate quantity needed to reach target level
                $neededQty = $targetStockLevel - $currentStock - $pendingQty;

                // Ensure minimum efficient transfer (at least 15 units)
                $neededQty = max($neededQty, 15);

                // Don't exceed max stock level
                $maxTransferQty = $maxStockLevel - $currentStock - $pendingQty;
                $transferQty = min($neededQty, $maxTransferQty);
                $transferQty = max(0, $transferQty); // Never negative

                // Calculate available stock from main warehouse
                $mainWarehouseBuffer = max(20, $minStockLevel * 0.5);
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

                $warehouseProcessed['items_processed'][] = $itemProcessed;
            }

            // Create transfer if we have items to transfer
            if (!empty($transferItems)) {
                try {
                    $transferRequest = [
                        'from_warehouse_id' => $mainWarehouseId,
                        'to_warehouse_id' => $whId,
                        'created_by' => $warehouseInfo['manager_id'],
                        'request_type' => 'auto_rop',
                        'items' => $transferItems
                    ];

                    $transferResult = $this->createTransferRequestInternal(json_encode($transferRequest));
                    
                    if ($transferResult && $transferResult['status'] === 'success') {
                        $warehouseProcessed['transfer_created'] = true;
                        $warehouseProcessed['transfer_id'] = $transferResult['data']['transfer_id'];
                        $summary['transfers_created']++;
                    } else {
                        $error = "Failed to create transfer for warehouse {$warehouseInfo['warehouse_name']}: " . 
                                ($transferResult['message'] ?? 'Unknown error');
                        $summary['errors'][] = $error;
                        $warehouseProcessed['warnings'][] = $error;
                    }
                    
                } catch (Exception $e) {
                    $error = "Exception creating transfer for warehouse {$warehouseInfo['warehouse_name']}: " . $e->getMessage();
                    $summary['errors'][] = $error;
                    $warehouseProcessed['warnings'][] = $error;
                }
            } else {
                $summary['transfers_skipped']++;
                $warehouseProcessed['warnings'][] = 'No transferable items - all products have insufficient main stock or pending transfers';
            }

            // Add insufficient stock warnings to warehouse processing info
            if (!empty($insufficientStockWarnings)) {
                $warehouseProcessed['insufficient_stock_warnings'] = $insufficientStockWarnings;
            }

            $summary['warehouses_processed'][] = $warehouseProcessed;
        }

        // Prepare final result
        $message = "Stock monitoring completed";
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
            'message' => 'Stock monitoring failed: ' . $e->getMessage(),
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

// Helper method for batch stock monitoring (can be called by cron job)
function batchStockMonitoring() {
    try {
        // Monitor all warehouses
        $result = $this->checkROPAndCreateTransfers();
        
        // Log summary for monitoring
        if (isset($result['data']['summary'])) {
            $summary = $result['data']['summary'];
            $logMessage = sprintf(
                "Batch stock monitoring - Checked: %d products, Created: %d transfers, Skipped: %d, Critical: %d, Low: %d",
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
        error_log("Batch stock monitoring failed: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

// Event handler for stock updates
function handleStockUpdateEvent($json) {
    try {
        $data = json_decode($json, true);
        $warehouseId = $data['warehouse_id'] ?? null;
        
        if ($warehouseId) {
            // Check specific warehouse after stock update
            $checkData = [
                'warehouse_id' => $warehouseId,
                'trigger_type' => 'stock_update',
                'force_check' => false // Don't override existing pending transfers on stock updates
            ];
            
            return $this->checkROPAndCreateTransfers(json_encode($checkData));
        }
        
        return [
            'status' => 'error',
            'message' => 'No warehouse_id provided in stock update event'
        ];
        
    } catch (Exception $e) {
        error_log("Stock update event handling failed: " . $e->getMessage());
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
        'force_check' => false // Don't override existing pending transfers in scheduled runs
    ];
    
    return $this->checkROPAndCreateTransfers(json_encode($checkData));
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

            // Get transfer items with unit prices
            $itemsSql = "SELECT wsti.*, p.product_name, wsti.unit_price
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

            // Get transfer items with unit prices
            $itemsSql = "SELECT wsti.*, p.product_name, wsti.unit_price
                        FROM warehouse_stock_transfer_items wsti
                        INNER JOIN products p ON wsti.product_id = p.product_id
                        WHERE wsti.transfer_id = :transfer_id";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(':transfer_id', $data['transfer_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get received quantities (if partial receive)
            $receivedItems = $data['received_items'] ?? [];
            
            $totalValue = 0;
            $itemsReceived = [];

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

                // FIXED: Select all required fields including quantity
                $existingStockSql = "SELECT stock_id, quantity, unit_price FROM warehouse_stock 
                                    WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                
                $existingStockStmt = $conn->prepare($existingStockSql);
                $existingStockStmt->execute([
                    ':warehouse_id' => $transfer['to_warehouse_id'],
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
                    $updateStockSql = "UPDATE warehouse_stock 
                                    SET quantity = quantity + :quantity, 
                                        unit_price = :unit_price,
                                        last_updated = NOW()
                                    WHERE warehouse_id = :warehouse_id AND product_id = :product_id";
                    
                    $updateStockStmt = $conn->prepare($updateStockSql);
                    $updateStockStmt->execute([
                        ':quantity' => $receivedQty,
                        ':unit_price' => $averageUnitPrice,
                        ':warehouse_id' => $transfer['to_warehouse_id'],
                        ':product_id' => $item['product_id']
                    ]);
                } else {
                    // Create new stock record with transferred unit price
                    $insertStockSql = "INSERT INTO warehouse_stock 
                                    (stock_id, warehouse_id, product_id, quantity, unit_price, last_updated) 
                                    VALUES (:stock_id, :warehouse_id, :product_id, :quantity, :unit_price, NOW())";
                    
                    $insertStockStmt = $conn->prepare($insertStockSql);
                    $insertStockStmt->execute([
                        ':stock_id' => $this->generateUUID(),
                        ':warehouse_id' => $transfer['to_warehouse_id'],
                        ':product_id' => $item['product_id'],
                        ':quantity' => $receivedQty,
                        ':unit_price' => $item['unit_price']
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

            // Get transfer items with unit prices
            $itemsSql = "SELECT 
                            wsti.*,
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
                            (wsti.quantity * wsti.unit_price) as item_value
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
            $transfer['total_value'] = array_sum(array_column($items, 'item_value'));

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