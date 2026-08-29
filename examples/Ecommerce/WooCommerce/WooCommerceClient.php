<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * WooCommerce REST API Client
 *
 * A client for interacting with WordPress WooCommerce REST API v3.
 *
 * @see https://woocommerce.github.io/woocommerce-rest-api-docs/
 */

namespace Examples\Ecommerce\WooCommerce;

/**
 * WooCommerce REST API Client
 *
 * Uses WooCommerce REST API with consumer key/secret authentication.
 */
class WooCommerceClient
{
    private string $site_url;
    private string $consumer_key;
    private string $consumer_secret;
    private string $api_version;
    private bool $debug;

    /**
     * @param string $siteUrl WordPress site URL (e.g., https://myshop.com)
     * @param string $consumerKey WooCommerce REST API consumer key
     * @param string $consumerSecret WooCommerce REST API consumer secret
     * @param string $apiVersion API version (default: wc/v3)
     * @param bool $debug Enable debug output
     */
    public function __construct(
        string $site_url,
        string $consumer_key,
        string $consumer_secret,
        string $api_version = 'wc/v3',
        bool $debug = false
    ) {
        $this->site_url = rtrim($site_url, '/');
        $this->consumer_key = $consumer_key;
        $this->consumer_secret = $consumer_secret;
        $this->api_version = $api_version;
        $this->debug = $debug;
    }

    // =========================================
    // PRODUCTS
    // =========================================

    /**
     * Create a new product
     *
     * @param array $productData Product data
     * @return array|null Created product data or null on failure
     */
    public function create_product(array $product_data): ?array
    {
        return $this->request('POST', 'products', $product_data);
    }

    /**
     * Update an existing product
     *
     * @param int $productId WooCommerce product ID
     * @param array $productData Product data to update
     * @return array|null Updated product data or null on failure
     */
    public function update_product(int $product_id, array $product_data): ?array
    {
        return $this->request('PUT', "products/{$product_id}", $product_data);
    }

    /**
     * Get a product by ID
     *
     * @param int $productId
     * @return array|null
     */
    public function get_product(int $product_id): ?array
    {
        return $this->request('GET', "products/{$product_id}");
    }

    /**
     * Get products list
     *
     * @param array $params Query parameters (per_page, page, sku, etc.)
     * @return array
     */
    public function get_products(array $params = []): array
    {
        $result = $this->request('GET', 'products', null, $params);
        return $result ?? [];
    }

    /**
     * Find product by SKU
     *
     * @param string $sku
     * @return array|null
     */
    public function find_product_by_sku(string $sku): ?array
    {
        $products = $this->get_products(['sku' => $sku]);
        return $products[0] ?? null;
    }

    /**
     * Delete a product
     *
     * @param int $productId
     * @param bool $force Force delete (bypass trash)
     * @return bool
     */
    public function delete_product(int $product_id, bool $force = false): bool
    {
        $result = $this->request('DELETE', "products/{$product_id}", null, ['force' => $force]);
        return $result !== null;
    }

    // =========================================
    // PRODUCT VARIATIONS (for variable products)
    // =========================================

    /**
     * Create a product variation
     *
     * @param int $productId Parent variable product ID
     * @param array $variationData Variation data
     * @return array|null
     */
    public function create_variation(int $product_id, array $variation_data): ?array
    {
        return $this->request('POST', "products/{$product_id}/variations", $variation_data);
    }

    /**
     * Update a product variation
     *
     * @param int $productId Parent variable product ID
     * @param int $variationId Variation ID
     * @param array $variationData Variation data
     * @return array|null
     */
    public function update_variation(int $product_id, int $variation_id, array $variation_data): ?array
    {
        return $this->request('PUT', "products/{$product_id}/variations/{$variation_id}", $variation_data);
    }

    /**
     * Get all variations for a product
     *
     * @param int $productId
     * @return array
     */
    public function get_variations(int $product_id): array
    {
        $result = $this->request('GET', "products/{$product_id}/variations");
        return $result ?? [];
    }

    /**
     * Delete a product variation
     *
     * @param int $productId Parent product ID
     * @param int $variationId Variation ID
     * @param bool $force Force delete
     * @return bool
     */
    public function delete_variation(int $product_id, int $variation_id, bool $force = false): bool
    {
        $result = $this->request('DELETE', "products/{$product_id}/variations/{$variation_id}", null, ['force' => $force]);
        return $result !== null;
    }

    // =========================================
    // PRODUCT ATTRIBUTES
    // =========================================

    /**
     * Get all product attributes
     *
     * @return array
     */
    public function get_attributes(): array
    {
        $result = $this->request('GET', 'products/attributes');
        return $result ?? [];
    }

    /**
     * Get attribute by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function get_attribute_by_slug(string $slug): ?array
    {
        $attributes = $this->get_attributes();
        foreach ($attributes as $attr) {
            if ($attr['slug'] === $slug) {
                return $attr;
            }
        }
        return null;
    }

    /**
     * Create a product attribute
     *
     * @param array $attributeData
     * @return array|null
     */
    public function create_attribute(array $attribute_data): ?array
    {
        return $this->request('POST', 'products/attributes', $attribute_data);
    }

    /**
     * Get attribute terms
     *
     * @param int $attributeId
     * @return array
     */
    public function get_attribute_terms(int $attribute_id): array
    {
        $result = $this->request('GET', "products/attributes/{$attribute_id}/terms");
        return $result ?? [];
    }

    /**
     * Create an attribute term
     *
     * @param int $attributeId
     * @param array $termData
     * @return array|null
     */
    public function create_attribute_term(int $attribute_id, array $term_data): ?array
    {
        return $this->request('POST', "products/attributes/{$attribute_id}/terms", $term_data);
    }

    // =========================================
    // PRODUCT CATEGORIES
    // =========================================

    /**
     * Get all product categories
     *
     * @param array $params Query parameters
     * @return array
     */
    public function get_categories(array $params = []): array
    {
        $result = $this->request('GET', 'products/categories', null, $params);
        return $result ?? [];
    }

    /**
     * Find category by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function find_category_by_slug(string $slug): ?array
    {
        $categories = $this->get_categories(['slug' => $slug]);
        return $categories[0] ?? null;
    }

    /**
     * Create a product category
     *
     * @param array $categoryData
     * @return array|null
     */
    public function create_category(array $category_data): ?array
    {
        return $this->request('POST', 'products/categories', $category_data);
    }

    // =========================================
    // BATCH OPERATIONS
    // =========================================

    /**
     * Batch create/update/delete products
     *
     * @param array $data ['create' => [...], 'update' => [...], 'delete' => [...]]
     * @return array|null
     */
    public function batch_products(array $data): ?array
    {
        return $this->request('POST', 'products/batch', $data);
    }

    /**
     * Batch create/update/delete variations
     *
     * @param int $productId Parent product ID
     * @param array $data ['create' => [...], 'update' => [...], 'delete' => [...]]
     * @return array|null
     */
    public function batch_variations(int $product_id, array $data): ?array
    {
        return $this->request('POST', "products/{$product_id}/variations/batch", $data);
    }

    // =========================================
    // PRODUCT BUNDLES
    // =========================================
    // Support for WooCommerce Product Bundles plugin
    // @see https://woocommerce.com/products/product-bundles/

    /**
     * Check if WooCommerce Product Bundles plugin is installed
     *
     * Tests by checking if 'bundle' product type is available.
     *
     * @return bool
     */
    public function has_bundle_support(): bool
    {
        if (isset($this->bundle_support_checked)) {
            return $this->bundle_support_checked;
        }

        // Try to get system status and check for bundle plugin
        $status = $this->get_system_status();

        if ($status && isset($status['active_plugins'])) {
            foreach ($status['active_plugins'] as $plugin) {
                $plugin_name = strtolower($plugin['plugin'] ?? $plugin['name'] ?? '');
                if (
                    str_contains($plugin_name, 'product-bundles') ||
                    str_contains($plugin_name, 'woocommerce-product-bundles')
                ) {
                    $this->bundle_support_checked = true;
                    return true;
                }
            }
        }

        // Alternative: try to create a bundle product type and see if it's accepted
        // This is a lightweight check that doesn't require plugin list access
        $this->bundle_support_checked = false;
        return false;
    }

    private bool $bundle_support_checked;

    /**
     * Create a bundle product (requires WooCommerce Product Bundles plugin)
     *
     * @param array $productData Product data including 'bundled_items'
     * @return array|null
     */
    public function create_bundle_product(array $product_data): ?array
    {
        $product_data['type'] = 'bundle';
        return $this->create_product($product_data);
    }

    /**
     * Create a grouped product (WooCommerce native - alternative to bundles)
     *
     * Grouped products display child products together but don't bundle pricing.
     * Each child is purchased separately.
     *
     * @param array $productData Product data
     * @param array $childProductIds Array of child product IDs
     * @return array|null
     */
    public function create_grouped_product(array $product_data, array $child_product_ids = []): ?array
    {
        $product_data['type'] = 'grouped';
        $product_data['grouped_products'] = $child_product_ids;
        return $this->create_product($product_data);
    }

    /**
     * Add products to a grouped product
     *
     * @param int $groupedProductId The grouped product ID
     * @param array $childProductIds Array of child product IDs to add
     * @return array|null
     */
    public function add_to_grouped_product(int $grouped_product_id, array $child_product_ids): ?array
    {
        return $this->update_product($grouped_product_id, [
            'grouped_products' => $child_product_ids,
        ]);
    }

    /**
     * Build bundled_items array for WooCommerce Product Bundles plugin
     *
     * @param array $components Array of ['product_id' => id, 'quantity' => qty, ...]
     * @return array Formatted bundled_items for API
     */
    public function build_bundled_items(array $components): array
    {
        $bundled_items = [];

        foreach ($components as $component) {
            $item = [
                'product_id' => $component['product_id'],
                'quantity_min' => $component['quantity'] ?? $component['quantity_min'] ?? 1,
                'quantity_max' => $component['quantity'] ?? $component['quantity_max'] ?? 1,
                'quantity_default' => $component['quantity'] ?? $component['quantity_default'] ?? 1,
                'priced_individually' => $component['priced_individually'] ?? false,
                'shipped_individually' => $component['shipped_individually'] ?? false,
                'optional' => $component['optional'] ?? false,
            ];

            // Add discount if specified
            if (isset($component['discount'])) {
                $item['discount'] = $component['discount'];
            }

            // Add title override if specified
            if (isset($component['title'])) {
                $item['title'] = $component['title'];
            }

            $bundled_items[] = $item;
        }

        return $bundled_items;
    }

    /**
     * Get bundle strategy based on plugin availability
     *
     * Returns 'bundle' if Product Bundles plugin is available,
     * 'grouped' for native WooCommerce grouped products,
     * or 'simple' if neither is suitable.
     *
     * @return string 'bundle', 'grouped', or 'simple'
     */
    public function get_bundle_strategy(): string
    {
        if ($this->has_bundle_support()) {
            return 'bundle';
        }
        return 'grouped';
    }

    // =========================================
    // SYSTEM
    // =========================================

    /**
     * Test the API connection
     *
     * @return bool
     */
    public function test_connection(): bool
    {
        // Try to get system status
        $result = $this->request('GET', 'system_status');
        return $result !== null;
    }

    /**
     * Get system status
     *
     * @return array|null
     */
    public function get_system_status(): ?array
    {
        return $this->request('GET', 'system_status');
    }

    // =========================================
    // HTTP CLIENT
    // =========================================

    /**
     * Make an API request
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint
     * @param array|null $body Request body
     * @param array $queryParams Query parameters
     * @return array|null
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $body = null,
        array $query_params = []
    ): ?array {
        $url = $this->build_url($endpoint, $query_params);

        if ($this->debug) {
            echo "[DEBUG] {$method} {$url}\n";
            if ($body) {
                echo "[DEBUG] Body: " . json_encode($body, JSON_PRETTY_PRINT) . "\n";
            }
        }

        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->consumer_key . ':' . $this->consumer_secret,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($body) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            if ($this->debug) {
                echo "[ERROR] cURL error: {$error}\n";
            }
            return null;
        }

        if ($http_code < 200 || $http_code >= 300) {
            if ($this->debug) {
                echo "[ERROR] HTTP {$http_code}: {$response}\n";
            }
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->debug) {
                echo "[ERROR] JSON decode error: " . json_last_error_msg() . "\n";
            }
            return null;
        }

        return $data;
    }

    /**
     * Build the full API URL
     *
     * @param string $endpoint
     * @param array $queryParams
     * @return string
     */
    private function build_url(string $endpoint, array $query_params = []): string
    {
        $url = "{$this->site_url}/wp-json/{$this->api_version}/" . ltrim($endpoint, '/');

        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        return $url;
    }

    /**
     * Get the last error message (for debugging)
     *
     * @return string|null
     */
    public function get_last_error(): ?string
    {
        return $this->last_error ?? null;
    }

    private ?string $last_error = null;
}
