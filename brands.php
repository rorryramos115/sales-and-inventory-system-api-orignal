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

  class Brand {
     private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    function getAllBrands(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM brands ORDER BY brand_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Brands retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertBrand($json){
        include "connection-pdo.php";
         $conn->beginTransaction();

        try {
            
            if(empty($json['brand_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: brand_name is required'
                ]);
                return;
            }
            $brandId = $this->generateUuid();
            $isActive = isset($json['is_active']) ? $json['is_active'] : true;

            $sql = "INSERT INTO brands(brand_id, brand_name, is_active) VALUES(:brandId, :brandName, :isActive)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":brandId", $brandId);
            $stmt->bindParam(":brandName", $json['brand_name']);
            $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
            $stmt->execute();

             $conn->commit(); 

            if($stmt->rowCount() > 0){
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Brand created successfully',
                    'data' => [
                        'brand_id' => $brandId,
                        'brand_name' => $json['brand_name'],
                        'is_active' => $isActive
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to create brand'
                ]);
            }
        } catch (PDOException $e) {
            $conn->rollback();
            if($e->getCode() == 23000) { 
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Brand name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function getBrand($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['brand_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: brand_id is required'
          ]);
          return;
        }

        $sql = "SELECT * FROM brands WHERE brand_id = :brandId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":brandId", $json['brand_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'Brand retrieved successfully',
            'data' => $rs[0]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Brand not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function updateBrand($json){
      include "connection-pdo.php";
        $conn->beginTransaction();
      
      try {
        
        // Validate required fields
        if(empty($json['brand_id']) || empty($json['brand_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: brand_id and brand_name are required'
          ]);
          return;
        }

        $isActive = isset($json['is_active']) ? $json['is_active'] : true;

        $sql = "UPDATE brands SET brand_name = :brandName, is_active = :isActive WHERE brand_id = :brandId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":brandName", $json['brand_name']);
        $stmt->bindParam(":isActive", $isActive, PDO::PARAM_BOOL);
        $stmt->bindParam(":brandId", $json['brand_id']);
        $stmt->execute();

          $conn->commit(); 

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Brand updated successfully',
            'data' => [
              'brand_id' => $json['brand_id'],
              'brand_name' => $json['brand_name'],
              'is_active' => $isActive
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Brand not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        $conn->rollback();
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Brand name already exists. Please use a different name.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function deleteBrand($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['brand_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: brand_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM brands WHERE brand_id = :brandId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":brandId", $json['brand_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Brand deleted successfully',
            'data' => [
              'brand_id' => $json['brand_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Brand not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function searchBrands($json) {
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
          $sql = "SELECT * FROM brands 
                  WHERE brand_name LIKE :searchTerm
                  ORDER BY brand_name
                  LIMIT 20";
          
          $stmt = $conn->prepare($sql);
          $stmt->bindValue(":searchTerm", $searchTerm);
          $stmt->execute();
          $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode([
              'status' => 'success',
              'message' => 'Brands search completed',
              'data' => $rs
          ]);
      } catch (PDOException $e) {
          echo json_encode([
              'status' => 'error',
              'message' => 'Database error: ' . $e->getMessage()
          ]);
      }
  }

    function checkBrandName($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['brand_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: brand_name is required'
          ]);
          return;
        }

        $sql = "SELECT COUNT(*) as count FROM brands WHERE brand_name = :brandName";
        if(isset($json['brand_id'])){
          $sql .= " AND brand_id != :brandId";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":brandName", $json['brand_name']);
        if(isset($json['brand_id'])){
          $stmt->bindParam(":brandId", $json['brand_id']);
        }
        $stmt->execute();
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Brand name check completed',
          'data' => [
            'exists' => $rs['count'] > 0,
            'brand_name' => $json['brand_name']
          ]
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function getActiveBrands(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM brands WHERE is_active = 1 ORDER BY brand_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Active brands retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }
  }



$brand = new Brand();
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

switch($operation){
  case "insertBrand":
    echo $brand->insertBrand($data);
    break;
  case "updateBrand":
    echo $brand->updateBrand($data);
    break;
  case "getAllBrands":
    echo $brand->getAllBrands();
    break;
  case "getBrand":
    $json = $_GET['json'] ?? '{}';
    echo $brand->getBrand($json);
    break;
  case "deleteBrand":
    $json = $_GET['json'] ?? '{}';
    echo $brand->deleteBrand($json);
    break;
  case "searchBrands":
    $json = $_GET['json'] ?? '{}';
    echo $brand->searchBrands($json);
    break;
  case "checkBrandName":
    $json = $_GET['json'] ?? '{}';
    echo $brand->checkBrandName($json);
    break;
  case "getActiveBrands":
    echo $brand->getActiveBrands();
    break;
  default:
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid operation',
        'available_operations' => [
            'insertBrand', 
            'updateBrand', 
            'getAllBrands', 
            'getBrand', 
            'deleteBrand', 
            'searchBrands', 
            'checkBrandName',
            'getActiveBrands'
        ]
    ]);
}
?>