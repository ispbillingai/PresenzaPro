-- Restaurant POS Database Schema
-- Run this to create all tables

CREATE DATABASE IF NOT EXISTS restaurant_pos;
USE restaurant_pos;

-- =====================================================
-- USERS & AUTHENTICATION
-- =====================================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'waiter', 'cashier', 'kitchen') NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- WORKSPACE & STRUCTURE
-- =====================================================

CREATE TABLE workspaces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    cover_charge DECIMAL(10,2) DEFAULT 2.50,
    printer_config JSON,
    notification_settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
);

CREATE TABLE tables_restaurant (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    table_number VARCHAR(20) NOT NULL,
    capacity INT DEFAULT 4,
    status ENUM('free', 'occupied', 'bill_requested', 'reserved') DEFAULT 'free',
    current_order_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

-- =====================================================
-- MENU & COMPOSITIONS
-- =====================================================

CREATE TABLE menu_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0,
    allow_composition TINYINT(1) DEFAULT 1,
    icon VARCHAR(50),
    color VARCHAR(20),
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    base_price DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(255),
    preparation_time INT DEFAULT 15,
    active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE CASCADE
);

CREATE TABLE menu_item_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    is_default TINYINT(1) DEFAULT 1,
    extra_price DECIMAL(10,2) DEFAULT 0.00,
    removable TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
);

-- =====================================================
-- ORDERS & ORDER ITEMS
-- =====================================================

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL UNIQUE,
    table_id INT NOT NULL,
    room_id INT NOT NULL,
    waiter_id INT NOT NULL,
    number_of_people INT DEFAULT 1,
    cover_charge_per_person DECIMAL(10,2) DEFAULT 2.50,
    status ENUM('open', 'sent_to_kitchen', 'bill_requested', 'paid', 'cancelled') DEFAULT 'open',
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    discount_type ENUM('percent', 'fixed') NULL,
    discount_value DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables_restaurant(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (waiter_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    notes TEXT,
    status ENUM('pending', 'in_kitchen', 'ready', 'served', 'cancelled') DEFAULT 'pending',
    sent_to_kitchen_at TIMESTAMP NULL,
    ready_at TIMESTAMP NULL,
    served_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
);

CREATE TABLE order_item_modifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_item_id INT NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    action ENUM('removed', 'added') NOT NULL,
    extra_price DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
);

-- =====================================================
-- PAYMENTS & DISCOUNTS
-- =====================================================

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    method ENUM('cash', 'card', 'mpesa', 'other') NOT NULL,
    reference VARCHAR(100),
    received_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
);

-- =====================================================
-- KITCHEN & NOTIFICATIONS
-- =====================================================

CREATE TABLE kitchen_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    status ENUM('queued', 'in_progress', 'ready', 'served') DEFAULT 'queued',
    priority INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    ready_at TIMESTAMP NULL,
    served_at TIMESTAMP NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_item_id INT,
    type ENUM('dish_ready', 'new_order', 'table_paid', 'bill_requested', 'general') NOT NULL,
    title VARCHAR(200),
    message TEXT,
    payload JSON,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =====================================================
-- ACTIVITY LOG
-- =====================================================

CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    details JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================================================
-- INDEXES FOR PERFORMANCE
-- =====================================================

CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_table ON orders(table_id);
CREATE INDEX idx_order_items_status ON order_items(status);
CREATE INDEX idx_kitchen_tickets_status ON kitchen_tickets(status);
CREATE INDEX idx_tables_status ON tables_restaurant(status);
CREATE INDEX idx_notifications_user ON notifications(user_id, read_at);

-- =====================================================
-- INSERT DEFAULT DATA
-- =====================================================

-- Default workspace
INSERT INTO workspaces (name, cover_charge) VALUES ('Main Restaurant', 2.50);

-- Default admin user (password: admin123)
INSERT INTO users (username, password, full_name, role, email) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin', 'admin@restaurant.com');

-- Sample users (password for all: password123)
INSERT INTO users (username, password, full_name, role) VALUES 
('waiter1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Waiter', 'waiter'),
('waiter2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Waiter', 'waiter'),
('cashier1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike Cashier', 'cashier'),
('kitchen1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chef Kitchen', 'kitchen');

-- Default rooms
INSERT INTO rooms (workspace_id, name, sort_order) VALUES 
(1, 'Main Hall', 1),
(1, 'Terrace', 2),
(1, 'VIP Room', 3);

-- Default tables
INSERT INTO tables_restaurant (room_id, table_number, capacity) VALUES 
(1, 'T1', 4), (1, 'T2', 4), (1, 'T3', 6), (1, 'T4', 2), (1, 'T5', 4), (1, 'T6', 8),
(2, 'TR1', 4), (2, 'TR2', 4), (2, 'TR3', 2),
(3, 'VIP1', 8), (3, 'VIP2', 10);

-- Menu categories
INSERT INTO menu_categories (name, description, sort_order, allow_composition, icon, color) VALUES 
('Appetizers', 'Start your meal right', 1, 1, 'utensils', '#e74c3c'),
('First Course', 'Soups and starters', 2, 1, 'bowl-food', '#3498db'),
('Main Course', 'Main dishes', 3, 1, 'drumstick-bite', '#2ecc71'),
('Pizza', 'Fresh baked pizzas', 4, 1, 'pizza-slice', '#f39c12'),
('Side Dishes', 'Perfect accompaniments', 5, 1, 'carrot', '#9b59b6'),
('Desserts', 'Sweet endings', 6, 1, 'ice-cream', '#e91e63'),
('Coffee', 'Hot beverages', 7, 0, 'mug-hot', '#795548'),
('Soft Drinks', 'Refreshing drinks', 8, 0, 'glass-water', '#00bcd4'),
('Wines', 'Fine selection', 9, 0, 'wine-glass', '#8e44ad'),
('Spirits', 'Premium liquors', 10, 0, 'whiskey-glass', '#34495e');

-- Sample menu items
INSERT INTO menu_items (category_id, name, description, base_price, preparation_time) VALUES 
-- Appetizers
(1, 'Italian Appetizer Mix', 'Selection of croquettes, arancini, and eggplant pizzas', 12.50, 15),
(1, 'Bruschetta Trio', 'Tomato, mushroom, and olive tapenade', 8.50, 10),
(1, 'Caprese Salad', 'Fresh mozzarella, tomatoes, basil', 9.00, 5),
-- First Course
(2, 'Minestrone Soup', 'Traditional vegetable soup', 7.50, 10),
(2, 'Caesar Salad', 'Romaine, croutons, parmesan', 10.00, 8),
-- Main Course
(3, 'Grilled Salmon', 'With lemon butter sauce', 22.00, 20),
(3, 'Beef Tenderloin', '8oz premium cut', 28.00, 25),
(3, 'Chicken Parmesan', 'Breaded chicken with marinara', 18.00, 18),
-- Pizza
(4, 'Margherita', 'Tomato, mozzarella, basil', 14.00, 15),
(4, 'Quattro Formaggi', 'Four cheese pizza', 16.00, 15),
(4, 'Pepperoni', 'Classic pepperoni pizza', 15.00, 15),
(4, 'Vegetariana', 'Seasonal vegetables', 14.50, 15),
-- Side Dishes
(5, 'French Fries', 'Crispy golden fries', 4.50, 8),
(5, 'Grilled Vegetables', 'Seasonal selection', 6.00, 10),
(5, 'Mashed Potatoes', 'Creamy and buttery', 5.00, 5),
-- Desserts
(6, 'Tiramisu', 'Classic Italian dessert', 8.00, 5),
(6, 'Panna Cotta', 'With berry sauce', 7.50, 5),
(6, 'Chocolate Cake', 'Rich and decadent', 7.00, 5),
-- Coffee
(7, 'Espresso', 'Single shot', 2.50, 2),
(7, 'Cappuccino', 'Espresso with steamed milk', 3.50, 3),
(7, 'Latte', 'Espresso with lots of milk', 4.00, 3),
(7, 'Americano', 'Espresso with hot water', 3.00, 2),
-- Soft Drinks
(8, 'Coca-Cola', '330ml', 2.50, 1),
(8, 'Sprite', '330ml', 2.50, 1),
(8, 'Orange Juice', 'Fresh squeezed', 4.00, 2),
(8, 'Mineral Water', '500ml', 2.00, 1),
-- Wines
(9, 'House Red Wine', 'Glass', 6.00, 1),
(9, 'House White Wine', 'Glass', 6.00, 1),
(9, 'Chianti Classico', 'Bottle', 28.00, 1),
-- Spirits
(10, 'Whiskey', 'Single measure', 8.00, 1),
(10, 'Vodka', 'Single measure', 7.00, 1),
(10, 'Gin & Tonic', 'Premium gin', 9.00, 2);

-- Sample components for Italian Appetizer Mix
INSERT INTO menu_item_components (menu_item_id, component_name, is_default, removable) VALUES 
(1, 'Croquettes', 1, 1),
(1, 'Arancini', 1, 1),
(1, 'Eggplant Pizzas', 1, 1),
(1, 'Frittelle', 1, 1),
(1, 'Extra Cheese', 0, 0);

-- Components for Margherita Pizza
INSERT INTO menu_item_components (menu_item_id, component_name, is_default, extra_price, removable) VALUES 
(9, 'Mozzarella', 1, 0, 1),
(9, 'Tomato Sauce', 1, 0, 1),
(9, 'Fresh Basil', 1, 0, 1),
(9, 'Extra Mozzarella', 0, 2.00, 0),
(9, 'Olives', 0, 1.50, 0);
