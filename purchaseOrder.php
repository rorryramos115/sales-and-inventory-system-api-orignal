<?php
// PurchaseOrder.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit();
}

class PurchaseOrder {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // Create purchase order only (no automatic stock receiving)
    function createPurchaseOrder($data) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if (empty($data['supplier_id'])) {
                throw new Exception("Supplier ID is required");
            }
            if (empty($data['created_by'])) {
                throw new Exception("Created by user ID is required");
            }
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Items are required");
            }

            // Generate UUID
            $orderId = $this->generateUuid();
            
            // Prepare data
            $orderDate = $data['order_date'] ?? date('Y-m-d');
            
            // Calculate total amount
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_cost'])) {
                    throw new Exception("Product ID, quantity, and unit cost are required for all items");
                }
                $totalAmount += $item['quantity'] * $item['unit_cost'];
            }

            // Insert purchase order
            $orderSql = "INSERT INTO purchase_orders(
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

            // Insert order items
            foreach ($data['items'] as $item) {
                $orderItemId = $this->generateUuid();
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                
                $orderItemSql = "INSERT INTO purchase_order_items(
                                    order_item_id, order_id, product_id, quantity, 
                                    unit_cost, total_price
                                ) VALUES(
                                    :orderItemId, :orderId, :productId, :quantity, 
                                    :unitCost, :totalPrice
                                )";
                
                $orderItemStmt = $conn->prepare($orderItemSql);
                $orderItemStmt->bindValue(":orderItemId", $orderItemId);
                $orderItemStmt->bindValue(":orderId", $orderId);
                $orderItemStmt->bindValue(":productId", $item['product_id']);
                $orderItemStmt->bindValue(":quantity", $item['quantity'], PDO::PARAM_INT);
                $orderItemStmt->bindValue(":unitCost", $item['unit_cost']);
                $orderItemStmt->bindValue(":totalPrice", $itemTotal);
                
                if (!$orderItemStmt->execute()) {
                    throw new Exception("Failed to create order item");
                }
            }
            
            $conn->commit();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Purchase order created successfully',
                'data' => [
                    'order_id' => $orderId,
                    'order_date' => $orderDate,
                    'supplier_id' => $data['supplier_id'],
                    'total_amount' => $totalAmount,
                    'status' => 'pending_receive'
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

    // Get all purchase orders with their receive status
    // function getAllPurchaseOrders() {
    //     include "connection-pdo.php";

    //     try {
    //         $sql = "SELECT 
    //                     po.order_id,
    //                     po.order_date,
    //                     po.total_amount,
    //                     po.created_at,
    //                     po.updated_at,
                        
    //                     -- Supplier information
    //                     s.supplier_id,
    //                     s.supplier_name,
    //                     s.contact_person,
    //                     s.phone as supplier_phone,
    //                     s.email as supplier_email,
    //                     s.address as supplier_address,
                        
    //                     -- Created by user information
    //                     u.user_id as created_by_id,
    //                     u.full_name as created_by_name,
    //                     u.email as created_by_email,
    //                     u.phone as created_by_phone,
                        
    //                     -- Receive status
    //                     sr.receive_id,
    //                     sr.supplier_receipt,
    //                     sr.receive_date,
    //                     sr.warehouse_id,
    //                     w.warehouse_name,
    //                     ru.full_name as received_by_name,
                        
    //                     -- Status calculation
    //                     CASE 
    //                         WHEN sr.receive_id IS NOT NULL THEN 'RECEIVED'
    //                         ELSE 'PENDING'
    //                     END as receive_status
                        
    //                 FROM purchase_orders po
    //                 INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
    //                 INNER JOIN users u ON po.created_by = u.user_id
    //                 LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
    //                 LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
    //                 LEFT JOIN users ru ON sr.received_by = ru.user_id
    //                 ORDER BY po.order_date DESC, po.created_at DESC";

    //         $stmt = $conn->prepare($sql);
    //         $stmt->execute();
    //         $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    //         // Get order items for each order
    //         foreach ($orders as &$order) {
    //             $itemsSql = "SELECT 
    //                             poi.order_item_id,
    //                             poi.product_id,
    //                             poi.quantity,
    //                             poi.unit_cost,
    //                             poi.total_price,
                                
    //                             -- Product information
    //                             p.product_name,
    //                             p.barcode,
    //                             p.description as product_description,
    //                             p.selling_price,
                                
    //                             -- Category information (if exists)
    //                             c.category_id,
    //                             c.category_name,
                                
    //                             -- Brand information
    //                             b.brand_id,
    //                             b.brand_name,
                                
    //                             -- Unit information
    //                             un.unit_id,
    //                             un.unit_name
                                
    //                         FROM purchase_order_items poi
    //                         INNER JOIN products p ON poi.product_id = p.product_id
    //                         LEFT JOIN categories c ON p.category_id = c.category_id
    //                         LEFT JOIN brands b ON p.brand_id = b.brand_id
    //                         LEFT JOIN units un ON p.unit_id = un.unit_id
    //                         WHERE poi.order_id = :orderId";
                
    //             $itemsStmt = $conn->prepare($itemsSql);
    //             $itemsStmt->bindValue(":orderId", $order['order_id']);
    //             $itemsStmt->execute();
    //             $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    //         }

    //         echo json_encode([
    //             'status' => 'success',
    //             'message' => 'All purchase orders retrieved successfully',
    //             'data' => [
    //                 'total_orders' => count($orders),
    //                 'orders' => $orders
    //             ]
    //         ]);
            
    //     } catch (PDOException $e) {
    //         echo json_encode([
    //             'status' => 'error',
    //             'message' => 'Database error: ' . $e->getMessage()
    //         ]);
    //     }
    // }

    // Get all purchase orders with their receive status and received quantities
// function getAllPurchaseOrders() {
//     include "connection-pdo.php";

//     try {
//         $sql = "SELECT 
//                     po.order_id,
//                     po.order_date,
//                     po.total_amount,
//                     po.created_at,
//                     po.updated_at,
                    
//                     -- Supplier information
//                     s.supplier_id,
//                     s.supplier_name,
//                     s.contact_person,
//                     s.phone as supplier_phone,
//                     s.email as supplier_email,
//                     s.address as supplier_address,
                    
//                     -- Created by user information
//                     u.user_id as created_by_id,
//                     u.full_name as created_by_name,
//                     u.email as created_by_email,
//                     u.phone as created_by_phone,
                    
//                     -- Receive status
//                     sr.receive_id,
//                     sr.supplier_receipt,
//                     sr.receive_date,
//                     sr.warehouse_id,
//                     w.warehouse_name,
//                     ru.full_name as received_by_name,
                    
//                     -- Status calculation
//                     CASE 
//                         WHEN sr.receive_id IS NOT NULL THEN 'RECEIVED'
//                         ELSE 'PENDING'
//                     END as status
                    
//                 FROM purchase_orders po
//                 INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
//                 INNER JOIN users u ON po.created_by = u.user_id
//                 LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
//                 LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
//                 LEFT JOIN users ru ON sr.received_by = ru.user_id
//                 ORDER BY po.order_date DESC, po.created_at DESC";

//         $stmt = $conn->prepare($sql);
//         $stmt->execute();
//         $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

//         // Get order items for each order
//         foreach ($orders as &$order) {
//             $itemsSql = "SELECT 
//                             poi.order_item_id,
//                             poi.product_id,
//                             poi.quantity,
//                             poi.unit_cost,
//                             poi.total_price,
                            
//                             -- Product information
//                             p.product_name,
//                             p.barcode,
//                             p.description as product_description,
//                             p.selling_price,
                            
//                             -- Category information (if exists)
//                             c.category_id,
//                             c.category_name,
                            
//                             -- Brand information
//                             b.brand_id,
//                             b.brand_name,
                            
//                             -- Unit information
//                             un.unit_id,
//                             un.unit_name,
                            
//                             -- Received quantity (if any)
//                             COALESCE(sri.quantity_receive, 0) as quantity_receive
                            
//                         FROM purchase_order_items poi
//                         INNER JOIN products p ON poi.product_id = p.product_id
//                         LEFT JOIN categories c ON p.category_id = c.category_id
//                         LEFT JOIN brands b ON p.brand_id = b.brand_id
//                         LEFT JOIN units un ON p.unit_id = un.unit_id
//                         LEFT JOIN stock_receive sr ON poi.order_id = sr.order_id
//                         LEFT JOIN stock_receive_items sri ON sr.receive_id = sri.receive_id AND poi.product_id = sri.product_id
//                         WHERE poi.order_id = :orderId";
            
//             $itemsStmt = $conn->prepare($itemsSql);
//             $itemsStmt->bindValue(":orderId", $order['order_id']);
//             $itemsStmt->execute();
//             $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
//         }

//         echo json_encode([
//             'status' => 'success',
//             'message' => 'All purchase orders retrieved successfully',
//             'data' => [
//                 'total_orders' => count($orders),
//                 'orders' => $orders
//             ]
//         ]);
        
//     } catch (PDOException $e) {
//         echo json_encode([
//             'status' => 'error',
//             'message' => 'Database error: ' . $e->getMessage()
//         ]);
//     }
// }
// Get all purchase orders with their receive status, received quantities, and return information
function getAllPurchaseOrders() {
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
                    u.phone as created_by_phone,
                    
                    -- Receive status
                    sr.receive_id,
                    sr.supplier_receipt,
                    sr.receive_date,
                    sr.warehouse_id,
                    w.warehouse_name,
                    ru.full_name as received_by_name,
                    
                    -- Status calculation
                    CASE 
                        WHEN sr.receive_id IS NOT NULL THEN 'RECEIVED'
                        ELSE 'PENDING'
                    END as status
                    
                FROM purchase_orders po
                INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                INNER JOIN users u ON po.created_by = u.user_id
                LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
                LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                LEFT JOIN users ru ON sr.received_by = ru.user_id
                ORDER BY po.order_date DESC, po.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get order items for each order
        foreach ($orders as &$order) {
            $itemsSql = "SELECT 
                            poi.order_item_id,
                            poi.product_id,
                            poi.quantity as ordered_quantity,
                            poi.unit_cost,
                            poi.total_price,
                            
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
                            un.unit_name,
                            
                            -- Received quantity (if any)
                            COALESCE(sri.quantity_receive, 0) as quantity_receive,
                            
                            -- Returned quantity (if any)
                            COALESCE(return_info.returned_quantity, 0) as returned_quantity,
                            
                            -- Calculate quantity difference (what needs to be returned)
                            GREATEST(0, poi.quantity - COALESCE(sri.quantity_receive, 0)) as quantity_to_return,
                            
                            -- Check if fully received
                            CASE 
                                WHEN COALESCE(sri.quantity_receive, 0) = 0 THEN 'NOT_RECEIVED'
                                WHEN poi.quantity = COALESCE(sri.quantity_receive, 0) THEN 'FULLY_RECEIVED'
                                ELSE 'PARTIALLY_RECEIVED'
                            END as receive_status,
                            
                            -- Check return status
                            CASE 
                                -- No returns needed (fully received or not received)
                                WHEN COALESCE(sri.quantity_receive, 0) = 0 OR poi.quantity = COALESCE(sri.quantity_receive, 0) THEN 'NO_RETURN_NEEDED'
                                
                                -- Returns needed but not processed yet
                                WHEN poi.quantity > COALESCE(sri.quantity_receive, 0) AND 
                                     COALESCE(return_info.returned_quantity, 0) = 0 THEN 'RETURN_NEEDED'
                                
                                -- Returns partially processed
                                WHEN poi.quantity > COALESCE(sri.quantity_receive, 0) AND 
                                     COALESCE(return_info.returned_quantity, 0) > 0 AND
                                     COALESCE(return_info.returned_quantity, 0) < (poi.quantity - COALESCE(sri.quantity_receive, 0)) THEN 'PARTIAL_RETURN'
                                
                                -- Returns fully processed
                                WHEN COALESCE(return_info.returned_quantity, 0) >= (poi.quantity - COALESCE(sri.quantity_receive, 0)) THEN 'RETURN_COMPLETED'
                                
                                ELSE 'NO_RETURN_NEEDED'
                            END as return_status,
                            
                            -- Calculate pending return quantity
                            GREATEST(0, (poi.quantity - COALESCE(sri.quantity_receive, 0)) - COALESCE(return_info.returned_quantity, 0)) as pending_return_quantity
                            
                        FROM purchase_order_items poi
                        INNER JOIN products p ON poi.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN units un ON p.unit_id = un.unit_id
                        LEFT JOIN stock_receive sr ON poi.order_id = sr.order_id
                        LEFT JOIN stock_receive_items sri ON sr.receive_id = sri.receive_id AND poi.product_id = sri.product_id
                        LEFT JOIN (
                            SELECT 
                                sri.product_id,
                                sr.order_id,
                                SUM(sri.quantity_return) as returned_quantity
                            FROM supplier_return_items sri
                            INNER JOIN supplier_returns sr ON sri.return_id = sr.return_id
                            GROUP BY sri.product_id, sr.order_id
                        ) return_info ON poi.product_id = return_info.product_id AND poi.order_id = return_info.order_id
                        WHERE poi.order_id = :orderId";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":orderId", $order['order_id']);
            $itemsStmt->execute();
            $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add return information for the entire order
            $returnSql = "SELECT 
                            sr.return_id,
                            sr.return_date,
                            sr.reason,
                            sr.total_amount,
                            sr.returned_by,
                            u.full_name as returned_by_name,
                            COUNT(sri.product_id) as total_returned_items,
                            SUM(sri.quantity_return) as total_returned_quantity
                         FROM supplier_returns sr
                         INNER JOIN supplier_return_items sri ON sr.return_id = sri.return_id
                         INNER JOIN users u ON sr.returned_by = u.user_id
                         WHERE sr.order_id = :orderId
                         GROUP BY sr.return_id
                         ORDER BY sr.return_date DESC";
            
            $returnStmt = $conn->prepare($returnSql);
            $returnStmt->bindValue(":orderId", $order['order_id']);
            $returnStmt->execute();
            $order['returns'] = $returnStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate overall order status
            $totalItems = count($order['items']);
            $fullyReceivedItems = 0;
            $partiallyReceivedItems = 0;
            $notReceivedItems = 0;
            $hasReturnsNeeded = false;
            $hasPendingReturns = false;
            $hasCompletedReturns = false;
            
            foreach ($order['items'] as $item) {
                if ($item['receive_status'] === 'FULLY_RECEIVED') {
                    $fullyReceivedItems++;
                } elseif ($item['receive_status'] === 'PARTIALLY_RECEIVED') {
                    $partiallyReceivedItems++;
                } else {
                    $notReceivedItems++;
                }
                
                // Check return status
                if ($item['return_status'] === 'RETURN_NEEDED' || $item['return_status'] === 'PARTIAL_RETURN') {
                    $hasReturnsNeeded = true;
                    $hasPendingReturns = true;
                } elseif ($item['return_status'] === 'RETURN_COMPLETED') {
                    $hasCompletedReturns = true;
                }
            }
            
            // Set overall order status
            if ($notReceivedItems === $totalItems) {
                $order['overall_status'] = 'PENDING_RECEIPT';
            } elseif ($fullyReceivedItems === $totalItems) {
                if ($hasPendingReturns) {
                    $order['overall_status'] = 'RECEIVED_WITH_PENDING_RETURNS';
                } elseif ($hasCompletedReturns) {
                    $order['overall_status'] = 'RECEIVED_WITH_COMPLETED_RETURNS';
                } else {
                    $order['overall_status'] = 'FULLY_RECEIVED';
                }
            } else {
                $order['overall_status'] = 'PARTIALLY_RECEIVED';
                if ($hasPendingReturns) {
                    $order['overall_status'] = 'PARTIALLY_RECEIVED_WITH_PENDING_RETURNS';
                }
            }
            
            // Calculate summary statistics
            $order['summary'] = [
                'total_ordered_quantity' => array_sum(array_column($order['items'], 'ordered_quantity')),
                'total_received_quantity' => array_sum(array_column($order['items'], 'quantity_receive')),
                'total_returned_quantity' => array_sum(array_column($order['items'], 'returned_quantity')),
                'total_pending_return_quantity' => array_sum(array_column($order['items'], 'pending_return_quantity')),
                'items_needing_returns' => count(array_filter($order['items'], function($item) {
                    return $item['return_status'] === 'RETURN_NEEDED' || $item['return_status'] === 'PARTIAL_RETURN';
                }))
            ];
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'All purchase orders retrieved successfully',
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

    // Add this method to your PurchaseOrder class
    function getAllPurchaseOrderDetails() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        po.order_id,
                        po.order_date,
                        po.total_amount,
                        po.created_at,
                        po.updated_at,
                        po.created_by,
                        
                        -- Supplier information (basic)
                        s.supplier_id,
                        s.supplier_name,
                        s.contact_person,
                        
                        -- Receive status
                        CASE 
                            WHEN sr.receive_id IS NOT NULL THEN 'RECEIVED'
                            ELSE 'PENDING'
                        END as status
                        
                    FROM purchase_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
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
                                poi.unit_cost,
                                poi.total_price,
                                
                                -- Product information only
                                p.product_name,
                                p.barcode
                                
                            FROM purchase_order_items poi
                            INNER JOIN products p ON poi.product_id = p.product_id
                            WHERE poi.order_id = :orderId";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bindValue(":orderId", $order['order_id']);
                $itemsStmt->execute();
                $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return json_encode([
                'status' => 'success',
                'message' => 'All purchase order details retrieved successfully',
                'data' => [
                    'total_orders' => count($orders),
                    'orders' => $orders
                ]
            ]);
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }


    // Get pending purchase orders (not yet received)
    function getPendingPurchaseOrders() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        po.order_id,
                        po.order_date,
                        po.total_amount,
                        po.created_at,
                        
                        -- Supplier information
                        s.supplier_id,
                        s.supplier_name,
                        s.contact_person,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Created by user information
                        u.user_id as created_by_id,
                        u.full_name as created_by_name,
                        u.email as created_by_email
                        
                    FROM purchase_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN users u ON po.created_by = u.user_id
                    LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
                    WHERE sr.receive_id IS NULL
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
                                poi.unit_cost,
                                poi.total_price,
                                
                                -- Product information
                                p.product_name,
                                p.barcode,
                                p.description as product_description,
                                p.selling_price,
                                
                                -- Category information (if exists)
                                c.category_id,
                                c.category_name
                                
                            FROM purchase_order_items poi
                            INNER JOIN products p ON poi.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            WHERE poi.order_id = :orderId";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bindValue(":orderId", $order['order_id']);
                $itemsStmt->execute();
                $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Pending purchase orders retrieved successfully',
                'data' => [
                    'total_pending_orders' => count($orders),
                    'pending_orders' => $orders
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get single purchase order with items and receive status
    function getPurchaseOrder($json) {
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
                        u.email as created_by_email,
                        
                        -- Receive information (if received)
                        sr.receive_id,
                        sr.supplier_receipt,
                        sr.receive_date,
                        sr.warehouse_id,
                        w.warehouse_name,
                        ru.user_id as received_by_id,
                        ru.full_name as received_by_name,
                        
                        -- Status
                        CASE 
                            WHEN sr.receive_id IS NOT NULL THEN 'RECEIVED'
                            ELSE 'PENDING'
                        END as receive_status
                        
                    FROM purchase_orders po
                    INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                    INNER JOIN users u ON po.created_by = u.user_id
                    LEFT JOIN stock_receive sr ON po.order_id = sr.order_id
                    LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    LEFT JOIN users ru ON sr.received_by = ru.user_id
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
                            poi.unit_cost,
                            poi.total_price,
                            
                            -- Product information
                            p.product_name,
                            p.barcode,
                            p.description as product_description,
                            p.selling_price,
                            
                            -- Category information
                            c.category_id,
                            c.category_name,
                            
                            -- Brand information
                            b.brand_id,
                            b.brand_name,
                            
                            -- Unit information
                            un.unit_id,
                            un.unit_name
                            
                        FROM purchase_order_items poi
                        INNER JOIN products p ON poi.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN units un ON p.unit_id = un.unit_id
                        WHERE poi.order_id = :orderId";
            
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":orderId", $data['order_id']);
            $itemsStmt->execute();
            $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Purchase order retrieved successfully',
                'data' => $order
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

$purchaseOrder = new PurchaseOrder();

// Handle operations
switch($operation) {
    case "createPurchaseOrder":
        echo $purchaseOrder->createPurchaseOrder($data);
        break;
        
    case "getAllPurchaseOrderDetails":
        echo $purchaseOrder->getAllPurchaseOrderDetails();
        break;
                
    case "getAllPurchaseOrders":
        echo $purchaseOrder->getAllPurchaseOrders();
        break;
        
    case "getPendingPurchaseOrders":
        echo $purchaseOrder->getPendingPurchaseOrders();
        break;
        
    case "getPurchaseOrder":
        echo $purchaseOrder->getPurchaseOrder($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}
?>