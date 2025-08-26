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

            // Verify purchase order exists and get order items
            $orderCheckSql = "SELECT 
                                po.order_id, po.supplier_id, po.total_amount,
                                poi.product_id, poi.quantity as ordered_quantity, poi.unit_cost
                              FROM purchase_orders po
                              INNER JOIN purchase_order_items poi ON po.order_id = poi.order_id
                              WHERE po.order_id = :orderId";
            $orderCheckStmt = $conn->prepare($orderCheckSql);
            $orderCheckStmt->bindValue(":orderId", $data['order_id']);
            $orderCheckStmt->execute();
            $orderItems = $orderCheckStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$orderItems) {
                throw new Exception("Purchase order not found");
            }

            $order = [
                'order_id' => $orderItems[0]['order_id'],
                'supplier_id' => $orderItems[0]['supplier_id'],
                'total_amount' => $orderItems[0]['total_amount']
            ];

            // Check if already received
            $receiveCheckSql = "SELECT receive_id FROM stock_receive WHERE order_id = :orderId";
            $receiveCheckStmt = $conn->prepare($receiveCheckSql);
            $receiveCheckStmt->bindValue(":orderId", $data['order_id']);
            $receiveCheckStmt->execute();
            
            if ($receiveCheckStmt->fetch()) {
                throw new Exception("This purchase order has already been received");
            }

            // Create array of ordered items for validation
            $orderedItems = [];
            foreach ($orderItems as $item) {
                $orderedItems[$item['product_id']] = [
                    'ordered_quantity' => $item['ordered_quantity'],
                    'unit_cost' => $item['unit_cost']
                ];
            }

            // Generate UUID
            $receiveId = $this->generateUuid();
            
            // Prepare data
            $receiveDate = $data['receive_date'] ?? date('Y-m-d');
            
            // Calculate total amount from received items and validate
            $totalAmount = 0;
            $returnItems = []; // Track items that need to be returned to supplier
            
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || !isset($item['quantity_receive']) || empty($item['unit_cost'])) {
                    throw new Exception("Product ID, quantity_receive, and unit cost are required for all items");
                }

                // Validate that product exists in the order
                if (!isset($orderedItems[$item['product_id']])) {
                    throw new Exception("Product {$item['product_id']} is not in the original purchase order");
                }

                $orderedQty = $orderedItems[$item['product_id']]['ordered_quantity'];
                $receivedQty = $item['quantity_receive'];

                // Validate received quantity doesn't exceed ordered quantity
                if ($receivedQty > $orderedQty) {
                    throw new Exception("Received quantity ({$receivedQty}) cannot exceed ordered quantity ({$orderedQty}) for product {$item['product_id']}");
                }

                $totalAmount += $receivedQty * $item['unit_cost'];

                // Track items that need to be returned to supplier
                if ($receivedQty < $orderedQty) {
                    $returnItems[] = [
                        'product_id' => $item['product_id'],
                        'ordered_quantity' => $orderedQty,
                        'received_quantity' => $receivedQty,
                        'return_quantity' => $orderedQty - $receivedQty,
                        'unit_cost' => $item['unit_cost']
                    ];
                }
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
                
                // Insert stock receive item with quantity_receive
                $receiveItemSql = "INSERT INTO stock_receive_items(
                                    receive_item_id, receive_id, product_id, quantity_receive, 
                                    unit_cost
                                ) VALUES(
                                    :receiveItemId, :receiveId, :productId, :quantityReceive, 
                                    :unitCost
                                )";
                
                $receiveItemStmt = $conn->prepare($receiveItemSql);
                $receiveItemStmt->bindValue(":receiveItemId", $receiveItemId);
                $receiveItemStmt->bindValue(":receiveId", $receiveId);
                $receiveItemStmt->bindValue(":productId", $item['product_id']);
                $receiveItemStmt->bindValue(":quantityReceive", $item['quantity_receive'], PDO::PARAM_INT);
                $receiveItemStmt->bindValue(":unitCost", $item['unit_cost']);
                
                if (!$receiveItemStmt->execute()) {
                    throw new Exception("Failed to create receive item");
                }

                // Update warehouse stock with received quantity
                $this->updateWarehouseStock($conn, $data['warehouse_id'], $item['product_id'], 
                                        $item['quantity_receive'], $item['unit_cost']);
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
                    'total_amount' => $totalAmount,
                    'return_items' => $returnItems // Items that can be returned to supplier
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

    // Return items to supplier
    // function returnToSupplier($data) {
    //     include "connection-pdo.php";
    //     $conn->beginTransaction();

    //     try {
    //         if (empty($data['order_id'])) {
    //             throw new Exception("Order ID is required");
    //         }
    //         if (empty($data['warehouse_id'])) {
    //             throw new Exception("Warehouse ID is required");
    //         }
    //         if (empty($data['returned_by'])) {
    //             throw new Exception("Returned by user ID is required");
    //         }
    //         if (empty($data['items']) || !is_array($data['items'])) {
    //             throw new Exception("Items are required");
    //         }

    //         // Get purchase order and receive information
    //         $orderSql = "SELECT 
    //                         po.order_id, po.supplier_id,
    //                         sr.receive_id
    //                      FROM purchase_orders po
    //                      INNER JOIN stock_receive sr ON po.order_id = sr.order_id
    //                      WHERE po.order_id = :orderId";
    //         $orderStmt = $conn->prepare($orderSql);
    //         $orderStmt->bindValue(":orderId", $data['order_id']);
    //         $orderStmt->execute();
    //         $orderInfo = $orderStmt->fetch(PDO::FETCH_ASSOC);

    //         if (!$orderInfo) {
    //             throw new Exception("Purchase order not found or not yet received");
    //         }

    //         // Get ordered vs received quantities to validate return quantities
    //         $itemsSql = "SELECT 
    //                         poi.product_id, 
    //                         poi.quantity as ordered_quantity,
    //                         sri.quantity_receive,
    //                         sri.unit_cost
    //                      FROM purchase_order_items poi
    //                      INNER JOIN stock_receive_items sri ON poi.product_id = sri.product_id
    //                      INNER JOIN stock_receive sr ON sri.receive_id = sr.receive_id
    //                      WHERE poi.order_id = :orderId AND sr.receive_id = :receiveId";
    //         $itemsStmt = $conn->prepare($itemsSql);
    //         $itemsStmt->bindValue(":orderId", $data['order_id']);
    //         $itemsStmt->bindValue(":receiveId", $orderInfo['receive_id']);
    //         $itemsStmt->execute();
    //         $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    //         // Create lookup array
    //         $itemLookup = [];
    //         foreach ($orderItems as $item) {
    //             $itemLookup[$item['product_id']] = $item;
    //         }

    //         // Generate UUID for return
    //         $returnId = $this->generateUuid();
            
    //         // Prepare data
    //         $returnDate = $data['return_date'] ?? date('Y-m-d');
    //         $reason = $data['reason'] ?? 'Quantity shortage from supplier';
            
    //         // Calculate total return amount and validate items
    //         $totalAmount = 0;
    //         foreach ($data['items'] as $item) {
    //             if (empty($item['product_id']) || empty($item['quantity_return']) || empty($item['unit_cost'])) {
    //                 throw new Exception("Product ID, quantity_return, and unit cost are required for all items");
    //             }

    //             // Validate product exists in order
    //             if (!isset($itemLookup[$item['product_id']])) {
    //                 throw new Exception("Product {$item['product_id']} is not in the original purchase order");
    //             }

    //             $orderItem = $itemLookup[$item['product_id']];
    //             $maxReturnQty = $orderItem['ordered_quantity'] - $orderItem['quantity_receive'];

    //             // Validate return quantity
    //             if ($item['quantity_return'] > $maxReturnQty) {
    //                 throw new Exception("Return quantity ({$item['quantity_return']}) cannot exceed undelivered quantity ({$maxReturnQty}) for product {$item['product_id']}");
    //             }

    //             if ($item['quantity_return'] <= 0) {
    //                 throw new Exception("Return quantity must be greater than 0 for product {$item['product_id']}");
    //             }

    //             $totalAmount += $item['quantity_return'] * $item['unit_cost'];
    //         }

    //         // Insert supplier return record
    //         $returnSql = "INSERT INTO supplier_returns(
    //                         return_id, supplier_id, return_date, warehouse_id, 
    //                         reason, returned_by, total_amount
    //                     ) VALUES(
    //                         :returnId, :supplierId, :returnDate, :warehouseId, 
    //                         :reason, :returnedBy, :totalAmount
    //                     )";
            
    //         $returnStmt = $conn->prepare($returnSql);
    //         $returnStmt->bindValue(":returnId", $returnId);
    //         $returnStmt->bindValue(":supplierId", $orderInfo['supplier_id']);
    //         $returnStmt->bindValue(":returnDate", $returnDate);
    //         $returnStmt->bindValue(":warehouseId", $data['warehouse_id']);
    //         $returnStmt->bindValue(":reason", $reason);
    //         $returnStmt->bindValue(":returnedBy", $data['returned_by']);
    //         $returnStmt->bindValue(":totalAmount", $totalAmount);
            
    //         if (!$returnStmt->execute()) {
    //             throw new Exception("Failed to create supplier return record");
    //         }

    //         // Process each return item
    //         foreach ($data['items'] as $item) {
    //             $returnItemId = $this->generateUuid();
    //             $totalCost = $item['quantity_return'] * $item['unit_cost'];
                
    //             // Insert supplier return item
    //             $returnItemSql = "INSERT INTO supplier_return_items(
    //                                 return_item_id, return_id, product_id, quantity_return, 
    //                                 unit_cost, total_cost
    //                             ) VALUES(
    //                                 :returnItemId, :returnId, :productId, :quantityReturn, 
    //                                 :unitCost, :totalCost
    //                             )";
                
    //             $returnItemStmt = $conn->prepare($returnItemSql);
    //             $returnItemStmt->bindValue(":returnItemId", $returnItemId);
    //             $returnItemStmt->bindValue(":returnId", $returnId);
    //             $returnItemStmt->bindValue(":productId", $item['product_id']);
    //             $returnItemStmt->bindValue(":quantityReturn", $item['quantity_return'], PDO::PARAM_INT);
    //             $returnItemStmt->bindValue(":unitCost", $item['unit_cost']);
    //             $returnItemStmt->bindValue(":totalCost", $totalCost);
                
    //             if (!$returnItemStmt->execute()) {
    //                 throw new Exception("Failed to create return item");
    //             }
    //         }
            
    //         $conn->commit();
            
    //         return json_encode([
    //             'status' => 'success',
    //             'message' => 'Items returned to supplier successfully',
    //             'data' => [
    //                 'return_id' => $returnId,
    //                 'order_id' => $data['order_id'],
    //                 'supplier_id' => $orderInfo['supplier_id'],
    //                 'return_date' => $returnDate,
    //                 'warehouse_id' => $data['warehouse_id'],
    //                 'total_amount' => $totalAmount,
    //                 'reason' => $reason
    //             ]
    //         ]);
            
    //     } catch (Exception $e) {
    //         $conn->rollBack();
    //         return json_encode([
    //             'status' => 'error',
    //             'message' => $e->getMessage()
    //         ]);
    //     }
    // }
    function returnToSupplier($data) {
    include "connection-pdo.php";
    $conn->beginTransaction();

    try {
        if (empty($data['order_id'])) {
            throw new Exception("Order ID is required");
        }
        if (empty($data['warehouse_id'])) {
            throw new Exception("Warehouse ID is required");
        }
        if (empty($data['returned_by'])) {
            throw new Exception("Returned by user ID is required");
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception("Items are required");
        }

        // Get purchase order and receive information
        $orderSql = "SELECT 
                        po.order_id, po.supplier_id,
                        sr.receive_id
                     FROM purchase_orders po
                     INNER JOIN stock_receive sr ON po.order_id = sr.order_id
                     WHERE po.order_id = :orderId";
        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->bindValue(":orderId", $data['order_id']);
        $orderStmt->execute();
        $orderInfo = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) {
            throw new Exception("Purchase order not found or not yet received");
        }

        // Get ordered vs received quantities to validate return quantities
        $itemsSql = "SELECT 
                        poi.product_id, 
                        poi.quantity as ordered_quantity,
                        sri.quantity_receive,
                        sri.unit_cost
                     FROM purchase_order_items poi
                     INNER JOIN stock_receive_items sri ON poi.product_id = sri.product_id
                     INNER JOIN stock_receive sr ON sri.receive_id = sr.receive_id
                     WHERE poi.order_id = :orderId AND sr.receive_id = :receiveId";
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->bindValue(":orderId", $data['order_id']);
        $itemsStmt->bindValue(":receiveId", $orderInfo['receive_id']);
        $itemsStmt->execute();
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Create lookup array
        $itemLookup = [];
        foreach ($orderItems as $item) {
            $itemLookup[$item['product_id']] = $item;
        }

        // Generate UUID for return
        $returnId = $this->generateUuid();
        
        // Prepare data
        $returnDate = $data['return_date'] ?? date('Y-m-d');
        $reason = $data['reason'] ?? 'Quantity shortage from supplier';
        
        // Calculate total return amount and validate items
        $totalAmount = 0;
        foreach ($data['items'] as $item) {
            if (empty($item['product_id']) || empty($item['quantity_return']) || empty($item['unit_cost'])) {
                throw new Exception("Product ID, quantity_return, and unit cost are required for all items");
            }

            // Validate product exists in order
            if (!isset($itemLookup[$item['product_id']])) {
                throw new Exception("Product {$item['product_id']} is not in the original purchase order");
            }

            $orderItem = $itemLookup[$item['product_id']];
            $maxReturnQty = $orderItem['ordered_quantity'] - $orderItem['quantity_receive'];

            // Validate return quantity
            if ($item['quantity_return'] > $maxReturnQty) {
                throw new Exception("Return quantity ({$item['quantity_return']}) cannot exceed undelivered quantity ({$maxReturnQty}) for product {$item['product_id']}");
            }

            if ($item['quantity_return'] <= 0) {
                throw new Exception("Return quantity must be greater than 0 for product {$item['product_id']}");
            }

            $totalAmount += $item['quantity_return'] * $item['unit_cost'];
        }

        // Insert supplier return record - UPDATED TO INCLUDE order_id
        $returnSql = "INSERT INTO supplier_returns(
                        return_id, supplier_id, order_id, return_date, warehouse_id, 
                        reason, returned_by, total_amount
                    ) VALUES(
                        :returnId, :supplierId, :orderId, :returnDate, :warehouseId, 
                        :reason, :returnedBy, :totalAmount
                    )";
        
        $returnStmt = $conn->prepare($returnSql);
        $returnStmt->bindValue(":returnId", $returnId);
        $returnStmt->bindValue(":supplierId", $orderInfo['supplier_id']);
        $returnStmt->bindValue(":orderId", $data['order_id']); // Added order_id parameter
        $returnStmt->bindValue(":returnDate", $returnDate);
        $returnStmt->bindValue(":warehouseId", $data['warehouse_id']);
        $returnStmt->bindValue(":reason", $reason);
        $returnStmt->bindValue(":returnedBy", $data['returned_by']);
        $returnStmt->bindValue(":totalAmount", $totalAmount);
        
        if (!$returnStmt->execute()) {
            throw new Exception("Failed to create supplier return record");
        }

        // Process each return item
        foreach ($data['items'] as $item) {
            $returnItemId = $this->generateUuid();
            $totalCost = $item['quantity_return'] * $item['unit_cost'];
            
            // Insert supplier return item
            $returnItemSql = "INSERT INTO supplier_return_items(
                                return_item_id, return_id, product_id, quantity_return, 
                                unit_cost, total_cost
                            ) VALUES(
                                :returnItemId, :returnId, :productId, :quantityReturn, 
                                :unitCost, :totalCost
                            )";
            
            $returnItemStmt = $conn->prepare($returnItemSql);
            $returnItemStmt->bindValue(":returnItemId", $returnItemId);
            $returnItemStmt->bindValue(":returnId", $returnId);
            $returnItemStmt->bindValue(":productId", $item['product_id']);
            $returnItemStmt->bindValue(":quantityReturn", $item['quantity_return'], PDO::PARAM_INT);
            $returnItemStmt->bindValue(":unitCost", $item['unit_cost']);
            $returnItemStmt->bindValue(":totalCost", $totalCost);
            
            if (!$returnItemStmt->execute()) {
                throw new Exception("Failed to create return item");
            }
        }
        
        $conn->commit();
        
        return json_encode([
            'status' => 'success',
            'message' => 'Items returned to supplier successfully',
            'data' => [
                'return_id' => $returnId,
                'order_id' => $data['order_id'],
                'supplier_id' => $orderInfo['supplier_id'],
                'return_date' => $returnDate,
                'warehouse_id' => $data['warehouse_id'],
                'total_amount' => $totalAmount,
                'reason' => $reason
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
    // function directStockReceive($data) {
    //     include "connection-pdo.php";
    //     $conn->beginTransaction();

    //     try {
    //         if (empty($data['supplier_id'])) {
    //             throw new Exception("Supplier ID is required");
    //         }
    //         if (empty($data['warehouse_id'])) {
    //             throw new Exception("Warehouse ID is required");
    //         }
    //         if (empty($data['received_by'])) {
    //             throw new Exception("Received by user ID is required");
    //         }
    //         if (empty($data['supplier_receipt'])) {
    //             throw new Exception("Supplier receipt number is required");
    //         }
    //         if (empty($data['items']) || !is_array($data['items'])) {
    //             throw new Exception("Items are required");
    //         }

    //         // Generate UUID
    //         $receiveId = $this->generateUuid();
            
    //         // Prepare data
    //         $receiveDate = $data['receive_date'] ?? date('Y-m-d');
            
    //         // Calculate total amount
    //         $totalAmount = 0;
    //         foreach ($data['items'] as $item) {
    //             if (empty($item['product_id']) || !isset($item['quantity_receive']) || empty($item['unit_cost'])) {
    //                 throw new Exception("Product ID, quantity_receive, and unit cost are required for all items");
    //             }
    //             $totalAmount += $item['quantity_receive'] * $item['unit_cost'];
    //         }

    //         // Insert stock receive record (without order_id)
    //         $receiveSql = "INSERT INTO stock_receive(
    //                         receive_id, supplier_receipt, receive_date, 
    //                         supplier_id, warehouse_id, received_by, total_amount
    //                     ) VALUES(
    //                         :receiveId, :supplierReceipt, :receiveDate, 
    //                         :supplierId, :warehouseId, :receivedBy, :totalAmount
    //                     )";
            
    //         $receiveStmt = $conn->prepare($receiveSql);
    //         $receiveStmt->bindValue(":receiveId", $receiveId);
    //         $receiveStmt->bindValue(":supplierReceipt", $data['supplier_receipt']);
    //         $receiveStmt->bindValue(":receiveDate", $receiveDate);
    //         $receiveStmt->bindValue(":supplierId", $data['supplier_id']);
    //         $receiveStmt->bindValue(":warehouseId", $data['warehouse_id']);
    //         $receiveStmt->bindValue(":receivedBy", $data['received_by']);
    //         $receiveStmt->bindValue(":totalAmount", $totalAmount);
            
    //         if (!$receiveStmt->execute()) {
    //             throw new Exception("Failed to create stock receive record");
    //         }

    //         // Process each item
    //         foreach ($data['items'] as $item) {
    //             $receiveItemId = $this->generateUuid();
                
    //             // Insert stock receive item
    //             $receiveItemSql = "INSERT INTO stock_receive_items(
    //                                 receive_item_id, receive_id, product_id, quantity_receive, 
    //                                 unit_cost
    //                             ) VALUES(
    //                                 :receiveItemId, :receiveId, :productId, :quantityReceive, 
    //                                 :unitCost
    //                             )";
                
    //             $receiveItemStmt = $conn->prepare($receiveItemSql);
    //             $receiveItemStmt->bindValue(":receiveItemId", $receiveItemId);
    //             $receiveItemStmt->bindValue(":receiveId", $receiveId);
    //             $receiveItemStmt->bindValue(":productId", $item['product_id']);
    //             $receiveItemStmt->bindValue(":quantityReceive", $item['quantity_receive'], PDO::PARAM_INT);
    //             $receiveItemStmt->bindValue(":unitCost", $item['unit_cost']);
                
    //             if (!$receiveItemStmt->execute()) {
    //                 throw new Exception("Failed to create receive item");
    //             }

    //             // Update warehouse stock
    //             $this->updateWarehouseStock($conn, $data['warehouse_id'], $item['product_id'], 
    //                                     $item['quantity_receive'], $item['unit_cost']);
    //         }
            
    //         $conn->commit();
            
    //         return json_encode([
    //             'status' => 'success',
    //             'message' => 'Direct stock receive completed successfully',
    //             'data' => [
    //                 'receive_id' => $receiveId,
    //                 'supplier_receipt' => $data['supplier_receipt'],
    //                 'receive_date' => $receiveDate,
    //                 'supplier_id' => $data['supplier_id'],
    //                 'warehouse_id' => $data['warehouse_id'],
    //                 'total_amount' => $totalAmount
    //             ]
    //         ]);
            
    //     } catch (Exception $e) {
    //         $conn->rollBack();
    //         return json_encode([
    //             'status' => 'error',
    //             'message' => $e->getMessage()
    //         ]);
    //     }
    // }

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
                                    sri.quantity_receive,
                                    sri.unit_cost,
                                    (sri.quantity_receive * sri.unit_cost) as total_price,
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

    // Get all supplier returns
    function getAllSupplierReturns() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        spr.return_id,
                        spr.supplier_id,
                        spr.return_date,
                        spr.warehouse_id,
                        spr.reason,
                        spr.total_amount,
                        spr.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        s.contact_person,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        
                        -- Returned by user information
                        u.user_id as returned_by_id,
                        u.full_name as returned_by_name,
                        u.email as returned_by_email
                        
                    FROM supplier_returns spr
                    INNER JOIN suppliers s ON spr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON spr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON spr.returned_by = u.user_id
                    ORDER BY spr.return_date DESC, spr.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get return items for each return
            foreach ($returns as &$return) {
                $returnItemsSql = "SELECT 
                                    spri.return_item_id,
                                    spri.product_id,
                                    spri.quantity_return,
                                    spri.unit_cost,
                                    spri.total_cost,
                                    
                                    -- Product information
                                    p.product_name,
                                    p.barcode,
                                    p.description as product_description,
                                    
                                    -- Category information
                                    c.category_id,
                                    c.category_name,
                                    
                                    -- Brand information
                                    b.brand_id,
                                    b.brand_name,
                                    
                                    -- Unit information
                                    un.unit_id,
                                    un.unit_name
                                    
                                    FROM supplier_return_items spri
                                    INNER JOIN products p ON spri.product_id = p.product_id
                                    LEFT JOIN categories c ON p.category_id = c.category_id
                                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                                    LEFT JOIN units un ON p.unit_id = un.unit_id
                                    WHERE spri.return_id = :returnId
                                    ORDER BY p.product_name ASC";
                
                $returnItemsStmt = $conn->prepare($returnItemsSql);
                $returnItemsStmt->bindValue(":returnId", $return['return_id']);
                $returnItemsStmt->execute();
                $return['items'] = $returnItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'All supplier returns retrieved successfully',
                'data' => [
                    'total_returns' => count($returns),
                    'returns' => $returns
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get returnable items for a specific order (items that can be returned to supplier)
    function getReturnableItems($json) {
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
                        poi.product_id,
                        poi.quantity as ordered_quantity,
                        COALESCE(sri.quantity_receive, 0) as received_quantity,
                        (poi.quantity - COALESCE(sri.quantity_receive, 0)) as returnable_quantity,
                        poi.unit_cost,
                        
                        -- Product information
                        p.product_name,
                        p.barcode,
                        p.description as product_description,
                        
                        -- Purchase order information
                        po.order_id,
                        po.supplier_id,
                        s.supplier_name,
                        
                        -- Stock receive information
                        sr.receive_id,
                        sr.receive_date,
                        sr.warehouse_id,
                        w.warehouse_name
                        
                    FROM purchase_order_items poi
                    INNER JOIN purchase_orders po ON poi.order_id = po.order_id
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN products p ON poi.product_id = p.product_id
                    LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
                    LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    LEFT JOIN stock_receive_items sri ON sr.receive_id = sri.receive_id 
                        AND poi.product_id = sri.product_id
                    WHERE poi.order_id = :orderId
                    HAVING returnable_quantity > 0
                    ORDER BY p.product_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":orderId", $data['order_id']);
            $stmt->execute();
            $returnableItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($returnableItems)) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'No returnable items found for this order',
                    'data' => [
                        'order_id' => $data['order_id'],
                        'returnable_items' => []
                    ]
                ]);
                return;
            }

            // Calculate total returnable amount
            $totalReturnableAmount = 0;
            foreach ($returnableItems as $item) {
                $totalReturnableAmount += $item['returnable_quantity'] * $item['unit_cost'];
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Returnable items retrieved successfully',
                'data' => [
                    'order_id' => $data['order_id'],
                    'supplier_id' => $returnableItems[0]['supplier_id'],
                    'supplier_name' => $returnableItems[0]['supplier_name'],
                    'warehouse_id' => $returnableItems[0]['warehouse_id'],
                    'warehouse_name' => $returnableItems[0]['warehouse_name'],
                    'receive_date' => $returnableItems[0]['receive_date'],
                    'total_returnable_amount' => $totalReturnableAmount,
                    'returnable_items' => $returnableItems
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
        
    // case "directStockReceive":
    //     echo $stockReceive->directStockReceive($data);
    //     break;
        
    case "returnToSupplier":
        echo $stockReceive->returnToSupplier($data);
        break;
        
    case "getAllStockReceives":
        echo $stockReceive->getAllStockReceives();
        break;
        
    case "getAllSupplierReturns":
        echo $stockReceive->getAllSupplierReturns();
        break;
        
    case "getReturnableItems":
        echo $stockReceive->getReturnableItems($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}

?>