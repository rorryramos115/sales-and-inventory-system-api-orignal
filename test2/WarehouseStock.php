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
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}


?>