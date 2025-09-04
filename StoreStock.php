<?php
// StoreStock.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit();
}

class StoreStock {
    // Get store stock levels
    function getStoreStock($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['store_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        ss.store_stock_id,
                        ss.store_id,
                        ss.product_id,
                        ss.quantity,
                        ss.unit_price,
                        ss.last_updated,
                        ss.created_at,
                        
                        -- Store information
                        s.store_name,
                        s.address as store_address,
                        
                        -- Product information
                        p.product_name,
                        p.barcode,
                        p.description as product_description,
                        p.selling_price,
                        p.reorder_point,
                        p.min_stock_level,
                        p.max_stock_level,
                        (ss.quantity * ss.unit_price) as total_cost_value,
                        (ss.quantity * p.selling_price) as total_selling_value,
                        
                        -- Stock status
                        CASE 
                            WHEN ss.quantity <= p.min_stock_level THEN 'critical'
                            WHEN ss.quantity <= p.reorder_point THEN 'warning'
                            WHEN ss.quantity >= p.max_stock_level THEN 'overstock'
                            ELSE 'normal'
                        END as stock_status,
                        
                        -- Category information
                        c.category_id,
                        c.category_name,
                        
                        -- Brand information
                        b.brand_id,
                        b.brand_name,
                        
                        -- Unit information
                        u.unit_id,
                        u.unit_name
                        
                    FROM store_stock ss
                    INNER JOIN store s ON ss.store_id = s.store_id
                    INNER JOIN products p ON ss.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    WHERE ss.store_id = :storeId AND ss.quantity > 0
                    ORDER BY p.product_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":storeId", $data['store_id']);
            $stmt->execute();
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $totalItems = count($stock);
            $totalQuantity = array_sum(array_column($stock, 'quantity'));
            $totalCostValue = array_sum(array_column($stock, 'total_cost_value'));
            $totalSellingValue = array_sum(array_column($stock, 'total_selling_value'));

            // Count stock alerts
            $criticalCount = 0;
            $warningCount = 0;
            $overstockCount = 0;
            
            foreach ($stock as $item) {
                if ($item['stock_status'] === 'critical') $criticalCount++;
                if ($item['stock_status'] === 'warning') $warningCount++;
                if ($item['stock_status'] === 'overstock') $overstockCount++;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Store stock retrieved successfully',
                'data' => [
                    'store_id' => $data['store_id'],
                    'store_name' => !empty($stock) ? $stock[0]['store_name'] : null,
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_quantity' => $totalQuantity,
                        'total_cost_value' => $totalCostValue,
                        'total_selling_value' => $totalSellingValue,
                        'potential_profit' => $totalSellingValue - $totalCostValue,
                        'critical_alerts' => $criticalCount,
                        'warning_alerts' => $warningCount,
                        'overstock_alerts' => $overstockCount
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

    // Get all store stock
    function getAllStoreStock() {
        include "connection-pdo.php";

        try {
            $sql = "SELECT 
                        ss.store_stock_id,
                        ss.store_id,
                        ss.product_id,
                        ss.quantity,
                        ss.unit_price,
                        ss.last_updated,
                        ss.created_at,
                        
                        -- Store information
                        s.store_name,
                        s.address as store_address,
                        
                        -- Product information
                        p.product_name,
                        p.barcode,
                        p.description as product_description,
                        p.selling_price,
                        p.reorder_point,
                        p.min_stock_level,
                        (ss.quantity * ss.unit_price) as total_cost_value,
                        (ss.quantity * p.selling_price) as total_selling_value,
                        
                        -- Stock status
                        CASE 
                            WHEN ss.quantity <= p.min_stock_level THEN 'critical'
                            WHEN ss.quantity <= p.reorder_point THEN 'warning'
                            ELSE 'normal'
                        END as stock_status,
                        
                        -- Category information
                        c.category_id,
                        c.category_name,
                        
                        -- Brand information
                        b.brand_id,
                        b.brand_name,
                        
                        -- Unit information
                        u.unit_id,
                        u.unit_name
                        
                    FROM store_stock ss
                    INNER JOIN store s ON ss.store_id = s.store_id
                    INNER JOIN products p ON ss.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    LEFT JOIN units u ON p.unit_id = u.unit_id
                    WHERE ss.quantity > 0
                    ORDER BY s.store_name ASC, p.product_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $totalItems = count($stock);
            $totalQuantity = array_sum(array_column($stock, 'quantity'));
            $totalCostValue = array_sum(array_column($stock, 'total_cost_value'));
            $totalSellingValue = array_sum(array_column($stock, 'total_selling_value'));

            // Group by store
            $stockByStore = [];
            foreach ($stock as $item) {
                $storeId = $item['store_id'];
                if (!isset($stockByStore[$storeId])) {
                    $stockByStore[$storeId] = [
                        'store_id' => $storeId,
                        'store_name' => $item['store_name'],
                        'store_address' => $item['store_address'],
                        'items' => []
                    ];
                }
                $stockByStore[$storeId]['items'][] = $item;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'All store stock retrieved successfully',
                'data' => [
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_quantity' => $totalQuantity,
                        'total_cost_value' => $totalCostValue,
                        'total_selling_value' => $totalSellingValue,
                        'potential_profit' => $totalSellingValue - $totalCostValue,
                        'total_stores' => count($stockByStore)
                    ],
                    'stock_by_store' => array_values($stockByStore),
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

    // Get low stock alerts for stores
    function getStoreLowStockAlerts() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        ss.store_stock_id,
                        ss.store_id,
                        ss.product_id,
                        ss.quantity,
                        p.product_name,
                        p.reorder_point,
                        p.min_stock_level,
                        s.store_name,
                        c.category_name,
                        b.brand_name,
                        CASE 
                            WHEN ss.quantity <= p.min_stock_level THEN 'critical'
                            WHEN ss.quantity <= p.reorder_point THEN 'warning'
                            ELSE 'normal'
                        END as stock_status
                    FROM store_stock ss
                    INNER JOIN products p ON ss.product_id = p.product_id
                    INNER JOIN store s ON ss.store_id = s.store_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN brands b ON p.brand_id = b.brand_id
                    WHERE ss.quantity <= p.reorder_point AND ss.quantity > 0
                    ORDER BY stock_status DESC, ss.quantity ASC";

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
                'message' => 'Store low stock alerts retrieved successfully',
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

    // Get store stock value by category
    function getStoreStockValueByCategory($json = '{}') {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $storeId = $data['store_id'] ?? null;
            
            $whereClause = $storeId ? "AND ss.store_id = :storeId" : "";
            
            $sql = "SELECT 
                        c.category_id,
                        c.category_name,
                        COUNT(DISTINCT ss.product_id) as product_count,
                        SUM(ss.quantity) as total_quantity,
                        SUM(ss.quantity * ss.unit_price) as total_cost_value,
                        SUM(ss.quantity * p.selling_price) as total_selling_value
                    FROM store_stock ss
                    INNER JOIN products p ON ss.product_id = p.product_id
                    INNER JOIN categories c ON p.category_id = c.category_id
                    WHERE ss.quantity > 0 $whereClause
                    GROUP BY c.category_id, c.category_name
                    ORDER BY total_cost_value DESC";

            $stmt = $conn->prepare($sql);
            if ($storeId) {
                $stmt->bindValue(":storeId", $storeId);
            }
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store stock value by category retrieved successfully',
                'data' => [
                    'store_id' => $storeId,
                    'categories' => $categories
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get store sales performance
    function getStoreSalesPerformance($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $storeId = $data['store_id'] ?? null;
            $days = $data['days'] ?? 30;
            
            if (!$storeId) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        c.category_name,
                        ss.quantity as current_stock,
                        COALESCE(SUM(si.quantity), 0) as total_sold,
                        COALESCE(SUM(si.total_price), 0) as total_revenue,
                        COUNT(DISTINCT s.sale_id) as transaction_count,
                        CASE 
                            WHEN ss.quantity > 0 THEN COALESCE(SUM(si.quantity), 0) / ss.quantity
                            ELSE 0 
                        END as turnover_ratio,
                        CASE 
                            WHEN COALESCE(SUM(si.quantity), 0) > 0 THEN 'active'
                            ELSE 'inactive'
                        END as sales_status
                    FROM store_stock ss
                    INNER JOIN products p ON ss.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN sales_items si ON p.product_id = si.product_id
                        AND si.sale_id IN (
                            SELECT sale_id FROM sales 
                            WHERE store_id = :storeId 
                            AND sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                        )
                    LEFT JOIN sales s ON si.sale_id = s.sale_id
                    WHERE ss.store_id = :storeId AND ss.quantity > 0
                    GROUP BY p.product_id, p.product_name, c.category_name, ss.quantity
                    ORDER BY total_sold DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":storeId", $storeId);
            $stmt->bindValue(":days", $days, PDO::PARAM_INT);
            $stmt->execute();
            $performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals
            $totalRevenue = array_sum(array_column($performance, 'total_revenue'));
            $totalSold = array_sum(array_column($performance, 'total_sold'));
            $totalTransactions = array_sum(array_column($performance, 'transaction_count'));

            echo json_encode([
                'status' => 'success',
                'message' => 'Store sales performance retrieved successfully',
                'data' => [
                    'store_id' => $storeId,
                    'period_days' => $days,
                    'summary' => [
                        'total_revenue' => $totalRevenue,
                        'total_items_sold' => $totalSold,
                        'total_transactions' => $totalTransactions,
                        'avg_transaction_value' => $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0
                    ],
                    'performance' => $performance
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get store stock aging analysis
    function getStoreStockAgingAnalysis($json = '{}') {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $storeId = $data['store_id'] ?? null;
            
            $whereClause = $storeId ? "AND ss.store_id = :storeId" : "";
            
            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        c.category_name,
                        s.store_name,
                        ss.quantity,
                        ss.unit_price,
                        DATEDIFF(CURDATE(), ss.last_updated) as days_in_stock,
                        CASE 
                            WHEN DATEDIFF(CURDATE(), ss.last_updated) <= 7 THEN '0-7 days'
                            WHEN DATEDIFF(CURDATE(), ss.last_updated) <= 30 THEN '8-30 days'
                            WHEN DATEDIFF(CURDATE(), ss.last_updated) <= 60 THEN '31-60 days'
                            ELSE '60+ days'
                        END as aging_bucket
                    FROM store_stock ss
                    INNER JOIN products p ON ss.product_id = p.product_id
                    INNER JOIN store s ON ss.store_id = s.store_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    WHERE ss.quantity > 0 $whereClause
                    ORDER BY days_in_stock DESC";

            $stmt = $conn->prepare($sql);
            if ($storeId) {
                $stmt->bindValue(":storeId", $storeId);
            }
            $stmt->execute();
            $agingData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary by aging bucket
            $agingSummary = [
                '0-7 days' => ['count' => 0, 'value' => 0],
                '8-30 days' => ['count' => 0, 'value' => 0],
                '31-60 days' => ['count' => 0, 'value' => 0],
                '60+ days' => ['count' => 0, 'value' => 0]
            ];

            foreach ($agingData as $item) {
                $bucket = $item['aging_bucket'];
                $value = $item['quantity'] * $item['unit_price'];
                
                $agingSummary[$bucket]['count']++;
                $agingSummary[$bucket]['value'] += $value;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Store stock aging analysis retrieved successfully',
                'data' => [
                    'store_id' => $storeId,
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

    // Get top selling products by store
    function getTopSellingProducts($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $storeId = $data['store_id'] ?? null;
            $limit = $data['limit'] ?? 10;
            $days = $data['days'] ?? 30;
            
            if (!$storeId) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        p.product_id,
                        p.product_name,
                        c.category_name,
                        SUM(si.quantity) as total_sold,
                        SUM(si.total_price) as total_revenue,
                        AVG(si.unit_price) as avg_selling_price,
                        COUNT(DISTINCT s.sale_id) as transaction_count,
                        ss.quantity as current_stock
                    FROM sales_items si
                    INNER JOIN sales s ON si.sale_id = s.sale_id
                    INNER JOIN products p ON si.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN store_stock ss ON p.product_id = ss.product_id AND ss.store_id = s.store_id
                    WHERE s.store_id = :storeId 
                    AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    GROUP BY p.product_id, p.product_name, c.category_name, ss.quantity
                    ORDER BY total_sold DESC
                    LIMIT :limit";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":storeId", $storeId);
            $stmt->bindValue(":days", $days, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->execute();
            $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Top selling products retrieved successfully',
                'data' => [
                    'store_id' => $storeId,
                    'period_days' => $days,
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

    // Get store stock movement history
    function getStoreStockMovement($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $storeId = $data['store_id'] ?? null;
            $days = $data['days'] ?? 7;
            
            if (!$storeId) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: store_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        'transfer_in' as movement_type,
                        ssti.product_id,
                        p.product_name,
                        ssti.quantity,
                        ssti.unit_price,
                        sst.requested_date as movement_date,
                        w.warehouse_name as source,
                        'Stock Transfer In' as description
                    FROM store_stock_transfer_items ssti
                    INNER JOIN store_stock_transfer sst ON ssti.transfer_id = sst.transfer_id
                    INNER JOIN products p ON ssti.product_id = p.product_id
                    INNER JOIN warehouses w ON sst.from_warehouse_id = w.warehouse_id
                    WHERE sst.to_store_id = :storeId
                    AND sst.requested_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    AND sst.status = 'completed'
                    
                    UNION ALL
                    
                    SELECT 
                        'sale' as movement_type,
                        si.product_id,
                        p.product_name,
                        -si.quantity as quantity,
                        si.unit_price,
                        s.sale_date as movement_date,
                        CONCAT('Sale #', s.sale_code) as source,
                        'Product Sale' as description
                    FROM sales_items si
                    INNER JOIN sales s ON si.sale_id = s.sale_id
                    INNER JOIN products p ON si.product_id = p.product_id
                    WHERE s.store_id = :storeId
                    AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    
                    UNION ALL
                    
                    SELECT 
                        'return' as movement_type,
                        sri.product_id,
                        p.product_name,
                        sri.quantity,
                        sri.unit_price,
                        sr.return_date as movement_date,
                        CONCAT('Return #', sr.return_id) as source,
                        'Sales Return' as description
                    FROM sales_return_items sri
                    INNER JOIN sales_returns sr ON sri.return_id = sr.return_id
                    INNER JOIN products p ON sri.product_id = p.product_id
                    WHERE sr.warehouse_id = :storeId
                    AND sr.return_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    
                    ORDER BY movement_date DESC
                    LIMIT 100";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":storeId", $storeId);
            $stmt->bindValue(":days", $days, PDO::PARAM_INT);
            $stmt->execute();
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Store stock movement retrieved successfully',
                'data' => [
                    'store_id' => $storeId,
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

    // Compare store performance
    function compareStorePerformance($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            $days = $data['days'] ?? 30;
            
            $sql = "SELECT 
                        s.store_id,
                        s.store_name,
                        COUNT(DISTINCT ss.product_id) as total_products,
                        SUM(ss.quantity) as total_stock_quantity,
                        SUM(ss.quantity * ss.unit_price) as total_stock_value,
                        COALESCE(sales_data.total_sales, 0) as total_sales,
                        COALESCE(sales_data.total_revenue, 0) as total_revenue,
                        COALESCE(sales_data.transaction_count, 0) as transaction_count
                    FROM store s
                    LEFT JOIN store_stock ss ON s.store_id = ss.store_id
                    LEFT JOIN (
                        SELECT 
                            store_id,
                            SUM(total_items) as total_sales,
                            SUM(total_amount) as total_revenue,
                            COUNT(*) as transaction_count
                        FROM sales 
                        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                        GROUP BY store_id
                    ) sales_data ON s.store_id = sales_data.store_id
                    WHERE ss.quantity > 0
                    GROUP BY s.store_id, s.store_name, sales_data.total_sales, 
                             sales_data.total_revenue, sales_data.transaction_count
                    ORDER BY total_revenue DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":days", $days, PDO::PARAM_INT);
            $stmt->execute();
            $comparison = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate additional metrics
            foreach ($comparison as &$store) {
                $store['avg_transaction_value'] = $store['transaction_count'] > 0 ? 
                    $store['total_revenue'] / $store['transaction_count'] : 0;
                $store['stock_turnover'] = $store['total_stock_value'] > 0 ? 
                    $store['total_revenue'] / $store['total_stock_value'] : 0;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Store performance comparison retrieved successfully',
                'data' => [
                    'period_days' => $days,
                    'stores' => $comparison
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

// Handle request method and get parameters
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

$storeStock = new StoreStock();

// Handle operations
switch($operation) {
    case "getStoreStock":
        $storeStock->getStoreStock($json);
        break;
        
    case "getAllStoreStock":
        $storeStock->getAllStoreStock();
        break;
        
    case "getStoreLowStockAlerts":
        $storeStock->getStoreLowStockAlerts();
        break;
        
    case "getStoreStockValueByCategory":
        $storeStock->getStoreStockValueByCategory($json);
        break;
        
    case "getStoreSalesPerformance":
        $storeStock->getStoreSalesPerformance($json);
        break;
        
    case "getStoreStockAgingAnalysis":
        $storeStock->getStoreStockAgingAnalysis($json);
        break;
        
    case "getTopSellingProducts":
        $storeStock->getTopSellingProducts($json);
        break;
        
    case "getStoreStockMovement":
        $storeStock->getStoreStockMovement($json);
        break;
        
    case "compareStorePerformance":
        $storeStock->compareStorePerformance($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}
