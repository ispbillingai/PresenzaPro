# RestoPOS - Restaurant Management System

> Deployed via git. Test pull: this line was added to verify the server pull workflow.

A comprehensive PHP-based Point of Sale system for restaurants with support for multiple roles, real-time kitchen display, order management, and payment processing.

## Features

### 🍽️ **Table Management**
- Multiple rooms/areas support
- Visual table layout with status indicators (free, occupied, bill requested)
- Quick table selection for new orders

### 📝 **Order Management**
- Create orders with guest count and cover charges
- Add menu items with customizations
- Component-based dish composition (add/remove ingredients)
- Special instructions support
- Real-time order totals calculation

### 👨‍🍳 **Kitchen Display System (KDS)**
- Real-time view of pending orders
- Status tracking (queued → in progress → ready)
- Time-based urgency indicators
- One-click "ready" notifications to waiters

### 💰 **Cashier & Payments**
- Bill summary view
- Multiple payment methods (Cash, Card, M-Pesa)
- Discount support (percentage or fixed amount)
- Receipt generation
- Change calculation

### 📊 **Admin Dashboard**
- Revenue reports and analytics
- Top-selling items tracking
- Waiter performance metrics
- Category-wise revenue breakdown
- User management
- Menu & category configuration

### 🔔 **Notifications**
- Real-time notifications for waiters when dishes are ready
- Cashier alerts for bill requests
- In-app notification bell with unread count

## User Roles

| Role | Access |
|------|--------|
| **Admin** | Full system access, reports, configuration |
| **Waiter** | Table selection, order management, bill requests |
| **Cashier** | Payment processing, discounts, receipts |
| **Kitchen** | Kitchen display, order status updates |

## Installation

### Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- PDO MySQL extension

### Setup Steps

1. **Create Database**
   ```sql
   CREATE DATABASE restaurant_pos;
   ```

2. **Import Schema**
   ```bash
   mysql -u root -p restaurant_pos < database_schema.sql
   ```

3. **Configure Database Connection**
   Edit `config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'restaurant_pos');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

4. **Set Up Web Server**
   Point your web server document root to the project folder.

5. **Access the Application**
   Open your browser and navigate to your server URL.

## Default Login Credentials

| Username | Password | Role |
|----------|----------|------|
| admin | password | Admin |
| waiter1 | password | Waiter |
| waiter2 | password | Waiter |
| cashier1 | password | Cashier |
| kitchen1 | password | Kitchen |

> ⚠️ **Important**: Change default passwords after installation!

## Project Structure

```
restaurant-pos/
├── admin/              # Admin panel pages
│   ├── index.php       # Dashboard
│   ├── menu.php        # Menu management
│   ├── rooms.php       # Rooms & tables
│   ├── users.php       # User management
│   ├── reports.php     # Analytics & reports
│   ├── orders.php      # Order history
│   └── settings.php    # System settings
├── api/                # API endpoints
│   ├── orders.php      # Order operations
│   ├── kitchen.php     # Kitchen operations
│   ├── payments.php    # Payment processing
│   ├── menu.php        # Menu data
│   ├── notifications.php
│   └── status.php      # Polling updates
├── assets/
│   ├── css/style.css   # Main stylesheet
│   └── js/app.js       # JavaScript functions
├── cashier/            # Cashier pages
│   ├── index.php       # Dashboard
│   ├── payment.php     # Payment processing
│   └── receipt.php     # Receipt printing
├── config/
│   └── database.php    # Database configuration
├── includes/
│   ├── functions.php   # Helper functions
│   ├── header.php      # Page header
│   └── footer.php      # Page footer
├── kitchen/
│   └── index.php       # Kitchen display
├── waiter/
│   ├── index.php       # Table selection
│   ├── order.php       # Order editing
│   └── orders.php      # Order list
├── index.php           # Entry point
├── login.php           # Login page
├── logout.php          # Logout handler
├── unauthorized.php    # Access denied page
└── database_schema.sql # Database setup
```

## API Endpoints

### Orders API (`/api/orders.php`)
- `POST create` - Create new order
- `POST add_item` - Add item to order
- `POST update_quantity` - Update item quantity
- `POST remove_item` - Remove item from order
- `POST send_to_kitchen` - Send order to kitchen
- `POST request_bill` - Request bill for table

### Kitchen API (`/api/kitchen.php`)
- `POST update_status` - Update item status
- `POST mark_all_ready` - Mark all items ready
- `GET list` - Get pending items

### Payments API (`/api/payments.php`)
- `POST apply_discount` - Apply discount to order
- `POST process_payment` - Process payment

## Customization

### Adding Menu Categories
1. Go to Admin → Menu Management
2. Click "Add Category"
3. Set name, icon, and composition settings

### Adding Menu Items
1. Go to Admin → Menu Management
2. Select a category
3. Click "Add Item"
4. For dishes with components, click "Components" to add ingredients

### Room & Table Setup
1. Go to Admin → Rooms & Tables
2. Add rooms first
3. Add tables individually or use "Bulk Add"

## Browser Support

- Chrome (recommended)
- Firefox
- Safari
- Edge

## License

MIT License - feel free to use and modify for your restaurant!

## Support

For issues or feature requests, please create an issue in the repository.
