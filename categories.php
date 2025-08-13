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

  class Category {
     private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    function getAllCategories(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM categories ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Categories retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertCategory($json){
        include "connection-pdo.php";
         $conn->beginTransaction();

        try {
            
            if(empty($json['category_name'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Missing required field: category_name is required'
                ]);
                return;
            }
            $categoryId = $this->generateUuid();

            $sql = "INSERT INTO categories(category_id, category_name, description) VALUES(:categoryId, :categoryName, :description)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":categoryId", $categoryId);
            $stmt->bindParam(":categoryName", $json['category_name']);
            $stmt->bindParam(":description", $json['description']);
            $stmt->execute();

             $conn->commit(); 

            if($stmt->rowCount() > 0){
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Category created successfully',
                    'data' => [
                        'category_id' => $categoryId,
                        'category_name' => $json['category_name'],
                        'description' => $json['description']
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to create category'
                ]);
            }
        } catch (PDOException $e) {
            if($e->getCode() == 23000) { 
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Category name already exists. Please use a different name.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        }
    }

    function getCategory($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['category_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: category_id is required'
          ]);
          return;
        }

        $sql = "SELECT * FROM categories WHERE category_id = :categoryId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryId", $json['category_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'Category retrieved successfully',
            'data' => $rs[0]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Category not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function updateCategory($json){
      include "connection-pdo.php";
        $conn->beginTransaction();
      
      try {
        
        // Validate required fields
        if(empty($json['category_id']) || empty($json['category_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: category_id and category_name are required'
          ]);
          return;
        }

        $sql = "UPDATE categories SET category_name = :categoryName, description = :description WHERE category_id = :categoryId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryName", $json['category_name']);
        $stmt->bindParam(":description", $json['description']);
        $stmt->bindParam(":categoryId", $json['category_id']);
        $stmt->execute();

          $conn->commit(); 

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Category updated successfully',
            'data' => [
              'category_id' => $json['category_id'],
              'category_name' => $json['category_name'],
              'description' => $json['description']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Category not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Category name already exists. Please use a different name.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function deleteCategory($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['category_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: category_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM categories WHERE category_id = :categoryId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryId", $json['category_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Category deleted successfully',
            'data' => [
              'category_id' => $json['category_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Category not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function searchCategories($json) {
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
          $sql = "SELECT * FROM categories 
                  WHERE category_name LIKE :searchTerm 
                  OR description LIKE :searchTerm
                  ORDER BY category_name
                  LIMIT 20";
          
          $stmt = $conn->prepare($sql);
          $stmt->bindValue(":searchTerm", $searchTerm);
          $stmt->execute();
          $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode([
              'status' => 'success',
              'message' => 'Categories search completed',
              'data' => $rs
          ]);
      } catch (PDOException $e) {
          echo json_encode([
              'status' => 'error',
              'message' => 'Database error: ' . $e->getMessage()
          ]);
      }
  }

    function checkCategoryName($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['category_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: category_name is required'
          ]);
          return;
        }

        $sql = "SELECT COUNT(*) as count FROM categories WHERE category_name = :categoryName";
        if(isset($json['category_id'])){
          $sql .= " AND category_id != :categoryId";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryName", $json['category_name']);
        if(isset($json['category_id'])){
          $stmt->bindParam(":categoryId", $json['category_id']);
        }
        $stmt->execute();
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Category name check completed',
          'data' => [
            'exists' => $rs['count'] > 0,
            'category_name' => $json['category_name']
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



$category = new Category();
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
  case "insertCategory":
    echo $category->insertCategory($data);
    break;
  case "updateCategory":
    echo $category->updateCategory($data);
    break;
  case "getAllCategories":
    echo $category->getAllCategories();
    break;
  case "getCategory":
    $json = $_GET['json'] ?? '{}';
    echo $category->getCategory($json);
    break;
  case "deleteCategory":
    $json = $_GET['json'] ?? '{}';
    echo $category->deleteCategory($json);
    break;
  case "searchCategories":
    $json = $_GET['json'] ?? '{}';
    echo $category->searchCategories($json);
    break;
  case "checkCategoryName":
    $json = $_GET['json'] ?? '{}';
    echo $category->checkCategoryName($json);
    break;
  default:
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid operation',
        'available_operations' => [
            'insertUser', 
            'updateUser', 
            'login', 
            'getAllUsers', 
            'getUser', 
            'deleteUser', 
            'checkEmail', 
            'searchUsers'
        ]
    ]);
}
?>
