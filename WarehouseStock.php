<?php
// WarehouseStock.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit();
}


class WarehouseStock {
    // Get warehouse stock levels
    function getWarehouseStock($json) {
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
                        ws.stock_id,
                        ws.warehouse_id,
                        ws.product_id,
                        ws.quantity,
                        ws.unit_price,
                        ws.last_updated,
                        ws.created_at,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        w.is_main,
                        
                        -- Product information
                        p.product_name,
                        p.barcode,
                        p.description as product_description,
                        p.selling_price,
                        (ws.quantity * ws.unit_price) as total_cost_value,
                        (ws.quantity * p.selling_price) as total_selling_value,
                        
                        -- Category information
                        c.category_id,
                        c.category_name,
                        
                        -- Brand information
                        b.brand_id,
                        b.brand_name,
                        
                        -- Unit information
                        u.unit_id,
                        u.unit_name
                        
                    FROM warehouse_stock ws
                    INNER JOIN warehouses w ON ws.warehouse_id = w.warehouse_id
                    INNER JOIN products p ON ws.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    WHERE ws.warehouse_id = :warehouseId AND ws.quantity > 0
                    ORDER BY p.product_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $data['warehouse_id']);
            $stmt->execute();
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $totalItems = count($stock);
            $totalQuantity = array_sum(array_column($stock, 'quantity'));
            $totalCostValue = array_sum(array_column($stock, 'total_cost_value'));
            $totalSellingValue = array_sum(array_column($stock, 'total_selling_value'));

            echo json_encode([
                'status' => 'success',
                'message' => 'Warehouse stock retrieved successfully',
                'data' => [
                    'warehouse_id' => $data['warehouse_id'],
                    'warehouse_name' => !empty($stock) ? $stock[0]['warehouse_name'] : null,
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_quantity' => $totalQuantity,
                        'total_cost_value' => $totalCostValue,
                        'total_selling_value' => $totalSellingValue,
                        'potential_profit' => $totalSellingValue - $totalCostValue
                    ],
                    'stock' => $stock
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get all warehouse stock
    function getAllWarehouseStock() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        ws.stock_id,
                        ws.warehouse_id,
                        ws.product_id,
                        ws.quantity,
                        ws.unit_price,
                        ws.last_updated,
                        ws.created_at,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        w.is_main,
                        
                        -- Product information
                        p.product_name,
                        p.barcode,
                        p.description as product_description,
                        p.selling_price,
                        (ws.quantity * ws.unit_price) as total_cost_value,
                        (ws.quantity * p.selling_price) as total_selling_value,
                        
                        -- Category information
                        c.category_id,
                        c.category_name,
                        
                        -- Brand information
                        b.brand_id,
                        b.brand_name,
                        
                        -- Unit information
                        u.unit_id,
                        u.unit_name
                        
                    FROM warehouse_stock ws
                    INNER JOIN warehouses w ON ws.warehouse_id = w.warehouse_id
                    INNER JOIN products p ON ws.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    WHERE ws.quantity > 0
                    ORDER BY w.warehouse_name ASC, p.product_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $totalItems = count($stock);
            $totalQuantity = array_sum(array_column($stock, 'quantity'));
            $totalCostValue = array_sum(array_column($stock, 'total_cost_value'));
            $totalSellingValue = array_sum(array_column($stock, 'total_selling_value'));

            // Group by warehouse
            $stockByWarehouse = [];
            foreach ($stock as $item) {
                $warehouseId = $item['warehouse_id'];
                if (!isset($stockByWarehouse[$warehouseId])) {
                    $stockByWarehouse[$warehouseId] = [
                        'warehouse_id' => $warehouseId,
                        'warehouse_name' => $item['warehouse_name'],
                        'is_main' => $item['is_main'],
                        'items' => []
                    ];
                }
                $stockByWarehouse[$warehouseId]['items'][] = $item;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'All warehouse stock retrieved successfully',
                'data' => [
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_quantity' => $totalQuantity,
                        'total_cost_value' => $totalCostValue,
                        'total_selling_value' => $totalSellingValue,
                        'potential_profit' => $totalSellingValue - $totalCostValue,
                        'total_warehouses' => count($stockByWarehouse)
                    ],
                    'stock_by_warehouse' => array_values($stockByWarehouse),
                    'all_stock' => $stock
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get low stock alerts across all warehouses
    function getLowStockAlerts() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        ws.stock_id,
                        ws.warehouse_id,
                        ws.product_id,
                        ws.quantity,
                        p.product_name,
                        p.reorder_point,
                        p.min_stock_level,
                        w.warehouse_name,
                        c.category_name,
                        b.brand_name,
                        CASE 
                            WHEN ws.quantity <= p.min_stock_level THEN 'critical'
                            WHEN ws.quantity <= p.reorder_point THEN 'warning'
                            ELSE 'normal'
                        END as stock_status
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id
                    INNER JOIN warehouses w ON ws.warehouse_id = w.warehouse_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    WHERE ws.quantity > 0
                    ORDER BY stock_status DESC, ws.quantity ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $criticalCount = 0;
            $warningCount = 0;
            
            foreach ($alerts as $alert) {
                if ($alert['stock_status'] === 'critical') $criticalCount++;
                if ($alert['stock_status'] === 'warning') $warningCount++;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Low stock alerts retrieved successfully',
                'data' => [
                    'summary' => [
                        'total_alerts' => count($alerts),
                        'critical_alerts' => $criticalCount,
                        'warning_alerts' => $warningCount
                    ],
                    'alerts' => $alerts
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock value by category
    function getStockValueByCategory() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        c.category_id,
                        c.category_name,
                        COUNT(DISTINCT ws.product_id) as product_count,
                        SUM(ws.quantity) as total_quantity,
                        SUM(ws.quantity * ws.unit_price) as total_cost_value,
                        SUM(ws.quantity * p.selling_price) as total_selling_value
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id
                    INNER JOIN categories c ON p.category_id = c.category_id
                    WHERE ws.quantity > 0
                    GROUP BY c.category_id, c.category_name
                    ORDER BY total_cost_value DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock value by category retrieved successfully',
                'data' => $categories
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock movement (recent changes)
    function getRecentStockMovement($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $days = $data['days'] ?? 7; // Default to 7 days
            
            $sql = "SELECT 
                        'receive' as movement_type,
                        sri.product_id,
                        p.product_name,
                        sri.quantity_receive as quantity,
                        sri.unit_cost,
                        sr.receive_date as movement_date,
                        w.warehouse_name,
                        sup.supplier_name
                    FROM stock_receive_items sri
                    INNER JOIN stock_receive sr ON sri.receive_id = sr.receive_id
                    INNER JOIN products p ON sri.product_id = p.product_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN suppliers sup ON sr.supplier_id = sup.supplier_id
                    WHERE sr.receive_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    
                    UNION ALL
                    
                    SELECT 
                        'sale' as movement_type,
                        si.product_id,
                        p.product_name,
                        -si.quantity as quantity,
                        si.unit_price,
                        s.sale_date as movement_date,
                        w.warehouse_name as store_name,
                        CONCAT('Sale #', s.sale_code) as reference
                    FROM sales_items si
                    INNER JOIN sales s ON si.sale_id = s.sale_id
                    INNER JOIN products p ON si.product_id = p.product_id
                    INNER JOIN warehouses w ON s.store_id = w.warehouse_id
                    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    
                    UNION ALL
                    
                    SELECT 
                        'transfer' as movement_type,
                        wsti.product_id,
                        p.product_name,
                        wsti.quantity,
                        wsti.unit_price,
                        wst.requested_date as movement_date,
                        CONCAT('From: ', w1.warehouse_name, ' → To: ', w2.warehouse_name) as reference,
                        CONCAT('Transfer #', wst.transfer_id) as details
                    FROM warehouse_stock_transfer_items wsti
                    INNER JOIN warehouse_stock_transfer wst ON wsti.transfer_id = wst.transfer_id
                    INNER JOIN products p ON wsti.product_id = p.product_id
                    INNER JOIN warehouses w1 ON wst.from_warehouse_id = w1.warehouse_id
                    INNER JOIN warehouses w2 ON wst.to_warehouse_id = w2.warehouse_id
                    WHERE wst.requested_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                        AND wst.status = 'completed'
                    
                    ORDER BY movement_date DESC
                    LIMIT 100";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":days", $days, PDO::PARAM_INT);
            $stmt->execute();
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Recent stock movement retrieved successfully',
                'data' => [
                    'days' => $days,
                    'movements' => $movements
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock aging analysis
    function getStockAgingAnalysis() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        c.category_name,
                        ws.quantity,
                        ws.unit_price,
                        DATEDIFF(CURDATE(), ws.last_updated) as days_in_stock,
                        CASE 
                            WHEN DATEDIFF(CURDATE(), ws.last_updated) <= 30 THEN '0-30 days'
                            WHEN DATEDIFF(CURDATE(), ws.last_updated) <= 60 THEN '31-60 days'
                            WHEN DATEDIFF(CURDATE(), ws.last_updated) <= 90 THEN '61-90 days'
                            ELSE '90+ days'
                        END as aging_bucket,
                        w.warehouse_name
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id
                    INNER JOIN warehouses w ON ws.warehouse_id = w.warehouse_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    WHERE ws.quantity > 0
                    ORDER BY days_in_stock DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $agingData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary by aging bucket
            $agingSummary = [
                '0-30 days' => ['count' => 0, 'value' => 0],
                '31-60 days' => ['count' => 0, 'value' => 0],
                '61-90 days' => ['count' => 0, 'value' => 0],
                '90+ days' => ['count' => 0, 'value' => 0]
            ];

            foreach ($agingData as $item) {
                $bucket = $item['aging_bucket'];
                $value = $item['quantity'] * $item['unit_price'];
                
                $agingSummary[$bucket]['count']++;
                $agingSummary[$bucket]['value'] += $value;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock aging analysis retrieved successfully',
                'data' => [
                    'summary' => $agingSummary,
                    'details' => $agingData
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get top products by value
    function getTopProductsByValue($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $limit = $data['limit'] ?? 10;
            
            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        c.category_name,
                        SUM(ws.quantity) as total_quantity,
                        AVG(ws.unit_price) as avg_unit_cost,
                        p.selling_price,
                        SUM(ws.quantity * ws.unit_price) as total_cost_value,
                        SUM(ws.quantity * p.selling_price) as total_selling_value,
                        COUNT(DISTINCT ws.warehouse_id) as warehouses_count
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    WHERE ws.quantity > 0
                    GROUP BY p.product_id, p.product_name, c.category_name, p.selling_price
                    ORDER BY total_cost_value DESC
                    LIMIT :limit";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->execute();
            $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Top products by value retrieved successfully',
                'data' => [
                    'limit' => $limit,
                    'products' => $topProducts
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get stock turnover rate
    function getStockTurnoverRate() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        c.category_name,
                        AVG(ws.quantity) as avg_stock_level,
                        COALESCE(SUM(si.quantity), 0) as total_sold,
                        CASE 
                            WHEN AVG(ws.quantity) > 0 THEN COALESCE(SUM(si.quantity), 0) / AVG(ws.quantity)
                            ELSE 0 
                        END as turnover_rate,
                        CASE 
                            WHEN COALESCE(SUM(si.quantity), 0) / AVG(ws.quantity) > 1 THEN 'high'
                            WHEN COALESCE(SUM(si.quantity), 0) / AVG(ws.quantity) > 0.5 THEN 'medium'
                            ELSE 'low'
                        END as turnover_category
                    FROM warehouse_stock ws
                    INNER JOIN products p ON ws.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN sales_items si ON p.product_id = si.product_id
                        AND si.sale_id IN (
                            SELECT sale_id FROM sales 
                            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        )
                    WHERE ws.quantity > 0
                    GROUP BY p.product_id, p.product_name, c.category_name
                    HAVING total_sold > 0
                    ORDER BY turnover_rate DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $turnoverData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stock turnover rate retrieved successfully',
                'data' => $turnoverData
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

$warehouseStock = new WarehouseStock();

// Handle operations
switch($operation) {
    case "getWarehouseStock":
        echo $warehouseStock->getWarehouseStock($json);
        break;
        
    case "getAllWarehouseStock":
        echo $warehouseStock->getAllWarehouseStock();
        break;
        
    case "getLowStockAlerts":
        echo $warehouseStock->getLowStockAlerts();
        break;
        
    case "getStockValueByCategory":
        echo $warehouseStock->getStockValueByCategory();
        break;
        
    case "getRecentStockMovement":
        echo $warehouseStock->getRecentStockMovement($json);
        break;
        
    case "getStockAgingAnalysis":
        echo $warehouseStock->getStockAgingAnalysis();
        break;
        
    case "getTopProductsByValue":
        echo $warehouseStock->getTopProductsByValue($json);
        break;
        
    case "getStockTurnoverRate":
        echo $warehouseStock->getStockTurnoverRate();
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations: getWarehouseStock, getAllWarehouseStock, getLowStockAlerts, getStockValueByCategory, getRecentStockMovement, getStockAgingAnalysis, getTopProductsByValue, getStockTurnoverRate'
        ]);
}


?>