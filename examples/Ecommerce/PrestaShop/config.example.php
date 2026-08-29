<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * PrestaShop Import Configuration
 *
 * Copy this file to config.php and fill in your settings.
 *
 * IMPORTANT: Never commit config.php to version control!
 */

return [
    // ===========================================
    // PRESTASHOP API SETTINGS
    // ===========================================

    /**
     * PrestaShop store URL (without trailing slash)
     * Example: 'https://myfashionstore.com'
     */
    'prestashop_url' => 'https://your-store.com',

    /**
     * PrestaShop WebService API Key
     * Generate this in: Back Office > Advanced Parameters > Webservice
     * Make sure the key has GET permissions for:
     *   - orders, order_details, order_states
     *   - products, customers, addresses
     */
    'prestashop_api_key' => 'YOUR_API_KEY_HERE',

    // ===========================================
    // IMPORT SETTINGS
    // ===========================================

    /**
     * Minimum order ID to start importing from
     * Useful for resuming imports or skipping old orders
     */
    'min_order_id' => 1,

    /**
     * Maximum number of orders to fetch per run
     * PrestaShop may have limits on API responses
     */
    'orders_limit' => 100,

    /**
     * Default currency code (ISO 4217)
     * Used when currency is not specified in order
     */
    'default_currency' => 'EUR',

    // ===========================================
    // LOCAL DATABASE SETTINGS
    // ===========================================

    /**
     * Database connection type: 'sqlite', 'mysql', 'postgresql'
     */
    'db_type' => 'sqlite',

    /**
     * SQLite database file path (relative to this config file)
     */
    'db_sqlite_path' => __DIR__ . '/ecommerce.db',

    /**
     * MySQL/PostgreSQL settings (if using those instead of SQLite)
     */
    'db_host' => 'localhost',
    'db_port' => 3306,          // 3306 for MySQL, 5432 for PostgreSQL
    'db_name' => 'ecommerce',
    'db_user' => 'root',
    'db_password' => '',

    // ===========================================
    // MAPPING SETTINGS
    // ===========================================

    /**
     * PrestaShop order state ID to Schema.org OrderStatus mapping
     * Customize based on your PrestaShop configuration
     *
     * Common PrestaShop states:
     *   1 = Awaiting check payment
     *   2 = Payment accepted
     *   3 = Processing in progress
     *   4 = Shipped
     *   5 = Delivered
     *   6 = Canceled
     *   7 = Refunded
     *   8 = Payment error
     *   9 = On backorder (paid)
     *   10 = Awaiting bank wire payment
     *   11 = Remote payment accepted
     *   12 = On backorder (not paid)
     */
    'order_status_map' => [
        1 => 'OrderPaymentDue',      // Awaiting check payment
        2 => 'OrderProcessing',       // Payment accepted
        3 => 'OrderProcessing',       // Processing in progress
        4 => 'OrderInTransit',        // Shipped
        5 => 'OrderDelivered',        // Delivered
        6 => 'OrderCancelled',        // Canceled
        7 => 'OrderReturned',         // Refunded
        8 => 'OrderProblem',          // Payment error
        9 => 'OrderProcessing',       // On backorder (paid)
        10 => 'OrderPaymentDue',      // Awaiting bank wire payment
        11 => 'OrderProcessing',      // Remote payment accepted
        12 => 'OrderPaymentDue',      // On backorder (not paid)
    ],

    // ===========================================
    // DEBUG SETTINGS
    // ===========================================

    /**
     * Enable debug mode for verbose output
     */
    'debug' => false,

    /**
     * Log file path (null to disable file logging)
     */
    'log_file' => __DIR__ . '/import.log',
];
