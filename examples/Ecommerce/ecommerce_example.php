<?php
/**
 * E-commerce Example
 *
 * Demonstrates a Schema.org-based e-commerce system using DelegatedTypes.
 *
 * Schema.org types used:
 * - Product: https://schema.org/Product
 * - ProductGroup: https://schema.org/ProductGroup
 * - Order: https://schema.org/Order
 * - OrderItem: https://schema.org/OrderItem
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';

use Italix\Orm\Dialects\Driver;
use Italix\Orm\IxOrm;
use Examples\Ecommerce\Schema;
use Examples\Ecommerce\Models\Thing;
use Examples\Ecommerce\Models\Product;
use Examples\Ecommerce\Models\Order;
use Examples\Ecommerce\Models\OrderItem;

// Autoload example classes
spl_autoload_register(function ($class) {
    $prefix = 'Examples\\Ecommerce\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative_class = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

echo "=== Schema.org E-commerce Example ===\n\n";

// ============================================================
// SETUP
// ============================================================

$driver = Driver::sqlite_memory();
$db = new IxOrm($driver);
$schema = new Schema();

$db->create_tables(...$schema->get_tables());

Thing::set_persistence($db, $schema->things);
Product::set_persistence($db, $schema->products);
Order::set_persistence($db, $schema->orders);
OrderItem::set_persistence($db, $schema->order_items);

// ============================================================
// CREATE PRODUCTS
// ============================================================

echo "1. Creating Products\n";
echo str_repeat('-', 40) . "\n\n";

// Simple product
$laptop = Thing::create_product([
    'name' => 'MacBook Pro 14"',
    'description' => 'Apple MacBook Pro with M3 Pro chip, 18GB RAM, 512GB SSD',
    'url' => 'https://store.example.com/macbook-pro-14',
    'image' => 'https://store.example.com/images/macbook-pro-14.jpg',
], [
    'sku' => 'MBP-14-M3PRO',
    'gtin' => '19428394857392',
    'brand' => 'Apple',
    'price' => 1999.00,
    'currency' => 'USD',
    'availability' => 'InStock',
    'inventory_level' => 25,
]);

echo "Created: {$laptop['name']}\n";
echo "  SKU: {$laptop->delegate()->sku()}\n";
echo "  Price: {$laptop->delegate()->formatted_price()}\n";
echo "  Status: {$laptop->delegate()->availability_text()}\n\n";

// ============================================================
// CREATE PRODUCT GROUP WITH VARIANTS
// ============================================================

echo "2. Creating ProductGroup with Variants\n";
echo str_repeat('-', 40) . "\n\n";

// Create a ProductGroup (T-shirt that comes in different sizes)
$tshirt = Thing::create_product_group([
    'name' => 'Premium Cotton T-Shirt',
    'description' => 'Soft 100% organic cotton t-shirt, available in multiple sizes',
], [
    'sku' => 'TSHIRT-PREMIUM',
    'brand' => 'EcoWear',
    'category' => 'Clothing > T-Shirts',
    'varies_by' => 'size',
]);

echo "ProductGroup: {$tshirt['name']}\n";
echo "  SKU: {$tshirt->delegate()->sku()}\n";
echo "  Varies by: " . implode(', ', $tshirt->delegate()->varies_by()) . "\n\n";

// Create size variants
$sizes = [
    'S' => ['price' => 24.99, 'inventory' => 50],
    'M' => ['price' => 24.99, 'inventory' => 100],
    'L' => ['price' => 24.99, 'inventory' => 75],
    'XL' => ['price' => 26.99, 'inventory' => 30],
];

$variants = [];
foreach ($sizes as $size => $data) {
    $variant = Thing::create_product_variant($tshirt, [
        'name' => "Premium Cotton T-Shirt - Size {$size}",
    ], [
        'sku' => "TSHIRT-PREMIUM-{$size}",
        'price' => $data['price'],
        'inventory_level' => $data['inventory'],
        'availability' => 'InStock',
    ]);
    $variants[$size] = $variant;
    echo "  Variant: Size {$size} - {$variant->delegate()->formatted_price()} ({$data['inventory']} in stock)\n";
}

echo "\n";

// ============================================================
// CREATE AN ORDER
// ============================================================

echo "3. Creating an Order\n";
echo str_repeat('-', 40) . "\n\n";

$order = Thing::create_order([
    'name' => 'Order #10042',
    'description' => 'Web store order',
], [
    'order_number' => 'ORD-2024-10042',
    'order_status' => Order::STATUS_PROCESSING,
    'order_date' => date('Y-m-d H:i:s'),
    'customer_name' => 'Jane Smith',
    'customer_email' => 'jane.smith@example.com',
    'shipping_address' => "123 Main Street\nApt 4B\nNew York, NY 10001",
    'payment_method' => 'CreditCard',
]);

echo "Order: {$order->delegate()['order_number']}\n";
echo "  Customer: {$order->delegate()->customer_name()}\n";
echo "  Status: {$order->delegate()->status_text()}\n";
echo "  Date: {$order->delegate()->formatted_date()}\n\n";

// ============================================================
// ADD ORDER ITEMS
// ============================================================

echo "4. Adding Order Items\n";
echo str_repeat('-', 40) . "\n\n";

// Add MacBook to order
$item1 = Thing::create_order_item($order, $laptop, 1, [], [
    'order_item_number' => 'ITEM-001',
]);

// Add 2 Medium T-shirts
$item2 = Thing::create_order_item($order, $variants['M'], 2, [], [
    'order_item_number' => 'ITEM-002',
]);

// Add 1 Large T-shirt
$item3 = Thing::create_order_item($order, $variants['L'], 1, [], [
    'order_item_number' => 'ITEM-003',
]);

// Display order items
$items = Thing::find_order_items($order);
echo "Order Items:\n";
foreach ($items as $item) {
    $delegate = $item->delegate();
    $product = $delegate->product();
    echo "  {$delegate->item_number()}: {$delegate->quantity()}x {$product['name']}\n";
    echo "           Unit: {$delegate->formatted_unit_price()} | Line: {$delegate->formatted_line_total()}\n";
}

echo "\n";

// ============================================================
// CALCULATE ORDER TOTALS
// ============================================================

echo "5. Order Summary\n";
echo str_repeat('-', 40) . "\n\n";

// Calculate totals with 8% tax and $9.99 shipping
$totals = Order::calculate_totals($items, 0.08, 9.99, 0);

echo "Subtotal:  \$" . number_format($totals['subtotal'], 2) . "\n";
echo "Tax (8%):  \$" . number_format($totals['tax'], 2) . "\n";
echo "Shipping:  \$" . number_format($totals['shipping_cost'], 2) . "\n";
echo "─────────────────────\n";
echo "Total:     \$" . number_format($totals['total_price'], 2) . "\n\n";

// Update order with calculated totals
$order->delegate()->update($totals);

// ============================================================
// SCHEMA.ORG JSON-LD OUTPUT
// ============================================================

echo "6. Schema.org JSON-LD (for SEO)\n";
echo str_repeat('-', 40) . "\n\n";

// Product JSON-LD
$product_schema = $laptop->delegate()->to_schema_org($laptop);
echo "Product Schema:\n";
echo json_encode($product_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// ============================================================
// SERIALIZATION FOR API / CACHING
// ============================================================

echo "7. Full Serialization (with delegates)\n";
echo str_repeat('-', 40) . "\n\n";

$json = $laptop->to_json_with_delegates(false, JSON_PRETTY_PRINT);
echo "Serialized Product:\n";
echo $json . "\n\n";

// Reconstruct
echo "Reconstructing from JSON...\n";
$reconstructed = Thing::from_json($json);
echo "  Name: {$reconstructed['name']}\n";
echo "  SKU: {$reconstructed->delegate()['sku']}\n";
echo "  Price: {$reconstructed->delegate()->formatted_price()}\n\n";

// ============================================================
// QUERYING
// ============================================================

echo "8. Querying Products\n";
echo str_repeat('-', 40) . "\n\n";

// Find all products
$all_products = Thing::find_products();
echo "Total Products: " . count($all_products) . "\n";

// Find product groups
$groups = Thing::find_product_groups();
echo "Product Groups: " . count($groups) . "\n";

// List product groups and their variants
foreach ($groups as $group) {
    echo "\n  {$group['name']}:\n";
    $group_variants = $group->delegate()->variants();
    foreach ($group_variants as $v) {
        echo "    - {$v->delegate()->sku()}: {$v->delegate()->formatted_price()}\n";
    }
}

echo "\n=== Example Complete ===\n";
