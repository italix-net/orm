<?php
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
    private IxOrm $db;
    private Schema $schema;
    private array $config;
    private array $productCache = [];
    private array $customerCache = [];
    private array $addressCache = [];

    /**
     * Import statistics
     */
    private int $ordersImported = 0;
    private int $ordersSkipped = 0;
    private int $productsCreated = 0;
    private int $itemsCreated = 0;
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
        $this->initDatabase();
    }

    /**
     * Initialize the local database connection
     */
    private function initDatabase(): void
    {
        $dbType = $this->config['db_type'] ?? 'sqlite';

        switch ($dbType) {
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
                $driver = Driver::postgresql(
                    $this->config['db_host'],
                    $this->config['db_user'],
                    $this->config['db_password'],
                    $this->config['db_name'],
                    $this->config['db_port'] ?? 5432
                );
                break;

            case 'sqlite':
            default:
                $dbPath = $this->config['db_sqlite_path'] ?? __DIR__ . '/ecommerce.db';
                $driver = Driver::sqlite($dbPath);
                break;
        }

        $this->db = new IxOrm($driver);
        $this->schema = new Schema();

        // Create tables if they don't exist
        $this->db->create_tables(...$this->schema->get_tables());

        // Set up persistence
        Thing::set_persistence($this->db, $this->schema->things);
        Product::set_persistence($this->db, $this->schema->products);
        Order::set_persistence($this->db, $this->schema->orders);
        OrderItem::set_persistence($this->db, $this->schema->order_items);
    }

    /**
     * Run the import
     *
     * @param int|null $minOrderId Override minimum order ID
     * @param int|null $limit Override orders limit
     * @return array Import statistics
     */
    public function run(?int $minOrderId = null, ?int $limit = null): array
    {
        $minOrderId = $minOrderId ?? ($this->config['min_order_id'] ?? 1);
        $limit = $limit ?? ($this->config['orders_limit'] ?? 100);

        $this->log("Starting PrestaShop order import...");
        $this->log("  Min Order ID: {$minOrderId}");
        $this->log("  Limit: {$limit}");

        // Test connection
        if (!$this->client->testConnection()) {
            $this->error("Failed to connect to PrestaShop API");
            return $this->getStats();
        }

        $this->log("Connected to PrestaShop API");

        // Fetch orders
        $orders = $this->client->getOrders($minOrderId, $limit);
        $this->log("Fetched " . count($orders) . " orders from PrestaShop");

        foreach ($orders as $psOrder) {
            try {
                $this->importOrder($psOrder);
            } catch (\Exception $e) {
                $this->error("Failed to import order #{$psOrder['id']}: " . $e->getMessage());
            }
        }

        $this->log("\nImport completed!");
        $this->log("  Orders imported: {$this->ordersImported}");
        $this->log("  Orders skipped: {$this->ordersSkipped}");
        $this->log("  Products created: {$this->productsCreated}");
        $this->log("  Items created: {$this->itemsCreated}");
        $this->log("  Errors: " . count($this->errors));

        return $this->getStats();
    }

    /**
     * Import a single order
     *
     * @param array $psOrder PrestaShop order data
     */
    private function importOrder(array $psOrder): void
    {
        $psOrderId = (int) $psOrder['id'];
        $psOrderRef = $psOrder['reference'] ?? "PS-{$psOrderId}";

        // Check if order already exists
        if ($this->orderExists($psOrderRef)) {
            $this->log("  Skipping order #{$psOrderId} ({$psOrderRef}) - already imported");
            $this->ordersSkipped++;
            return;
        }

        $this->log("  Importing order #{$psOrderId} ({$psOrderRef})...");

        // Get customer info
        $customer = $this->getCustomer((int) $psOrder['id_customer']);
        $customerName = $this->formatCustomerName($customer);
        $customerEmail = $customer['email'] ?? '';

        // Get addresses
        $shippingAddress = $this->getAddress((int) $psOrder['id_address_delivery']);
        $billingAddress = $this->getAddress((int) $psOrder['id_address_invoice']);

        // Map order status
        $orderStatus = $this->mapOrderStatus((int) $psOrder['current_state']);

        // Create the order
        $order = Thing::create_order([
            'name' => "Order {$psOrderRef}",
            'description' => "Imported from PrestaShop (ID: {$psOrderId})",
        ], [
            'order_number' => $psOrderRef,
            'order_status' => $orderStatus,
            'order_date' => $psOrder['date_add'] ?? date('Y-m-d H:i:s'),
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'shipping_address' => $this->formatAddress($shippingAddress),
            'billing_address' => $this->formatAddress($billingAddress),
            'subtotal' => (float) ($psOrder['total_products'] ?? 0),
            'tax' => (float) ($psOrder['total_products_wt'] ?? 0) - (float) ($psOrder['total_products'] ?? 0),
            'shipping_cost' => (float) ($psOrder['total_shipping'] ?? 0),
            'discount' => (float) ($psOrder['total_discounts'] ?? 0),
            'total_price' => (float) ($psOrder['total_paid'] ?? 0),
            'currency' => $this->config['default_currency'] ?? 'EUR',
            'payment_method' => $psOrder['payment'] ?? 'Unknown',
            'payment_status' => $this->mapPaymentStatus($psOrder),
        ]);

        $this->ordersImported++;

        // Import order items
        $orderDetails = $psOrder['associations']['order_rows'] ?? [];

        foreach ($orderDetails as $item) {
            $this->importOrderItem($order, $item);
        }
    }

    /**
     * Import a single order item
     *
     * @param Thing $order The order Thing
     * @param array $item PrestaShop order row data
     */
    private function importOrderItem(Thing $order, array $item): void
    {
        $productId = (int) $item['product_id'];
        $productName = $item['product_name'] ?? 'Unknown Product';
        $quantity = (int) ($item['product_quantity'] ?? 1);
        $unitPrice = (float) ($item['unit_price_tax_incl'] ?? $item['product_price'] ?? 0);

        // Get or create the product
        $product = $this->getOrCreateProduct($productId, $productName, $item);

        if (!$product) {
            $this->error("    Could not create product for item: {$productName}");
            return;
        }

        // Check if this is a bundle/pack product
        $isPack = $this->client->isProductPack($productId);

        // Create order item
        $orderItem = Thing::create_order_item($order, $product, $quantity, [
            'name' => $productName,
            'description' => $isPack ? 'Product Bundle' : null,
        ], [
            'order_item_number' => "ITEM-{$item['id']}",
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice * $quantity,
            'order_item_status' => $order->delegate()->status(),
        ]);

        $this->itemsCreated++;

        // If it's a pack, also import the pack items as sub-items (optional)
        if ($isPack) {
            $this->importPackItems($order, $product, $productId, $quantity);
        }
    }

    /**
     * Import pack/bundle items
     *
     * @param Thing $order The order
     * @param Thing $packProduct The pack product
     * @param int $psProductId PrestaShop product ID
     * @param int $packQuantity Quantity of packs ordered
     */
    private function importPackItems(Thing $order, Thing $packProduct, int $psProductId, int $packQuantity): void
    {
        $packItems = $this->client->getPackItems($psProductId);

        if (empty($packItems)) {
            return;
        }

        $this->log("      Importing {" . count($packItems) . "} bundle items...");

        foreach ($packItems as $packItem) {
            $itemProductId = (int) ($packItem['id'] ?? $packItem['id_product'] ?? 0);
            $itemQuantity = (int) ($packItem['quantity'] ?? 1);

            if ($itemProductId === 0) {
                continue;
            }

            // Get product info
            $psProduct = $this->client->getProduct($itemProductId);
            if (!$psProduct) {
                continue;
            }

            $productName = $this->getProductName($psProduct);
            $product = $this->getOrCreateProduct($itemProductId, $productName, []);

            if (!$product) {
                continue;
            }

            // Create order item for bundle component
            // Mark it as part of a bundle with a special naming convention
            Thing::create_order_item($order, $product, $itemQuantity * $packQuantity, [
                'name' => "[Bundle: {$packProduct['name']}] {$productName}",
                'description' => "Part of bundle product",
            ], [
                'order_item_number' => "BUNDLE-{$psProductId}-{$itemProductId}",
                'unit_price' => 0, // Bundle items have 0 price (price is on the bundle)
                'line_total' => 0,
                'order_item_status' => $order->delegate()->status(),
            ]);

            $this->itemsCreated++;
        }
    }

    /**
     * Get or create a product in the local database
     *
     * @param int $psProductId PrestaShop product ID
     * @param string $name Product name
     * @param array $itemData Additional item data
     * @return Thing|null
     */
    private function getOrCreateProduct(int $psProductId, string $name, array $itemData): ?Thing
    {
        // Check cache first
        $cacheKey = "ps_{$psProductId}";
        if (isset($this->productCache[$cacheKey])) {
            return $this->productCache[$cacheKey];
        }

        // Check if product exists in database
        $existingProducts = Thing::find_products();
        foreach ($existingProducts as $existing) {
            $delegate = $existing->delegate();
            // Match by SKU that contains PrestaShop ID
            if ($delegate && str_contains($delegate['sku'] ?? '', "PS-{$psProductId}")) {
                $this->productCache[$cacheKey] = $existing;
                return $existing;
            }
        }

        // Fetch product details from PrestaShop
        $psProduct = $this->client->getProduct($psProductId);

        $sku = $itemData['product_reference'] ?? $psProduct['reference'] ?? "PS-{$psProductId}";
        $price = (float) ($itemData['unit_price_tax_incl'] ?? $psProduct['price'] ?? 0);

        // Determine if it's a bundle
        $isPack = ($psProduct['type'] ?? '') === 'pack'
            || ($psProduct['cache_is_pack'] ?? '0') === '1';

        // Create the product
        $product = Thing::create_product([
            'name' => $name,
            'description' => $this->getProductDescription($psProduct),
            'url' => $this->config['prestashop_url'] . '/index.php?id_product=' . $psProductId . '&controller=product',
        ], [
            'sku' => $sku,
            'gtin' => $psProduct['ean13'] ?? null,
            'brand' => $psProduct['manufacturer_name'] ?? null,
            'price' => $price,
            'currency' => $this->config['default_currency'] ?? 'EUR',
            'availability' => ((int)($psProduct['quantity'] ?? 0)) > 0 ? 'InStock' : 'OutOfStock',
            'inventory_level' => (int) ($psProduct['quantity'] ?? 0),
            'is_group' => $isPack, // Mark bundles as ProductGroup
        ]);

        $this->productCache[$cacheKey] = $product;
        $this->productsCreated++;

        $this->log("    Created product: {$name} (SKU: {$sku})" . ($isPack ? ' [BUNDLE]' : ''));

        return $product;
    }

    /**
     * Check if an order already exists in the local database
     *
     * @param string $orderNumber The order reference/number
     * @return bool
     */
    private function orderExists(string $orderNumber): bool
    {
        $orders = Thing::find_orders();

        foreach ($orders as $order) {
            $delegate = $order->delegate();
            if ($delegate && $delegate['order_number'] === $orderNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get customer data (with caching)
     */
    private function getCustomer(int $customerId): array
    {
        if (!isset($this->customerCache[$customerId])) {
            $this->customerCache[$customerId] = $this->client->getCustomer($customerId) ?? [];
        }
        return $this->customerCache[$customerId];
    }

    /**
     * Get address data (with caching)
     */
    private function getAddress(int $addressId): array
    {
        if (!isset($this->addressCache[$addressId])) {
            $this->addressCache[$addressId] = $this->client->getAddress($addressId) ?? [];
        }
        return $this->addressCache[$addressId];
    }

    /**
     * Format customer name from customer data
     */
    private function formatCustomerName(array $customer): string
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
    private function formatAddress(array $address): string
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
    private function getProductName(array $psProduct): string
    {
        // Product name can be in different places depending on API response
        if (isset($psProduct['name'])) {
            if (is_array($psProduct['name'])) {
                // Multi-language: get first available
                foreach ($psProduct['name'] as $lang) {
                    if (isset($lang['value']) && !empty($lang['value'])) {
                        return $lang['value'];
                    }
                }
            }
            return (string) $psProduct['name'];
        }

        return 'Unknown Product';
    }

    /**
     * Get product description from PrestaShop product data
     */
    private function getProductDescription(array $psProduct): ?string
    {
        if (!$psProduct) {
            return null;
        }

        $desc = $psProduct['description_short'] ?? $psProduct['description'] ?? null;

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
    private function mapOrderStatus(int $stateId): string
    {
        $map = $this->config['order_status_map'] ?? [];
        return $map[$stateId] ?? 'OrderProcessing';
    }

    /**
     * Map payment status from PrestaShop order
     */
    private function mapPaymentStatus(array $psOrder): string
    {
        $paid = (float) ($psOrder['total_paid_real'] ?? 0);
        $total = (float) ($psOrder['total_paid'] ?? 0);

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
    public function getStats(): array
    {
        return [
            'orders_imported' => $this->ordersImported,
            'orders_skipped' => $this->ordersSkipped,
            'products_created' => $this->productsCreated,
            'items_created' => $this->itemsCreated,
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
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        echo "Error: Configuration file not found.\n";
        echo "Please copy config.example.php to config.php and fill in your settings.\n";
        exit(1);
    }

    $config = require $configFile;

    // Apply CLI overrides
    if (isset($options['debug'])) {
        $config['debug'] = true;
    }

    $minId = isset($options['min-id']) ? (int) $options['min-id'] : null;
    $limit = isset($options['limit']) ? (int) $options['limit'] : null;

    // Run the import
    $importer = new ImportOrders($config);
    $stats = $importer->run($minId, $limit);

    // Exit with error code if there were errors
    exit(count($stats['errors']) > 0 ? 1 : 0);
}
