<?php
/**
 * Customer Menu - Public Facing
 * Restaurant POS System
 * Beautiful, mobile-friendly menu with multi-language support
 *
 * Which menu (the links to share in each menu's admin page):
 *   (default) Menu a tavola · ?m=online Menu online · ?m=till Menu cassa
 */

require_once __DIR__ . '/config/database.php';

// ==================== TRANSLATIONS ====================
$translations = [
    'en' => [
        'lang_name' => 'English',
        'flag' => '🇬🇧',
        'est' => 'Est.',
        'discover' => 'Discover our carefully crafted dishes made with the finest ingredients and served with passion',
        'menu' => 'Menu',
        'delicious_options' => 'delicious options',
        'quick_serve' => 'Quick Serve',
        'min' => 'min',
        'fresh_daily' => 'Fresh Daily',
        'popular' => 'Popular',
        'chef_special' => "Chef's Special",
        'add_to_order' => 'Add to order',
        'thank_you' => 'Thank you for choosing us. We look forward to serving you an unforgettable dining experience.',
        'open_daily' => 'Open Daily',
        'all_rights' => 'All rights reserved',
        'powered_by' => 'Powered by Focacciami',
        'menu_coming_soon' => 'Menu Coming Soon',
        'preparing' => "We're preparing something delicious for you!",
        'currency' => '€',
        'categories' => 'Categories',
        'our_menu' => 'Our Menu',
        'view_menu' => 'View Menu',
        'appetizers' => 'Appetizers',
        'first_course' => 'First Course',
        'main_course' => 'Main Course',
        'pizza' => 'Pizza',
        'side_dishes' => 'Side Dishes',
        'desserts' => 'Desserts',
        'coffee' => 'Coffee',
        'soft_drinks' => 'Soft Drinks',
        'wines' => 'Wines',
        'spirits' => 'Spirits',
    ],
    'it' => [
        'lang_name' => 'Italiano',
        'flag' => '🇮🇹',
        'est' => 'Fondato',
        'discover' => 'Scopri i nostri piatti preparati con cura, realizzati con ingredienti pregiati e serviti con passione',
        'menu' => 'Menù',
        'delicious_options' => 'deliziose opzioni',
        'quick_serve' => 'Servizio Rapido',
        'min' => 'min',
        'fresh_daily' => 'Fresco Ogni Giorno',
        'popular' => 'Popolare',
        'chef_special' => 'Specialità dello Chef',
        'add_to_order' => "Aggiungi all'ordine",
        'thank_you' => "Grazie per averci scelto. Non vediamo l'ora di offrirti un'esperienza culinaria indimenticabile.",
        'open_daily' => 'Aperto Tutti i Giorni',
        'all_rights' => 'Tutti i diritti riservati',
        'powered_by' => 'Powered by Focacciami',
        'menu_coming_soon' => 'Menù in Arrivo',
        'preparing' => 'Stiamo preparando qualcosa di delizioso per te!',
        'currency' => '€',
        'categories' => 'Categorie',
        'our_menu' => 'Il Nostro Menù',
        'view_menu' => 'Vedi Menù',
        'appetizers' => 'Antipasti',
        'first_course' => 'Primi Piatti',
        'main_course' => 'Secondi Piatti',
        'pizza' => 'Pizza',
        'side_dishes' => 'Contorni',
        'desserts' => 'Dolci',
        'coffee' => 'Caffè',
        'soft_drinks' => 'Bevande',
        'wines' => 'Vini',
        'spirits' => 'Liquori',
    ],
    'fr' => [
        'lang_name' => 'Français',
        'flag' => '🇫🇷',
        'est' => 'Fondé en',
        'discover' => 'Découvrez nos plats soigneusement élaborés avec les meilleurs ingrédients et servis avec passion',
        'menu' => 'Menu',
        'delicious_options' => 'options délicieuses',
        'quick_serve' => 'Service Rapide',
        'min' => 'min',
        'fresh_daily' => 'Frais du Jour',
        'popular' => 'Populaire',
        'chef_special' => 'Spécialité du Chef',
        'add_to_order' => 'Ajouter à la commande',
        'thank_you' => 'Merci de nous avoir choisis. Nous avons hâte de vous offrir une expérience culinaire inoubliable.',
        'open_daily' => 'Ouvert Tous les Jours',
        'all_rights' => 'Tous droits réservés',
        'powered_by' => 'Propulsé par RestoPOS',
        'menu_coming_soon' => 'Menu à Venir',
        'preparing' => 'Nous préparons quelque chose de délicieux pour vous!',
        'currency' => '€',
        'categories' => 'Catégories',
        'our_menu' => 'Notre Menu',
        'view_menu' => 'Voir le Menu',
        'appetizers' => 'Entrées',
        'first_course' => 'Premier Plat',
        'main_course' => 'Plat Principal',
        'pizza' => 'Pizza',
        'side_dishes' => 'Accompagnements',
        'desserts' => 'Desserts',
        'coffee' => 'Café',
        'soft_drinks' => 'Boissons',
        'wines' => 'Vins',
        'spirits' => 'Spiritueux',
    ],
    'de' => [
        'lang_name' => 'Deutsch',
        'flag' => '🇩🇪',
        'est' => 'Gegründet',
        'discover' => 'Entdecken Sie unsere sorgfältig zubereiteten Gerichte aus feinsten Zutaten, serviert mit Leidenschaft',
        'menu' => 'Speisekarte',
        'delicious_options' => 'köstliche Optionen',
        'quick_serve' => 'Schneller Service',
        'min' => 'Min',
        'fresh_daily' => 'Täglich Frisch',
        'popular' => 'Beliebt',
        'chef_special' => 'Empfehlung des Küchenchefs',
        'add_to_order' => 'Zur Bestellung hinzufügen',
        'thank_you' => 'Vielen Dank, dass Sie sich für uns entschieden haben. Wir freuen uns darauf, Ihnen ein unvergessliches kulinarisches Erlebnis zu bieten.',
        'open_daily' => 'Täglich Geöffnet',
        'all_rights' => 'Alle Rechte vorbehalten',
        'powered_by' => 'Powered by Focacciami',
        'menu_coming_soon' => 'Speisekarte Kommt Bald',
        'preparing' => 'Wir bereiten etwas Köstliches für Sie vor!',
        'currency' => '€',
        'categories' => 'Kategorien',
        'our_menu' => 'Unsere Speisekarte',
        'view_menu' => 'Speisekarte Ansehen',
        'appetizers' => 'Vorspeisen',
        'first_course' => 'Erster Gang',
        'main_course' => 'Hauptgericht',
        'pizza' => 'Pizza',
        'side_dishes' => 'Beilagen',
        'desserts' => 'Nachspeisen',
        'coffee' => 'Kaffee',
        'soft_drinks' => 'Erfrischungsgetränke',
        'wines' => 'Weine',
        'spirits' => 'Spirituosen',
    ],
    'es' => [
        'lang_name' => 'Español',
        'flag' => '🇪🇸',
        'est' => 'Fundado en',
        'discover' => 'Descubre nuestros platos cuidadosamente elaborados con los mejores ingredientes y servidos con pasión',
        'menu' => 'Menú',
        'delicious_options' => 'opciones deliciosas',
        'quick_serve' => 'Servicio Rápido',
        'min' => 'min',
        'fresh_daily' => 'Fresco del Día',
        'popular' => 'Popular',
        'chef_special' => 'Especialidad del Chef',
        'add_to_order' => 'Añadir al pedido',
        'thank_you' => 'Gracias por elegirnos. Esperamos ofrecerte una experiencia gastronómica inolvidable.',
        'open_daily' => 'Abierto Todos los Días',
        'all_rights' => 'Todos los derechos reservados',
        'powered_by' => 'Powered by Focacciami',
        'menu_coming_soon' => 'Menú Próximamente',
        'preparing' => '¡Estamos preparando algo delicioso para ti!',
        'currency' => '€',
        'categories' => 'Categorías',
        'our_menu' => 'Nuestro Menú',
        'view_menu' => 'Ver Menú',
        'appetizers' => 'Entrantes',
        'first_course' => 'Primer Plato',
        'main_course' => 'Plato Principal',
        'pizza' => 'Pizza',
        'side_dishes' => 'Guarniciones',
        'desserts' => 'Postres',
        'coffee' => 'Café',
        'soft_drinks' => 'Refrescos',
        'wines' => 'Vinos',
        'spirits' => 'Licores',
    ],
];

// Category name translations
$categoryTranslations = [
    'en' => [
        'Appetizers' => 'Appetizers',
        'First Course' => 'First Course',
        'Main Course' => 'Main Course',
        'Pizza' => 'Pizza',
        'Side Dishes' => 'Side Dishes',
        'Desserts' => 'Desserts',
        'Coffee' => 'Coffee',
        'Soft Drinks' => 'Soft Drinks',
        'Wines' => 'Wines',
        'Spirits' => 'Spirits',
    ],
    'it' => [
        'Appetizers' => 'Antipasti',
        'First Course' => 'Primi Piatti',
        'Main Course' => 'Secondi Piatti',
        'Pizza' => 'Pizza',
        'Side Dishes' => 'Contorni',
        'Desserts' => 'Dolci',
        'Coffee' => 'Caffè',
        'Soft Drinks' => 'Bevande',
        'Wines' => 'Vini',
        'Spirits' => 'Liquori',
    ],
    'fr' => [
        'Appetizers' => 'Entrées',
        'First Course' => 'Premier Plat',
        'Main Course' => 'Plat Principal',
        'Pizza' => 'Pizza',
        'Side Dishes' => 'Accompagnements',
        'Desserts' => 'Desserts',
        'Coffee' => 'Café',
        'Soft Drinks' => 'Boissons',
        'Wines' => 'Vins',
        'Spirits' => 'Spiritueux',
    ],
    'de' => [
        'Appetizers' => 'Vorspeisen',
        'First Course' => 'Erster Gang',
        'Main Course' => 'Hauptgericht',
        'Pizza' => 'Pizza',
        'Side Dishes' => 'Beilagen',
        'Desserts' => 'Nachspeisen',
        'Coffee' => 'Kaffee',
        'Soft Drinks' => 'Erfrischungsgetränke',
        'Wines' => 'Weine',
        'Spirits' => 'Spirituosen',
    ],
    'es' => [
        'Appetizers' => 'Entrantes',
        'First Course' => 'Primer Plato',
        'Main Course' => 'Plato Principal',
        'Pizza' => 'Pizza',
        'Side Dishes' => 'Guarniciones',
        'Desserts' => 'Postres',
        'Coffee' => 'Café',
        'Soft Drinks' => 'Refrescos',
        'Wines' => 'Vinos',
        'Spirits' => 'Licores',
    ],
];

// Get current language
$lang = $_GET['lang'] ?? $_COOKIE['menu_lang'] ?? 'en';
if (!isset($translations[$lang])) {
    $lang = 'en';
}

// Set cookie for language preference
if (isset($_GET['lang'])) {
    setcookie('menu_lang', $lang, time() + (365 * 24 * 60 * 60), '/');
}

$t = $translations[$lang];
$catTrans = $categoryTranslations[$lang];

// Helper function to translate
function __($key) {
    global $t;
    return $t[$key] ?? $key;
}

function translateCategory($name) {
    global $catTrans;
    return $catTrans[$name] ?? $name;
}

try {
    $pdo = getDBConnection();

    // Get restaurant info
    $stmt = $pdo->query("SELECT * FROM workspaces LIMIT 1");
    $restaurant = $stmt->fetch();

    // Which menu: the tables' (default), the online one or the till's.
    $menuKind  = in_array($_GET['m'] ?? '', ['online', 'till'], true) ? $_GET['m'] : 'table';
    $menuWhere = ['table' => 'mc.till_only = 0 AND mc.online_only = 0', 'online' => 'mc.online_only = 1', 'till' => 'mc.till_only = 1'][$menuKind];
    $freeItem  = (int) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'till_free_item'")->fetchColumn() ?: 0); // the keypad's hidden "Varie"

    // Get all active categories
    $stmt = $pdo->query("SELECT * FROM menu_categories mc WHERE mc.active = 1 AND $menuWhere ORDER BY mc.sort_order");
    $categories = $stmt->fetchAll();

    // Get all menu items with categories
    $stmt = $pdo->query("
        SELECT mi.*, mc.name as category_name, mc.id as category_id, mc.icon as category_icon
        FROM menu_items mi
        JOIN menu_categories mc ON mi.category_id = mc.id
        WHERE mi.active = 1 AND mc.active = 1 AND $menuWhere AND mi.id <> $freeItem
        ORDER BY mc.sort_order, mi.sort_order, mi.name
    ");
    $allItems = $stmt->fetchAll();
} catch (Exception $e) {
    $restaurant = ['name' => 'Our Restaurant'];
    $categories = [];
    $allItems = [];
}

// Group items by category
$menuByCategory = [];
foreach ($allItems as $item) {
    $catId = $item['category_id'];
    if (!isset($menuByCategory[$catId])) {
        $menuByCategory[$catId] = [
            'name' => $item['category_name'],
            'icon' => $item['category_icon'] ?? 'utensils',
            'items' => []
        ];
    }
    $menuByCategory[$catId]['items'][] = $item;
}

// Food images by category
$categoryImages = [
    'Appetizers' => [
        'https://images.unsplash.com/photo-1541014741259-de529411b96a?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1608039829572-9b9fa1ba2af5?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1599021419847-d8a7a6aba5b4?w=400&h=300&fit=crop',
    ],
    'First Course' => [
        'https://images.unsplash.com/photo-1547592166-23ac45744acd?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1588013273468-315fd88ea34c?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?w=400&h=300&fit=crop',
    ],
    'Main Course' => [
        'https://images.unsplash.com/photo-1544025162-d76694265947?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1432139555190-58524dae6a55?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=400&h=300&fit=crop',
    ],
    'Pizza' => [
        'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1593560708920-61dd98c46a4e?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1604382355076-af4b0eb60143?w=400&h=300&fit=crop',
    ],
    'Side Dishes' => [
        'https://images.unsplash.com/photo-1518013431117-eb1465fa5752?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1546793665-c74683f339c1?w=400&h=300&fit=crop',
    ],
    'Desserts' => [
        'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1571115177098-24ec42ed204d?w=400&h=300&fit=crop',
    ],
    'Coffee' => [
        'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=400&h=300&fit=crop',
    ],
    'Soft Drinks' => [
        'https://images.unsplash.com/photo-1437418747212-8d9709afab22?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1527960471264-932f39eb5846?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?w=400&h=300&fit=crop',
    ],
    'Wines' => [
        'https://images.unsplash.com/photo-1510812431401-41d2bd2722f3?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1506377247377-2a5b3b417ebb?w=400&h=300&fit=crop',
    ],
    'Spirits' => [
        'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?w=400&h=300&fit=crop',
        'https://images.unsplash.com/photo-1470337458703-46ad1756a187?w=400&h=300&fit=crop',
    ],
];

// Default food images
$defaultImages = [
    'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1476224203421-9ac39bcb3327?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=400&h=300&fit=crop',
];

function getItemImage($item, $index, $categoryImages, $defaultImages) {
    if (!empty($item['image_url'])) {
        return $item['image_url'];
    }
    $catName = $item['category_name'] ?? '';
    if (isset($categoryImages[$catName])) {
        $images = $categoryImages[$catName];
        return $images[$index % count($images)];
    }
    return $defaultImages[$index % count($defaultImages)];
}

$restaurantName = $restaurant['name'] ?? 'Our Restaurant';
$currency = __('currency');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= __('menu') ?> - <?= htmlspecialchars($restaurantName) ?></title>
    <meta name="description" content="<?= __('discover') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold: #b8860b;
            --gold-light: #daa520;
            --gold-dark: #8b6914;
            --black: #0a0a0a;
            --dark: #1a1a1a;
            --charcoal: #2d2d2d;
            --cream: #faf8f5;
            --beige: #f5f1eb;
            --white: #ffffff;
            --text-dark: #1a1a1a;
            --text-medium: #4a4a4a;
            --text-light: #7a7a7a;
            --success: #2e7d32;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.12);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.16);
            --shadow-xl: 0 20px 60px rgba(0,0,0,0.2);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            font-size: 16px;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--cream);
            color: var(--text-dark);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* ==================== LANGUAGE SELECTOR ==================== */
        .language-selector {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
        }

        .lang-toggle {
            background: var(--white);
            border: none;
            padding: 10px 16px;
            border-radius: 30px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .lang-toggle:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .lang-toggle .flag {
            font-size: 1.2rem;
        }

        .lang-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 10px;
            background: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            min-width: 160px;
        }

        .language-selector:hover .lang-dropdown,
        .language-selector.active .lang-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .lang-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text-dark);
            font-size: 0.9rem;
            transition: var(--transition);
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .lang-option:last-child {
            border-bottom: none;
        }

        .lang-option:hover {
            background: var(--cream);
            color: var(--gold-dark);
        }

        .lang-option.active {
            background: var(--gold);
            color: var(--white);
        }

        .lang-option .flag {
            font-size: 1.3rem;
        }

        /* ==================== HERO ==================== */
        .hero {
            background: var(--black);
            color: var(--white);
            position: relative;
            min-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=1920&q=80') center/cover no-repeat;
            opacity: 0.4;
            transform: scale(1.1);
            animation: slowZoom 20s ease-in-out infinite alternate;
        }

        @keyframes slowZoom {
            from { transform: scale(1.1); }
            to { transform: scale(1.2); }
        }

        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 100%);
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 40px 20px;
            max-width: 900px;
        }

        .hero-badge {
            display: inline-block;
            background: var(--gold);
            color: var(--black);
            padding: 8px 24px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 4px;
            text-transform: uppercase;
            border-radius: 30px;
            margin-bottom: 30px;
        }

        .hero h1.hero-logo { background: rgba(255,255,255,.94); border-radius: 22px; padding: 14px 22px; display: table; margin: 18px auto 22px; box-shadow: 0 10px 40px rgba(0,0,0,.35); letter-spacing: 0; }
        .hero h1.hero-logo img { display: block; width: min(420px, 70vw); height: auto; }
        .hero h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(3rem, 10vw, 6rem);
            font-weight: 600;
            line-height: 1.1;
            margin-bottom: 20px;
            letter-spacing: -1px;
        }

        .hero-divider {
            width: 60px;
            height: 2px;
            background: var(--gold);
            margin: 30px auto;
        }

        .hero p {
            font-size: 1.1rem;
            font-weight: 300;
            opacity: 0.9;
            max-width: 500px;
            margin: 0 auto 40px;
            line-height: 1.8;
        }

        .scroll-indicator {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            color: var(--gold);
            font-size: 1.5rem;
            animation: bounce 2s infinite;
            cursor: pointer;
            text-decoration: none;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateX(-50%) translateY(0); }
            40% { transform: translateX(-50%) translateY(-10px); }
            60% { transform: translateX(-50%) translateY(-5px); }
        }

        /* ==================== CATEGORY NAV ==================== */
        .category-nav {
            background: var(--white);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-sm);
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .category-nav-inner {
            display: flex;
            overflow-x: auto;
            max-width: 1400px;
            margin: 0 auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            gap: 5px;
            padding: 5px 15px;
        }

        .category-nav-inner::-webkit-scrollbar {
            display: none;
        }

        .category-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 14px 20px;
            text-decoration: none;
            color: var(--text-medium);
            font-weight: 500;
            font-size: 0.85rem;
            white-space: nowrap;
            transition: var(--transition);
            border-radius: var(--radius-sm);
            position: relative;
        }

        .category-link::after {
            content: '';
            position: absolute;
            bottom: 8px;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--gold);
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .category-link:hover,
        .category-link.active {
            color: var(--gold-dark);
        }

        .category-link:hover::after,
        .category-link.active::after {
            width: calc(100% - 40px);
        }

        .category-link i {
            font-size: 1rem;
            opacity: 0.8;
        }

        /* ==================== MENU SECTIONS ==================== */
        .menu-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 60px 20px 100px;
        }

        .menu-section {
            margin-bottom: 80px;
            scroll-margin-top: 80px;
        }

        .section-header {
            text-align: center;
            margin-bottom: 50px;
            position: relative;
        }

        .section-icon {
            width: 60px;
            height: 60px;
            background: var(--gold);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin: 0 auto 20px;
        }

        .section-header h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .section-header p {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .section-line {
            width: 50px;
            height: 2px;
            background: var(--gold);
            margin: 20px auto 0;
        }

        /* ==================== MENU GRID ==================== */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 30px;
        }

        .menu-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            cursor: pointer;
            position: relative;
        }

        .menu-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }

        .menu-card-image {
            position: relative;
            height: 200px;
            overflow: hidden;
        }

        .dish-play { position: absolute; inset: 0; margin: auto; width: 64px; height: 64px; border-radius: 50%; border: 0; z-index: 2;
                     background: rgba(0,0,0,.55); color: #fff; font-size: 1.4rem; cursor: pointer; display: flex; align-items: center; justify-content: center; padding-left: 4px; }
        .dish-play:hover { background: rgba(0,0,0,.75); }
        .menu-card-image video { width: 100%; height: 100%; object-fit: cover; background: #000; position: relative; z-index: 3; }
        .menu-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }

        .menu-card:hover .menu-card-image img {
            transform: scale(1.1);
        }

        .menu-card-image::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60%;
            background: linear-gradient(transparent, rgba(0,0,0,0.4));
            pointer-events: none;
        }

        .menu-card-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--gold);
            color: var(--white);
            padding: 6px 14px;
            font-size: 0.7rem;
            font-weight: 700;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
            z-index: 1;
        }

        .menu-card-content {
            padding: 25px;
        }

        .menu-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            gap: 15px;
        }

        .menu-card h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text-dark);
            line-height: 1.3;
        }

        .menu-card-price {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gold-dark);
            white-space: nowrap;
        }

        .menu-card-desc {
            color: var(--text-light);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 15px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .menu-card-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0,0,0,0.06);
        }

        .menu-card-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .menu-card-meta i {
            color: var(--gold);
            font-size: 0.85rem;
        }

        /* ==================== ORDER BUTTON ==================== */
        .order-btn {
            display: none;
            position: absolute;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: var(--gold);
            color: var(--white);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition);
            box-shadow: var(--shadow-md);
            z-index: 2;
        }

        .menu-card:hover .order-btn {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .order-btn:hover {
            background: var(--gold-dark);
            transform: scale(1.1);
        }

        /* ==================== FOOTER ==================== */
        .menu-footer {
            background: var(--dark);
            color: var(--white);
            padding: 60px 20px;
            text-align: center;
        }

        .menu-footer h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem;
            margin-bottom: 20px;
        }

        .menu-footer p {
            color: rgba(255,255,255,0.7);
            margin-bottom: 30px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .footer-info {
            display: flex;
            justify-content: center;
            gap: 40px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .footer-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255,255,255,0.8);
        }

        .footer-info-item i {
            color: var(--gold);
            font-size: 1.2rem;
        }

        .footer-social {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }

        .footer-social a {
            width: 45px;
            height: 45px;
            background: rgba(255,255,255,0.1);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-social a:hover {
            background: var(--gold);
            transform: translateY(-3px);
        }

        .footer-bottom {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
            color: rgba(255,255,255,0.5);
        }

        /* ==================== EMPTY STATE ==================== */
        .empty-state {
            text-align: center;
            padding: 100px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gold);
            margin-bottom: 20px;
        }

        .empty-state h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--text-light);
        }

        /* ==================== MOBILE STYLES ==================== */
        @media (max-width: 768px) {
            .language-selector {
                top: 15px;
                right: 15px;
            }

            .lang-toggle {
                padding: 8px 12px;
                font-size: 0.85rem;
            }

            .hero {
                min-height: 60vh;
            }

            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1rem;
            }

            .category-link {
                padding: 12px 16px;
                font-size: 0.8rem;
            }

            .menu-container {
                padding: 40px 15px 80px;
            }

            .menu-section {
                margin-bottom: 60px;
            }

            .menu-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .menu-card-image {
                height: 180px;
            }

            .menu-card-content {
                padding: 20px;
            }

            .menu-card h3 {
                font-size: 1.2rem;
            }

            .footer-info {
                flex-direction: column;
                gap: 20px;
            }
        }

        /* ==================== ANIMATIONS ==================== */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <!-- Language Selector -->
    <div class="language-selector" id="langSelector">
        <button class="lang-toggle" onclick="toggleLangDropdown()">
            <span class="flag"><?= $translations[$lang]['flag'] ?></span>
            <span><?= $translations[$lang]['lang_name'] ?></span>
            <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-left: 5px;"></i>
        </button>
        <div class="lang-dropdown">
            <?php foreach ($translations as $code => $trans): ?>
                <a href="?<?= htmlspecialchars(http_build_query(['lang' => $code] + (($menuKind ?? 'table') !== 'table' ? ['m' => $menuKind] : []))) ?>" class="lang-option <?= $code === $lang ? 'active' : '' ?>">
                    <span class="flag"><?= $trans['flag'] ?></span>
                    <span><?= $trans['lang_name'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-bg"></div>
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <span class="hero-badge"><?= __('est') ?> 2024</span>
            <?php if ($logo = (is_file(__DIR__ . '/assets/img/logo.png') ? '/assets/img/logo.png?v=' . filemtime(__DIR__ . '/assets/img/logo.png') : null)): ?>
            <h1 class="hero-logo"><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($restaurantName) ?>"></h1>
            <?php else: ?>
            <h1><?= htmlspecialchars($restaurantName) ?></h1>
            <?php endif; ?>
            <div class="hero-divider"></div>
            <p><?= __('discover') ?></p>
        </div>
        <a href="#menu" class="scroll-indicator">
            <i class="fas fa-chevron-down"></i>
        </a>
    </section>

    <!-- Category Navigation -->
    <nav class="category-nav" id="menu">
        <div class="category-nav-inner">
            <?php foreach ($categories as $cat): ?>
                <a href="#category-<?= $cat['id'] ?>" class="category-link" data-category="<?= $cat['id'] ?>">
                    <i class="fas fa-<?= htmlspecialchars($cat['icon'] ?? 'utensils') ?>"></i>
                    <span><?= htmlspecialchars(translateCategory($cat['name'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <!-- Menu Content -->
    <main class="menu-container">
        <?php 
        $itemIndex = 0;
        foreach ($menuByCategory as $catId => $category): 
        ?>
            <section class="menu-section fade-in" id="category-<?= $catId ?>">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-<?= htmlspecialchars($category['icon'] ?? 'utensils') ?>"></i>
                    </div>
                    <h2><?= htmlspecialchars(translateCategory($category['name'])) ?></h2>
                    <p><?= count($category['items']) ?> <?= __('delicious_options') ?></p>
                    <div class="section-line"></div>
                </div>

                <div class="menu-grid">
                    <?php foreach ($category['items'] as $item): 
                        $imageUrl = getItemImage($item, $itemIndex, $categoryImages, $defaultImages);
                        $itemIndex++;
                    ?>
                        <article class="menu-card fade-in">
                            <div class="menu-card-image">
                                <img src="<?= htmlspecialchars($imageUrl) ?>"
                                     alt="<?= htmlspecialchars($item['name']) ?>"
                                     loading="lazy">
                                <?php if (!empty($item['video_url'])): ?>
                                    <!-- The dish's video: the browser plays it (MP4 / WebM), no plugin -->
                                    <button type="button" class="dish-play" onclick="playDishVideo(this, <?= htmlspecialchars(json_encode($item['video_url']), ENT_QUOTES) ?>)" aria-label="Video">
                                        <i class="fas fa-play"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($item['preparation_time'] <= 10): ?>
                                    <span class="menu-card-badge"><?= __('quick_serve') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="menu-card-content">
                                <div class="menu-card-header">
                                    <h3><?= htmlspecialchars($item['name']) ?></h3>
                                    <span class="menu-card-price"><?= $currency ?><?= number_format($item['base_price'], 2) ?></span>
                                </div>
                                <?php if (!empty($item['description'])): ?>
                                    <p class="menu-card-desc"><?= htmlspecialchars($item['description']) ?></p>
                                <?php endif; ?>
                                <div class="menu-card-meta">
                                    <span>
                                        <i class="fas fa-clock"></i>
                                        <?= $item['preparation_time'] ?? 15 ?> <?= __('min') ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-fire"></i>
                                        <?= __('fresh_daily') ?>
                                    </span>
                                </div>
                            </div>
                            <button class="order-btn" title="<?= __('add_to_order') ?>">
                                <i class="fas fa-plus"></i>
                            </button>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if (empty($menuByCategory)): ?>
            <div class="empty-state">
                <i class="fas fa-utensils"></i>
                <h2><?= __('menu_coming_soon') ?></h2>
                <p><?= __('preparing') ?></p>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="menu-footer">
        <h3><?= htmlspecialchars($restaurantName) ?></h3>
        <p><?= __('thank_you') ?></p>
        
        <div class="footer-info">
            <div class="footer-info-item">
                <i class="fas fa-map-marker-alt"></i>
                <span>123 Gourmet Street, City</span>
            </div>
            <div class="footer-info-item">
                <i class="fas fa-phone"></i>
                <span>+254 700 000 000</span>
            </div>
            <div class="footer-info-item">
                <i class="fas fa-clock"></i>
                <span><?= __('open_daily') ?>: 11AM - 11PM</span>
            </div>
        </div>

        <div class="footer-social">
            <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
            <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
            <a href="#" aria-label="TripAdvisor"><i class="fab fa-tripadvisor"></i></a>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($restaurantName) ?>. <?= __('all_rights') ?></p>
            <p style="margin-top: 5px;"><?= __('powered_by') ?></p>
        </div>
    </footer>

    <script>
        // Language dropdown toggle
        function toggleLangDropdown() {
            document.getElementById('langSelector').classList.toggle('active');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const selector = document.getElementById('langSelector');
            if (!selector.contains(e.target)) {
                selector.classList.remove('active');
            }
        });

        // Smooth scroll for category links
        document.querySelectorAll('.category-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    const navHeight = document.querySelector('.category-nav').offsetHeight;
                    const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - navHeight - 20;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Active category highlighting on scroll
        const sections = document.querySelectorAll('.menu-section');
        const categoryLinks = document.querySelectorAll('.category-link');

        function updateActiveCategory() {
            const navHeight = document.querySelector('.category-nav').offsetHeight;
            let current = '';

            sections.forEach(section => {
                const sectionTop = section.offsetTop - navHeight - 100;
                if (window.pageYOffset >= sectionTop) {
                    current = section.getAttribute('id');
                }
            });

            categoryLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + current) {
                    link.classList.add('active');
                    link.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                }
            });
        }

        window.addEventListener('scroll', updateActiveCategory);

        // Fade in animation on scroll
        const fadeElements = document.querySelectorAll('.fade-in');

        const fadeObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        fadeElements.forEach(el => fadeObserver.observe(el));

        // Order button click
        document.querySelectorAll('.order-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const card = this.closest('.menu-card');
                const itemName = card.querySelector('h3').textContent;
                
                this.innerHTML = '<i class="fas fa-check"></i>';
                this.style.background = 'var(--success)';
                
                setTimeout(() => {
                    this.innerHTML = '<i class="fas fa-plus"></i>';
                    this.style.background = '';
                }, 1500);

                console.log('Added to order:', itemName);
            });
        });

        // Scroll indicator
        document.querySelector('.scroll-indicator')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelector('#menu').scrollIntoView({ behavior: 'smooth' });
        });
    </script>
<script>
// The dish's video in place of its photo (plays inline on phones too).
function playDishVideo(btn, src) {
    const box = btn.parentElement;
    const img = box.querySelector('img');
    const v = document.createElement('video');
    v.src = src; v.controls = true; v.autoplay = true; v.playsInline = true; v.setAttribute('playsinline', '');
    if (img) v.poster = img.src;
    box.querySelectorAll('img, .dish-play, .menu-card-badge').forEach(el => el.remove());
    box.appendChild(v);
    v.play().catch(() => {});
}
</script>
</body>
</html>