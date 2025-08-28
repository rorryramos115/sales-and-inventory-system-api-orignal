<?php
// SupplierReturns.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class SupplierReturns {
    
    // Get all supplier returns
    function getAllSupplierReturns() {
        include "connection-pdo.php";
        
        try {
            $sql = "SELECT 
                        sr.return_id,
                        sr.supplier_id,
                        sr.order_id,
                        sr.return_date,
                        sr.warehouse_id,
                        sr.reason,
                        sr.returned_by,
                        sr.total_amount,
                        sr.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        s.contact_person as supplier_contact,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        
                        -- User information
                        u.full_name as returned_by_name,
                        u.email as returned_by_email,
                        
                        -- Purchase order information (if available)
                        po.order_date as purchase_order_date,
                        po.total_amount as purchase_order_total
                        
                    FROM supplier_returns sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.returned_by = u.user_id
                    LEFT JOIN purchase_orders po ON sr.order_id = po.order_id
                    ORDER BY sr.return_date DESC, sr.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get return items for each return
            $returnItemsSql = "SELECT 
                                sri.return_item_id,
                                sri.return_id,
                                sri.product_id,
                                sri.quantity_return,
                                sri.unit_cost,
                                sri.total_cost,
                                
                                -- Product information
                                p.product_name,
                                p.barcode,
                                p.selling_price,
                                
                                -- Category information
                                c.category_name,
                                
                                -- Brand information
                                b.brand_name,
                                
                                -- Unit information
                                u.unit_name
                                
                            FROM supplier_return_items sri
                            INNER JOIN products p ON sri.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            LEFT JOIN brands b ON p.brand_id = b.brand_id
                            LEFT JOIN units u ON p.unit_id = u.unit_id
                            WHERE sri.return_id = :returnId
                            ORDER BY p.product_name ASC";

            $itemsStmt = $conn->prepare($returnItemsSql);
            
            // Add items to each return
            foreach ($returns as &$return) {
                $itemsStmt->bindValue(":returnId", $return['return_id']);
                $itemsStmt->execute();
                $return['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate summary
            $totalReturns = count($returns);
            $totalAmount = array_sum(array_column($returns, 'total_amount'));
            $totalItems = 0;
            
            foreach ($returns as $return) {
                $totalItems += count($return['items']);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier returns retrieved successfully',
                'data' => [
                    'summary' => [
                        'total_returns' => $totalReturns,
                        'total_amount' => $totalAmount,
                        'total_items' => $totalItems
                    ],
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

    // Get supplier return by ID
    function getSupplierReturnById($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['return_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: return_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        sr.return_id,
                        sr.supplier_id,
                        sr.order_id,
                        sr.return_date,
                        sr.warehouse_id,
                        sr.reason,
                        sr.returned_by,
                        sr.total_amount,
                        sr.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        s.contact_person as supplier_contact,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        
                        -- User information
                        u.full_name as returned_by_name,
                        u.email as returned_by_email,
                        
                        -- Purchase order information (if available)
                        po.order_date as purchase_order_date,
                        po.total_amount as purchase_order_total
                        
                    FROM supplier_returns sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.returned_by = u.user_id
                    LEFT JOIN purchase_orders po ON sr.order_id = po.order_id
                    WHERE sr.return_id = :returnId";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":returnId", $data['return_id']);
            $stmt->execute();
            $return = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$return) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Supplier return not found'
                ]);
                return;
            }

            // Get return items
            $itemsSql = "SELECT 
                            sri.return_item_id,
                            sri.return_id,
                            sri.product_id,
                            sri.quantity_return,
                            sri.unit_cost,
                            sri.total_cost,
                            
                            -- Product information
                            p.product_name,
                            p.barcode,
                            p.selling_price,
                            
                            -- Category information
                            c.category_name,
                            
                            -- Brand information
                            b.brand_name,
                            
                            -- Unit information
                            u.unit_name
                            
                        FROM supplier_return_items sri
                        INNER JOIN products p ON sri.product_id = p.product_id
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN units u ON p.unit_id = u.unit_id
                        WHERE sri.return_id = :returnId
                        ORDER BY p.product_name ASC";

            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bindValue(":returnId", $data['return_id']);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $return['items'] = $items;

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier return retrieved successfully',
                'data' => $return
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get supplier returns by supplier ID
    function getSupplierReturnsBySupplier($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['supplier_id'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: supplier_id'
                ]);
                return;
            }

            $sql = "SELECT 
                        sr.return_id,
                        sr.supplier_id,
                        sr.order_id,
                        sr.return_date,
                        sr.warehouse_id,
                        sr.reason,
                        sr.returned_by,
                        sr.total_amount,
                        sr.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        s.contact_person as supplier_contact,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        
                        -- User information
                        u.full_name as returned_by_name,
                        u.email as returned_by_email,
                        
                        -- Purchase order information (if available)
                        po.order_date as purchase_order_date,
                        po.total_amount as purchase_order_total
                        
                    FROM supplier_returns sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.returned_by = u.user_id
                    LEFT JOIN purchase_orders po ON sr.order_id = po.order_id
                    WHERE sr.supplier_id = :supplierId
                    ORDER BY sr.return_date DESC, sr.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":supplierId", $data['supplier_id']);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get return items for each return
            $returnItemsSql = "SELECT 
                                sri.return_item_id,
                                sri.return_id,
                                sri.product_id,
                                sri.quantity_return,
                                sri.unit_cost,
                                sri.total_cost,
                                
                                -- Product information
                                p.product_name,
                                p.barcode,
                                p.selling_price,
                                
                                -- Category information
                                c.category_name,
                                
                                -- Brand information
                                b.brand_name,
                                
                                -- Unit information
                                u.unit_name
                                
                            FROM supplier_return_items sri
                            INNER JOIN products p ON sri.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            LEFT JOIN brands b ON p.brand_id = b.brand_id
                            LEFT JOIN units u ON p.unit_id = u.unit_id
                            WHERE sri.return_id = :returnId
                            ORDER BY p.product_name ASC";

            $itemsStmt = $conn->prepare($returnItemsSql);
            
            // Add items to each return
            foreach ($returns as &$return) {
                $itemsStmt->bindValue(":returnId", $return['return_id']);
                $itemsStmt->execute();
                $return['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate summary
            $totalReturns = count($returns);
            $totalAmount = array_sum(array_column($returns, 'total_amount'));
            $totalItems = 0;
            
            foreach ($returns as $return) {
                $totalItems += count($return['items']);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier returns retrieved successfully',
                'data' => [
                    'supplier_id' => $data['supplier_id'],
                    'supplier_name' => !empty($returns) ? $returns[0]['supplier_name'] : null,
                    'summary' => [
                        'total_returns' => $totalReturns,
                        'total_amount' => $totalAmount,
                        'total_items' => $totalItems
                    ],
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

    // Get supplier returns by warehouse ID
    function getSupplierReturnsByWarehouse($json) {
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
                        sr.return_id,
                        sr.supplier_id,
                        sr.order_id,
                        sr.return_date,
                        sr.warehouse_id,
                        sr.reason,
                        sr.returned_by,
                        sr.total_amount,
                        sr.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        s.contact_person as supplier_contact,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        
                        -- User information
                        u.full_name as returned_by_name,
                        u.email as returned_by_email,
                        
                        -- Purchase order information (if available)
                        po.order_date as purchase_order_date,
                        po.total_amount as purchase_order_total
                        
                    FROM supplier_returns sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.returned_by = u.user_id
                    LEFT JOIN purchase_orders po ON sr.order_id = po.order_id
                    WHERE sr.warehouse_id = :warehouseId
                    ORDER BY sr.return_date DESC, sr.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":warehouseId", $data['warehouse_id']);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get return items for each return
            $returnItemsSql = "SELECT 
                                sri.return_item_id,
                                sri.return_id,
                                sri.product_id,
                                sri.quantity_return,
                                sri.unit_cost,
                                sri.total_cost,
                                
                                -- Product information
                                p.product_name,
                                p.barcode,
                                p.selling_price,
                                
                                -- Category information
                                c.category_name,
                                
                                -- Brand information
                                b.brand_name,
                                
                                -- Unit information
                                u.unit_name
                                
                            FROM supplier_return_items sri
                            INNER JOIN products p ON sri.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            LEFT JOIN brands b ON p.brand_id = b.brand_id
                            LEFT JOIN units u ON p.unit_id = u.unit_id
                            WHERE sri.return_id = :returnId
                            ORDER BY p.product_name ASC";

            $itemsStmt = $conn->prepare($returnItemsSql);
            
            // Add items to each return
            foreach ($returns as &$return) {
                $itemsStmt->bindValue(":returnId", $return['return_id']);
                $itemsStmt->execute();
                $return['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate summary
            $totalReturns = count($returns);
            $totalAmount = array_sum(array_column($returns, 'total_amount'));
            $totalItems = 0;
            
            foreach ($returns as $return) {
                $totalItems += count($return['items']);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier returns retrieved successfully',
                'data' => [
                    'warehouse_id' => $data['warehouse_id'],
                    'warehouse_name' => !empty($returns) ? $returns[0]['warehouse_name'] : null,
                    'summary' => [
                        'total_returns' => $totalReturns,
                        'total_amount' => $totalAmount,
                        'total_items' => $totalItems
                    ],
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

    // Get supplier returns by date range
    function getSupplierReturnsByDateRange($json) {
        include "connection-pdo.php";
        
        try {
            $data = json_decode($json, true);
            
            if(empty($data['start_date']) || empty($data['end_date'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: start_date and end_date'
                ]);
                return;
            }

            $sql = "SELECT 
                        sr.return_id,
                        sr.supplier_id,
                        sr.order_id,
                        sr.return_date,
                        sr.warehouse_id,
                        sr.reason,
                        sr.returned_by,
                        sr.total_amount,
                        sr.created_at,
                        
                        -- Supplier information
                        s.supplier_name,
                        s.contact_person as supplier_contact,
                        s.phone as supplier_phone,
                        s.email as supplier_email,
                        
                        -- Warehouse information
                        w.warehouse_name,
                        w.address as warehouse_address,
                        
                        -- User information
                        u.full_name as returned_by_name,
                        u.email as returned_by_email,
                        
                        -- Purchase order information (if available)
                        po.order_date as purchase_order_date,
                        po.total_amount as purchase_order_total
                        
                    FROM supplier_returns sr
                    INNER JOIN suppliers s ON sr.supplier_id = s.supplier_id
                    INNER JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                    INNER JOIN users u ON sr.returned_by = u.user_id
                    LEFT JOIN purchase_orders po ON sr.order_id = po.order_id
                    WHERE sr.return_date BETWEEN :startDate AND :endDate
                    ORDER BY sr.return_date DESC, sr.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":startDate", $data['start_date']);
            $stmt->bindValue(":endDate", $data['end_date']);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get return items for each return
            $returnItemsSql = "SELECT 
                                sri.return_item_id,
                                sri.return_id,
                                sri.product_id,
                                sri.quantity_return,
                                sri.unit_cost,
                                sri.total_cost,
                                
                                -- Product information
                                p.product_name,
                                p.barcode,
                                p.selling_price,
                                
                                -- Category information
                                c.category_name,
                                
                                -- Brand information
                                b.brand_name,
                                
                                -- Unit information
                                u.unit_name
                                
                            FROM supplier_return_items sri
                            INNER JOIN products p ON sri.product_id = p.product_id
                            LEFT JOIN categories c ON p.category_id = c.category_id
                            LEFT JOIN brands b ON p.brand_id = b.brand_id
                            LEFT JOIN units u ON p.unit_id = u.unit_id
                            WHERE sri.return_id = :returnId
                            ORDER BY p.product_name ASC";

            $itemsStmt = $conn->prepare($returnItemsSql);
            
            // Add items to each return
            foreach ($returns as &$return) {
                $itemsStmt->bindValue(":returnId", $return['return_id']);
                $itemsStmt->execute();
                $return['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate summary
            $totalReturns = count($returns);
            $totalAmount = array_sum(array_column($returns, 'total_amount'));
            $totalItems = 0;
            
            foreach ($returns as $return) {
                $totalItems += count($return['items']);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Supplier returns retrieved successfully',
                'data' => [
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'summary' => [
                        'total_returns' => $totalReturns,
                        'total_amount' => $totalAmount,
                        'total_items' => $totalItems
                    ],
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
}

// Handle request method and parameters
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

$supplierReturns = new SupplierReturns();

// Handle operations
switch($operation) {
    case "getAllSupplierReturns":
        echo $supplierReturns->getAllSupplierReturns();
        break;
        
    case "getSupplierReturnById":
        echo $supplierReturns->getSupplierReturnById($json);
        break;
        
    case "getSupplierReturnsBySupplier":
        echo $supplierReturns->getSupplierReturnsBySupplier($json);
        break;
        
    case "getSupplierReturnsByWarehouse":
        echo $supplierReturns->getSupplierReturnsByWarehouse($json);
        break;
        
    case "getSupplierReturnsByDateRange":
        echo $supplierReturns->getSupplierReturnsByDateRange($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}
?>