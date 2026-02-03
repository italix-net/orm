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
use Examples\Ecommerce\Models\Customer;
use Examples\Ecommerce\Models\Person;
use Examples\Ecommerce\Models\Organization;
use Examples\Ecommerce\Models\PostalAddress;

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
        $this->test_variant_attributes();
        $this->test_find_variants_by();
        $this->test_virtual_products();
        $this->test_customer_creation();
        $this->test_postal_address();
        $this->test_order_creation();
        $this->test_order_items();
        $this->test_bundle_order_items();
        $this->test_order_totals();
        $this->test_customer_order_history();
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

        // Thing hierarchy
        Thing::set_persistence($this->db, $this->schema->things);
        Product::set_persistence($this->db, $this->schema->products);
        Order::set_persistence($this->db, $this->schema->orders);
        OrderItem::set_persistence($this->db, $this->schema->order_items);

        // Customer hierarchy
        Customer::set_persistence($this->db, $this->schema->customers);
        Person::set_persistence($this->db, $this->schema->persons);
        Organization::set_persistence($this->db, $this->schema->organizations);

        // PostalAddress
        PostalAddress::set_persistence($this->db, $this->schema->postal_addresses);
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

    private function test_variant_attributes(): void
    {
        echo "Variant Attributes (Size, Color, etc.)\n";
        echo str_repeat('-', 30) . "\n";

        // Create a ProductGroup with size and color variations
        $dress = Thing::create_product_group([
            'name' => 'Summer Dress',
            'description' => 'Elegant summer dress',
        ], [
            'sku' => 'DRESS-SUMMER',
            'brand' => 'FashionCo',
            'varies_by' => 'color, size',
        ]);

        // Create variants using the new create_variant method
        $red_small = Thing::create_variant($dress, [
            'color' => 'Red',
            'size' => 'S',
            'material' => '100% Cotton',
        ], [
            'sku' => 'DRESS-SUMMER-RED-S',
            'price' => 79.99,
            'inventory_level' => 25,
        ]);

        $red_medium = Thing::create_variant($dress, [
            'color' => 'Red',
            'size' => 'M',
        ], [
            'sku' => 'DRESS-SUMMER-RED-M',
            'price' => 79.99,
            'inventory_level' => 30,
        ]);

        $blue_small = Thing::create_variant($dress, [
            'color' => 'Navy Blue',
            'size' => 'S',
        ], [
            'sku' => 'DRESS-SUMMER-BLUE-S',
            'price' => 79.99,
            'inventory_level' => 20,
        ]);

        $blue_large = Thing::create_variant($dress, [
            'color' => 'Navy Blue',
            'size' => 'L',
            'pattern' => 'Striped',
        ], [
            'sku' => 'DRESS-SUMMER-BLUE-L',
            'price' => 84.99,
            'inventory_level' => 15,
        ]);

        // Test variant attribute getters
        $red_small_product = $red_small->delegate();
        $this->assert('Variant has color Red', $red_small_product->color() === 'Red');
        $this->assert('Variant has size S', $red_small_product->size() === 'S');
        $this->assert('Variant has material', $red_small_product->material() === '100% Cotton');

        $blue_large_product = $blue_large->delegate();
        $this->assert('Variant pattern works', $blue_large_product->pattern() === 'Striped');

        // Test get_variant_attribute for standard
        $this->assert('get_variant_attribute color', $red_small_product->get_variant_attribute('color') === 'Red');
        $this->assert('get_variant_attribute size', $red_small_product->get_variant_attribute('size') === 'S');

        // Test all_variant_attributes
        $all_attrs = $red_small_product->all_variant_attributes();
        $this->assert('all_variant_attributes has color', isset($all_attrs['color']));
        $this->assert('all_variant_attributes has size', isset($all_attrs['size']));
        $this->assert('all_variant_attributes has material', isset($all_attrs['material']));

        // Test variant_description
        $desc = $red_small_product->variant_description();
        $this->assert('variant_description contains Red', strpos($desc, 'Red') !== false);
        $this->assert('variant_description contains S', strpos($desc, 'S') !== false);

        // Test auto-generated name
        $this->assert('Variant name auto-generated', strpos($red_small['name'], 'Summer Dress') !== false);
        $this->assert('Variant name includes Red', strpos($red_small['name'], 'Red') !== false);

        // Test get_variant_options on parent group
        $dress_product = $dress->delegate();
        $colors = $dress_product->get_variant_options('color');
        $sizes = $dress_product->get_variant_options('size');

        $this->assert('get_variant_options returns colors', count($colors) === 2);
        $this->assert('Colors include Red', in_array('Red', $colors));
        $this->assert('Colors include Navy Blue', in_array('Navy Blue', $colors));
        $this->assert('Sizes include S', in_array('S', $sizes));
        $this->assert('Sizes include M', in_array('M', $sizes));
        $this->assert('Sizes include L', in_array('L', $sizes));

        echo "\n";
    }

    private function test_find_variants_by(): void
    {
        echo "Find Variants By Attributes\n";
        echo str_repeat('-', 30) . "\n";

        // Create another product group for testing
        $tshirt = Thing::create_product_group([
            'name' => 'Basic T-Shirt',
            'description' => 'Everyday basic tee',
        ], [
            'sku' => 'TSHIRT-BASIC',
            'brand' => 'BasicWear',
            'varies_by' => 'color, size',
        ]);

        // Create variants with size and color
        $variants_data = [
            ['color' => 'White', 'size' => 'S'],
            ['color' => 'White', 'size' => 'M'],
            ['color' => 'White', 'size' => 'L'],
            ['color' => 'Black', 'size' => 'S'],
            ['color' => 'Black', 'size' => 'M'],
            ['color' => 'Black', 'size' => 'L'],
            ['color' => 'Grey', 'size' => 'M'],
        ];

        foreach ($variants_data as $i => $attrs) {
            Thing::create_variant($tshirt, $attrs, [
                'sku' => 'TSHIRT-BASIC-' . $attrs['color'] . '-' . $attrs['size'],
                'price' => 14.99,
                'inventory_level' => 50,
            ]);
        }

        $tshirt_product = $tshirt->delegate();

        // Find all white variants
        $white_variants = $tshirt_product->find_variants_by(['color' => 'White']);
        $this->assert('find_variants_by color=White returns 3', count($white_variants) === 3);

        // Find all medium variants
        $medium_variants = $tshirt_product->find_variants_by(['size' => 'M']);
        $this->assert('find_variants_by size=M returns 3', count($medium_variants) === 3);

        // Find specific combination
        $black_large = $tshirt_product->find_variants_by(['color' => 'Black', 'size' => 'L']);
        $this->assert('find_variants_by color+size returns 1', count($black_large) === 1);

        // Find non-existent combination
        $grey_large = $tshirt_product->find_variants_by(['color' => 'Grey', 'size' => 'L']);
        $this->assert('find_variants_by non-existent returns 0', count($grey_large) === 0);

        // Test sibling_variants
        $first_variant = $tshirt_product->variants()[0];
        $first_product = $first_variant->delegate();
        $siblings = $first_product->sibling_variants();
        $this->assert('sibling_variants returns 6 (all except self)', count($siblings) === 6);

        echo "\n";
    }

    private function test_virtual_products(): void
    {
        echo "Virtual Products (Downloadable/Service)\n";
        echo str_repeat('-', 30) . "\n";

        // Create a downloadable product (e-book)
        $ebook = Thing::create_product([
            'name' => 'PHP Design Patterns E-Book',
            'description' => 'Comprehensive guide to PHP design patterns',
        ], [
            'sku' => 'EBOOK-PHP-001',
            'price' => 29.99,
            'is_virtual' => true,
            'is_downloadable' => true,
            'download_url' => 'https://example.com/downloads/php-patterns.pdf',
            'download_limit' => 5,
            'download_expiry_days' => 365,
        ]);

        $ebookDelegate = $ebook->delegate();
        $this->assert('E-book is virtual', $ebookDelegate->is_virtual() === true);
        $this->assert('E-book is downloadable', $ebookDelegate->is_downloadable() === true);
        $this->assert('E-book is not physical', $ebookDelegate->is_physical() === false);
        $this->assert('E-book is not service', $ebookDelegate->is_service() === false);
        $this->assert('E-book download_limit is 5', $ebookDelegate->download_limit() === 5);
        $this->assert('E-book download_expiry_days is 365', $ebookDelegate->download_expiry_days() === 365);
        $this->assert('E-book product_type is DownloadableProduct', $ebookDelegate->product_type() === 'DownloadableProduct');

        // Create a service product (consultation)
        $consultation = Thing::create_product([
            'name' => 'PHP Code Review Service',
            'description' => '1-hour code review session with a senior developer',
        ], [
            'sku' => 'SERVICE-REVIEW-001',
            'price' => 150.00,
            'is_virtual' => true,
            'is_service' => true,
            'service_duration' => '1 hour',
        ]);

        $serviceDelegate = $consultation->delegate();
        $this->assert('Service is virtual', $serviceDelegate->is_virtual() === true);
        $this->assert('Service is not downloadable', $serviceDelegate->is_downloadable() === false);
        $this->assert('Service is_service is true', $serviceDelegate->is_service() === true);
        $this->assert('Service product_type is ServiceProduct', $serviceDelegate->product_type() === 'ServiceProduct');
        $this->assert('Service duration is correct', $serviceDelegate->service_duration() === '1 hour');

        // Create a physical product for comparison
        $physicalProduct = Thing::create_product([
            'name' => 'PHP Book (Printed)',
        ], [
            'sku' => 'BOOK-PHP-001',
            'price' => 49.99,
        ]);

        $physicalDelegate = $physicalProduct->delegate();
        $this->assert('Physical product is not virtual', $physicalDelegate->is_virtual() === false);
        $this->assert('Physical product is_physical is true', $physicalDelegate->is_physical() === true);
        $this->assert('Physical product_type is Product', $physicalDelegate->product_type() === 'Product');

        // Test Schema.org output for virtual products
        $schema = $ebookDelegate->to_schema_org($ebook);
        $this->assert('Schema.org has additionalType for downloadable', isset($schema['additionalType']));
        $this->assert('Schema.org additionalType is DigitalDocument', str_contains($schema['additionalType'], 'DigitalDocument'));

        echo "\n";
    }

    private function test_customer_creation(): void
    {
        echo "Customer Creation (Person/Organization)\n";
        echo str_repeat('-', 30) . "\n";

        // Create a Person customer
        $person = Customer::create_person([
            'email' => 'john.doe@example.com',
            'telephone' => '+1-555-123-4567',
            'customer_number' => 'CUST-001',
        ], [
            'given_name' => 'John',
            'family_name' => 'Doe',
            'honorific_prefix' => 'Mr.',
            'gender' => 'Male',
        ]);

        $this->assert('Person customer created', $person['id'] !== null);
        $this->assert('Person type is correct', $person['type'] === 'Person');
        $this->assert('Person is_person flag', $person['is_person'] === true);
        $this->assert('Person is_organization flag false', $person['is_organization'] === false);

        $personDelegate = $person->delegate();
        $this->assert('Person delegate is Person instance', $personDelegate instanceof Person);
        $this->assert('Person given_name correct', $personDelegate->given_name() === 'John');
        $this->assert('Person family_name correct', $personDelegate->family_name() === 'Doe');
        $this->assert('Person display_name correct', $personDelegate->display_name() === 'Mr. John Doe');

        // Create an Organization customer
        $org = Customer::create_organization([
            'email' => 'contact@acme.com',
            'telephone' => '+1-555-987-6543',
            'customer_number' => 'CUST-002',
        ], [
            'legal_name' => 'ACME Corporation Inc.',
            'trading_name' => 'ACME Corp',
            'vat_id' => 'IT12345678901',
            'contact_name' => 'Jane Smith',
        ]);

        $this->assert('Organization customer created', $org['id'] !== null);
        $this->assert('Organization type is correct', $org['type'] === 'Organization');
        $this->assert('Organization is_organization flag', $org['is_organization'] === true);

        $orgDelegate = $org->delegate();
        $this->assert('Org delegate is Organization instance', $orgDelegate instanceof Organization);
        $this->assert('Org has_vat returns true', $orgDelegate->has_vat() === true);
        $this->assert('Org vat_country is IT', $orgDelegate->vat_country() === 'IT');
        $this->assert('Org display_name uses trading name', $orgDelegate->display_name() === 'ACME Corp');

        // Test auto-detection (with VAT = Organization)
        $autoOrg = Customer::create_auto([
            'email' => 'billing@business.com',
        ], [
            'vat_id' => 'DE123456789',
            'legal_name' => 'German Business GmbH',
        ]);
        $this->assert('Auto-detect with VAT creates Organization', $autoOrg->is_organization() === true);

        // Test auto-detection (without VAT = Person)
        $autoPerson = Customer::create_auto([
            'email' => 'personal@email.com',
        ], [
            'given_name' => 'Alice',
            'family_name' => 'Smith',
        ]);
        $this->assert('Auto-detect without VAT creates Person', $autoPerson->is_person() === true);

        // Test find_by_email
        $found = Customer::find_by_email('john.doe@example.com');
        $this->assert('find_by_email returns customer', $found !== null);
        $this->assert('find_by_email has correct email', $found['email'] === 'john.doe@example.com');

        echo "\n";
    }

    private function test_postal_address(): void
    {
        echo "Postal Address\n";
        echo str_repeat('-', 30) . "\n";

        // Create a customer first
        $customer = Customer::create_person([
            'email' => 'address.test@example.com',
        ], [
            'given_name' => 'Test',
            'family_name' => 'User',
        ]);

        // Create a billing address
        $billing = PostalAddress::make_billing_address($customer, [
            'address_name' => 'Home',
            'street_address' => '123 Main Street, Apt 4B',
            'address_locality' => 'New York',
            'address_region' => 'NY',
            'postal_code' => '10001',
            'address_country' => 'USA',
            'contact_name' => 'Test User',
            'telephone' => '+1-555-111-2222',
        ]);

        $this->assert('Billing address created', $billing['id'] !== null);
        $this->assert('Billing address is_billing', $billing->is_billing() === true);
        $this->assert('Billing address city', $billing->city() === 'New York');
        $this->assert('Billing address postal_code', $billing->postal_code() === '10001');

        // Create a shipping address
        $shipping = PostalAddress::make_shipping_address($customer, [
            'address_name' => 'Work',
            'street_address' => '456 Office Park',
            'address_locality' => 'Los Angeles',
            'address_region' => 'CA',
            'postal_code' => '90001',
            'address_country' => 'USA',
        ]);

        $this->assert('Shipping address created', $shipping['id'] !== null);
        $this->assert('Shipping address is_shipping', $shipping->is_shipping() === true);

        // Test formatted output
        $formatted = $billing->formatted();
        $this->assert('Formatted address includes street', strpos($formatted, '123 Main Street') !== false);
        $this->assert('Formatted address includes city', strpos($formatted, 'New York') !== false);

        // Test one_line output
        $oneLine = $billing->one_line();
        $this->assert('One-line has city', strpos($oneLine, 'New York') !== false);

        // Test find_by_owner
        $addresses = PostalAddress::find_by_owner($customer);
        $this->assert('find_by_owner returns 2 addresses', count($addresses) === 2);

        // Test owner relationship
        $owner = $billing->owner();
        $this->assert('Owner returns customer', $owner !== null);
        $this->assert('Owner email matches', $owner['email'] === 'address.test@example.com');

        echo "\n";
    }

    private function test_order_creation(): void
    {
        echo "Order Creation\n";
        echo str_repeat('-', 30) . "\n";

        // Create customer and address for order
        $customer = Customer::create_person([
            'email' => 'order.customer@example.com',
        ], [
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);

        $address = PostalAddress::make_address([
            'street_address' => '123 Main St',
            'address_locality' => 'City',
            'address_region' => 'State',
            'postal_code' => '12345',
            'address_country' => 'USA',
            'is_billing' => true,
            'is_shipping' => true,
        ], $customer);

        // Create order with customer and address
        $order = Thing::create_order_for_customer(
            $customer,
            $address,
            null, // same address for delivery
            [
                'name' => 'Order #12345',
                'description' => 'Customer order from web store',
            ],
            [
                'order_number' => 'ORD-2024-12345',
                'order_status' => Order::STATUS_PROCESSING,
                'order_date' => date('Y-m-d H:i:s'),
                'subtotal' => 100.00,
                'tax' => 8.00,
                'shipping_cost' => 5.99,
                'total_price' => 113.99,
                'currency' => 'USD',
            ]
        );

        $this->assert('Order created with ID', $order['id'] !== null);
        $this->assert('Order type is correct', $order['type'] === 'Order');
        $this->assert('is_order flag is true', $order['is_order'] === true);

        $delegate = $order->delegate();
        $this->assert('Delegate is Order instance', $delegate instanceof Order);
        $this->assert('Order number is correct', $delegate['order_number'] === 'ORD-2024-12345');
        $this->assert('Order status is correct', $delegate->status() === Order::STATUS_PROCESSING);
        $this->assert('Order formatted_total works', $delegate->formatted_total() === '$113.99');
        $this->assert('Order can_cancel returns true', $delegate->can_cancel() === true);

        // Test customer relationship
        $orderCustomer = $delegate->customer();
        $this->assert('Order has customer', $orderCustomer !== null);
        $this->assert('Order customer email matches', $orderCustomer['email'] === 'order.customer@example.com');
        $this->assert('Order customer_name method', $delegate->customer_name() === 'John Doe');
        $this->assert('Order customer_is_person', $delegate->customer_is_person() === true);

        // Test address relationships
        $billingAddr = $delegate->billing_address();
        $this->assert('Order has billing address', $billingAddr !== null);
        $this->assert('Billing address city', $billingAddr->city() === 'City');

        $deliveryAddr = $delegate->delivery_address();
        $this->assert('Order has delivery address', $deliveryAddr !== null);
        $this->assert('Same billing/delivery', $delegate->same_billing_delivery() === true);

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

        // Create customer and address for order
        $customer = Customer::create_person([
            'email' => 'items.customer@example.com',
        ], [
            'given_name' => 'Test',
            'family_name' => 'Customer',
        ]);

        $address = PostalAddress::make_address([
            'street_address' => '456 Test St',
            'address_locality' => 'TestCity',
            'is_billing' => true,
            'is_shipping' => true,
        ], $customer);

        // Create order
        $order = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'Test Order',
        ], [
            'order_number' => 'ORD-TEST-001',
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

    private function test_bundle_order_items(): void
    {
        echo "Bundle/Pack Order Items\n";
        echo str_repeat('-', 30) . "\n";

        // Create a product bundle (starter kit)
        $bundle = Thing::create_product_group([
            'name' => 'PHP Developer Starter Kit',
            'description' => 'Everything you need to start PHP development',
        ], [
            'sku' => 'BUNDLE-PHP-STARTER',
            'price' => 199.99,
        ]);

        // Create bundle component products
        $book = Thing::create_product(['name' => 'PHP Programming Book'], [
            'sku' => 'BOOK-PHP-PROG',
            'price' => 49.99,
        ]);

        $course = Thing::create_product([
            'name' => 'PHP Video Course',
        ], [
            'sku' => 'COURSE-PHP-001',
            'price' => 99.99,
            'is_virtual' => true,
            'is_downloadable' => true,
        ]);

        $tools = Thing::create_product(['name' => 'PHP IDE License'], [
            'sku' => 'LICENSE-IDE-001',
            'price' => 79.99,
            'is_virtual' => true,
            'is_service' => true,
        ]);

        // Create customer and address
        $customer = Customer::create_person([
            'email' => 'bundle.buyer@example.com',
        ], [
            'given_name' => 'Bundle',
            'family_name' => 'Buyer',
        ]);

        $address = PostalAddress::make_address([
            'street_address' => '123 Bundle Street',
            'address_locality' => 'BundleCity',
            'is_billing' => true,
            'is_shipping' => true,
        ], $customer);

        // Create order with bundle
        $order = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'Bundle Test Order',
        ], [
            'order_number' => 'ORD-BUNDLE-001',
        ]);

        // Create main bundle order item
        $bundleItem = Thing::create_order_item($order, $bundle, 1, [
            'name' => 'PHP Developer Starter Kit',
        ], [
            'order_item_number' => 'ITEM-BUNDLE-001',
            'unit_price' => 199.99,
            'is_bundle_component' => false,
        ]);

        // Create component items (part of bundle)
        $bookItem = Thing::create_order_item($order, $book, 1, [
            'name' => '[Bundle: PHP Developer Starter Kit] PHP Programming Book',
        ], [
            'order_item_number' => 'ITEM-BUNDLE-001-A',
            'unit_price' => 0,
            'line_total' => 0,
            'parent_bundle_item_id' => $bundleItem['id'],
            'is_bundle_component' => true,
        ]);

        $courseItem = Thing::create_order_item($order, $course, 1, [
            'name' => '[Bundle: PHP Developer Starter Kit] PHP Video Course',
        ], [
            'order_item_number' => 'ITEM-BUNDLE-001-B',
            'unit_price' => 0,
            'line_total' => 0,
            'parent_bundle_item_id' => $bundleItem['id'],
            'is_bundle_component' => true,
        ]);

        $toolsItem = Thing::create_order_item($order, $tools, 1, [
            'name' => '[Bundle: PHP Developer Starter Kit] PHP IDE License',
        ], [
            'order_item_number' => 'ITEM-BUNDLE-001-C',
            'unit_price' => 0,
            'line_total' => 0,
            'parent_bundle_item_id' => $bundleItem['id'],
            'is_bundle_component' => true,
        ]);

        // Test bundle item properties
        $bundleDelegate = $bundleItem->delegate();
        $this->assert('Bundle item is_bundle_component is false', $bundleDelegate->is_bundle_component() === false);
        $this->assert('Bundle item is_bundle is true', $bundleDelegate->is_bundle() === true);

        // Test component item properties
        $bookDelegate = $bookItem->delegate();
        $this->assert('Component item is_bundle_component is true', $bookDelegate->is_bundle_component() === true);
        $this->assert('Component item is_bundle is false', $bookDelegate->is_bundle() === false);

        // Test parent_bundle_item relationship
        $parentItem = $bookDelegate->parent_bundle_item();
        $this->assert('Component has parent_bundle_item', $parentItem !== null);
        $this->assert('Parent bundle item is correct', $parentItem['id'] === $bundleItem['id']);

        // Test bundle_components relationship
        $components = $bundleDelegate->bundle_components();
        $this->assert('Bundle has 3 components', count($components) === 3);

        // Verify component products include virtual products
        $hasVirtualComponent = false;
        foreach ($components as $component) {
            $product = $component->delegate()->product();
            if ($product && $product->delegate()->is_virtual()) {
                $hasVirtualComponent = true;
                break;
            }
        }
        $this->assert('Bundle includes virtual product components', $hasVirtualComponent === true);

        echo "\n";
    }

    private function test_order_totals(): void
    {
        echo "Order Totals Calculation\n";
        echo str_repeat('-', 30) . "\n";

        // Create customer and address
        $customer = Customer::create_person([
            'email' => 'totals.test@example.com',
        ], [
            'given_name' => 'Totals',
            'family_name' => 'Tester',
        ]);

        $address = PostalAddress::make_address([
            'street_address' => '789 Totals Lane',
            'address_locality' => 'TotalCity',
            'is_billing' => true,
            'is_shipping' => true,
        ], $customer);

        // Create order with items and calculate totals
        $product = Thing::create_product(['name' => 'Test Product'], ['price' => 10.00]);

        $order = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'Totals Test Order',
        ], [
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

    private function test_customer_order_history(): void
    {
        echo "Customer Order History\n";
        echo str_repeat('-', 30) . "\n";

        // Create a customer with multiple orders
        $customer = Customer::create_person([
            'email' => 'history.test@example.com',
            'customer_number' => 'HIST-001',
        ], [
            'given_name' => 'History',
            'family_name' => 'Customer',
        ]);

        $address = PostalAddress::make_address([
            'street_address' => '100 History Lane',
            'address_locality' => 'HistoryCity',
            'is_billing' => true,
            'is_shipping' => true,
        ], $customer);

        // Create multiple orders with different dates
        $order1 = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'First Order',
        ], [
            'order_number' => 'HIST-ORD-001',
            'order_date' => '2024-01-15 10:00:00',
            'total_price' => 50.00,
        ]);

        $order2 = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'Second Order',
        ], [
            'order_number' => 'HIST-ORD-002',
            'order_date' => '2024-02-20 14:30:00',
            'total_price' => 75.00,
        ]);

        $order3 = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'Third Order',
        ], [
            'order_number' => 'HIST-ORD-003',
            'order_date' => '2024-03-10 09:15:00',
            'total_price' => 100.00,
        ]);

        // Test order_count
        $this->assert('Customer has 3 orders', $customer->order_count() === 3);

        // Test orders() method
        $orders = $customer->orders();
        $this->assert('orders() returns 3 orders', count($orders) === 3);

        // Test total_spent
        $totalSpent = $customer->total_spent();
        $this->assert('Total spent is $225.00', $totalSpent === 225.00);

        // Test average_order_value
        $avgValue = $customer->average_order_value();
        $this->assert('Average order value is $75.00', $avgValue === 75.00);

        // Test first_order_date
        $firstDate = $customer->first_order_date();
        $this->assert('First order date is 2024-01-15', strpos($firstDate, '2024-01-15') === 0);

        // Test last_order_date
        $lastDate = $customer->last_order_date();
        $this->assert('Last order date is 2024-03-10', strpos($lastDate, '2024-03-10') === 0);

        // Test average_days_between_orders
        $avgDays = $customer->average_days_between_orders();
        $this->assert('Average days between orders calculated', $avgDays !== null && $avgDays > 0);

        // Test customer addresses
        $addresses = $customer->addresses();
        $this->assert('Customer has addresses', count($addresses) >= 1);

        echo "\n";
    }

    private function test_type_checking(): void
    {
        echo "Type Checking\n";
        echo str_repeat('-', 30) . "\n";

        // Create customer and address for order
        $customer = Customer::create_person([
            'email' => 'type.test@example.com',
        ], [
            'given_name' => 'Type',
            'family_name' => 'Tester',
        ]);

        $address = PostalAddress::make_address([
            'street_address' => '111 Type St',
            'address_locality' => 'TypeCity',
            'is_billing' => true,
        ], $customer);

        $product = Thing::create_product(['name' => 'Type Test Product'], []);
        $order = Thing::create_order_for_customer($customer, $address, null, [
            'name' => 'Type Test Order',
        ], [
            'order_number' => 'ORD-TYPE-001',
        ]);

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
