<?php
  header('Content-Type: application/json');
  header("Access-Control-Allow-Origin: *");

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
        $sql = "SELECT * FROM categories ORDER BY category_name";
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

        try {
            $json = json_decode($json, true);
            
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
      
      try {
        $json = json_decode($json, true);
        
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

  //submitted by the client - operation and json
  if ($_SERVER['REQUEST_METHOD'] == 'GET'){
    $operation = $_GET['operation'];
    $json = isset($_GET['json']) ? $_GET['json'] : "";
  }else if($_SERVER['REQUEST_METHOD'] == 'POST'){
    // Prefer POST params, then URL params, then raw JSON body
    if(isset($_POST['operation'])){
      $operation = $_POST['operation'];
    } else if(isset($_GET['operation'])){
      $operation = $_GET['operation'];
    } else {
      $operation = '';
    }

    if(isset($_POST['json'])){
      $json = $_POST['json'];
    } else if(isset($_GET['json'])){
      $json = $_GET['json'];
    } else {
      // Fallback to raw body
      $json = file_get_contents('php://input');
    }
  }

  $category = new Category();
  switch($operation){
    case "getAllCategories":
      echo $category->getAllCategories();
      break;
    case "insertCategory":
      echo $category->insertCategory($json);
      break;
    case "getCategory":
      echo $category->getCategory($json);
      break;
    case "updateCategory":
      echo $category->updateCategory($json);
      break;
    case "deleteCategory":
      echo $category->deleteCategory($json);
      break;
    case "checkCategoryName":
      echo $category->checkCategoryName($json);
      break;
  }
?>
