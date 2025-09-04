<?php
// StoreAPI.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit();
}

class StoreAPI {
    private $conn;
    
    public function __construct() {
        include "connection-pdo.php";
        $this->conn = $conn;
    }

    // Get all stores
    public function getAllStores() {
        try {
            $sql = "SELECT 
                        store_id,
                        store_name,
                        address,
                        created_at,
                        updated_at
                    FROM store
                    ORDER BY store_name ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'message' => 'Stores retrieved successfully',
                'data' => [
                    'stores' => $stores,
                    'total_count' => count($stores)
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // Get store by ID
    public function getStoreById($json) {
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
                        store_id,
                        store_name,
                        address,
                        created_at,
                        updated_at
                    FROM store
                    WHERE store_id = :store_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":store_id", $data['store_id']);
            $stmt->execute();
            $store = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$store) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Store not found'
                ]);
                return;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Store retrieved successfully',
                'data' => $store
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

$storeAPI = new StoreAPI();

// Handle operations
switch($operation) {
    case "getAllStores":
        $storeAPI->getAllStores();
        break;
        
    case "getStoreById":
        $storeAPI->getStoreById($json);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation. Available operations.'
        ]);
}