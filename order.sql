-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 31, 2026 at 09:02 PM
-- Server version: 8.0.45-0ubuntu0.22.04.1
-- PHP Version: 8.4.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `order`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `details` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 22:49:22'),
(2, 1, 'order_created', 'orders', 1, NULL, '105.163.1.73', '2025-11-29 22:51:16'),
(3, 1, 'logout', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 22:53:46'),
(4, 2, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 22:54:04'),
(5, 2, 'logout', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 22:54:24'),
(6, 4, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 22:54:52'),
(7, 5, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 22:58:45'),
(8, 5, 'logout', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 23:28:02'),
(9, 1, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-11-29 23:28:28'),
(10, 1, 'login', NULL, NULL, NULL, '93.150.196.137', '2025-11-30 06:15:06'),
(11, 1, 'login', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 09:23:26'),
(12, 1, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-12-01 11:04:03'),
(13, 1, 'logout', NULL, NULL, NULL, '105.163.1.73', '2025-12-01 11:06:13'),
(14, 2, 'login', NULL, NULL, NULL, '105.163.1.73', '2025-12-01 11:06:25'),
(15, 2, 'order_created', 'orders', 2, NULL, '105.163.1.73', '2025-12-01 11:06:43'),
(16, 1, 'logout', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 11:10:29'),
(17, 2, 'login', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 11:10:54'),
(18, 2, 'order_created', 'orders', 3, NULL, '93.47.95.167', '2025-12-01 11:13:09'),
(19, 2, 'sent_to_kitchen', 'orders', 3, NULL, '93.47.95.167', '2025-12-01 11:23:13'),
(20, 2, 'bill_requested', 'orders', 3, NULL, '93.47.95.167', '2025-12-01 11:23:55'),
(21, 2, 'logout', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 11:25:47'),
(22, 5, 'login', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 11:26:37'),
(23, 5, 'kitchen_status_update', 'order_items', 1, '{\"status\": \"in_kitchen\"}', '93.47.95.167', '2025-12-01 11:27:17'),
(24, 5, 'kitchen_status_update', 'order_items', 1, '{\"status\": \"ready\"}', '93.47.95.167', '2025-12-01 11:27:21'),
(25, 5, 'all_items_ready', 'orders', 3, NULL, '93.47.95.167', '2025-12-01 11:27:26'),
(26, 2, 'logout', NULL, NULL, NULL, '105.160.93.233', '2025-12-01 11:28:54'),
(27, 2, 'login', NULL, NULL, NULL, '105.160.93.233', '2025-12-01 11:29:15'),
(28, 2, 'logout', NULL, NULL, NULL, '105.160.93.233', '2025-12-01 11:29:43'),
(29, 4, 'login', NULL, NULL, NULL, '105.160.93.233', '2025-12-01 11:29:56'),
(30, 5, 'logout', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 11:30:03'),
(31, 4, 'login', NULL, NULL, NULL, '93.47.95.167', '2025-12-01 11:30:26'),
(32, 1, 'login', NULL, NULL, NULL, '185.165.240.86', '2025-12-04 17:36:07'),
(33, 1, 'logout', NULL, NULL, NULL, '185.165.240.86', '2025-12-04 17:38:38'),
(34, 1, 'login', NULL, NULL, NULL, '105.161.91.28', '2026-01-23 09:00:55'),
(35, 1, 'login', NULL, NULL, NULL, '212.216.254.158', '2026-01-23 09:01:26'),
(36, 1, 'bill_requested', 'orders', 3, NULL, '212.216.254.158', '2026-01-23 09:03:11'),
(37, 1, 'order_created', 'orders', 4, NULL, '212.216.254.158', '2026-01-23 09:14:48');

-- --------------------------------------------------------

--
-- Table structure for table `kitchen_tickets`
--

CREATE TABLE `kitchen_tickets` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `order_item_id` int NOT NULL,
  `status` enum('queued','in_progress','ready','served') DEFAULT 'queued',
  `priority` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `ready_at` timestamp NULL DEFAULT NULL,
  `served_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `kitchen_tickets`
--

INSERT INTO `kitchen_tickets` (`id`, `order_id`, `order_item_id`, `status`, `priority`, `created_at`, `started_at`, `ready_at`, `served_at`) VALUES
(1, 3, 2, 'ready', 0, '2025-12-01 11:23:13', NULL, NULL, NULL),
(2, 3, 3, 'ready', 0, '2025-12-01 11:23:13', NULL, NULL, NULL),
(3, 3, 4, 'ready', 0, '2025-12-01 11:23:13', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `menu_categories`
--

CREATE TABLE `menu_categories` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `sort_order` int DEFAULT '0',
  `allow_composition` tinyint(1) DEFAULT '1',
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `menu_categories`
--

INSERT INTO `menu_categories` (`id`, `name`, `description`, `sort_order`, `allow_composition`, `icon`, `color`, `active`, `created_at`) VALUES
(1, 'Appetizers', 'Start your meal right', 1, 1, 'utensils', '#e74c3c', 1, '2025-11-29 22:47:27'),
(2, 'First Course', 'Soups and starters', 2, 1, 'bowl-food', '#3498db', 1, '2025-11-29 22:47:27'),
(3, 'Main Course', 'Main dishes', 3, 1, 'drumstick-bite', '#2ecc71', 1, '2025-11-29 22:47:27'),
(4, 'Pizza', 'Fresh baked pizzas', 4, 1, 'pizza-slice', '#f39c12', 1, '2025-11-29 22:47:27'),
(5, 'Side Dishes', 'Perfect accompaniments', 5, 1, 'carrot', '#9b59b6', 1, '2025-11-29 22:47:27'),
(6, 'Desserts', 'Sweet endings', 6, 1, 'ice-cream', '#e91e63', 1, '2025-11-29 22:47:27'),
(7, 'Coffee', 'Hot beverages', 7, 0, 'mug-hot', '#795548', 1, '2025-11-29 22:47:27'),
(8, 'Soft Drinks', 'Refreshing drinks', 8, 0, 'glass-water', '#00bcd4', 1, '2025-11-29 22:47:27'),
(9, 'Wines', 'Fine selection', 9, 0, 'wine-glass', '#8e44ad', 1, '2025-11-29 22:47:27'),
(10, 'Spirits', 'Premium liquors', 10, 0, 'whiskey-glass', '#34495e', 1, '2025-11-29 22:47:27');

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `id` int NOT NULL,
  `category_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text,
  `base_price` decimal(10,2) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `preparation_time` int DEFAULT '15',
  `active` tinyint(1) DEFAULT '1',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`id`, `category_id`, `name`, `description`, `base_price`, `image_url`, `preparation_time`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'Italian Appetizer Mix', 'Selection of croquettes, arancini, and eggplant pizzas', '12.50', NULL, 15, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(2, 1, 'Bruschetta Trio', 'Tomato, mushroom, and olive tapenade', '8.50', NULL, 10, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(3, 1, 'Caprese Salad', 'Fresh mozzarella, tomatoes, basil', '9.00', NULL, 5, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(4, 2, 'Minestrone Soup', 'Traditional vegetable soup', '7.50', NULL, 10, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(5, 2, 'Caesar Salad', 'Romaine, croutons, parmesan', '10.00', NULL, 8, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(6, 3, 'Grilled Salmon', 'With lemon butter sauce', '22.00', NULL, 20, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(7, 3, 'Beef Tenderloin', '8oz premium cut', '28.00', NULL, 25, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(8, 3, 'Chicken Parmesan', 'Breaded chicken with marinara', '18.00', NULL, 18, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(9, 4, 'Margherita', 'Tomato, mozzarella, basil', '14.00', NULL, 15, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(10, 4, 'Quattro Formaggi', 'Four cheese pizza', '16.00', NULL, 15, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(11, 4, 'Pepperoni', 'Classic pepperoni pizza', '15.00', NULL, 15, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(12, 4, 'Vegetariana', 'Seasonal vegetables', '14.50', NULL, 15, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(13, 5, 'French Fries', 'Crispy golden fries', '4.50', NULL, 8, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(14, 5, 'Grilled Vegetables', 'Seasonal selection', '6.00', NULL, 10, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(15, 5, 'Mashed Potatoes', 'Creamy and buttery', '5.00', NULL, 5, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(16, 6, 'Tiramisu', 'Classic Italian dessert', '8.00', NULL, 5, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(17, 6, 'Panna Cotta', 'With berry sauce', '7.50', NULL, 5, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(18, 6, 'Chocolate Cake', 'Rich and decadent', '7.00', NULL, 5, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(19, 7, 'Espresso', 'Single shot', '2.50', NULL, 2, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(20, 7, 'Cappuccino', 'Espresso with steamed milk', '3.50', NULL, 3, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(21, 7, 'Latte', 'Espresso with lots of milk', '4.00', NULL, 3, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(22, 7, 'Americano', 'Espresso with hot water', '3.00', NULL, 2, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(23, 8, 'Coca-Cola', '330ml', '2.50', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(24, 8, 'Sprite', '330ml', '2.50', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(25, 8, 'Orange Juice', 'Fresh squeezed', '4.00', NULL, 2, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(26, 8, 'Mineral Water', '500ml', '2.00', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(27, 9, 'House Red Wine', 'Glass', '6.00', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(28, 9, 'House White Wine', 'Glass', '6.00', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(29, 9, 'Chianti Classico', 'Bottle', '28.00', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(30, 10, 'Whiskey', 'Single measure', '8.00', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(31, 10, 'Vodka', 'Single measure', '7.00', NULL, 1, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(32, 10, 'Gin & Tonic', 'Premium gin', '9.00', NULL, 2, 1, 0, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(33, 5, 'ff', 'ff', '4.00', NULL, 15, 1, 0, '2025-11-29 22:53:01', '2025-11-29 22:53:01'),
(34, 1, 'gg', 'gggg', '5.00', NULL, 15, 1, 0, '2025-11-29 22:53:25', '2025-11-29 22:53:25');

-- --------------------------------------------------------

--
-- Table structure for table `menu_item_components`
--

CREATE TABLE `menu_item_components` (
  `id` int NOT NULL,
  `menu_item_id` int NOT NULL,
  `component_name` varchar(100) NOT NULL,
  `is_default` tinyint(1) DEFAULT '1',
  `extra_price` decimal(10,2) DEFAULT '0.00',
  `removable` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `menu_item_components`
--

INSERT INTO `menu_item_components` (`id`, `menu_item_id`, `component_name`, `is_default`, `extra_price`, `removable`, `created_at`) VALUES
(1, 1, 'Croquettes', 1, '0.00', 1, '2025-11-29 22:47:27'),
(2, 1, 'Arancini', 1, '0.00', 1, '2025-11-29 22:47:27'),
(3, 1, 'Eggplant Pizzas', 1, '0.00', 1, '2025-11-29 22:47:27'),
(4, 1, 'Frittelle', 1, '0.00', 1, '2025-11-29 22:47:27'),
(5, 1, 'Extra Cheese', 0, '0.00', 0, '2025-11-29 22:47:27'),
(6, 9, 'Mozzarella', 1, '0.00', 1, '2025-11-29 22:47:27'),
(7, 9, 'Tomato Sauce', 1, '0.00', 1, '2025-11-29 22:47:27'),
(8, 9, 'Fresh Basil', 1, '0.00', 1, '2025-11-29 22:47:27'),
(9, 9, 'Extra Mozzarella', 0, '2.00', 0, '2025-11-29 22:47:27'),
(10, 9, 'Olives', 0, '1.50', 0, '2025-11-29 22:47:27');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `order_item_id` int DEFAULT NULL,
  `type` enum('dish_ready','new_order','table_paid','bill_requested','general') NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `message` text,
  `payload` json DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `order_item_id`, `type`, `title`, `message`, `payload`, `read_at`, `created_at`) VALUES
(1, 4, NULL, 'bill_requested', 'Bill Requested', 'Table T4 is ready to pay', '{\"order_id\": 3}', NULL, '2025-12-01 11:23:55'),
(2, 2, 1, 'dish_ready', 'Dish Ready!', 'Caesar Salad is ready for Table T2', '{\"order_id\": 2}', NULL, '2025-12-01 11:27:21'),
(3, 2, NULL, 'dish_ready', 'Order Ready!', 'All dishes for Table T4 are ready!', '{\"order_id\": 3}', NULL, '2025-12-01 11:27:26'),
(4, 4, NULL, 'bill_requested', 'Bill Requested', 'Table T4 is ready to pay', '{\"order_id\": 3}', NULL, '2026-01-23 09:03:11');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int NOT NULL,
  `order_number` varchar(20) NOT NULL,
  `table_id` int NOT NULL,
  `room_id` int NOT NULL,
  `waiter_id` int NOT NULL,
  `number_of_people` int DEFAULT '1',
  `cover_charge_per_person` decimal(10,2) DEFAULT '2.50',
  `status` enum('open','sent_to_kitchen','bill_requested','paid','cancelled') DEFAULT 'open',
  `subtotal` decimal(10,2) DEFAULT '0.00',
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `discount_type` enum('percent','fixed') DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) DEFAULT '0.00',
  `notes` text,
  `opened_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `table_id`, `room_id`, `waiter_id`, `number_of_people`, `cover_charge_per_person`, `status`, `subtotal`, `discount_amount`, `discount_type`, `discount_value`, `total`, `notes`, `opened_at`, `closed_at`, `created_at`, `updated_at`) VALUES
(1, 'ORD-20251130-434208', 1, 1, 1, 2, '2.50', 'open', '5.00', '0.00', NULL, '0.00', '5.00', NULL, '2025-11-29 22:51:16', NULL, '2025-11-29 22:51:16', '2025-11-30 06:15:31'),
(2, 'ORD-20251201-32BA9C', 2, 1, 2, 1, '2.50', 'open', '12.50', '0.00', NULL, '0.00', '12.50', NULL, '2025-12-01 11:06:43', NULL, '2025-12-01 11:06:43', '2025-12-01 11:07:07'),
(3, 'ORD-20251201-53DA4C', 4, 1, 2, 1, '2.50', 'bill_requested', '71.00', '0.00', NULL, '0.00', '71.00', NULL, '2025-12-01 11:13:09', NULL, '2025-12-01 11:13:09', '2025-12-01 11:30:38'),
(4, 'ORD-20260123-83D6A9', 5, 1, 1, 5, '2.50', 'open', '12.50', '0.00', NULL, '0.00', '12.50', NULL, '2026-01-23 09:14:48', NULL, '2026-01-23 09:14:48', '2026-01-23 09:14:48');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `menu_item_id` int NOT NULL,
  `quantity` int DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `notes` text,
  `status` enum('pending','in_kitchen','ready','served','cancelled') DEFAULT 'pending',
  `sent_to_kitchen_at` timestamp NULL DEFAULT NULL,
  `ready_at` timestamp NULL DEFAULT NULL,
  `served_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `menu_item_id`, `quantity`, `unit_price`, `total_price`, `notes`, `status`, `sent_to_kitchen_at`, `ready_at`, `served_at`, `created_at`, `updated_at`) VALUES
(1, 2, 5, 1, '10.00', '10.00', 'ff', 'ready', '2025-12-01 11:27:17', '2025-12-01 11:27:21', NULL, '2025-12-01 11:07:07', '2025-12-01 11:27:21'),
(2, 3, 2, 1, '8.50', '8.50', 'With onions', 'ready', '2025-12-01 11:23:13', '2025-12-01 11:27:26', NULL, '2025-12-01 11:15:47', '2025-12-01 11:27:26'),
(3, 3, 11, 3, '15.00', '45.00', '', 'ready', '2025-12-01 11:23:13', '2025-12-01 11:27:26', NULL, '2025-12-01 11:16:19', '2025-12-01 11:27:26'),
(4, 3, 11, 1, '15.00', '15.00', 'senza formaggio', 'ready', '2025-12-01 11:23:13', '2025-12-01 11:27:26', NULL, '2025-12-01 11:21:28', '2025-12-01 11:27:26');

-- --------------------------------------------------------

--
-- Table structure for table `order_item_modifications`
--

CREATE TABLE `order_item_modifications` (
  `id` int NOT NULL,
  `order_item_id` int NOT NULL,
  `component_name` varchar(100) NOT NULL,
  `action` enum('removed','added') NOT NULL,
  `extra_price` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('cash','card','mpesa','other') NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `received_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int NOT NULL,
  `workspace_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `sort_order` int DEFAULT '0',
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `workspace_id`, `name`, `sort_order`, `active`, `created_at`) VALUES
(1, 1, 'Main Hall', 1, 1, '2025-11-29 22:47:27'),
(2, 1, 'Terrace', 2, 1, '2025-11-29 22:47:27'),
(3, 1, 'VIP Room', 3, 1, '2025-11-29 22:47:27');

-- --------------------------------------------------------

--
-- Table structure for table `tables_restaurant`
--

CREATE TABLE `tables_restaurant` (
  `id` int NOT NULL,
  `room_id` int NOT NULL,
  `table_number` varchar(20) NOT NULL,
  `capacity` int DEFAULT '4',
  `status` enum('free','occupied','bill_requested','reserved') DEFAULT 'free',
  `current_order_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tables_restaurant`
--

INSERT INTO `tables_restaurant` (`id`, `room_id`, `table_number`, `capacity`, `status`, `current_order_id`, `created_at`) VALUES
(1, 1, 'T1', 4, 'occupied', 1, '2025-11-29 22:47:27'),
(2, 1, 'T2', 4, 'occupied', 2, '2025-11-29 22:47:27'),
(3, 1, 'T3', 6, 'free', NULL, '2025-11-29 22:47:27'),
(4, 1, 'T4', 2, 'bill_requested', 3, '2025-11-29 22:47:27'),
(5, 1, 'T5', 4, 'occupied', 4, '2025-11-29 22:47:27'),
(6, 1, 'T6', 8, 'free', NULL, '2025-11-29 22:47:27'),
(7, 2, 'TR1', 4, 'free', NULL, '2025-11-29 22:47:27'),
(8, 2, 'TR2', 4, 'free', NULL, '2025-11-29 22:47:27'),
(9, 2, 'TR3', 2, 'free', NULL, '2025-11-29 22:47:27'),
(10, 3, 'VIP1', 8, 'free', NULL, '2025-11-29 22:47:27'),
(11, 3, 'VIP2', 10, 'free', NULL, '2025-11-29 22:47:27');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','waiter','cashier','kitchen') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `email`, `phone`, `active`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin', 'admin@restaurant.com', NULL, 1, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(2, 'waiter1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Waiter', 'waiter', NULL, NULL, 1, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(3, 'waiter2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane Waiter', 'waiter', NULL, NULL, 1, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(4, 'cashier1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike Cashier', 'cashier', NULL, NULL, 1, '2025-11-29 22:47:27', '2025-11-29 22:47:27'),
(5, 'kitchen1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chef Kitchen', 'kitchen', NULL, NULL, 1, '2025-11-29 22:47:27', '2025-11-29 22:47:27');

-- --------------------------------------------------------

--
-- Table structure for table `workspaces`
--

CREATE TABLE `workspaces` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `cover_charge` decimal(10,2) DEFAULT '2.50',
  `printer_config` json DEFAULT NULL,
  `notification_settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `workspaces`
--

INSERT INTO `workspaces` (`id`, `name`, `cover_charge`, `printer_config`, `notification_settings`, `created_at`, `updated_at`) VALUES
(1, 'Main Restaurant', '2.50', NULL, NULL, '2025-11-29 22:47:27', '2025-11-29 22:47:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `kitchen_tickets`
--
ALTER TABLE `kitchen_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `order_item_id` (`order_item_id`),
  ADD KEY `idx_kitchen_tickets_status` (`status`);

--
-- Indexes for table `menu_categories`
--
ALTER TABLE `menu_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `menu_item_components`
--
ALTER TABLE `menu_item_components`
  ADD PRIMARY KEY (`id`),
  ADD KEY `menu_item_id` (`menu_item_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_id`,`read_at`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `waiter_id` (`waiter_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_table` (`table_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `menu_item_id` (`menu_item_id`),
  ADD KEY `idx_order_items_status` (`status`);

--
-- Indexes for table `order_item_modifications`
--
ALTER TABLE `order_item_modifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_item_id` (`order_item_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `received_by` (`received_by`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `workspace_id` (`workspace_id`);

--
-- Indexes for table `tables_restaurant`
--
ALTER TABLE `tables_restaurant`
  ADD PRIMARY KEY (`id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `idx_tables_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `workspaces`
--
ALTER TABLE `workspaces`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `kitchen_tickets`
--
ALTER TABLE `kitchen_tickets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `menu_categories`
--
ALTER TABLE `menu_categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `menu_item_components`
--
ALTER TABLE `menu_item_components`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `order_item_modifications`
--
ALTER TABLE `order_item_modifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tables_restaurant`
--
ALTER TABLE `tables_restaurant`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `workspaces`
--
ALTER TABLE `workspaces`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `kitchen_tickets`
--
ALTER TABLE `kitchen_tickets`
  ADD CONSTRAINT `kitchen_tickets_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kitchen_tickets_ibfk_2` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `menu_items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `menu_categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_item_components`
--
ALTER TABLE `menu_item_components`
  ADD CONSTRAINT `menu_item_components_ibfk_1` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`table_id`) REFERENCES `tables_restaurant` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`waiter_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`);

--
-- Constraints for table `order_item_modifications`
--
ALTER TABLE `order_item_modifications`
  ADD CONSTRAINT `order_item_modifications_ibfk_1` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tables_restaurant`
--
ALTER TABLE `tables_restaurant`
  ADD CONSTRAINT `tables_restaurant_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
