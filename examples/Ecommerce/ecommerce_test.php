<?php
/**
 * E-commerce Test Suite
 *
 * Tests for the Schema.org-based e-commerce system using DelegatedTypes.
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

/**
 * Test runner
 */
class EcommerceTestRunner
{
    private IxOrm $db;
    private Schema $schema;
    private array $passed = [];
    private array $failed = [];

    public function run_all(): void
    {
        echo "E-commerce Schema.org Test Suite\n";
        echo str_repeat('=', 50) . "\n\n";

        $this->setup();

        $this->test_product_creation();
        $this->test_product_group();
        $this->test_order_creation();
        $this->test_order_items();
        $this->test_order_totals();
        $this->test_type_checking();
        $this->test_serialization();

        $this->teardown();
        $this->print_summary();
    }

    private function setup(): void
    {
        $driver = Driver::sqlite_memory();
        $this->db = new IxOrm($driver);
        $this->schema = new Schema();

        $this->db->create_tables(...$this->schema->get_tables());

        Thing::set_persistence($this->db, $this->schema->things);
        Product::set_persistence($this->db, $this->schema->products);
        Order::set_persistence($this->db, $this->schema->orders);
        OrderItem::set_persistence($this->db, $this->schema->order_items);
    }

    private function teardown(): void
    {
        $this->db->drop_tables(...array_reverse($this->schema->get_tables()));
    }

    private function assert(string $name, bool $condition): void
    {
        if ($condition) {
            $this->passed[] = $name;
            echo "  ✓ $name\n";
        } else {
            $this->failed[] = $name;
            echo "  ✗ $name\n";
        }
    }

    // =========================================
    // TESTS
    // =========================================

    private function test_product_creation(): void
    {
        echo "Product Creation\n";
        echo str_repeat('-', 30) . "\n";

        // Create a simple product
        $laptop = Thing::create_product([
            'name' => 'MacBook Pro 14"',
            'description' => 'Apple MacBook Pro with M3 chip',
            'url' => 'https://example.com/macbook-pro',
            'image' => 'https://example.com/images/macbook.jpg',
        ], [
            'sku' => 'MBP-14-M3',
            'gtin' => '12345678901234',
            'brand' => 'Apple',
            'price' => 1999.00,
            'currency' => 'USD',
            'availability' => 'InStock',
            'inventory_level' => 50,
        ]);

        $this->assert('Product created with ID', $laptop['id'] !== null);
        $this->assert('Product has UUID', strlen($laptop['uuid']) === 36);
        $this->assert('Product type is correct', $laptop['type'] === 'Product');
        $this->assert('Product type_path is correct', $laptop['type_path'] === 'Thing/Product');
        $this->assert('is_product flag is true', $laptop['is_product'] === true);

        // Check delegate
        $delegate = $laptop->delegate();
        $this->assert('Delegate is Product instance', $delegate instanceof Product);
        $this->assert('Delegate has correct SKU', $delegate['sku'] === 'MBP-14-M3');
        $this->assert('Delegate has correct price', (float)$delegate['price'] === 1999.00);
        $this->assert('Delegate formatted_price works', $delegate->formatted_price() === '$1,999.00');
        $this->assert('Delegate is_in_stock works', $delegate->is_in_stock() === true);

        echo "\n";
    }

    private function test_product_group(): void
    {
        echo "ProductGroup (Variants)\n";
        echo str_repeat('-', 30) . "\n";

        // Create a ProductGroup (T-shirt with size variants)
        $tshirt_group = Thing::create_product_group([
            'name' => 'Classic Cotton T-Shirt',
            'description' => 'Comfortable 100% cotton t-shirt',
        ], [
            'sku' => 'TSHIRT-CLASSIC',
            'brand' => 'BasicWear',
            'varies_by' => 'size, color',
        ]);

        $this->assert('ProductGroup created', $tshirt_group['id'] !== null);
        $this->assert('ProductGroup is_group is true', $tshirt_group->delegate()['is_group'] === true);
        $this->assert('is_product_group() returns true', $tshirt_group->is_product_group() === true);

        // Create variants
        $small = Thing::create_product_variant($tshirt_group, [
            'name' => 'Classic Cotton T-Shirt - Small',
        ], [
            'sku' => 'TSHIRT-CLASSIC-S',
            'price' => 19.99,
            'inventory_level' => 100,
        ]);

        $medium = Thing::create_product_variant($tshirt_group, [
            'name' => 'Classic Cotton T-Shirt - Medium',
        ], [
            'sku' => 'TSHIRT-CLASSIC-M',
            'price' => 19.99,
            'inventory_level' => 150,
        ]);

        $large = Thing::create_product_variant($tshirt_group, [
            'name' => 'Classic Cotton T-Shirt - Large',
        ], [
            'sku' => 'TSHIRT-CLASSIC-L',
            'price' => 21.99,
            'inventory_level' => 75,
        ]);

        $this->assert('Variant Small created', $small['id'] !== null);
        $this->assert('Variant has variant_of_id', $small->delegate()['variant_of_id'] === $tshirt_group['id']);
        $this->assert('Variant is_variant() returns true', $small->delegate()->is_variant() === true);
        $this->assert('Variant is_group is false', $small->delegate()['is_group'] === false);

        // Test parent_group navigation
        $parent = $small->delegate()->parent_group();
        $this->assert('Variant parent_group returns ProductGroup', $parent !== null);
        $this->assert('Parent group name is correct', $parent['name'] === 'Classic Cotton T-Shirt');

        // Test variants() method
        $variants = $tshirt_group->delegate()->variants();
        $this->assert('ProductGroup has 3 variants', count($variants) === 3);

        echo "\n";
    }

    private function test_order_creation(): void
    {
        echo "Order Creation\n";
        echo str_repeat('-', 30) . "\n";

        $order = Thing::create_order([
            'name' => 'Order #12345',
            'description' => 'Customer order from web store',
        ], [
            'order_number' => 'ORD-2024-12345',
            'order_status' => Order::STATUS_PROCESSING,
            'order_date' => date('Y-m-d H:i:s'),
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'shipping_address' => '123 Main St, City, State 12345',
            'subtotal' => 100.00,
            'tax' => 8.00,
            'shipping_cost' => 5.99,
            'total_price' => 113.99,
            'currency' => 'USD',
        ]);

        $this->assert('Order created with ID', $order['id'] !== null);
        $this->assert('Order type is correct', $order['type'] === 'Order');
        $this->assert('is_order flag is true', $order['is_order'] === true);

        $delegate = $order->delegate();
        $this->assert('Delegate is Order instance', $delegate instanceof Order);
        $this->assert('Order number is correct', $delegate['order_number'] === 'ORD-2024-12345');
        $this->assert('Order status is correct', $delegate->status() === Order::STATUS_PROCESSING);
        $this->assert('Order formatted_total works', $delegate->formatted_total() === '$113.99');
        $this->assert('Order can_cancel returns true', $delegate->can_cancel() === true);

        echo "\n";
    }

    private function test_order_items(): void
    {
        echo "OrderItem Creation\n";
        echo str_repeat('-', 30) . "\n";

        // Create products
        $product1 = Thing::create_product([
            'name' => 'Widget A',
        ], [
            'sku' => 'WIDGET-A',
            'price' => 25.00,
        ]);

        $product2 = Thing::create_product([
            'name' => 'Widget B',
        ], [
            'sku' => 'WIDGET-B',
            'price' => 15.00,
        ]);

        // Create order
        $order = Thing::create_order([
            'name' => 'Test Order',
        ], [
            'order_number' => 'ORD-TEST-001',
            'customer_name' => 'Test Customer',
        ]);

        // Create order items
        $item1 = Thing::create_order_item($order, $product1, 2, [], [
            'order_item_number' => 'ITEM-001',
        ]);

        $item2 = Thing::create_order_item($order, $product2, 3, [], [
            'order_item_number' => 'ITEM-002',
        ]);

        $this->assert('OrderItem 1 created', $item1['id'] !== null);
        $this->assert('OrderItem 1 type is correct', $item1['type'] === 'OrderItem');
        $this->assert('is_order_item flag is true', $item1['is_order_item'] === true);

        $delegate1 = $item1->delegate();
        $this->assert('Item 1 delegate is OrderItem', $delegate1 instanceof OrderItem);
        $this->assert('Item 1 quantity is 2', $delegate1->quantity() === 2);
        $this->assert('Item 1 unit_price is 25.00', $delegate1->unit_price() === 25.00);
        $this->assert('Item 1 line_total is 50.00', $delegate1->line_total() === 50.00);
        $this->assert('Item 1 formatted_line_total is $50.00', $delegate1->formatted_line_total() === '$50.00');

        $delegate2 = $item2->delegate();
        $this->assert('Item 2 quantity is 3', $delegate2->quantity() === 3);
        $this->assert('Item 2 line_total is 45.00', $delegate2->line_total() === 45.00);

        // Test relationships
        $item_order = $delegate1->order();
        $this->assert('Item->order() returns Order', $item_order !== null);
        $this->assert('Item order is correct', $item_order['id'] === $order['id']);

        $item_product = $delegate1->product();
        $this->assert('Item->product() returns Product', $item_product !== null);
        $this->assert('Item product is correct', $item_product['id'] === $product1['id']);

        echo "\n";
    }

    private function test_order_totals(): void
    {
        echo "Order Totals Calculation\n";
        echo str_repeat('-', 30) . "\n";

        // Create order with items and calculate totals
        $product = Thing::create_product(['name' => 'Test Product'], ['price' => 10.00]);

        $order = Thing::create_order(['name' => 'Totals Test Order'], [
            'order_number' => 'ORD-TOTALS-001',
        ]);

        $item1 = Thing::create_order_item($order, $product, 5);  // 5 x $10 = $50
        $item2 = Thing::create_order_item($order, $product, 3);  // 3 x $10 = $30

        // Calculate totals
        $items = Thing::find_order_items($order);
        $totals = Order::calculate_totals($items, 0.08, 5.99, 10.00);

        $this->assert('Subtotal is $80.00', $totals['subtotal'] === 80.00);
        $this->assert('Tax (8%) is $6.40', $totals['tax'] === 6.40);
        $this->assert('Shipping is $5.99', $totals['shipping_cost'] === 5.99);
        $this->assert('Discount is $10.00', $totals['discount'] === 10.00);
        $this->assert('Total is $82.39', $totals['total_price'] === 82.39);

        echo "\n";
    }

    private function test_type_checking(): void
    {
        echo "Type Checking\n";
        echo str_repeat('-', 30) . "\n";

        $product = Thing::create_product(['name' => 'Type Test Product'], []);
        $order = Thing::create_order(['name' => 'Type Test Order'], ['order_number' => 'ORD-TYPE-001']);

        $this->assert('Product is_product() returns true', $product->is_product() === true);
        $this->assert('Product is_order() returns false', $product->is_order() === false);
        $this->assert('Order is_order() returns true', $order->is_order() === true);
        $this->assert('Order is_product() returns false', $order->is_product() === false);

        // Test find methods
        $products = Thing::find_products();
        $orders = Thing::find_orders();

        $this->assert('find_products() returns products', count($products) > 0);
        $this->assert('find_orders() returns orders', count($orders) > 0);

        $all_are_products = true;
        foreach ($products as $p) {
            if (!$p->is_product()) {
                $all_are_products = false;
                break;
            }
        }
        $this->assert('All found products are products', $all_are_products);

        echo "\n";
    }

    private function test_serialization(): void
    {
        echo "Serialization / Reconstruction\n";
        echo str_repeat('-', 30) . "\n";

        // Create a product
        $product = Thing::create_product([
            'name' => 'Serialization Test Product',
            'description' => 'Testing JSON serialization',
        ], [
            'sku' => 'SERIAL-001',
            'price' => 99.99,
            'brand' => 'TestBrand',
        ]);

        // Serialize
        $serialized = $product->to_array_with_delegates();
        $this->assert('Serialization includes _type', isset($serialized['_type']));
        $this->assert('Serialization includes _delegate', isset($serialized['_delegate']));
        $this->assert('Delegate data has SKU', $serialized['_delegate']['_data']['sku'] === 'SERIAL-001');

        // JSON round-trip
        $json = $product->to_json_with_delegates();
        $reconstructed = Thing::from_json($json);

        $this->assert('Reconstruction returns Thing', $reconstructed instanceof Thing);
        $this->assert('Reconstructed name matches', $reconstructed['name'] === 'Serialization Test Product');
        $this->assert('Reconstructed has delegate', $reconstructed->delegate() !== null);
        $this->assert('Reconstructed delegate SKU matches', $reconstructed->delegate()['sku'] === 'SERIAL-001');

        // Schema.org JSON-LD
        $schema_org = $product->delegate()->to_schema_org($product);
        $this->assert('Schema.org has @type', isset($schema_org['@type']));
        $this->assert('Schema.org @type is Product', $schema_org['@type'] === 'Product');
        $this->assert('Schema.org has offers', isset($schema_org['offers']));

        echo "\n";
    }

    private function print_summary(): void
    {
        echo str_repeat('=', 50) . "\n";
        echo "SUMMARY\n";
        echo str_repeat('=', 50) . "\n\n";

        $total = count($this->passed) + count($this->failed);
        $status = count($this->failed) === 0 ? '✓' : '✗';

        echo "$status Total: " . count($this->passed) . " passed, " . count($this->failed) . " failed\n";

        if (count($this->failed) > 0) {
            echo "\nFailed tests:\n";
            foreach ($this->failed as $name) {
                echo "  - $name\n";
            }
            echo "\n⚠️  Some tests failed!\n";
            exit(1);
        } else {
            echo "\n✓ All tests passed!\n";
        }
    }
}

// Run the tests
$runner = new EcommerceTestRunner();
$runner->run_all();
