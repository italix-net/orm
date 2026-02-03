<?php
/**
 * WooCommerce Export Configuration
 *
 * Copy this file to config.php and configure your settings.
 *
 * To generate WooCommerce API keys:
 * 1. Go to WooCommerce > Settings > Advanced > REST API
 * 2. Click "Add key"
 * 3. Set Description, User, and Permissions (Read/Write)
 * 4. Click "Generate API key"
 * 5. Copy the Consumer Key and Consumer Secret
 *
 * @see https://woocommerce.github.io/woocommerce-rest-api-docs/#authentication
 */

return [
    // =========================================
    // WOOCOMMERCE API SETTINGS
    // =========================================

    // WordPress site URL (without trailing slash)
    'woocommerce_url' => 'https://your-wordpress-site.com',

    // WooCommerce REST API Consumer Key
    'woocommerce_consumer_key' => 'ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

    // WooCommerce REST API Consumer Secret
    'woocommerce_consumer_secret' => 'cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

    // API version (default: wc/v3)
    'woocommerce_api_version' => 'wc/v3',

    // =========================================
    // LOCAL DATABASE SETTINGS
    // =========================================

    // Database type: 'sqlite', 'mysql', or 'postgresql'
    'db_type' => 'sqlite',

    // SQLite database path (for db_type = 'sqlite')
    'db_sqlite_path' => __DIR__ . '/../ecommerce.db',

    // MySQL/PostgreSQL settings (for db_type = 'mysql' or 'postgresql')
    // 'db_host' => 'localhost',
    // 'db_port' => 3306,
    // 'db_name' => 'ecommerce',
    // 'db_user' => 'root',
    // 'db_password' => '',

    // =========================================
    // EXPORT SETTINGS
    // =========================================

    // Default behavior for existing products (can be overridden with --update)
    'update_existing' => false,

    // Enable debug output (can be overridden with --debug)
    'debug' => false,

    // Dry run mode (can be overridden with --dry-run)
    'dry_run' => false,

    // =========================================
    // MAPPING SETTINGS
    // =========================================

    // Product status for new products ('draft', 'pending', 'private', 'publish')
    'default_product_status' => 'publish',

    // Default tax status ('taxable', 'shipping', 'none')
    'default_tax_status' => 'taxable',

    // Default tax class (empty string for standard)
    'default_tax_class' => '',

    // =========================================
    // FIELD MAPPING
    // =========================================

    // Map local fields to WooCommerce fields
    // These are applied automatically during export
    'field_mapping' => [
        // Local field => WooCommerce field
        'name' => 'name',
        'description' => 'description',
        'sku' => 'sku',
        'price' => 'regular_price',
        'inventory_level' => 'stock_quantity',
        // Add custom mappings as needed
    ],

    // =========================================
    // CATEGORY MAPPING (optional)
    // =========================================

    // Map local categories to WooCommerce category slugs
    // If not mapped, categories will be created automatically
    'category_mapping' => [
        // 'Local Category' => 'woocommerce-category-slug',
        // 'Electronics' => 'electronics',
        // 'Clothing' => 'apparel',
    ],

    // =========================================
    // ATTRIBUTE MAPPING (optional)
    // =========================================

    // Map local variant attributes to WooCommerce attribute slugs
    // If not mapped, attributes will be created as custom product attributes
    'attribute_mapping' => [
        // 'local_attribute' => 'pa_woocommerce_attribute',
        'color' => 'pa_color',
        'size' => 'pa_size',
        'material' => 'pa_material',
    ],
];
