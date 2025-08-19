-- =====================
-- MASTER DATA
-- =====================

-- Categories
CREATE TABLE categories (
    category_id CHAR(36) PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE
);

-- Products
CREATE TABLE products (
    product_id CHAR(36) PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    barcode VARCHAR(50) NOT NULL UNIQUE,
    category_id CHAR(36),
    description TEXT,
    selling_price DECIMAL(10,2) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id)
);

-- Warehouses
CREATE TABLE warehouses (
    warehouse_id CHAR(36) PRIMARY KEY,
    warehouse_name VARCHAR(100) NOT NULL,
    location VARCHAR(255)
);

-- Stores
CREATE TABLE stores (
    store_id CHAR(36) PRIMARY KEY,
    store_name VARCHAR(100) NOT NULL,
    location VARCHAR(255)
);

-- Users
CREATE TABLE users (
    user_id CHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    role ENUM('admin','warehouse_manager','store_manager','cashier') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================
-- STOCK STORAGE
-- =====================

-- Warehouse Stock
CREATE TABLE warehouse_stock (
    warehouse_stock_id CHAR(36) PRIMARY KEY,
    warehouse_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    UNIQUE KEY (warehouse_id, product_id)
);

-- Store Stock
CREATE TABLE store_stock (
    store_stock_id CHAR(36) PRIMARY KEY,
    store_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    UNIQUE KEY (store_id, product_id)
);

-- =====================
-- STOCK RECEIVING (Supplier → Warehouse)
-- =====================

CREATE TABLE stock_receivings (
    receiving_id CHAR(36) PRIMARY KEY,
    warehouse_id CHAR(36) NOT NULL,
    received_by CHAR(36) NOT NULL,
    supplier_name VARCHAR(100),
    receiving_date DATE NOT NULL,
    status ENUM('pending','completed','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id),
    FOREIGN KEY (received_by) REFERENCES users(user_id)
);

CREATE TABLE stock_receiving_items (
    receiving_item_id CHAR(36) PRIMARY KEY,
    receiving_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL,
    batch_number VARCHAR(50),
    expiry_date DATE,
    FOREIGN KEY (receiving_id) REFERENCES stock_receivings(receiving_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- =====================
-- STOCK TRANSFER (Warehouse → Store, etc.)
-- =====================

CREATE TABLE stock_transfers (
    transfer_id CHAR(36) PRIMARY KEY,
    from_location_id CHAR(36) NOT NULL,
    to_location_id CHAR(36) NOT NULL,
    transfer_type ENUM('warehouse_to_store','warehouse_to_warehouse','store_to_store') NOT NULL,
    status ENUM('pending','approved','in_transit','completed','cancelled') DEFAULT 'pending',
    created_by CHAR(36) NOT NULL,
    transfer_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

CREATE TABLE stock_transfer_items (
    transfer_item_id CHAR(36) PRIMARY KEY,
    transfer_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity_requested INT NOT NULL,
    quantity_received INT DEFAULT 0,
    FOREIGN KEY (transfer_id) REFERENCES stock_transfers(transfer_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- =====================
-- SALES (Store → Customer)
-- =====================

CREATE TABLE sales (
    sale_id CHAR(36) PRIMARY KEY,
    store_id CHAR(36) NOT NULL,
    cashier_id CHAR(36) NOT NULL,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2),
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (cashier_id) REFERENCES users(user_id)
);

CREATE TABLE sale_items (
    sale_item_id CHAR(36) PRIMARY KEY,
    sale_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- =====================
-- SALES RETURN (Customer → Store)
-- =====================

CREATE TABLE sales_returns (
    sales_return_id CHAR(36) PRIMARY KEY,
    sale_id CHAR(36) NOT NULL,
    store_id CHAR(36) NOT NULL,
    returned_by_customer VARCHAR(100),
    return_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason TEXT,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
    FOREIGN KEY (store_id) REFERENCES stores(store_id)
);

CREATE TABLE sales_return_items (
    sales_return_item_id CHAR(36) PRIMARY KEY,
    sales_return_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL,
    FOREIGN KEY (sales_return_id) REFERENCES sales_returns(sales_return_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- =====================
-- PURCHASE ORDER RETURN (Warehouse → Supplier)
-- =====================

CREATE TABLE purchase_order_returns (
    po_return_id CHAR(36) PRIMARY KEY,
    warehouse_id CHAR(36) NOT NULL,
    returned_by CHAR(36) NOT NULL,
    supplier_name VARCHAR(100),
    return_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason TEXT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id),
    FOREIGN KEY (returned_by) REFERENCES users(user_id)
);

CREATE TABLE purchase_order_return_items (
    po_return_item_id CHAR(36) PRIMARY KEY,
    po_return_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL,
    FOREIGN KEY (po_return_id) REFERENCES purchase_order_returns(po_return_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- =====================
-- STOCK ADJUSTMENTS (Spoilage, Shrinkage, Manual Correction)
-- =====================

CREATE TABLE stock_adjustments (
    adjustment_id CHAR(36) PRIMARY KEY,
    location_type ENUM('warehouse','store') NOT NULL,
    location_id CHAR(36) NOT NULL,
    adjusted_by CHAR(36) NOT NULL,
    adjustment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason ENUM('spoilage','shrinkage','manual') NOT NULL,
    notes TEXT,
    FOREIGN KEY (adjusted_by) REFERENCES users(user_id)
);

CREATE TABLE stock_adjustment_items (
    adjustment_item_id CHAR(36) PRIMARY KEY,
    adjustment_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity_change INT NOT NULL, -- positive or negative
    FOREIGN KEY (adjustment_id) REFERENCES stock_adjustments(adjustment_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

<!-- -- Warehouse transfers
CREATE TABLE warehouse_transfers (
    transfer_id CHAR(36) PRIMARY KEY,
    from_warehouse_id CHAR(36) NOT NULL,
    to_warehouse_id CHAR(36) NOT NULL,
    transfer_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'in_transit', 'completed', 'cancelled') DEFAULT 'pending',
    transferred_by CHAR(36) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(warehouse_id),
    FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(warehouse_id),
    FOREIGN KEY (transferred_by) REFERENCES users(user_id)
);

-- Warehouse transfer items
CREATE TABLE warehouse_transfer_items (
    transfer_item_id CHAR(36) PRIMARY KEY,
    transfer_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL,
    received_quantity INT DEFAULT 0,
    batch_number VARCHAR(50),
    FOREIGN KEY (transfer_id) REFERENCES warehouse_transfers(transfer_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- Product stock table (inventory)
CREATE TABLE store_stock (
    store_stock_id CHAR(36) PRIMARY KEY,
    product_id CHAR(36) NOT NULL,
    warehouse_id CHAR(36),
    quantity INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id),
    UNIQUE KEY (product_id, warehouse_id)
);

-- Store Receipts (header)
CREATE TABLE store_transfers (
    receipt_id CHAR(36) PRIMARY KEY,
    store_id CHAR(36) NOT NULL,
    warehouse_id CHAR(36) NOT NULL, 
    transfer_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id),
);

-- Store Receipt Items (detail)
CREATE TABLE store_transfers_items (
    receipt_item_id CHAR(36) PRIMARY KEY,
    receipt_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    quantity INT NOT NULL,
    received_quantity INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (receipt_id) REFERENCES store_receipts(receipt_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
); -->
