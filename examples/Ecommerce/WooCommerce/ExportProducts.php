<?php
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
    private IxOrm $db;
    private Schema $schema;
    private array $config;
    private bool $debug;
    private bool $dryRun;
    private bool $updateExisting;

    // Statistics
    private int $productsExported = 0;
    private int $productsUpdated = 0;
    private int $productsSkipped = 0;
    private int $variationsCreated = 0;
    private int $bundlesExported = 0;
    private int $errors = 0;

    // Bundle export strategy: 'bundle' (plugin), 'grouped' (native), or 'simple'
    private string $bundleStrategy = 'grouped';

    // Cache for WooCommerce data
    private array $attributeCache = [];
    private array $categoryCache = [];

    // Cache for exported products (needed for bundle component references)
    private array $exportedProductsCache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->debug = $config['debug'] ?? false;
        $this->dryRun = $config['dry_run'] ?? false;
        $this->updateExisting = $config['update_existing'] ?? false;

        // Initialize WooCommerce client
        $this->client = new WooCommerceClient(
            $config['woocommerce_url'],
            $config['woocommerce_consumer_key'],
            $config['woocommerce_consumer_secret'],
            $config['woocommerce_api_version'] ?? 'wc/v3',
            $this->debug
        );

        // Initialize local database
        $this->initDatabase();
    }

    /**
     * Initialize local database connection
     */
    private function initDatabase(): void
    {
        $dbType = $this->config['db_type'] ?? 'sqlite';

        if ($dbType === 'sqlite') {
            $dbPath = $this->config['db_sqlite_path'] ?? __DIR__ . '/../ecommerce.db';
            $driver = Driver::sqlite($dbPath);
        } else {
            throw new \RuntimeException("Unsupported database type: {$dbType}");
        }

        $this->db = new IxOrm($driver);
        $this->schema = new Schema();

        // Set up persistence for models
        Thing::set_persistence($this->db, $this->schema->things);
        Product::set_persistence($this->db, $this->schema->products);
        Order::set_persistence($this->db, $this->schema->orders);
        OrderItem::set_persistence($this->db, $this->schema->order_items);
        Customer::set_persistence($this->db, $this->schema->customers);
        Person::set_persistence($this->db, $this->schema->persons);
        Organization::set_persistence($this->db, $this->schema->organizations);
        PostalAddress::set_persistence($this->db, $this->schema->postal_addresses);
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

        if ($this->dryRun) {
            $this->log("DRY RUN MODE - No changes will be made\n");
        }

        // Test connection
        if (!$this->dryRun) {
            $this->log("Testing WooCommerce connection...");
            if (!$this->client->testConnection()) {
                $this->error("Failed to connect to WooCommerce API");
                return;
            }
            $this->log("Connected successfully!\n");
        }

        // Pre-load WooCommerce attributes and categories
        if (!$this->dryRun) {
            $this->loadWooCommerceData();
            $this->detectBundleSupport();
        }

        // Get products to export
        $products = $this->getProductsToExport($options);
        $this->log("Found " . count($products) . " products to export\n");

        foreach ($products as $thing) {
            $this->exportProduct($thing);
        }

        $this->printSummary();
    }

    /**
     * Load WooCommerce attributes and categories into cache
     */
    private function loadWooCommerceData(): void
    {
        $this->log("Loading WooCommerce attributes...");
        $attributes = $this->client->getAttributes();
        foreach ($attributes as $attr) {
            $this->attributeCache[$attr['slug']] = $attr;
        }
        $this->log("  Loaded " . count($attributes) . " attributes");

        $this->log("Loading WooCommerce categories...");
        $categories = $this->client->getCategories(['per_page' => 100]);
        foreach ($categories as $cat) {
            $this->categoryCache[$cat['slug']] = $cat;
        }
        $this->log("  Loaded " . count($categories) . " categories\n");
    }

    /**
     * Detect WooCommerce bundle plugin support
     */
    private function detectBundleSupport(): void
    {
        $this->log("Checking bundle support...");
        $this->bundleStrategy = $this->client->getBundleStrategy();

        if ($this->bundleStrategy === 'bundle') {
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
    private function getProductsToExport(array $options): array
    {
        $allProducts = Thing::find_products();

        // Filter out variants (they're exported with their parent)
        $products = array_filter($allProducts, function ($thing) {
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
    private function exportProduct(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $sku = $delegate['sku'] ?? '';
        $name = $thing['name'];

        $this->log("Exporting: {$name} (SKU: {$sku})");

        // Check if product already exists
        if (!$this->dryRun && $sku) {
            $existing = $this->client->findProductBySku($sku);
            if ($existing) {
                if ($this->updateExisting) {
                    $this->updateExistingProduct($thing, $existing);
                } else {
                    $this->log("  Skipped (already exists, use --update to update)");
                    $this->productsSkipped++;
                }
                return;
            }
        }

        // Determine product type and build data
        if ($delegate->is_bundle()) {
            $this->exportBundleProduct($thing);
        } elseif ($delegate->is_group()) {
            $this->exportVariableProduct($thing);
        } else {
            $this->exportSimpleProduct($thing);
        }
    }

    /**
     * Export a simple product (including virtual/downloadable)
     *
     * @param Thing $thing
     */
    private function exportSimpleProduct(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $data = $this->buildProductData($thing);

        // Set type based on product characteristics
        $data['type'] = 'simple';

        // Handle virtual products
        if ($delegate->is_virtual()) {
            $data['virtual'] = true;

            if ($delegate->is_downloadable()) {
                $data['downloadable'] = true;

                // Add download info if available
                $downloadUrl = $delegate->download_url();
                if ($downloadUrl) {
                    $data['downloads'] = [
                        [
                            'name' => $thing['name'],
                            'file' => $downloadUrl,
                        ]
                    ];
                }

                $downloadLimit = $delegate->download_limit();
                if ($downloadLimit !== null) {
                    $data['download_limit'] = $downloadLimit;
                }

                $downloadExpiry = $delegate->download_expiry_days();
                if ($downloadExpiry !== null) {
                    $data['download_expiry'] = $downloadExpiry;
                }
            }
        }

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would create simple product");
            $this->log("  Type: " . ($delegate->is_virtual() ? ($delegate->is_downloadable() ? 'downloadable' : 'virtual') : 'physical'));
            $this->productsExported++;
            return;
        }

        $result = $this->client->createProduct($data);

        if ($result) {
            $this->log("  Created product ID: {$result['id']}");
            $this->productsExported++;

            // Cache the exported product for bundle references
            $sku = $delegate['sku'] ?? '';
            if ($sku) {
                $this->exportedProductsCache[$sku] = $result['id'];
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
    private function exportBundleProduct(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $bundleItems = $delegate->bundle_items();
        $data = $this->buildProductData($thing);

        $this->log("  Bundle with " . count($bundleItems) . " components");

        // First, ensure all bundle component products are exported
        $componentWcIds = $this->exportBundleComponents($bundleItems);

        if ($this->bundleStrategy === 'bundle') {
            // Use WooCommerce Product Bundles plugin
            $this->exportBundleWithPlugin($thing, $data, $bundleItems, $componentWcIds);
        } else {
            // Use native grouped products
            $this->exportBundleAsGrouped($thing, $data, $componentWcIds);
        }
    }

    /**
     * Export bundle component products first
     *
     * @param array $bundleItems Bundle item definitions
     * @return array Map of local product ID to WooCommerce product ID
     */
    private function exportBundleComponents(array $bundleItems): array
    {
        $wcIds = [];

        foreach ($bundleItems as $item) {
            $productId = $item['product_id'] ?? $item['prestashop_id'] ?? null;
            if (!$productId) {
                continue;
            }

            // Find the local product
            $componentThing = Thing::find_with_delegate($productId);
            if (!$componentThing) {
                $this->log("    Warning: Bundle component product ID {$productId} not found locally");
                continue;
            }

            $componentDelegate = $componentThing->delegate();
            $componentSku = $componentDelegate['sku'] ?? '';

            // Check if already exported in this session
            if (isset($this->exportedProductsCache[$componentSku])) {
                $wcIds[$productId] = $this->exportedProductsCache[$componentSku];
                continue;
            }

            // Check if exists in WooCommerce
            if (!$this->dryRun && $componentSku) {
                $existing = $this->client->findProductBySku($componentSku);
                if ($existing) {
                    $wcIds[$productId] = $existing['id'];
                    $this->exportedProductsCache[$componentSku] = $existing['id'];
                    $this->log("    Component exists: {$componentThing['name']} (WC ID: {$existing['id']})");
                    continue;
                }
            }

            // Export the component product
            $this->log("    Exporting component: {$componentThing['name']}");
            if ($componentDelegate->is_variant()) {
                // Skip variants - they should be exported with their parent
                $this->log("      Skipping variant (export parent instead)");
                continue;
            }

            // Export based on type
            if ($componentDelegate->is_bundle()) {
                $this->exportBundleProduct($componentThing);
            } elseif ($componentDelegate->is_group()) {
                $this->exportVariableProduct($componentThing);
            } else {
                $this->exportSimpleProduct($componentThing);
            }

            // Get the WC ID from cache
            if (isset($this->exportedProductsCache[$componentSku])) {
                $wcIds[$productId] = $this->exportedProductsCache[$componentSku];
            }
        }

        return $wcIds;
    }

    /**
     * Export bundle using WooCommerce Product Bundles plugin
     *
     * @param Thing $thing
     * @param array $data Base product data
     * @param array $bundleItems Local bundle items
     * @param array $componentWcIds Map of local ID to WC ID
     */
    private function exportBundleWithPlugin(Thing $thing, array $data, array $bundleItems, array $componentWcIds): void
    {
        $delegate = $thing->delegate();

        $data['type'] = 'bundle';

        // Build bundled_items for the plugin
        $bundledItems = [];
        foreach ($bundleItems as $item) {
            $localId = $item['product_id'] ?? $item['prestashop_id'] ?? null;
            $wcId = $componentWcIds[$localId] ?? null;

            if (!$wcId) {
                $this->log("    Warning: Skipping component - no WC ID for local ID {$localId}");
                continue;
            }

            $bundledItems[] = [
                'product_id' => $wcId,
                'quantity_min' => $item['quantity'] ?? 1,
                'quantity_max' => $item['quantity'] ?? 1,
                'quantity_default' => $item['quantity'] ?? 1,
                'priced_individually' => false,
                'shipped_individually' => false,
                'optional' => false,
            ];
        }

        $data['bundled_items'] = $bundledItems;

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would create bundle product with " . count($bundledItems) . " items");
            $this->bundlesExported++;
            return;
        }

        $result = $this->client->createBundleProduct($data);

        if ($result) {
            $this->log("  Created bundle product ID: {$result['id']}");
            $this->bundlesExported++;

            $sku = $delegate['sku'] ?? '';
            if ($sku) {
                $this->exportedProductsCache[$sku] = $result['id'];
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
    private function exportBundleAsGrouped(Thing $thing, array $data, array $componentWcIds): void
    {
        $delegate = $thing->delegate();

        // Note: Grouped products don't have their own price - each component is sold separately
        // We'll set the price to 0 and add a note in the description
        $bundlePrice = $delegate->price();
        $bundleValue = $delegate->bundle_value();
        $savings = $delegate->bundle_savings();

        // Update description to explain the grouped product
        $groupedNote = "\n\n<strong>This is a product bundle.</strong> Purchase all items together.";
        if ($savings > 0) {
            $groupedNote .= sprintf(" Bundle value: %s (Save %s!)",
                $delegate->formatted_price(),
                number_format($savings, 2)
            );
        }
        $data['description'] = ($data['description'] ?? '') . $groupedNote;

        // For grouped products, we don't set a price (components have their own prices)
        unset($data['regular_price']);

        $data['type'] = 'grouped';
        $data['grouped_products'] = array_values($componentWcIds);

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would create grouped product with " . count($componentWcIds) . " children");
            $this->log("  Note: Grouped products - components sold separately (no bundle pricing)");
            $this->bundlesExported++;
            return;
        }

        $result = $this->client->createGroupedProduct($data, array_values($componentWcIds));

        if ($result) {
            $this->log("  Created grouped product ID: {$result['id']}");
            $this->log("  Note: Using grouped product (install WooCommerce Product Bundles for true bundle support)");
            $this->bundlesExported++;

            $sku = $delegate['sku'] ?? '';
            if ($sku) {
                $this->exportedProductsCache[$sku] = $result['id'];
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
    private function exportVariableProduct(Thing $thing): void
    {
        $delegate = $thing->delegate();
        $data = $this->buildProductData($thing);
        $data['type'] = 'variable';

        // Get variants
        $variants = $delegate->variants();

        if (empty($variants)) {
            $this->log("  Warning: ProductGroup has no variants, exporting as simple");
            $data['type'] = 'simple';

            if ($this->dryRun) {
                $this->log("  [DRY RUN] Would create simple product (no variants)");
                $this->productsExported++;
                return;
            }

            $result = $this->client->createProduct($data);
            if ($result) {
                $this->log("  Created product ID: {$result['id']}");
                $this->productsExported++;
            } else {
                $this->error("  Failed to create product");
                $this->errors++;
            }
            return;
        }

        // Collect all attribute options from variants
        $attributeOptions = $this->collectVariantAttributes($variants);

        // Build attributes for the parent product
        $data['attributes'] = $this->buildWooCommerceAttributes($attributeOptions);

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would create variable product with " . count($variants) . " variations");
            $this->log("  Attributes: " . implode(', ', array_keys($attributeOptions)));
            $this->productsExported++;
            $this->variationsCreated += count($variants);
            return;
        }

        // Create parent product
        $result = $this->client->createProduct($data);

        if (!$result) {
            $this->error("  Failed to create variable product");
            $this->errors++;
            return;
        }

        $parentId = $result['id'];
        $this->log("  Created variable product ID: {$parentId}");
        $this->productsExported++;

        // Cache the exported product for bundle references
        $sku = $delegate['sku'] ?? '';
        if ($sku) {
            $this->exportedProductsCache[$sku] = $parentId;
        }

        // Create variations
        foreach ($variants as $variant) {
            $this->createVariation($parentId, $variant);
        }
    }

    /**
     * Create a product variation
     *
     * @param int $parentId WooCommerce parent product ID
     * @param Thing $variant Variant Thing
     */
    private function createVariation(int $parentId, Thing $variant): void
    {
        $delegate = $variant->delegate();

        $variationData = [
            'sku' => $delegate['sku'] ?? '',
            'regular_price' => (string) ($delegate['price'] ?? '0'),
            'description' => $variant['description'] ?? '',
            'manage_stock' => true,
            'stock_quantity' => (int) ($delegate['inventory_level'] ?? 0),
            'stock_status' => $delegate->is_in_stock() ? 'instock' : 'outofstock',
        ];

        // Add variation attributes
        $attrs = $delegate->all_variant_attributes();
        $variationData['attributes'] = [];

        foreach ($attrs as $name => $value) {
            $variationData['attributes'][] = [
                'name' => ucfirst($name),
                'option' => $value,
            ];
        }

        // Handle virtual variants
        if ($delegate->is_virtual()) {
            $variationData['virtual'] = true;
            if ($delegate->is_downloadable()) {
                $variationData['downloadable'] = true;
            }
        }

        // Add weight if available
        $weight = $delegate['weight'] ?? null;
        if ($weight !== null) {
            $variationData['weight'] = (string) $weight;
        }

        $result = $this->client->createVariation($parentId, $variationData);

        if ($result) {
            $this->log("    Created variation: {$delegate['sku']} (ID: {$result['id']})");
            $this->variationsCreated++;
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
    private function updateExistingProduct(Thing $thing, array $existing): void
    {
        $delegate = $thing->delegate();
        $data = $this->buildProductData($thing);

        // Don't change the product type
        unset($data['type']);

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would update product ID: {$existing['id']}");
            $this->productsUpdated++;
            return;
        }

        $result = $this->client->updateProduct($existing['id'], $data);

        if ($result) {
            $this->log("  Updated product ID: {$existing['id']}");
            $this->productsUpdated++;

            // Update variations if variable product
            if ($existing['type'] === 'variable' && $delegate->is_group()) {
                $this->updateVariations($existing['id'], $delegate->variants());
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
    private function updateVariations(int $parentId, array $variants): void
    {
        // Get existing variations
        $existingVariations = $this->client->getVariations($parentId);
        $existingBySku = [];
        foreach ($existingVariations as $v) {
            if (!empty($v['sku'])) {
                $existingBySku[$v['sku']] = $v;
            }
        }

        foreach ($variants as $variant) {
            $delegate = $variant->delegate();
            $sku = $delegate['sku'] ?? '';

            if (isset($existingBySku[$sku])) {
                // Update existing variation
                $variationData = [
                    'regular_price' => (string) ($delegate['price'] ?? '0'),
                    'stock_quantity' => (int) ($delegate['inventory_level'] ?? 0),
                    'stock_status' => $delegate->is_in_stock() ? 'instock' : 'outofstock',
                ];

                $this->client->updateVariation($parentId, $existingBySku[$sku]['id'], $variationData);
                $this->log("    Updated variation: {$sku}");
            } else {
                // Create new variation
                $this->createVariation($parentId, $variant);
            }
        }
    }

    /**
     * Build WooCommerce product data from local Thing
     *
     * @param Thing $thing
     * @return array
     */
    private function buildProductData(Thing $thing): array
    {
        $delegate = $thing->delegate();

        $data = [
            'name' => $thing['name'],
            'description' => $thing['description'] ?? '',
            'short_description' => $this->buildShortDescription($thing),
            'sku' => $delegate['sku'] ?? '',
            'regular_price' => (string) ($delegate['price'] ?? '0'),
            'manage_stock' => true,
            'stock_quantity' => (int) ($delegate['inventory_level'] ?? 0),
            'stock_status' => $delegate->is_in_stock() ? 'instock' : 'outofstock',
        ];

        // Add images if URL is available
        $imageUrl = $thing['image'] ?? null;
        if ($imageUrl) {
            $data['images'] = [
                ['src' => $imageUrl]
            ];
        }

        // Add category if available
        $category = $delegate['category'] ?? null;
        if ($category) {
            $categoryId = $this->getOrCreateCategory($category);
            if ($categoryId) {
                $data['categories'] = [['id' => $categoryId]];
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
    private function buildShortDescription(Thing $thing): string
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
    private function collectVariantAttributes(array $variants): array
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
    private function buildWooCommerceAttributes(array $attributeOptions): array
    {
        $attributes = [];
        $position = 0;

        foreach ($attributeOptions as $name => $options) {
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
    private function getOrCreateCategory(string $categoryName): ?int
    {
        $slug = $this->slugify($categoryName);

        // Check cache
        if (isset($this->categoryCache[$slug])) {
            return $this->categoryCache[$slug]['id'];
        }

        if ($this->dryRun) {
            return null;
        }

        // Try to find by slug
        $existing = $this->client->findCategoryBySlug($slug);
        if ($existing) {
            $this->categoryCache[$slug] = $existing;
            return $existing['id'];
        }

        // Create new category
        $result = $this->client->createCategory([
            'name' => $categoryName,
            'slug' => $slug,
        ]);

        if ($result) {
            $this->categoryCache[$slug] = $result;
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
    private function printSummary(): void
    {
        $this->log("\n" . str_repeat('=', 50));
        $this->log("EXPORT SUMMARY");
        $this->log(str_repeat('=', 50));
        $this->log("Products created:   {$this->productsExported}");
        $this->log("Products updated:   {$this->productsUpdated}");
        $this->log("Products skipped:   {$this->productsSkipped}");
        $this->log("Variations created: {$this->variationsCreated}");
        $this->log("Bundles exported:   {$this->bundlesExported}");
        $this->log("Errors:             {$this->errors}");

        if ($this->bundlesExported > 0) {
            $this->log("\nBundle strategy: {$this->bundleStrategy}");
            if ($this->bundleStrategy === 'grouped') {
                $this->log("  Note: Install WooCommerce Product Bundles plugin for true bundle support.");
            }
        }

        if ($this->dryRun) {
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
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        echo "Error: Configuration file not found.\n";
        echo "Please copy config.example.php to config.php and configure your settings.\n";
        exit(1);
    }

    $config = require $configFile;

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
