<?php
/**
 * Menu API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'categories':
            $categories = getMenuCategories();
            jsonResponse(['success' => true, 'categories' => $categories]);
            break;
            
        case 'items':
            $categoryId = $_GET['category_id'] ?? null;
            if ($categoryId) {
                $items = getMenuItemsByCategory($categoryId);
            } else {
                $items = getAllMenuItems();
            }
            jsonResponse(['success' => true, 'items' => $items]);
            break;
            
        case 'components':
            $itemId = $_GET['item_id'] ?? null;
            if (!$itemId) {
                jsonResponse(['success' => false, 'message' => 'Item ID required']);
            }
            $components = getMenuItemComponents($itemId);
            jsonResponse(['success' => true, 'components' => $components]);
            break;
            
        case 'item':
            $itemId = $_GET['item_id'] ?? null;
            if (!$itemId) {
                jsonResponse(['success' => false, 'message' => 'Item ID required']);
            }
            
            $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            
            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            
            $item['components'] = getMenuItemComponents($itemId);
            jsonResponse(['success' => true, 'item' => $item]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
