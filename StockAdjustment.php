<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class StockAdjustment {
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function calculateQuantityChange($reason, $quantity) {
        $reduceStockReasons = ['spoilage', 'shrinkage', 'consumption'];
        
        $manualReasons = ['manual'];
        
        if (in_array($reason, $reduceStockReasons)) {
            return -abs($quantity); 
        } else {
            return $quantity; 
        }
    }

    function getAllStockAdjustments() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT sa.*, w.warehouse_name, u.full_name as adjusted_by_name
                    FROM stock_adjustments sa
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    ORDER BY sa.adjustment_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getStockAdjustment($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['adjustment_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: adjustment_id is required'
                ]);
                return;
            }

            // Get adjustment header
            $sql = "SELECT sa.*, w.warehouse_name, u.full_name as adjusted_by_name
                    FROM stock_adjustments sa
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    WHERE sa.adjustment_id = :adjustmentId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmt->execute();
            $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!$adjustment) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Stock adjustment not found'
                ]);
                return;
            }

            // Get adjustment items
            $sqlItems = "SELECT sai.*, p.product_name, p.barcode, b.brand_name, c.category_name, u.unit_name
                         FROM stock_adjustment_items sai
                         INNER JOIN products p ON sai.product_id = p.product_id
                         INNER JOIN brands b ON p.brand_id = b.brand_id
                         INNER JOIN categories c ON p.category_id = c.category_id
                         INNER JOIN units u ON p.unit_id = u.unit_id
                         WHERE sai.adjustment_id = :adjustmentId";
            
            $stmtItems = $conn->prepare($sqlItems);
            $stmtItems->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmtItems->execute();
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $adjustment['items'] = $items;

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment retrieved successfully',
                'data' => $adjustment
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function insertStockAdjustment($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            // Validate required fields
            if(empty($json['warehouse_id']) || empty($json['adjusted_by']) || 
               empty($json['reason']) || empty($json['items'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: warehouse_id, adjusted_by, reason, and items are required'
                ]);
                return;
            }

            // Validate reason enum
            $validReasons = ['spoilage', 'shrinkage', 'manual', 'consumption'];
            if(!in_array($json['reason'], $validReasons)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid reason. Must be one of: ' . implode(', ', $validReasons)
                ]);
                return;
            }

            $adjustmentId = $this->generateUuid();

            // Insert stock adjustment header
            $sql = "INSERT INTO stock_adjustments(adjustment_id, warehouse_id, adjusted_by, reason) 
                    VALUES(:adjustmentId, :warehouseId, :adjustedBy, :reason)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":adjustmentId", $adjustmentId);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->bindParam(":adjustedBy", $json['adjusted_by']);
            $stmt->bindParam(":reason", $json['reason']);
            $stmt->execute();

            // Insert adjustment items and update warehouse stock
            foreach($json['items'] as $item) {
                if(empty($item['product_id']) || !isset($item['quantity']) || $item['quantity'] <= 0) {
                    throw new Exception('Each item must have product_id and positive quantity');
                }

                // Calculate actual quantity change based on reason
                $quantityChange = $this->calculateQuantityChange($json['reason'], $item['quantity']);

                $adjustmentItemId = $this->generateUuid();
                
                // Insert adjustment item (store the calculated quantity change)
                $sqlItem = "INSERT INTO stock_adjustment_items(adjustment_item_id, adjustment_id, product_id, quantity_change)
                           VALUES(:adjustmentItemId, :adjustmentId, :productId, :quantityChange)";
                
                $stmtItem = $conn->prepare($sqlItem);
                $stmtItem->bindParam(":adjustmentItemId", $adjustmentItemId);
                $stmtItem->bindParam(":adjustmentId", $adjustmentId);
                $stmtItem->bindParam(":productId", $item['product_id']);
                $stmtItem->bindParam(":quantityChange", $quantityChange);
                $stmtItem->execute();

                // Update warehouse stock
                $sqlUpdateStock = "UPDATE warehouse_stock 
                                  SET quantity = quantity + :quantityChange
                                  WHERE warehouse_id = :warehouseId AND product_id = :productId";
                
                $stmtUpdateStock = $conn->prepare($sqlUpdateStock);
                $stmtUpdateStock->bindParam(":quantityChange", $quantityChange);
                $stmtUpdateStock->bindParam(":warehouseId", $json['warehouse_id']);
                $stmtUpdateStock->bindParam(":productId", $item['product_id']);
                $stmtUpdateStock->execute();

                // If no existing stock record, create one
                if($stmtUpdateStock->rowCount() == 0) {
                    $newQuantity = max(0, $quantityChange); // Don't allow negative starting stock
                    $stockId = $this->generateUuid();
                    
                    $sqlInsertStock = "INSERT INTO warehouse_stock(stock_id, warehouse_id, product_id, quantity)
                                      VALUES(:stockId, :warehouseId, :productId, :quantity)";
                    
                    $stmtInsertStock = $conn->prepare($sqlInsertStock);
                    $stmtInsertStock->bindParam(":stockId", $stockId);
                    $stmtInsertStock->bindParam(":warehouseId", $json['warehouse_id']);
                    $stmtInsertStock->bindParam(":productId", $item['product_id']);
                    $stmtInsertStock->bindParam(":quantity", $newQuantity);
                    $stmtInsertStock->execute();
                }
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment created successfully',
                'data' => [
                    'adjustment_id' => $adjustmentId,
                    'warehouse_id' => $json['warehouse_id'],
                    'reason' => $json['reason'],
                    'items_count' => count($json['items'])
                ]
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Error: ' . $e->getMessage()
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getStockAdjustmentsByWarehouse($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id is required'
                ]);
                return;
            }

            $sql = "SELECT sa.*, w.warehouse_name, u.full_name as adjusted_by_name
                    FROM stock_adjustments sa
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    WHERE sa.warehouse_id = :warehouseId
                    ORDER BY sa.adjustment_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getStockAdjustmentsByDateRange($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['start_date']) || empty($json['end_date'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: start_date and end_date are required'
                ]);
                return;
            }

            $sql = "SELECT sa.*, w.warehouse_name, u.full_name as adjusted_by_name
                    FROM stock_adjustments sa
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    WHERE DATE(sa.adjustment_date) BETWEEN :startDate AND :endDate";
            
            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $sql .= " AND sa.warehouse_id = :warehouseId";
            }
            
            $sql .= " ORDER BY sa.adjustment_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":startDate", $json['start_date']);
            $stmt->bindParam(":endDate", $json['end_date']);
            
            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            }
            
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getStockAdjustmentsByReason($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['reason'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: reason is required'
                ]);
                return;
            }

            $validReasons = ['spoilage', 'shrinkage', 'manual', 'consumption'];
            if(!in_array($json['reason'], $validReasons)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid reason. Must be one of: ' . implode(', ', $validReasons)
                ]);
                return;
            }

            $sql = "SELECT sa.*, w.warehouse_name, u.full_name as adjusted_by_name
                    FROM stock_adjustments sa
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    WHERE sa.reason = :reason";
            
            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $sql .= " AND sa.warehouse_id = :warehouseId";
            }
            
            $sql .= " ORDER BY sa.adjustment_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":reason", $json['reason']);
            
            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            }
            
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustments retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getStockAdjustmentItems($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['adjustment_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: adjustment_id is required'
                ]);
                return;
            }

            $sql = "SELECT sai.*, p.product_name, p.barcode, p.selling_price,
                           b.brand_name, c.category_name, u.unit_name
                    FROM stock_adjustment_items sai
                    INNER JOIN products p ON sai.product_id = p.product_id
                    INNER JOIN brands b ON p.brand_id = b.brand_id
                    INNER JOIN categories c ON p.category_id = c.category_id
                    INNER JOIN units u ON p.unit_id = u.unit_id
                    WHERE sai.adjustment_id = :adjustmentId
                    ORDER BY p.product_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment items retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function updateStockAdjustment($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if(empty($json['adjustment_id']) || empty($json['reason'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: adjustment_id and reason are required'
                ]);
                return;
            }

            // Validate reason enum
            $validReasons = ['spoilage', 'shrinkage', 'manual', 'consumption'];
            if(!in_array($json['reason'], $validReasons)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid reason. Must be one of: ' . implode(', ', $validReasons)
                ]);
                return;
            }

            // Update stock adjustment
            $sql = "UPDATE stock_adjustments 
                    SET reason = :reason
                    WHERE adjustment_id = :adjustmentId";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":reason", $json['reason']);
            $stmt->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmt->execute();

            if($stmt->rowCount() > 0) {
                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Stock adjustment updated successfully',
                    'data' => [
                        'adjustment_id' => $json['adjustment_id'],
                        'reason' => $json['reason'],
                    ]
                ]);
            } else {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Stock adjustment not found'
                ]);
            }
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function deleteStockAdjustment($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            $json = json_decode($json, true);
            
            if(empty($json['adjustment_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: adjustment_id is required'
                ]);
                return;
            }

            // Get adjustment details before deletion for stock reversal
            $sqlGetAdjustment = "SELECT warehouse_id FROM stock_adjustments WHERE adjustment_id = :adjustmentId";
            $stmtGetAdjustment = $conn->prepare($sqlGetAdjustment);
            $stmtGetAdjustment->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmtGetAdjustment->execute();
            $adjustment = $stmtGetAdjustment->fetch(PDO::FETCH_ASSOC);

            if(!$adjustment) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Stock adjustment not found'
                ]);
                return;
            }

            // Get adjustment items to reverse stock changes
            $sqlGetItems = "SELECT product_id, quantity_change FROM stock_adjustment_items WHERE adjustment_id = :adjustmentId";
            $stmtGetItems = $conn->prepare($sqlGetItems);
            $stmtGetItems->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmtGetItems->execute();
            $items = $stmtGetItems->fetchAll(PDO::FETCH_ASSOC);

            // Reverse stock changes
            foreach($items as $item) {
                $reverseQuantity = -$item['quantity_change']; // Reverse the adjustment
                
                $sqlReverseStock = "UPDATE warehouse_stock 
                                   SET quantity = quantity + :reverseQuantity
                                   WHERE warehouse_id = :warehouseId AND product_id = :productId";
                
                $stmtReverseStock = $conn->prepare($sqlReverseStock);
                $stmtReverseStock->bindParam(":reverseQuantity", $reverseQuantity);
                $stmtReverseStock->bindParam(":warehouseId", $adjustment['warehouse_id']);
                $stmtReverseStock->bindParam(":productId", $item['product_id']);
                $stmtReverseStock->execute();
            }

            // Delete adjustment items first (foreign key constraint)
            $sqlDeleteItems = "DELETE FROM stock_adjustment_items WHERE adjustment_id = :adjustmentId";
            $stmtDeleteItems = $conn->prepare($sqlDeleteItems);
            $stmtDeleteItems->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmtDeleteItems->execute();

            // Delete adjustment
            $sqlDelete = "DELETE FROM stock_adjustments WHERE adjustment_id = :adjustmentId";
            $stmtDelete = $conn->prepare($sqlDelete);
            $stmtDelete->bindParam(":adjustmentId", $json['adjustment_id']);
            $stmtDelete->execute();

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment deleted successfully and stock levels reversed',
                'data' => [
                    'adjustment_id' => $json['adjustment_id']
                ]
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getStockAdjustmentSummary($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            $whereClause = "WHERE 1=1";
            $params = [];

            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $whereClause .= " AND sa.warehouse_id = :warehouseId";
                $params[':warehouseId'] = $json['warehouse_id'];
            }

            if(isset($json['start_date']) && !empty($json['start_date'])) {
                $whereClause .= " AND DATE(sa.adjustment_date) >= :startDate";
                $params[':startDate'] = $json['start_date'];
            }

            if(isset($json['end_date']) && !empty($json['end_date'])) {
                $whereClause .= " AND DATE(sa.adjustment_date) <= :endDate";
                $params[':endDate'] = $json['end_date'];
            }

            $sql = "SELECT 
                        sa.reason,
                        COUNT(*) as adjustment_count,
                        SUM(CASE WHEN sai.quantity_change > 0 THEN sai.quantity_change ELSE 0 END) as total_positive_adjustments,
                        SUM(CASE WHEN sai.quantity_change < 0 THEN ABS(sai.quantity_change) ELSE 0 END) as total_negative_adjustments,
                        SUM(sai.quantity_change) as net_adjustment
                    FROM stock_adjustments sa
                    INNER JOIN stock_adjustment_items sai ON sa.adjustment_id = sai.adjustment_id
                    {$whereClause}
                    GROUP BY sa.reason
                    ORDER BY adjustment_count DESC";
            
            $stmt = $conn->prepare($sql);
            foreach($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment summary retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getProductAdjustmentHistory($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['product_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: product_id is required'
                ]);
                return;
            }

            $sql = "SELECT sa.adjustment_id, sa.warehouse_id, w.warehouse_name, sa.reason,
                           sa.adjustment_date, u.full_name as adjusted_by_name,
                           sai.quantity_change
                    FROM stock_adjustments sa
                    INNER JOIN stock_adjustment_items sai ON sa.adjustment_id = sai.adjustment_id
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    WHERE sai.product_id = :productId";

            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $sql .= " AND sa.warehouse_id = :warehouseId";
            }
            
            $sql .= " ORDER BY sa.adjustment_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":productId", $json['product_id']);
            
            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            }
            
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Product adjustment history retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getCurrentWarehouseStock($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['warehouse_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: warehouse_id is required'
                ]);
                return;
            }

            $sql = "SELECT ws.*, p.product_name, p.barcode, p.selling_price,
                           b.brand_name, c.category_name, u.unit_name
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id
                    INNER JOIN brands b ON p.brand_id = b.brand_id
                    INNER JOIN categories c ON p.category_id = c.category_id
                    INNER JOIN units u ON p.unit_id = u.unit_id
                    WHERE ws.warehouse_id = :warehouseId
                    ORDER BY p.product_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":warehouseId", $json['warehouse_id']);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse stock retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function searchStockAdjustments($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            if(empty($json['search_term'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: search_term'
                ]);
                return;
            }

            $searchTerm = '%' . $json['search_term'] . '%';
            
            $sql = "SELECT DISTINCT sa.*, w.warehouse_name, u.full_name as adjusted_by_name
                    FROM stock_adjustments sa
                    INNER JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sa.adjusted_by = u.user_id
                    LEFT JOIN stock_adjustment_items sai ON sa.adjustment_id = sai.adjustment_id
                    LEFT JOIN products p ON sai.product_id = p.product_id
                    WHERE w.warehouse_name LIKE :searchTerm 
                       OR u.full_name LIKE :searchTerm
                       OR p.product_name LIKE :searchTerm
                    ORDER BY sa.adjustment_date DESC
                    LIMIT 50";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":searchTerm", $searchTerm);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustments search completed',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function validateStockAdjustment($json) {
        include "connection-pdo.php";
        
        try {
            if(empty($json['warehouse_id']) || empty($json['items'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: warehouse_id and items are required'
                ]);
                return;
            }

            $errors = [];
            $warnings = [];

            foreach($json['items'] as $index => $item) {
                if(empty($item['product_id'])) {
                    $errors[] = "Item #{$index}: product_id is required";
                    continue;
                }

                if(!isset($item['quantity']) || $item['quantity'] <= 0) {
                    $errors[] = "Item #{$index}: quantity must be a positive number";
                    continue;
                }

                // Check if product exists
                $sqlProduct = "SELECT product_name FROM products WHERE product_id = :productId AND is_active = TRUE";
                $stmtProduct = $conn->prepare($sqlProduct);
                $stmtProduct->bindParam(":productId", $item['product_id']);
                $stmtProduct->execute();
                $product = $stmtProduct->fetch(PDO::FETCH_ASSOC);

                if(!$product) {
                    $errors[] = "Item #{$index}: Product not found or inactive";
                    continue;
                }

                // Calculate quantity change based on reason
                $quantityChange = $this->calculateQuantityChange($json['reason'], $item['quantity']);

                // Check current stock if reducing
                if($quantityChange < 0) {
                    $sqlStock = "SELECT quantity FROM warehouse_stock 
                                WHERE warehouse_id = :warehouseId AND product_id = :productId";
                    $stmtStock = $conn->prepare($sqlStock);
                    $stmtStock->bindParam(":warehouseId", $json['warehouse_id']);
                    $stmtStock->bindParam(":productId", $item['product_id']);
                    $stmtStock->execute();
                    $stock = $stmtStock->fetch(PDO::FETCH_ASSOC);

                    $currentQuantity = $stock ? $stock['quantity'] : 0;
                    $newQuantity = $currentQuantity + $quantityChange;

                    if($newQuantity < 0) {
                        $warnings[] = "Item #{$index} ({$product['product_name']}): Adjustment will result in negative stock (Current: {$currentQuantity}, Deducting: {$item['quantity']}, Result: {$newQuantity})";
                    }
                }
            }

            $isValid = empty($errors);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment validation completed',
                'data' => [
                    'is_valid' => $isValid,
                    'errors' => $errors,
                    'warnings' => $warnings
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getAdjustmentReasons() {
        echo json_encode([
            'status' => 'success',
            'message' => 'Adjustment reasons retrieved successfully',
            'data' => [
                ['value' => 'spoilage', 'label' => 'Spoilage', 'type' => 'reduce', 'description' => 'Products damaged or expired'],
                ['value' => 'shrinkage', 'label' => 'Shrinkage', 'type' => 'reduce', 'description' => 'Products lost or stolen'],
                ['value' => 'consumption', 'label' => 'Consumption', 'type' => 'reduce', 'description' => 'Products used internally'],
                ['value' => 'manual', 'label' => 'Manual Count', 'type' => 'both', 'description' => 'Physical inventory count correction']
            ]
        ]);
    }

    function getAdjustmentStatistics($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            $whereClause = "WHERE 1=1";
            $params = [];

            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $whereClause .= " AND sa.warehouse_id = :warehouseId";
                $params[':warehouseId'] = $json['warehouse_id'];
            }

            if(isset($json['start_date']) && !empty($json['start_date'])) {
                $whereClause .= " AND DATE(sa.adjustment_date) >= :startDate";
                $params[':startDate'] = $json['start_date'];
            }

            if(isset($json['end_date']) && !empty($json['end_date'])) {
                $whereClause .= " AND DATE(sa.adjustment_date) <= :endDate";
                $params[':endDate'] = $json['end_date'];
            }

            // Get overall statistics
            $sql = "SELECT 
                        COUNT(DISTINCT sa.adjustment_id) as total_adjustments,
                        COUNT(DISTINCT sai.product_id) as affected_products,
                        SUM(CASE WHEN sai.quantity_change > 0 THEN sai.quantity_change ELSE 0 END) as total_increases,
                        SUM(CASE WHEN sai.quantity_change < 0 THEN ABS(sai.quantity_change) ELSE 0 END) as total_decreases,
                        SUM(sai.quantity_change) as net_change
                    FROM stock_adjustments sa
                    INNER JOIN stock_adjustment_items sai ON sa.adjustment_id = sai.adjustment_id
                    {$whereClause}";
            
            $stmt = $conn->prepare($sql);
            foreach($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get breakdown by reason
            $sqlByReason = "SELECT 
                               sa.reason,
                               COUNT(DISTINCT sa.adjustment_id) as adjustment_count,
                               SUM(CASE WHEN sai.quantity_change > 0 THEN sai.quantity_change ELSE 0 END) as total_increases,
                               SUM(CASE WHEN sai.quantity_change < 0 THEN ABS(sai.quantity_change) ELSE 0 END) as total_decreases,
                               SUM(sai.quantity_change) as net_change
                           FROM stock_adjustments sa
                           INNER JOIN stock_adjustment_items sai ON sa.adjustment_id = sai.adjustment_id
                           {$whereClause}
                           GROUP BY sa.reason
                           ORDER BY adjustment_count DESC";
            
            $stmtByReason = $conn->prepare($sqlByReason);
            foreach($params as $key => $value) {
                $stmtByReason->bindValue($key, $value);
            }
            $stmtByReason->execute();
            $byReason = $stmtByReason->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock adjustment statistics retrieved successfully',
                'data' => [
                    'overall' => $stats,
                    'by_reason' => $byReason
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getMostAdjustedProducts($json) {
        include "connection-pdo.php";
        
        try {
            $json = json_decode($json, true);
            
            $whereClause = "WHERE 1=1";
            $params = [];
            $limit = isset($json['limit']) ? intval($json['limit']) : 20;

            if(isset($json['warehouse_id']) && !empty($json['warehouse_id'])) {
                $whereClause .= " AND sa.warehouse_id = :warehouseId";
                $params[':warehouseId'] = $json['warehouse_id'];
            }

            if(isset($json['start_date']) && !empty($json['start_date'])) {
                $whereClause .= " AND DATE(sa.adjustment_date) >= :startDate";
                $params[':startDate'] = $json['start_date'];
            }

            if(isset($json['end_date']) && !empty($json['end_date'])) {
                $whereClause .= " AND DATE(sa.adjustment_date) <= :endDate";
                $params[':endDate'] = $json['end_date'];
            }

            $sql = "SELECT 
                        p.product_id, p.product_name, p.barcode,
                        b.brand_name, c.category_name, u.unit_name,
                        COUNT(DISTINCT sa.adjustment_id) as adjustment_count,
                        SUM(CASE WHEN sai.quantity_change > 0 THEN sai.quantity_change ELSE 0 END) as total_increases,
                        SUM(CASE WHEN sai.quantity_change < 0 THEN ABS(sai.quantity_change) ELSE 0 END) as total_decreases,
                        SUM(sai.quantity_change) as net_change,
                        MAX(sa.adjustment_date) as last_adjustment_date
                    FROM stock_adjustments sa
                    INNER JOIN stock_adjustment_items sai ON sa.adjustment_id = sai.adjustment_id
                    INNER JOIN products p ON sai.product_id = p.product_id
                    INNER JOIN brands b ON p.brand_id = b.brand_id
                    INNER JOIN categories c ON p.category_id = c.category_id
                    INNER JOIN units u ON p.unit_id = u.unit_id
                    {$whereClause}
                    GROUP BY p.product_id, p.product_name, p.barcode, b.brand_name, c.category_name, u.unit_name
                    ORDER BY adjustment_count DESC, ABS(net_change) DESC
                    LIMIT :limit";
            
            $stmt = $conn->prepare($sql);
            foreach($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Most adjusted products retrieved successfully',
                'data' => $rs
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function bulkStockAdjustment($json) {
        include "connection-pdo.php";
        $conn->beginTransaction();

        try {
            if(empty($json['adjustments'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: adjustments array is required'
                ]);
                return;
            }

            $processedAdjustments = [];

            foreach($json['adjustments'] as $adjustmentData) {
                if(empty($adjustmentData['warehouse_id']) || empty($adjustmentData['adjusted_by']) || 
                   empty($adjustmentData['reason']) || empty($adjustmentData['items'])) {
                    throw new Exception('Each adjustment must have warehouse_id, adjusted_by, reason, and items');
                }

                // Validate reason
                $validReasons = ['spoilage', 'shrinkage', 'manual', 'consumption'];
                if(!in_array($adjustmentData['reason'], $validReasons)) {
                    throw new Exception('Invalid reason in adjustment: ' . $adjustmentData['reason']);
                }

                $adjustmentId = $this->generateUuid();

                // Insert stock adjustment header
                $sql = "INSERT INTO stock_adjustments(adjustment_id, warehouse_id, adjusted_by, reason) 
                        VALUES(:adjustmentId, :warehouseId, :adjustedBy, :reason)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(":adjustmentId", $adjustmentId);
                $stmt->bindParam(":warehouseId", $adjustmentData['warehouse_id']);
                $stmt->bindParam(":adjustedBy", $adjustmentData['adjusted_by']);
                $stmt->bindParam(":reason", $adjustmentData['reason']);
                $stmt->execute();

                // Process items
                foreach($adjustmentData['items'] as $item) {
                    if(empty($item['product_id']) || !isset($item['quantity_change'])) {
                        throw new Exception('Each item must have product_id and quantity_change');
                    }

                    $adjustmentItemId = $this->generateUuid();
                    
                    // Insert adjustment item
                    $sqlItem = "INSERT INTO stock_adjustment_items(adjustment_item_id, adjustment_id, product_id, quantity_change)
                               VALUES(:adjustmentItemId, :adjustmentId, :productId, :quantityChange)";
                    
                    $stmtItem = $conn->prepare($sqlItem);
                    $stmtItem->bindParam(":adjustmentItemId", $adjustmentItemId);
                    $stmtItem->bindParam(":adjustmentId", $adjustmentId);
                    $stmtItem->bindParam(":productId", $item['product_id']);
                    $stmtItem->bindParam(":quantityChange", $item['quantity_change']);
                    $stmtItem->execute();

                    // Update warehouse stock
                    $sqlUpdateStock = "UPDATE warehouse_stock 
                                      SET quantity = quantity + :quantityChange
                                      WHERE warehouse_id = :warehouseId AND product_id = :productId";
                    
                    $stmtUpdateStock = $conn->prepare($sqlUpdateStock);
                    $stmtUpdateStock->bindParam(":quantityChange", $item['quantity_change']);
                    $stmtUpdateStock->bindParam(":warehouseId", $adjustmentData['warehouse_id']);
                    $stmtUpdateStock->bindParam(":productId", $item['product_id']);
                    $stmtUpdateStock->execute();

                    // If no existing stock record, create one
                    if($stmtUpdateStock->rowCount() == 0) {
                        $newQuantity = max(0, $item['quantity_change']);
                        $stockId = $this->generateUuid();
                        
                        $sqlInsertStock = "INSERT INTO warehouse_stock(stock_id, warehouse_id, product_id, quantity)
                                          VALUES(:stockId, :warehouseId, :productId, :quantity)";
                        
                        $stmtInsertStock = $conn->prepare($sqlInsertStock);
                        $stmtInsertStock->bindParam(":stockId", $stockId);
                        $stmtInsertStock->bindParam(":warehouseId", $adjustmentData['warehouse_id']);
                        $stmtInsertStock->bindParam(":productId", $item['product_id']);
                        $stmtInsertStock->bindParam(":quantity", $newQuantity);
                        $stmtInsertStock->execute();
                    }
                }

                $processedAdjustments[] = [
                    'adjustment_id' => $adjustmentId,
                    'warehouse_id' => $adjustmentData['warehouse_id'],
                    'reason' => $adjustmentData['reason'],
                    'items_count' => count($adjustmentData['items'])
                ];
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Bulk stock adjustments created successfully',
                'data' => [
                    'processed_adjustments' => $processedAdjustments,
                    'total_adjustments' => count($processedAdjustments)
                ]
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Error: ' . $e->getMessage()
            ]);
        } catch (PDOException $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
}

$stockAdjustment = new StockAdjustment();
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

switch($operation) {
    case "insertStockAdjustment":
        echo $stockAdjustment->insertStockAdjustment($data);
        break;
    case "updateStockAdjustment":
        echo $stockAdjustment->updateStockAdjustment($data);
        break;
    case "getAllStockAdjustments":
        echo $stockAdjustment->getAllStockAdjustments();
        break;
    case "getStockAdjustment":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getStockAdjustment($json);
        break;
    case "deleteStockAdjustment":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->deleteStockAdjustment($json);
        break;
    case "getStockAdjustmentsByWarehouse":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getStockAdjustmentsByWarehouse($json);
        break;
    case "getStockAdjustmentsByDateRange":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getStockAdjustmentsByDateRange($json);
        break;
    case "getStockAdjustmentsByReason":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getStockAdjustmentsByReason($json);
        break;
    case "getStockAdjustmentItems":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getStockAdjustmentItems($json);
        break;
    case "getCurrentWarehouseStock":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getCurrentWarehouseStock($json);
        break;
    case "searchStockAdjustments":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->searchStockAdjustments($json);
        break;
    case "validateStockAdjustment":
        echo $stockAdjustment->validateStockAdjustment($data);
        break;
    case "getAdjustmentReasons":
        echo $stockAdjustment->getAdjustmentReasons();
        break;
    case "getAdjustmentStatistics":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getAdjustmentStatistics($json);
        break;
    case "getMostAdjustedProducts":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getMostAdjustedProducts($json);
        break;
    case "getProductAdjustmentHistory":
        $json = $_GET['json'] ?? '{}';
        echo $stockAdjustment->getProductAdjustmentHistory($json);
        break;
    case "bulkStockAdjustment":
        echo $stockAdjustment->bulkStockAdjustment($data);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation'
        ]);
}
?>