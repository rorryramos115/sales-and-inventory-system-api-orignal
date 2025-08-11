<?php
  header('Content-Type: application/json');
  header("Access-Control-Allow-Origin: *");

  class Supplier {
    private function generateUuid() {
      return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
          mt_rand(0, 0xffff), mt_rand(0, 0xffff),
          mt_rand(0, 0xffff),
          mt_rand(0, 0x0fff) | 0x4000,
          mt_rand(0, 0x3fff) | 0x8000,
          mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
      );
    }

    function getAllSuppliers(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM suppliers ORDER BY supplier_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Suppliers retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function insertSupplier($json){
      include "connection-pdo.php";

      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['supplier_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: supplier_name is required'
          ]);
          return;
        }

        // Generate UUID for supplier_id
        $supplierId = $this->generateUuid();

        $sql = "INSERT INTO suppliers(supplier_id, supplier_name, contact_person, phone, email, address, is_active) 
                VALUES(:supplierId, :supplier_name, :contactPerson, :phone, :email, :address, :isActive)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":supplierId", $supplierId);
        $stmt->bindParam(":supplier_name", $json['supplier_name']);
        $stmt->bindParam(":contactPerson", $json['contact_person']);
        $stmt->bindParam(":phone", $json['phone']);
        $stmt->bindParam(":email", $json['email']);
        $stmt->bindParam(":address", $json['address']);
        $stmt->bindParam(":isActive", $json['is_active']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Supplier created successfully',
            'data' => [
              'supplier_id' => $supplierId,
              'supplier_name' => $json['supplier_name'],
              'contact_person' => $json['contact_person'],
              'phone' => $json['phone'],
              'email' => $json['email'],
              'address' => $json['address'],
              'is_active' => $json['is_active']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create supplier'
          ]);
        }
      } catch (PDOException $e) {
        if($e->getCode() == 23000) { // Duplicate entry
          echo json_encode([
            'status' => 'error',
            'message' => 'Supplier name already exists. Please use a different name.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function getSupplier($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['supplier_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: supplier_id is required'
          ]);
          return;
        }

        $sql = "SELECT * FROM suppliers WHERE supplier_id = :supplierId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":supplierId", $json['supplier_id']);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rs) > 0) {
          echo json_encode([
            'status' => 'success',
            'message' => 'Supplier retrieved successfully',
            'data' => $rs[0]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Supplier not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function updateSupplier($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['supplier_id']) || empty($json['supplier_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required fields: supplier_id and supplier_name are required'
          ]);
          return;
        }

        $sql = "UPDATE suppliers SET supplier_name = :supplierName, contact_person = :contactPerson, 
                phone = :phone, email = :email, address = :address, is_active = :isActive 
                WHERE supplier_id = :supplierId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":supplierName", $json['supplier_name']);
        $stmt->bindParam(":contactPerson", $json['contact_person']);
        $stmt->bindParam(":phone", $json['phone']);
        $stmt->bindParam(":email", $json['email']);
        $stmt->bindParam(":address", $json['address']);
        $stmt->bindParam(":isActive", $json['is_active']);
        $stmt->bindParam(":supplierId", $json['supplier_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Supplier updated successfully',
            'data' => [
              'supplier_id' => $json['supplier_id'],
              'supplier_name' => $json['supplier_name'],
              'contact_person' => $json['contact_person'],
              'phone' => $json['phone'],
              'email' => $json['email'],
              'address' => $json['address'],
              'is_active' => $json['is_active']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Supplier not found or no changes made'
          ]);
        }
      } catch (PDOException $e) {
        if($e->getCode() == 23000) { 
          echo json_encode([
            'status' => 'error',
            'message' => 'Supplier name already exists. Please use a different name.'
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
          ]);
        }
      }
    }

    function deleteSupplier($json){
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        // Validate required fields
        if(empty($json['supplier_id'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: supplier_id is required'
          ]);
          return;
        }

        $sql = "DELETE FROM suppliers WHERE supplier_id = :supplierId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":supplierId", $json['supplier_id']);
        $stmt->execute();

        if($stmt->rowCount() > 0){
          echo json_encode([
            'status' => 'success',
            'message' => 'Supplier deleted successfully',
            'data' => [
              'supplier_id' => $json['supplier_id']
            ]
          ]);
        } else {
          echo json_encode([
            'status' => 'error',
            'message' => 'Supplier not found'
          ]);
        }
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    function getActiveSuppliers(){
      include "connection-pdo.php";

      try {
        $sql = "SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Active suppliers retrieved successfully',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    // Search suppliers by name, contact person, phone, or email
    function searchSuppliers($json) {
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        if(empty($json['search_term'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: search_term is required'
          ]);
          return;
        }

        $searchTerm = '%' . $json['search_term'] . '%';
        $sql = "SELECT * FROM suppliers 
                WHERE supplier_name LIKE :searchTerm 
                OR contact_person LIKE :searchTerm
                OR phone LIKE :searchTerm
                OR email LIKE :searchTerm
                ORDER BY supplier_name
                LIMIT 20";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":searchTerm", $searchTerm);
        $stmt->execute();
        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Suppliers search completed',
          'data' => $rs
        ]);
      } catch (PDOException $e) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Database error: ' . $e->getMessage()
        ]);
      }
    }

    // Check if supplier name exists
    function checkSupplierName($json) {
      include "connection-pdo.php";
      
      try {
        $json = json_decode($json, true);
        
        if(empty($json['supplier_name'])) {
          echo json_encode([
            'status' => 'error',
            'message' => 'Missing required field: supplier_name is required'
          ]);
          return;
        }

        $sql = "SELECT COUNT(*) as count FROM suppliers WHERE supplier_name = :supplierName";
        $params = [':supplierName' => $json['supplier_name']];
        
        if(isset($json['supplier_id'])) {
          $sql .= " AND supplier_id != :supplierId";
          $params[':supplierId'] = $json['supplier_id'];
        }
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
          $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'message' => 'Check completed',
          'data' => [
            'exists' => $rs['count'] > 0,
            'supplier_name' => $json['supplier_name']
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

// Handle request
if ($_SERVER['REQUEST_METHOD'] == 'GET'){
    $operation = $_GET['operation'];
    $json = isset($_GET['json']) ? $_GET['json'] : "";
} else if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if(isset($_POST['operation'])){
        $operation = $_POST['operation'];
        $json = isset($_POST['json']) ? $_POST['json'] : "";
    } else {
        if(isset($_GET['operation'])) {
            $operation = $_GET['operation'];
            $json = isset($_GET['json']) ? $_GET['json'] : "";
        } else {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            $operation = isset($_GET['operation']) ? $_GET['operation'] : '';
            $json = $input; 
        }
    }
}

$supplier = new Supplier();
  switch($operation){
    case "getAllSuppliers":
      echo $supplier->getAllSuppliers();
      break;
    case "insertSupplier":
      echo $supplier->insertSupplier($json);
      break;
    case "getSupplier":
      echo $supplier->getSupplier($json);
      break;
    case "updateSupplier":
      echo $supplier->updateSupplier($json);
      break;
    case "deleteSupplier":
      echo $supplier->deleteSupplier($json);
      break;
    case "getActiveSuppliers":
      echo $supplier->getActiveSuppliers();
      break;
    case "searchSuppliers":
      echo $supplier->searchSuppliers($json);
      break;
    case "checkSupplierName":
      echo $supplier->checkSupplierName($json);
      break;
    default:
      echo json_encode([
        'status' => 'error',
        'message' => 'Invalid operation'
      ]);
  }
?>