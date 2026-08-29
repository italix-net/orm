<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * PrestaShop Order Importer
 *
 * Imports orders from PrestaShop 1.7+ into the local e-commerce database
 * using the Schema.org-based data model.
 *
 * Features:
 * - Configurable minimum order ID and limit
 * - Skips already imported orders
 * - Handles product bundles/packs
 * - Maps PrestaShop statuses to Schema.org OrderStatus
 *
 * Usage:
 *   php ImportOrders.php [--min-id=N] [--limit=N] [--debug]
 *
 * @see https://devdocs.prestashop-project.org/1.7/webservice/
 */

namespace Examples\Ecommerce\PrestaShop;

require_once __DIR__ . '/../../../src/autoload.php';
require_once __DIR__ . '/../../../src/ActiveRow/functions.php';
require_once __DIR__ . '/PrestaShopClient.php';

use Italix\Orm\Dialects\Driver;
use Italix\Orm\DataManager;
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
        $file = __DIR__ . '/../' . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

/**
 * PrestaShop Order Importer
 */
class ImportOrders
{
    private PrestaShopClient $client;
    private DataManager $dm;
    private Schema $schema;
    private array $config;
    private array $product_cache = [];
    private array $customer_cache = [];
    private array $address_cache = [];

    /**
     * Import statistics
     */
    private int $orders_imported = 0;
    private int $orders_skipped = 0;
    private int $products_created = 0;
    private int $items_created = 0;
    private array $errors = [];

    public function __construct(array $config)
    {
        $this->config = $config;

        // Initialize PrestaShop client
        $this->client = new PrestaShopClient(
            $config['prestashop_url'],
            $config['prestashop_api_key'],
            $config['debug'] ?? false
        );

        // Initialize local database
        $this->init_database();
    }

    /**
     * Initialize the local database connection
     */
    private function init_database(): void
    {
        $db_type = $this->config['db_type'] ?? 'sqlite';

        switch ($db_type) {
            case 'mysql':
                $driver = Driver::mysql(
                    $this->config['db_host'],
                    $this->config['db_user'],
                    $this->config['db_password'],
                    $this->config['db_name'],
                    $this->config['db_port'] ?? 3306
                );
                break;

            case 'postgresql':
                $driver = Driver::postgres(
                    $this->config['db_host'],
                    $this->config['db_user'],
                    $this->config['db_password'],
                    $this->config['db_name'],
                    $this->config['db_port'] ?? 5432
                );
                break;

            case 'sqlite':
            default:
                $db_path = $this->config['db_sqlite_path'] ?? __DIR__ . '/ecommerce.db';
                $driver = Driver::sqlite($db_path);
                break;
        }

        $this->dm = new DataManager($driver);
        $this->schema = new Schema();

        // Create tables if they don't exist
        $this->dm->create_tables(...$this->schema->get_tables());

        // Set up persistence for Thing hierarchy
        Thing::set_persistence($this->dm, $this->schema->things);
        Product::set_persistence($this->dm, $this->schema->products);
        Order::set_persistence($this->dm, $this->schema->orders);
        OrderItem::set_persistence($this->dm, $this->schema->order_items);

        // Set up persistence for Customer hierarchy
        Customer::set_persistence($this->dm, $this->schema->customers);
        Person::set_persistence($this->dm, $this->schema->persons);
        Organization::set_persistence($this->dm, $this->schema->organizations);

        // Set up persistence for PostalAddress
        PostalAddress::set_persistence($this->dm, $this->schema->postal_addresses);
    }

    /**
     * Run the import
     *
     * @param int|null $minOrderId Override minimum order ID
     * @param int|null $limit Override orders limit
     * @return array Import statistics
     */
    public function run(?int $min_order_id = null, ?int $limit = null): array
    {
        $min_order_id = $min_order_id ?? ($this->config['min_order_id'] ?? 1);
        $limit = $limit ?? ($this->config['orders_limit'] ?? 100);

        $this->log("Starting PrestaShop order import...");
        $this->log("  Min Order ID: {$min_order_id}");
        $this->log("  Limit: {$limit}");

        // Test connection
        if (!$this->client->test_connection()) {
            $this->error("Failed to connect to PrestaShop API");
            return $this->get_stats();
        }

        $this->log("Connected to PrestaShop API");

        // Fetch orders
        $orders = $this->client->get_orders($min_order_id, $limit);
        $this->log("Fetched " . count($orders) . " orders from PrestaShop");

        foreach ($orders as $ps_order) {
            try {
                $this->import_order($ps_order);
            } catch (\Exception $e) {
                $this->error("Failed to import order #{$ps_order['id']}: " . $e->getMessage());
            }
        }

        $this->log("\nImport completed!");
        $this->log("  Orders imported: {$this->orders_imported}");
        $this->log("  Orders skipped: {$this->orders_skipped}");
        $this->log("  Products created: {$this->products_created}");
        $this->log("  Items created: {$this->items_created}");
        $this->log("  Errors: " . count($this->errors));

        return $this->get_stats();
    }

    /**
     * Import a single order
     *
     * @param array $psOrder PrestaShop order data
     */
    private function import_order(array $ps_order): void
    {
        $ps_order_id = (int) $ps_order['id'];
        $ps_order_ref = $ps_order['reference'] ?? "PS-{$ps_order_id}";

        // Check if order already exists
        if ($this->order_exists($ps_order_ref)) {
            $this->log("  Skipping order #{$ps_order_id} ({$ps_order_ref}) - already imported");
            $this->orders_skipped++;
            return;
        }

        $this->log("  Importing order #{$ps_order_id} ({$ps_order_ref})...");

        // Get customer info from PrestaShop
        $ps_customer = $this->get_customer((int) $ps_order['id_customer']);

        // Get addresses from PrestaShop
        $ps_shipping_address = $this->get_address((int) $ps_order['id_address_delivery']);
        $ps_billing_address = $this->get_address((int) $ps_order['id_address_invoice']);

        // Get or create customer (Person or Organization based on VAT in billing address)
        $customer = $this->get_or_create_customer($ps_customer, $ps_billing_address);

        // Create postal addresses
        $billing_address = $this->create_postal_address($ps_billing_address, $customer, true, false);
        $delivery_address = $this->create_postal_address($ps_shipping_address, $customer, false, true);

        // Map order status
        $order_status = $this->map_order_status((int) $ps_order['current_state']);

        // Create the order with customer and addresses
        $order = Thing::create_order_for_customer(
            $customer,
            $billing_address,
            $delivery_address,
            [
                'name' => "Order {$ps_order_ref}",
                'description' => "Imported from PrestaShop (ID: {$ps_order_id})",
            ],
            [
                'order_number' => $ps_order_ref,
                'order_status' => $order_status,
                'order_date' => $ps_order['date_add'] ?? date('Y-m-d H:i:s'),
                'subtotal' => (float) ($ps_order['total_products'] ?? 0),
                'tax' => (float) ($ps_order['total_products_wt'] ?? 0) - (float) ($ps_order['total_products'] ?? 0),
                'shipping_cost' => (float) ($ps_order['total_shipping'] ?? 0),
                'discount' => (float) ($ps_order['total_discounts'] ?? 0),
                'total_price' => (float) ($ps_order['total_paid'] ?? 0),
                'currency' => $this->config['default_currency'] ?? 'EUR',
                'payment_method' => $ps_order['payment'] ?? 'Unknown',
                'payment_status' => $this->map_payment_status($ps_order),
            ]
        );

        $this->orders_imported++;

        // Import order items
        $order_details = $ps_order['associations']['order_rows'] ?? [];

        foreach ($order_details as $item) {
            $this->import_order_item($order, $item);
        }

        $this->log("    Customer: {$customer->display_name()} (" . ($customer->is_organization() ? 'Organization' : 'Person') . ")");
    }

    /**
     * Get or create a Customer from PrestaShop data
     *
     * @param array $psCustomer PrestaShop customer data
     * @param array $psBillingAddress PrestaShop billing address (to check for VAT)
     * @return Customer
     */
    private function get_or_create_customer(array $ps_customer, array $ps_billing_address): Customer
    {
        $email = $ps_customer['email'] ?? '';
        $ps_customer_id = (int) ($ps_customer['id'] ?? 0);

        // Check cache first
        $cache_key = "customer_{$ps_customer_id}";
        if (isset($this->customer_cache[$cache_key]) && $this->customer_cache[$cache_key] instanceof Customer) {
            return $this->customer_cache[$cache_key];
        }

        // Try to find existing customer by email
        if ($email) {
            $existing = Customer::find_by_email($email);
            if ($existing) {
                $this->customer_cache[$cache_key] = $existing;
                return $existing;
            }
        }

        // Determine if Organization (has VAT number) or Person
        $vat_id = $ps_billing_address['vat_number'] ?? '';
        $has_vat = !empty($vat_id);

        // Customer base data
        $customer_data = [
            'email' => $email,
            'telephone' => $ps_billing_address['phone'] ?? $ps_billing_address['phone_mobile'] ?? null,
            'customer_number' => "PS-{$ps_customer_id}",
            'customer_since' => $ps_customer['date_add'] ?? date('Y-m-d H:i:s'),
            'customer_type' => ($ps_customer['is_guest'] ?? '0') === '1' ? 'guest' : 'registered',
        ];

        if ($has_vat) {
            // Create Organization
            $org_data = [
                'legal_name' => $ps_billing_address['company'] ?? null,
                'trading_name' => $ps_billing_address['company'] ?? null,
                'vat_id' => $vat_id,
                'contact_name' => $this->format_customer_name($ps_customer),
                'contact_email' => $email,
                'contact_phone' => $ps_billing_address['phone'] ?? null,
            ];

            $customer = Customer::create_organization($customer_data, $org_data);
            $this->log("    Created Organization customer: " . ($ps_billing_address['company'] ?? 'Unknown'));
        } else {
            // Create Person
            $person_data = [
                'given_name' => $ps_customer['firstname'] ?? null,
                'family_name' => $ps_customer['lastname'] ?? null,
                'gender' => $this->map_gender($ps_customer['id_gender'] ?? 0),
                'birth_date' => $ps_customer['birthday'] ?? null,
            ];

            $customer = Customer::create_person($customer_data, $person_data);
            $this->log("    Created Person customer: " . $this->format_customer_name($ps_customer));
        }

        $this->customer_cache[$cache_key] = $customer;
        return $customer;
    }

    /**
     * Create a PostalAddress from PrestaShop address data
     *
     * @param array $psAddress PrestaShop address data
     * @param Customer $customer The customer
     * @param bool $isBilling Whether this is a billing address
     * @param bool $isShipping Whether this is a shipping address
     * @return PostalAddress
     */
    private function create_postal_address(array $ps_address, Customer $customer, bool $is_billing, bool $is_shipping): PostalAddress
    {
        $address_data = [
            'address_name' => $ps_address['alias'] ?? ($is_billing ? 'Billing' : 'Shipping'),
            'street_address' => trim(($ps_address['address1'] ?? '') . "\n" . ($ps_address['address2'] ?? '')),
            'address_locality' => $ps_address['city'] ?? null,
            'address_region' => null, // PrestaShop uses id_state, would need lookup
            'postal_code' => $ps_address['postcode'] ?? null,
            'address_country' => $ps_address['country'] ?? null,
            'contact_name' => trim(($ps_address['firstname'] ?? '') . ' ' . ($ps_address['lastname'] ?? '')),
            'telephone' => $ps_address['phone'] ?? $ps_address['phone_mobile'] ?? null,
            'is_billing' => $is_billing,
            'is_shipping' => $is_shipping,
        ];

        return PostalAddress::make_address($address_data, $customer);
    }

    /**
     * Map PrestaShop gender ID to string
     *
     * @param int $genderId PrestaShop gender ID (1=Male, 2=Female)
     * @return string|null
     */
    private function map_gender(int $gender_id): ?string
    {
        return match ($gender_id) {
            1 => 'Male',
            2 => 'Female',
            default => null,
        };
    }

    /**
     * Import a single order item
     *
     * @param Thing $order The order Thing
     * @param array $item PrestaShop order row data
     * @return Thing|null The created order item
     */
    private function import_order_item(Thing $order, array $item): ?Thing
    {
        $product_id = (int) $item['product_id'];
        $combination_id = (int) ($item['product_attribute_id'] ?? 0);
        $product_name = $item['product_name'] ?? 'Unknown Product';
        $quantity = (int) ($item['product_quantity'] ?? 1);
        $unit_price = (float) ($item['unit_price_tax_incl'] ?? $item['product_price'] ?? 0);

        // Get or create the product (handles variants automatically)
        $product = $this->get_or_create_product_with_variant($product_id, $combination_id, $product_name, $item);

        if (!$product) {
            $this->error("    Could not create product for item: {$product_name}");
            return null;
        }

        // Get product type info
        $type_info = $this->client->get_product_type_info($product_id);
        $is_pack = $type_info['is_pack'];
        $is_virtual = $type_info['is_virtual'];

        // Build description based on product type
        $description = null;
        if ($is_pack) {
            $description = 'Product Bundle';
        } elseif ($type_info['is_downloadable']) {
            $description = 'Downloadable Product';
        } elseif ($is_virtual) {
            $description = 'Virtual Product/Service';
        }

        // Create order item
        $order_item = Thing::create_order_item($order, $product, $quantity, [
            'name' => $product_name,
            'description' => $description,
        ], [
            'order_item_number' => "ITEM-{$item['id']}",
            'unit_price' => $unit_price,
            'line_total' => $unit_price * $quantity,
            'order_item_status' => $order->delegate()->status(),
            'is_bundle_component' => false, // This is a top-level item, not a component
        ]);

        $this->items_created++;

        // If it's a pack, also import the pack items as sub-items with parent reference
        if ($is_pack) {
            $this->import_pack_items($order, $order_item, $product_id, $quantity);
        }

        // Log product type
        $type_label = $type_info['type'];
        if ($combination_id > 0) {
            $type_label = 'variant';
        }
        $this->log("    Item: {$product_name} (type: {$type_label})");

        return $order_item;
    }

    /**
     * Import pack/bundle items
     *
     * @param Thing $order The order
     * @param Thing $parentOrderItem The parent bundle order item (for tracking relationship)
     * @param int $psProductId PrestaShop product ID
     * @param int $packQuantity Quantity of packs ordered
     */
    private function import_pack_items(Thing $order, Thing $parent_order_item, int $ps_product_id, int $pack_quantity): void
    {
        $pack_items = $this->client->get_pack_items($ps_product_id);

        if (empty($pack_items)) {
            return;
        }

        $this->log("      Importing " . count($pack_items) . " bundle items...");

        foreach ($pack_items as $pack_item) {
            $item_product_id = (int) ($pack_item['id'] ?? $pack_item['id_product'] ?? 0);
            $item_quantity = (int) ($pack_item['quantity'] ?? 1);
            $item_combination_id = (int) ($pack_item['id_product_attribute'] ?? 0);

            if ($item_product_id === 0) {
                continue;
            }

            // Get product info
            $ps_product = $this->client->get_product($item_product_id);
            if (!$ps_product) {
                continue;
            }

            $product_name = $this->get_product_name($ps_product);

            // Handle bundle items that may have combinations (variants)
            $product = null;
            if ($item_combination_id > 0) {
                $product = $this->get_or_create_product_with_variant($item_product_id, $item_combination_id, $product_name, []);
            } else {
                $product = $this->get_or_create_product($item_product_id, $product_name, []);
            }

            if (!$product) {
                continue;
            }

            // Get parent order item name for reference
            $bundle_name = $parent_order_item['name'] ?? 'Bundle';

            // Create order item for bundle component with parent reference
            Thing::create_order_item($order, $product, $item_quantity * $pack_quantity, [
                'name' => "[Bundle: {$bundle_name}] {$product_name}",
                'description' => "Part of bundle product",
            ], [
                'order_item_number' => "BUNDLE-{$ps_product_id}-{$item_product_id}",
                'unit_price' => 0, // Bundle items have 0 price (price is on the bundle)
                'line_total' => 0,
                'order_item_status' => $order->delegate()->status(),
                'parent_bundle_item_id' => $parent_order_item['id'], // Link to parent bundle item
                'is_bundle_component' => true,
            ]);

            $this->items_created++;
            $this->log("        - {$product_name} x{$item_quantity}");
        }
    }

    /**
     * Get or create a product with variant support
     *
     * @param int $psProductId PrestaShop product ID
     * @param int $combinationId PrestaShop combination ID (0 for simple products)
     * @param string $name Product name
     * @param array $itemData Additional item data
     * @return Thing|null
     */
    private function get_or_create_product_with_variant(int $ps_product_id, int $combination_id, string $name, array $item_data): ?Thing
    {
        // If no combination, it's a simple product
        if ($combination_id === 0) {
            return $this->get_or_create_product($ps_product_id, $name, $item_data);
        }

        // Cache key for this specific variant
        $variant_cache_key = "ps_{$ps_product_id}_comb_{$combination_id}";
        if (isset($this->product_cache[$variant_cache_key])) {
            return $this->product_cache[$variant_cache_key];
        }

        // Check if variant exists by SKU
        $combination = $this->client->get_combination($combination_id);
        $variant_sku = $combination['reference'] ?? $item_data['product_reference'] ?? "PS-{$ps_product_id}-{$combination_id}";

        $existing_products = Thing::find_products();
        foreach ($existing_products as $existing) {
            $delegate = $existing->delegate();
            if ($delegate && $delegate['sku'] === $variant_sku) {
                $this->product_cache[$variant_cache_key] = $existing;
                return $existing;
            }
        }

        // First, ensure the parent ProductGroup exists
        $parent_group = $this->get_or_create_product_group($ps_product_id, $name);
        if (!$parent_group) {
            // Fall back to simple product if we can't create group
            return $this->get_or_create_product($ps_product_id, $name, $item_data);
        }

        // Get variant attributes (color, size, etc.)
        $variant_attrs = $this->client->get_combination_attributes($combination);

        // Get combination-specific data
        $price = (float) ($item_data['unit_price_tax_incl'] ?? $combination['price'] ?? 0);
        $quantity = (int) ($combination['quantity'] ?? 0);

        // If combination price is 0, use parent product price + impact
        if ($price <= 0) {
            $ps_product = $this->client->get_product($ps_product_id);
            $base_price = (float) ($ps_product['price'] ?? 0);
            $price_impact = (float) ($combination['price'] ?? 0);
            $price = $base_price + $price_impact;
        }

        // Create the variant using the new create_variant method
        $variant = Thing::create_variant($parent_group, $variant_attrs, [
            'sku' => $variant_sku,
            'gtin' => $combination['ean13'] ?? null,
            'price' => $price,
            'currency' => $this->config['default_currency'] ?? 'EUR',
            'availability' => $quantity > 0 ? 'InStock' : 'OutOfStock',
            'inventory_level' => $quantity,
        ]);

        $this->product_cache[$variant_cache_key] = $variant;
        $this->products_created++;

        $variant_desc = $variant->delegate()->variant_description();
        $this->log("    Created variant: {$parent_group['name']} - {$variant_desc} (SKU: {$variant_sku})");

        return $variant;
    }

    /**
     * Get or create a ProductGroup (parent for variants)
     *
     * @param int $psProductId PrestaShop product ID
     * @param string $name Product name
     * @return Thing|null
     */
    private function get_or_create_product_group(int $ps_product_id, string $name): ?Thing
    {
        $cache_key = "ps_{$ps_product_id}_group";
        if (isset($this->product_cache[$cache_key])) {
            return $this->product_cache[$cache_key];
        }

        // Check if ProductGroup already exists
        $existing_products = Thing::find_products();
        foreach ($existing_products as $existing) {
            $delegate = $existing->delegate();
            if ($delegate && $delegate['is_group'] && str_contains($delegate['sku'] ?? '', "PS-{$ps_product_id}")) {
                // Make sure it's the group, not a variant
                if (!$delegate->is_variant()) {
                    $this->product_cache[$cache_key] = $existing;
                    return $existing;
                }
            }
        }

        // Fetch product details from PrestaShop
        $ps_product = $this->client->get_product($ps_product_id);
        if (!$ps_product) {
            return null;
        }

        // Parse the product name (remove variant info if present)
        $group_name = $this->parse_base_product_name($name);

        // Determine what this product varies by
        $varies_by = $this->determine_varies_by($ps_product_id);

        // Create the ProductGroup
        $product_group = Thing::create_product_group([
            'name' => $group_name,
            'description' => $this->get_product_description($ps_product),
            'url' => $this->config['prestashop_url'] . '/index.php?id_product=' . $ps_product_id . '&controller=product',
        ], [
            'sku' => "PS-{$ps_product_id}",
            'gtin' => $ps_product['ean13'] ?? null,
            'brand' => $ps_product['manufacturer_name'] ?? null,
            'price' => (float) ($ps_product['price'] ?? 0),
            'currency' => $this->config['default_currency'] ?? 'EUR',
            'varies_by' => $varies_by,
        ]);

        $this->product_cache[$cache_key] = $product_group;
        $this->products_created++;

        $this->log("    Created ProductGroup: {$group_name} (varies by: {$varies_by}) [VARIANTS]");

        return $product_group;
    }

    /**
     * Determine what a product varies by (color, size, etc.)
     *
     * @param int $psProductId
     * @return string Comma-separated list of variation types
     */
    private function determine_varies_by(int $ps_product_id): string
    {
        $combinations = $this->client->get_product_combinations($ps_product_id);

        if (empty($combinations)) {
            return '';
        }

        // Get attributes from first combination to determine types
        $attr_types = [];
        foreach ($combinations as $combination) {
            $attrs = $this->client->get_combination_attributes($combination);
            foreach (array_keys($attrs) as $attr_type) {
                if (!in_array($attr_type, $attr_types, true)) {
                    $attr_types[] = $attr_type;
                }
            }
            // Usually all combinations have same attribute types, so check first few
            if (count($attr_types) > 0 && count($combinations) > 3) {
                break;
            }
        }

        return implode(', ', $attr_types);
    }

    /**
     * Parse base product name (remove variant info like " - Red, M")
     *
     * @param string $name
     * @return string
     */
    private function parse_base_product_name(string $name): string
    {
        // PrestaShop often appends variant info after " - "
        $parts = explode(' - ', $name);
        return trim($parts[0]);
    }

    /**
     * Get or create a product in the local database
     *
     * Handles all PrestaShop product types:
     * - Simple products (physical)
     * - Virtual products (downloadable files, services)
     * - Product packs/bundles
     *
     * @param int $psProductId PrestaShop product ID
     * @param string $name Product name
     * @param array $itemData Additional item data
     * @return Thing|null
     */
    private function get_or_create_product(int $ps_product_id, string $name, array $item_data): ?Thing
    {
        // Check cache first
        $cache_key = "ps_{$ps_product_id}";
        if (isset($this->product_cache[$cache_key])) {
            return $this->product_cache[$cache_key];
        }

        // Check if product exists in database
        $existing_products = Thing::find_products();
        foreach ($existing_products as $existing) {
            $delegate = $existing->delegate();
            // Match by SKU that contains PrestaShop ID
            if ($delegate && str_contains($delegate['sku'] ?? '', "PS-{$ps_product_id}")) {
                $this->product_cache[$cache_key] = $existing;
                return $existing;
            }
        }

        // Fetch product details from PrestaShop
        $ps_product = $this->client->get_product($ps_product_id);

        $sku = $item_data['product_reference'] ?? $ps_product['reference'] ?? "PS-{$ps_product_id}";
        $price = (float) ($item_data['unit_price_tax_incl'] ?? $ps_product['price'] ?? 0);

        // Get detailed product type info
        $type_info = $this->client->get_product_type_info($ps_product_id);

        // Determine if it's a bundle
        $is_pack = $type_info['is_pack'];

        // Build product data
        $product_data = [
            'sku' => $sku,
            'gtin' => $ps_product['ean13'] ?? null,
            'brand' => $ps_product['manufacturer_name'] ?? null,
            'price' => $price,
            'currency' => $this->config['default_currency'] ?? 'EUR',
            'availability' => ((int)($ps_product['quantity'] ?? 0)) > 0 ? 'InStock' : 'OutOfStock',
            'inventory_level' => (int) ($ps_product['quantity'] ?? 0),
            'is_group' => $is_pack, // Mark bundles as ProductGroup
            'is_bundle' => $is_pack, // Also mark as bundle for export

            // Virtual product fields
            'is_virtual' => $type_info['is_virtual'],
            'is_downloadable' => $type_info['is_downloadable'],
            'is_service' => $type_info['is_virtual'] && !$type_info['is_downloadable'],
        ];

        // Add download info for downloadable products
        if ($type_info['is_downloadable'] && $type_info['download_info']) {
            $download_info = $type_info['download_info'];
            $product_data['download_limit'] = $download_info['nb_downloadable'] ?? null;
            $product_data['download_expiry_days'] = $download_info['nb_days_accessible'] ?? null;
            // Note: download_url is typically generated per-order, not stored on product
        }

        // Add bundle items for pack products
        if ($is_pack) {
            $pack_items = $this->client->get_pack_items($ps_product_id);
            $bundle_items = [];
            foreach ($pack_items as $pack_item) {
                $bundle_items[] = [
                    'product_id' => (int) ($pack_item['id'] ?? $pack_item['id_product'] ?? 0),
                    'quantity' => (int) ($pack_item['quantity'] ?? 1),
                    'prestashop_id' => (int) ($pack_item['id'] ?? $pack_item['id_product'] ?? 0),
                ];
            }
            if (!empty($bundle_items)) {
                $product_data['bundle_items'] = json_encode($bundle_items, JSON_UNESCAPED_UNICODE);
            }
        }

        // Create the product
        $product = Thing::create_product([
            'name' => $name,
            'description' => $this->get_product_description($ps_product),
            'url' => $this->config['prestashop_url'] . '/index.php?id_product=' . $ps_product_id . '&controller=product',
        ], $product_data);

        $this->product_cache[$cache_key] = $product;
        $this->products_created++;

        // Log with product type
        $type_label = $type_info['type'];
        if ($is_pack) {
            $bundle_count = count($bundle_items ?? []);
            $this->log("    Created product: {$name} (SKU: {$sku}) [TYPE: {$type_label}] ({$bundle_count} items)");
        } else {
            $this->log("    Created product: {$name} (SKU: {$sku}) [TYPE: {$type_label}]");
        }

        return $product;
    }

    /**
     * Check if an order already exists in the local database
     *
     * @param string $orderNumber The order reference/number
     * @return bool
     */
    private function order_exists(string $order_number): bool
    {
        $orders = Thing::find_orders();

        foreach ($orders as $order) {
            $delegate = $order->delegate();
            if ($delegate && $delegate['order_number'] === $order_number) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get customer data (with caching)
     */
    private function get_customer(int $customer_id): array
    {
        if (!isset($this->customer_cache[$customer_id])) {
            $this->customer_cache[$customer_id] = $this->client->get_customer($customer_id) ?? [];
        }
        return $this->customer_cache[$customer_id];
    }

    /**
     * Get address data (with caching)
     */
    private function get_address(int $address_id): array
    {
        if (!isset($this->address_cache[$address_id])) {
            $this->address_cache[$address_id] = $this->client->get_address($address_id) ?? [];
        }
        return $this->address_cache[$address_id];
    }

    /**
     * Format customer name from customer data
     */
    private function format_customer_name(array $customer): string
    {
        $parts = array_filter([
            $customer['firstname'] ?? '',
            $customer['lastname'] ?? '',
        ]);

        return implode(' ', $parts) ?: 'Unknown Customer';
    }

    /**
     * Format address as a string
     */
    private function format_address(array $address): string
    {
        if (empty($address)) {
            return '';
        }

        $parts = array_filter([
            $address['address1'] ?? '',
            $address['address2'] ?? '',
            $address['postcode'] ?? '',
            $address['city'] ?? '',
            $address['country'] ?? '',
        ]);

        return implode("\n", $parts);
    }

    /**
     * Get product name from PrestaShop product data
     */
    private function get_product_name(array $ps_product): string
    {
        // Product name can be in different places depending on API response
        if (isset($ps_product['name'])) {
            if (is_array($ps_product['name'])) {
                // Multi-language: get first available
                foreach ($ps_product['name'] as $lang) {
                    if (isset($lang['value']) && !empty($lang['value'])) {
                        return $lang['value'];
                    }
                }
            }
            return (string) $ps_product['name'];
        }

        return 'Unknown Product';
    }

    /**
     * Get product description from PrestaShop product data
     */
    private function get_product_description(array $ps_product): ?string
    {
        if (!$ps_product) {
            return null;
        }

        $desc = $ps_product['description_short'] ?? $ps_product['description'] ?? null;

        if (is_array($desc)) {
            foreach ($desc as $lang) {
                if (isset($lang['value']) && !empty($lang['value'])) {
                    return strip_tags($lang['value']);
                }
            }
            return null;
        }

        return $desc ? strip_tags($desc) : null;
    }

    /**
     * Map PrestaShop order state to Schema.org OrderStatus
     */
    private function map_order_status(int $state_id): string
    {
        $map = $this->config['order_status_map'] ?? [];
        return $map[$state_id] ?? 'OrderProcessing';
    }

    /**
     * Map payment status from PrestaShop order
     */
    private function map_payment_status(array $ps_order): string
    {
        $paid = (float) ($ps_order['total_paid_real'] ?? 0);
        $total = (float) ($ps_order['total_paid'] ?? 0);

        if ($paid >= $total && $total > 0) {
            return 'Paid';
        } elseif ($paid > 0) {
            return 'PartiallyPaid';
        }

        return 'Pending';
    }

    /**
     * Log a message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}";

        echo $line . "\n";

        if (!empty($this->config['log_file'])) {
            file_put_contents($this->config['log_file'], $line . "\n", FILE_APPEND);
        }
    }

    /**
     * Log an error
     */
    private function error(string $message): void
    {
        $this->errors[] = $message;
        $this->log("ERROR: {$message}");
    }

    /**
     * Get import statistics
     */
    public function get_stats(): array
    {
        return [
            'orders_imported' => $this->orders_imported,
            'orders_skipped' => $this->orders_skipped,
            'products_created' => $this->products_created,
            'items_created' => $this->items_created,
            'errors' => $this->errors,
        ];
    }
}

// =============================================================================
// CLI RUNNER
// =============================================================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    // Parse command line arguments
    $options = getopt('', ['min-id::', 'limit::', 'debug', 'help']);

    if (isset($options['help'])) {
        echo <<<HELP
PrestaShop Order Importer

Usage:
  php ImportOrders.php [options]

Options:
  --min-id=N    Minimum order ID to fetch (default: from config)
  --limit=N     Maximum orders to fetch (default: from config)
  --debug       Enable debug output
  --help        Show this help message

Configuration:
  Copy config.example.php to config.php and fill in your PrestaShop
  API credentials and other settings.

Example:
  php ImportOrders.php --min-id=1000 --limit=50 --debug

HELP;
        exit(0);
    }

    // Load configuration
    $config_file = __DIR__ . '/config.php';
    if (!file_exists($config_file)) {
        echo "Error: Configuration file not found.\n";
        echo "Please copy config.example.php to config.php and fill in your settings.\n";
        exit(1);
    }

    $config = require $config_file;

    // Apply CLI overrides
    if (isset($options['debug'])) {
        $config['debug'] = true;
    }

    $min_id = isset($options['min-id']) ? (int) $options['min-id'] : null;
    $limit = isset($options['limit']) ? (int) $options['limit'] : null;

    // Run the import
    $importer = new ImportOrders($config);
    $stats = $importer->run($min_id, $limit);

    // Exit with error code if there were errors
    exit(count($stats['errors']) > 0 ? 1 : 0);
}
