<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * WooCommerce Product Exporter
 *
 * Exports local products to a WordPress WooCommerce store using the REST API.
 *
 * Usage:
 *   php examples/Ecommerce/WooCommerce/ExportProducts.php [options]
 *
 * Options:
 *   --sku=SKU        Export only product with this SKU
 *   --limit=N        Limit number of products to export
 *   --update         Update existing products (default: skip)
 *   --dry-run        Show what would be exported without actually exporting
 *   --debug          Enable debug output
 *
 * @see https://woocommerce.github.io/woocommerce-rest-api-docs/
 */

namespace Examples\Ecommerce\WooCommerce;

require_once __DIR__ . '/../../../src/autoload.php';
require_once __DIR__ . '/../../../src/ActiveRow/functions.php';

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
 * WooCommerce Product Exporter
 */
class ExportProducts
{
    private WooCommerceClient $client;
    private DataManager $dm;
    private Schema $schema;
    private array $config;
    private bool $debug;
    private bool $dry_run;
    private bool $update_existing;

    // Statistics
    private int $products_exported = 0;
    private int $products_updated = 0;
    private int $products_skipped = 0;
    private int $variations_created = 0;
    private int $bundles_exported = 0;
    private int $errors = 0;

    // Bundle export strategy: 'bundle' (plugin), 'grouped' (native), or 'simple'
    private string $bundle_strategy = 'grouped';

    // Cache for WooCommerce data
    private array $attribute_cache = [];
    private array $category_cache = [];

    // Cache for exported products (needed for bundle component references)
    private array $exported_products_cache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->debug = $config['debug'] ?? false;
        $this->dry_run = $config['dry_run'] ?? false;
        $this->update_existing = $config['update_existing'] ?? false;

        // Initialize WooCommerce client
        $this->client = new WooCommerceClient(
            $config['woocommerce_url'],
            $config['woocommerce_consumer_key'],
            $config['woocommerce_consumer_secret'],
            $config['woocommerce_api_version'] ?? 'wc/v3',
            $this->debug
        );

        // Initialize local database
        $this->init_database();
    }

    /**
     * Initialize local database connection
     */
    private function init_database(): void
    {
        $db_type = $this->config['db_type'] ?? 'sqlite';

        if ($db_type === 'sqlite') {
            $db_path = $this->config['db_sqlite_path'] ?? __DIR__ . '/../ecommerce.db';
            $driver = Driver::sqlite($db_path);
        } else {
            throw new \RuntimeException("Unsupported database type: {$db_type}");
        }

        $this->dm = new DataManager($driver);
        $this->schema = new Schema();

        // Set up persistence for models
        Thing::set_persistence($this->dm, $this->schema->things);
        Product::set_persistence($this->dm, $this->schema->products);
        Order::set_persistence($this->dm, $this->schema->orders);
        OrderItem::set_persistence($this->dm, $this->schema->order_items);
        Customer::set_persistence($this->dm, $this->schema->customers);
        Person::set_persistence($this->dm, $this->schema->persons);
        Organization::set_persistence($this->dm, $this->schema->organizations);
        PostalAddress::set_persistence($this->dm, $this->schema->postal_addresses);
    }

    /**
     * Run the export
     *
     * @param array $options Export options
     */
    public function run(array $options = []): void
    {
        $this->log("WooCommerce Product Exporter");
        $this->log(str_repeat('=', 50));

        if ($this->dry_run) {
            $this->log("DRY RUN MODE - No changes will be made\n");
        }

        // Test connection
        if (!$this->dry_run) {
            $this->log("Testing WooCommerce connection...");
            if (!$this->client->test_connection()) {
                $this->error("Failed to connect to WooCommerce API");
                return;
            }
            $this->log("Connected successfully!\n");
        }

        // Pre-load WooCommerce attributes and categories
        if (!$this->dry_run) {
            $this->load_woo_commerce_data();
            $this->detect_bundle_support();
        }

        // Get products to export
        $products = $this->get_products_to_export($options);
        $this->log("Found " . count($products) . " products to export\n");

        foreach ($products as $thing) {
            $this->export_product($thing);
        }

        $this->print_summary();
    }

    /**
     * Load WooCommerce attributes and categories into cache
     */
    private function load_woo_commerce_data(): void
    {
        $this->log("Loading WooCommerce attributes...");
        $attributes = $this->client->get_attributes();
        foreach ($attributes as $attr) {
            $this->attribute_cache[$attr['slug']] = $attr;
        }
        $this->log("  Loaded " . count($attributes) . " attributes");

        $this->log("Loading WooCommerce categories...");
        $categories = $this->client->get_categories(['per_page' => 100]);
        foreach ($categories as $cat) {
            $this->category_cache[$cat['slug']] = $cat;
        }
        $this->log("  Loaded " . count($categories) . " categories\n");
    }

    /**
     * Detect WooCommerce bundle plugin support
     */
    private function detect_bundle_support(): void
    {
        $this->log("Checking bundle support...");
        $this->bundle_strategy = $this->client->get_bundle_strategy();

        if ($this->bundle_strategy === 'bundle') {
            $this->log("  WooCommerce Product Bundles plugin detected!");
            $this->log("  Bundles will be exported as 'bundle' type products.\n");
        } else {
            $this->log("  No bundle plugin detected.");
            $this->log("  Bundles will be exported as 'grouped' products (components sold separately).\n");
        }
    }

    /**
     * Get products to export based on options
     *
     * @param array $options
     * @return array<Thing>
     */
    private function get_products_to_export(array $options): array
    {
        $all_products = Thing::find_products();

        // Filter out variants (they're exported with their parent)
        $products = array_filter($all_products, function ($thing) {
            $delegate = $thing->delegate();
            return !$delegate->is_variant();
        });

        // Filter by SKU if specified
        if (!empty($options['sku'])) {
            $products = array_filter($products, function ($thing) use ($options) {
                $delegate = $thing->delegate();
                return $delegate['sku'] === $options['sku'];
            });
        }

        // Apply limit
        if (!empty($options['limit'])) {
            $products = array_slice($products, 0, (int) $options['limit']);
        }

        return array_values($products);
    }

    /**
     * Export a single product to WooCommerce
     *
     * @param Thing $thing
     */
    private function export_product(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $sku = $delegate['sku'] ?? '';
        $name = $thing['name'];

        $this->log("Exporting: {$name} (SKU: {$sku})");

        // Check if product already exists
        if (!$this->dry_run && $sku) {
            $existing = $this->client->find_product_by_sku($sku);
            if ($existing) {
                if ($this->update_existing) {
                    $this->update_existing_product($thing, $existing);
                } else {
                    $this->log("  Skipped (already exists, use --update to update)");
                    $this->products_skipped++;
                }
                return;
            }
        }

        // Determine product type and build data
        if ($delegate->is_bundle()) {
            $this->export_bundle_product($thing);
        } elseif ($delegate->is_group()) {
            $this->export_variable_product($thing);
        } else {
            $this->export_simple_product($thing);
        }
    }

    /**
     * Export a simple product (including virtual/downloadable)
     *
     * @param Thing $thing
     */
    private function export_simple_product(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $data = $this->build_product_data($thing);

        // Set type based on product characteristics
        $data['type'] = 'simple';

        // Handle virtual products
        if ($delegate->is_virtual()) {
            $data['virtual'] = true;

            if ($delegate->is_downloadable()) {
                $data['downloadable'] = true;

                // Add download info if available
                $download_url = $delegate->download_url();
                if ($download_url) {
                    $data['downloads'] = [
                        [
                            'name' => $thing['name'],
                            'file' => $download_url,
                        ]
                    ];
                }

                $download_limit = $delegate->download_limit();
                if ($download_limit !== null) {
                    $data['download_limit'] = $download_limit;
                }

                $download_expiry = $delegate->download_expiry_days();
                if ($download_expiry !== null) {
                    $data['download_expiry'] = $download_expiry;
                }
            }
        }

        if ($this->dry_run) {
            $this->log("  [DRY RUN] Would create simple product");
            $this->log("  Type: " . ($delegate->is_virtual() ? ($delegate->is_downloadable() ? 'downloadable' : 'virtual') : 'physical'));
            $this->products_exported++;
            return;
        }

        $result = $this->client->create_product($data);

        if ($result) {
            $this->log("  Created product ID: {$result['id']}");
            $this->products_exported++;

            // Cache the exported product for bundle references
            $sku = $delegate['sku'] ?? '';
            if ($sku) {
                $this->exported_products_cache[$sku] = $result['id'];
            }
        } else {
            $this->error("  Failed to create product");
            $this->errors++;
        }
    }

    /**
     * Export a bundle/pack product
     *
     * Uses WooCommerce Product Bundles plugin if available,
     * otherwise falls back to grouped products.
     *
     * @param Thing $thing
     */
    private function export_bundle_product(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $bundle_items = $delegate->bundle_items();
        $data = $this->build_product_data($thing);

        $this->log("  Bundle with " . count($bundle_items) . " components");

        // First, ensure all bundle component products are exported
        $component_wc_ids = $this->export_bundle_components($bundle_items);

        if ($this->bundle_strategy === 'bundle') {
            // Use WooCommerce Product Bundles plugin
            $this->export_bundle_with_plugin($thing, $data, $bundle_items, $component_wc_ids);
        } else {
            // Use native grouped products
            $this->export_bundle_as_grouped($thing, $data, $component_wc_ids);
        }
    }

    /**
     * Export bundle component products first
     *
     * @param array $bundleItems Bundle item definitions
     * @return array Map of local product ID to WooCommerce product ID
     */
    private function export_bundle_components(array $bundle_items): array
    {
        $wc_ids = [];

        foreach ($bundle_items as $item) {
            $product_id = $item['product_id'] ?? $item['prestashop_id'] ?? null;
            if (!$product_id) {
                continue;
            }

            // Find the local product
            $component_thing = Thing::find_with_delegate($product_id);
            if (!$component_thing) {
                $this->log("    Warning: Bundle component product ID {$product_id} not found locally");
                continue;
            }

            $component_delegate = $component_thing->delegate();
            $component_sku = $component_delegate['sku'] ?? '';

            // Check if already exported in this session
            if (isset($this->exported_products_cache[$component_sku])) {
                $wc_ids[$product_id] = $this->exported_products_cache[$component_sku];
                continue;
            }

            // Check if exists in WooCommerce
            if (!$this->dry_run && $component_sku) {
                $existing = $this->client->find_product_by_sku($component_sku);
                if ($existing) {
                    $wc_ids[$product_id] = $existing['id'];
                    $this->exported_products_cache[$component_sku] = $existing['id'];
                    $this->log("    Component exists: {$component_thing['name']} (WC ID: {$existing['id']})");
                    continue;
                }
            }

            // Export the component product
            $this->log("    Exporting component: {$component_thing['name']}");
            if ($component_delegate->is_variant()) {
                // Skip variants - they should be exported with their parent
                $this->log("      Skipping variant (export parent instead)");
                continue;
            }

            // Export based on type
            if ($component_delegate->is_bundle()) {
                $this->export_bundle_product($component_thing);
            } elseif ($component_delegate->is_group()) {
                $this->export_variable_product($component_thing);
            } else {
                $this->export_simple_product($component_thing);
            }

            // Get the WC ID from cache
            if (isset($this->exported_products_cache[$component_sku])) {
                $wc_ids[$product_id] = $this->exported_products_cache[$component_sku];
            }
        }

        return $wc_ids;
    }

    /**
     * Export bundle using WooCommerce Product Bundles plugin
     *
     * @param Thing $thing
     * @param array $data Base product data
     * @param array $bundleItems Local bundle items
     * @param array $componentWcIds Map of local ID to WC ID
     */
    private function export_bundle_with_plugin(Thing $thing, array $data, array $bundle_items, array $component_wc_ids): void
    {
        $delegate = $thing->delegate();

        $data['type'] = 'bundle';

        // Build bundled_items for the plugin
        $bundled_items = [];
        foreach ($bundle_items as $item) {
            $local_id = $item['product_id'] ?? $item['prestashop_id'] ?? null;
            $wc_id = $component_wc_ids[$local_id] ?? null;

            if (!$wc_id) {
                $this->log("    Warning: Skipping component - no WC ID for local ID {$local_id}");
                continue;
            }

            $bundled_items[] = [
                'product_id' => $wc_id,
                'quantity_min' => $item['quantity'] ?? 1,
                'quantity_max' => $item['quantity'] ?? 1,
                'quantity_default' => $item['quantity'] ?? 1,
                'priced_individually' => false,
                'shipped_individually' => false,
                'optional' => false,
            ];
        }

        $data['bundled_items'] = $bundled_items;

        if ($this->dry_run) {
            $this->log("  [DRY RUN] Would create bundle product with " . count($bundled_items) . " items");
            $this->bundles_exported++;
            return;
        }

        $result = $this->client->create_bundle_product($data);

        if ($result) {
            $this->log("  Created bundle product ID: {$result['id']}");
            $this->bundles_exported++;

            $sku = $delegate['sku'] ?? '';
            if ($sku) {
                $this->exported_products_cache[$sku] = $result['id'];
            }
        } else {
            $this->error("  Failed to create bundle product");
            $this->errors++;
        }
    }

    /**
     * Export bundle as a grouped product (fallback when no bundle plugin)
     *
     * @param Thing $thing
     * @param array $data Base product data
     * @param array $componentWcIds Map of local ID to WC ID
     */
    private function export_bundle_as_grouped(Thing $thing, array $data, array $component_wc_ids): void
    {
        $delegate = $thing->delegate();

        // Note: Grouped products don't have their own price - each component is sold separately
        // We'll set the price to 0 and add a note in the description
        $bundle_price = $delegate->price();
        $bundle_value = $delegate->bundle_value();
        $savings = $delegate->bundle_savings();

        // Update description to explain the grouped product
        $grouped_note = "\n\n<strong>This is a product bundle.</strong> Purchase all items together.";
        if ($savings > 0) {
            $grouped_note .= sprintf(" Bundle value: %s (Save %s!)",
                $delegate->formatted_price(),
                number_format($savings, 2)
            );
        }
        $data['description'] = ($data['description'] ?? '') . $grouped_note;

        // For grouped products, we don't set a price (components have their own prices)
        unset($data['regular_price']);

        $data['type'] = 'grouped';
        $data['grouped_products'] = array_values($component_wc_ids);

        if ($this->dry_run) {
            $this->log("  [DRY RUN] Would create grouped product with " . count($component_wc_ids) . " children");
            $this->log("  Note: Grouped products - components sold separately (no bundle pricing)");
            $this->bundles_exported++;
            return;
        }

        $result = $this->client->create_grouped_product($data, array_values($component_wc_ids));

        if ($result) {
            $this->log("  Created grouped product ID: {$result['id']}");
            $this->log("  Note: Using grouped product (install WooCommerce Product Bundles for true bundle support)");
            $this->bundles_exported++;

            $sku = $delegate['sku'] ?? '';
            if ($sku) {
                $this->exported_products_cache[$sku] = $result['id'];
            }
        } else {
            $this->error("  Failed to create grouped product");
            $this->errors++;
        }
    }

    /**
     * Export a variable product (ProductGroup with variants)
     *
     * @param Thing $thing
     */
    private function export_variable_product(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $data = $this->build_product_data($thing);
        $data['type'] = 'variable';

        // Get variants
        $variants = $delegate->variants();

        if (empty($variants)) {
            $this->log("  Warning: ProductGroup has no variants, exporting as simple");
            $data['type'] = 'simple';

            if ($this->dry_run) {
                $this->log("  [DRY RUN] Would create simple product (no variants)");
                $this->products_exported++;
                return;
            }

            $result = $this->client->create_product($data);
            if ($result) {
                $this->log("  Created product ID: {$result['id']}");
                $this->products_exported++;
            } else {
                $this->error("  Failed to create product");
                $this->errors++;
            }
            return;
        }

        // Collect all attribute options from variants
        $attribute_options = $this->collect_variant_attributes($variants);

        // Build attributes for the parent product
        $data['attributes'] = $this->build_woo_commerce_attributes($attribute_options);

        if ($this->dry_run) {
            $this->log("  [DRY RUN] Would create variable product with " . count($variants) . " variations");
            $this->log("  Attributes: " . implode(', ', array_keys($attribute_options)));
            $this->products_exported++;
            $this->variations_created += count($variants);
            return;
        }

        // Create parent product
        $result = $this->client->create_product($data);

        if (!$result) {
            $this->error("  Failed to create variable product");
            $this->errors++;
            return;
        }

        $parent_id = $result['id'];
        $this->log("  Created variable product ID: {$parent_id}");
        $this->products_exported++;

        // Cache the exported product for bundle references
        $sku = $delegate['sku'] ?? '';
        if ($sku) {
            $this->exported_products_cache[$sku] = $parent_id;
        }

        // Create variations
        foreach ($variants as $variant) {
            $this->create_variation($parent_id, $variant);
        }
    }

    /**
     * Create a product variation
     *
     * @param int $parentId WooCommerce parent product ID
     * @param Thing $variant Variant Thing
     */
    private function create_variation(int $parent_id, Thing $variant): void
    {
        $delegate = $variant->delegate();

        $variation_data = [
            'sku' => $delegate['sku'] ?? '',
            'regular_price' => (string) ($delegate['price'] ?? '0'),
            'description' => $variant['description'] ?? '',
            'manage_stock' => true,
            'stock_quantity' => (int) ($delegate['inventory_level'] ?? 0),
            'stock_status' => $delegate->is_in_stock() ? 'instock' : 'outofstock',
        ];

        // Add variation attributes
        $attrs = $delegate->all_variant_attributes();
        $variation_data['attributes'] = [];

        foreach ($attrs as $name => $value) {
            $variation_data['attributes'][] = [
                'name' => ucfirst($name),
                'option' => $value,
            ];
        }

        // Handle virtual variants
        if ($delegate->is_virtual()) {
            $variation_data['virtual'] = true;
            if ($delegate->is_downloadable()) {
                $variation_data['downloadable'] = true;
            }
        }

        // Add weight if available
        $weight = $delegate['weight'] ?? null;
        if ($weight !== null) {
            $variation_data['weight'] = (string) $weight;
        }

        $result = $this->client->create_variation($parent_id, $variation_data);

        if ($result) {
            $this->log("    Created variation: {$delegate['sku']} (ID: {$result['id']})");
            $this->variations_created++;
        } else {
            $this->error("    Failed to create variation: {$delegate['sku']}");
            $this->errors++;
        }
    }

    /**
     * Update an existing WooCommerce product
     *
     * @param Thing $thing Local product
     * @param array $existing Existing WooCommerce product
     */
    private function update_existing_product(Thing $thing, array $existing): void
    {
        $delegate = $thing->delegate();
        $data = $this->build_product_data($thing);

        // Don't change the product type
        unset($data['type']);

        if ($this->dry_run) {
            $this->log("  [DRY RUN] Would update product ID: {$existing['id']}");
            $this->products_updated++;
            return;
        }

        $result = $this->client->update_product($existing['id'], $data);

        if ($result) {
            $this->log("  Updated product ID: {$existing['id']}");
            $this->products_updated++;

            // Update variations if variable product
            if ($existing['type'] === 'variable' && $delegate->is_group()) {
                $this->update_variations($existing['id'], $delegate->variants());
            }
        } else {
            $this->error("  Failed to update product");
            $this->errors++;
        }
    }

    /**
     * Update product variations
     *
     * @param int $parentId WooCommerce parent product ID
     * @param array $variants Local variants
     */
    private function update_variations(int $parent_id, array $variants): void
    {
        // Get existing variations
        $existing_variations = $this->client->get_variations($parent_id);
        $existing_by_sku = [];
        foreach ($existing_variations as $v) {
            if (!empty($v['sku'])) {
                $existing_by_sku[$v['sku']] = $v;
            }
        }

        foreach ($variants as $variant) {
            $delegate = $variant->delegate();
            $sku = $delegate['sku'] ?? '';

            if (isset($existing_by_sku[$sku])) {
                // Update existing variation
                $variation_data = [
                    'regular_price' => (string) ($delegate['price'] ?? '0'),
                    'stock_quantity' => (int) ($delegate['inventory_level'] ?? 0),
                    'stock_status' => $delegate->is_in_stock() ? 'instock' : 'outofstock',
                ];

                $this->client->update_variation($parent_id, $existing_by_sku[$sku]['id'], $variation_data);
                $this->log("    Updated variation: {$sku}");
            } else {
                // Create new variation
                $this->create_variation($parent_id, $variant);
            }
        }
    }

    /**
     * Build WooCommerce product data from local Thing
     *
     * @param Thing $thing
     * @return array
     */
    private function build_product_data(Thing $thing): array
    {
        $delegate = $thing->delegate();

        $data = [
            'name' => $thing['name'],
            'description' => $thing['description'] ?? '',
            'short_description' => $this->build_short_description($thing),
            'sku' => $delegate['sku'] ?? '',
            'regular_price' => (string) ($delegate['price'] ?? '0'),
            'manage_stock' => true,
            'stock_quantity' => (int) ($delegate['inventory_level'] ?? 0),
            'stock_status' => $delegate->is_in_stock() ? 'instock' : 'outofstock',
        ];

        // Add images if URL is available
        $image_url = $thing['image'] ?? null;
        if ($image_url) {
            $data['images'] = [
                ['src' => $image_url]
            ];
        }

        // Add category if available
        $category = $delegate['category'] ?? null;
        if ($category) {
            $category_id = $this->get_or_create_category($category);
            if ($category_id) {
                $data['categories'] = [['id' => $category_id]];
            }
        }

        // Add brand as attribute or tag
        $brand = $delegate['brand'] ?? null;
        if ($brand) {
            // WooCommerce doesn't have a built-in brand field
            // You can add it as a custom attribute or use a brand plugin
            $data['meta_data'] = [
                ['key' => '_brand', 'value' => $brand]
            ];
        }

        // Add weight
        $weight = $delegate['weight'] ?? null;
        if ($weight !== null) {
            $data['weight'] = (string) $weight;
        }

        // Add external URL if available
        $url = $thing['url'] ?? null;
        if ($url) {
            $data['external_url'] = $url;
        }

        // Add GTIN/EAN
        $gtin = $delegate['gtin'] ?? null;
        if ($gtin) {
            $data['meta_data'][] = ['key' => '_gtin', 'value' => $gtin];
        }

        return $data;
    }

    /**
     * Build short description from product
     *
     * @param Thing $thing
     * @return string
     */
    private function build_short_description(Thing $thing): string
    {
        $delegate = $thing->delegate();

        $parts = [];

        $brand = $delegate['brand'] ?? null;
        if ($brand) {
            $parts[] = "Brand: {$brand}";
        }

        if ($delegate->is_virtual()) {
            if ($delegate->is_downloadable()) {
                $parts[] = "Digital download";
            } elseif ($delegate->is_service()) {
                $duration = $delegate->service_duration();
                $parts[] = $duration ? "Service ({$duration})" : "Service";
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * Collect all variant attributes from variants
     *
     * @param array $variants
     * @return array ['color' => ['Red', 'Blue'], 'size' => ['S', 'M', 'L']]
     */
    private function collect_variant_attributes(array $variants): array
    {
        $attributes = [];

        foreach ($variants as $variant) {
            $delegate = $variant->delegate();
            $attrs = $delegate->all_variant_attributes();

            foreach ($attrs as $name => $value) {
                if (!isset($attributes[$name])) {
                    $attributes[$name] = [];
                }
                if (!in_array($value, $attributes[$name], true)) {
                    $attributes[$name][] = $value;
                }
            }
        }

        return $attributes;
    }

    /**
     * Build WooCommerce attributes array
     *
     * @param array $attributeOptions ['color' => ['Red', 'Blue'], ...]
     * @return array
     */
    private function build_woo_commerce_attributes(array $attribute_options): array
    {
        $attributes = [];
        $position = 0;

        foreach ($attribute_options as $name => $options) {
            $attributes[] = [
                'name' => ucfirst($name),
                'position' => $position++,
                'visible' => true,
                'variation' => true,
                'options' => $options,
            ];
        }

        return $attributes;
    }

    /**
     * Get or create a WooCommerce category
     *
     * @param string $categoryName
     * @return int|null Category ID
     */
    private function get_or_create_category(string $category_name): ?int
    {
        $slug = $this->slugify($category_name);

        // Check cache
        if (isset($this->category_cache[$slug])) {
            return $this->category_cache[$slug]['id'];
        }

        if ($this->dry_run) {
            return null;
        }

        // Try to find by slug
        $existing = $this->client->find_category_by_slug($slug);
        if ($existing) {
            $this->category_cache[$slug] = $existing;
            return $existing['id'];
        }

        // Create new category
        $result = $this->client->create_category([
            'name' => $category_name,
            'slug' => $slug,
        ]);

        if ($result) {
            $this->category_cache[$slug] = $result;
            return $result['id'];
        }

        return null;
    }

    /**
     * Convert string to slug
     *
     * @param string $text
     * @return string
     */
    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Print export summary
     */
    private function print_summary(): void
    {
        $this->log("\n" . str_repeat('=', 50));
        $this->log("EXPORT SUMMARY");
        $this->log(str_repeat('=', 50));
        $this->log("Products created:   {$this->products_exported}");
        $this->log("Products updated:   {$this->products_updated}");
        $this->log("Products skipped:   {$this->products_skipped}");
        $this->log("Variations created: {$this->variations_created}");
        $this->log("Bundles exported:   {$this->bundles_exported}");
        $this->log("Errors:             {$this->errors}");

        if ($this->bundles_exported > 0) {
            $this->log("\nBundle strategy: {$this->bundle_strategy}");
            if ($this->bundle_strategy === 'grouped') {
                $this->log("  Note: Install WooCommerce Product Bundles plugin for true bundle support.");
            }
        }

        if ($this->dry_run) {
            $this->log("\n(DRY RUN - no actual changes were made)");
        }
    }

    /**
     * Log a message
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        echo $message . "\n";
    }

    /**
     * Log an error
     *
     * @param string $message
     */
    private function error(string $message): void
    {
        echo "[ERROR] {$message}\n";
    }
}

// =========================================
// CLI ENTRY POINT
// =========================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    // Parse command line options
    $options = getopt('', [
        'sku:',
        'limit:',
        'update',
        'dry-run',
        'debug',
        'help',
    ]);

    if (isset($options['help'])) {
        echo "WooCommerce Product Exporter\n\n";
        echo "Usage: php ExportProducts.php [options]\n\n";
        echo "Options:\n";
        echo "  --sku=SKU        Export only product with this SKU\n";
        echo "  --limit=N        Limit number of products to export\n";
        echo "  --update         Update existing products (default: skip)\n";
        echo "  --dry-run        Show what would be exported without actually exporting\n";
        echo "  --debug          Enable debug output\n";
        echo "  --help           Show this help message\n";
        exit(0);
    }

    // Load configuration
    $config_file = __DIR__ . '/config.php';
    if (!file_exists($config_file)) {
        echo "Error: Configuration file not found.\n";
        echo "Please copy config.example.php to config.php and configure your settings.\n";
        exit(1);
    }

    $config = require $config_file;

    // Merge CLI options
    $config['debug'] = isset($options['debug']);
    $config['dry_run'] = isset($options['dry-run']);
    $config['update_existing'] = isset($options['update']);

    // Run the exporter
    try {
        $exporter = new ExportProducts($config);
        $exporter->run([
            'sku' => $options['sku'] ?? null,
            'limit' => $options['limit'] ?? null,
        ]);
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        if ($config['debug']) {
            echo $e->getTraceAsString() . "\n";
        }
        exit(1);
    }
}
