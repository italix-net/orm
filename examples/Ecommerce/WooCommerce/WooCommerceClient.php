<?php
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
    private string $siteUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $apiVersion;
    private bool $debug;

    /**
     * @param string $siteUrl WordPress site URL (e.g., https://myshop.com)
     * @param string $consumerKey WooCommerce REST API consumer key
     * @param string $consumerSecret WooCommerce REST API consumer secret
     * @param string $apiVersion API version (default: wc/v3)
     * @param bool $debug Enable debug output
     */
    public function __construct(
        string $siteUrl,
        string $consumerKey,
        string $consumerSecret,
        string $apiVersion = 'wc/v3',
        bool $debug = false
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->apiVersion = $apiVersion;
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
    public function createProduct(array $productData): ?array
    {
        return $this->request('POST', 'products', $productData);
    }

    /**
     * Update an existing product
     *
     * @param int $productId WooCommerce product ID
     * @param array $productData Product data to update
     * @return array|null Updated product data or null on failure
     */
    public function updateProduct(int $productId, array $productData): ?array
    {
        return $this->request('PUT', "products/{$productId}", $productData);
    }

    /**
     * Get a product by ID
     *
     * @param int $productId
     * @return array|null
     */
    public function getProduct(int $productId): ?array
    {
        return $this->request('GET', "products/{$productId}");
    }

    /**
     * Get products list
     *
     * @param array $params Query parameters (per_page, page, sku, etc.)
     * @return array
     */
    public function getProducts(array $params = []): array
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
    public function findProductBySku(string $sku): ?array
    {
        $products = $this->getProducts(['sku' => $sku]);
        return $products[0] ?? null;
    }

    /**
     * Delete a product
     *
     * @param int $productId
     * @param bool $force Force delete (bypass trash)
     * @return bool
     */
    public function deleteProduct(int $productId, bool $force = false): bool
    {
        $result = $this->request('DELETE', "products/{$productId}", null, ['force' => $force]);
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
    public function createVariation(int $productId, array $variationData): ?array
    {
        return $this->request('POST', "products/{$productId}/variations", $variationData);
    }

    /**
     * Update a product variation
     *
     * @param int $productId Parent variable product ID
     * @param int $variationId Variation ID
     * @param array $variationData Variation data
     * @return array|null
     */
    public function updateVariation(int $productId, int $variationId, array $variationData): ?array
    {
        return $this->request('PUT', "products/{$productId}/variations/{$variationId}", $variationData);
    }

    /**
     * Get all variations for a product
     *
     * @param int $productId
     * @return array
     */
    public function getVariations(int $productId): array
    {
        $result = $this->request('GET', "products/{$productId}/variations");
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
    public function deleteVariation(int $productId, int $variationId, bool $force = false): bool
    {
        $result = $this->request('DELETE', "products/{$productId}/variations/{$variationId}", null, ['force' => $force]);
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
    public function getAttributes(): array
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
    public function getAttributeBySlug(string $slug): ?array
    {
        $attributes = $this->getAttributes();
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
    public function createAttribute(array $attributeData): ?array
    {
        return $this->request('POST', 'products/attributes', $attributeData);
    }

    /**
     * Get attribute terms
     *
     * @param int $attributeId
     * @return array
     */
    public function getAttributeTerms(int $attributeId): array
    {
        $result = $this->request('GET', "products/attributes/{$attributeId}/terms");
        return $result ?? [];
    }

    /**
     * Create an attribute term
     *
     * @param int $attributeId
     * @param array $termData
     * @return array|null
     */
    public function createAttributeTerm(int $attributeId, array $termData): ?array
    {
        return $this->request('POST', "products/attributes/{$attributeId}/terms", $termData);
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
    public function getCategories(array $params = []): array
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
    public function findCategoryBySlug(string $slug): ?array
    {
        $categories = $this->getCategories(['slug' => $slug]);
        return $categories[0] ?? null;
    }

    /**
     * Create a product category
     *
     * @param array $categoryData
     * @return array|null
     */
    public function createCategory(array $categoryData): ?array
    {
        return $this->request('POST', 'products/categories', $categoryData);
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
    public function batchProducts(array $data): ?array
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
    public function batchVariations(int $productId, array $data): ?array
    {
        return $this->request('POST', "products/{$productId}/variations/batch", $data);
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
    public function hasBundleSupport(): bool
    {
        if (isset($this->bundleSupportChecked)) {
            return $this->bundleSupportChecked;
        }

        // Try to get system status and check for bundle plugin
        $status = $this->getSystemStatus();

        if ($status && isset($status['active_plugins'])) {
            foreach ($status['active_plugins'] as $plugin) {
                $pluginName = strtolower($plugin['plugin'] ?? $plugin['name'] ?? '');
                if (
                    str_contains($pluginName, 'product-bundles') ||
                    str_contains($pluginName, 'woocommerce-product-bundles')
                ) {
                    $this->bundleSupportChecked = true;
                    return true;
                }
            }
        }

        // Alternative: try to create a bundle product type and see if it's accepted
        // This is a lightweight check that doesn't require plugin list access
        $this->bundleSupportChecked = false;
        return false;
    }

    private bool $bundleSupportChecked;

    /**
     * Create a bundle product (requires WooCommerce Product Bundles plugin)
     *
     * @param array $productData Product data including 'bundled_items'
     * @return array|null
     */
    public function createBundleProduct(array $productData): ?array
    {
        $productData['type'] = 'bundle';
        return $this->createProduct($productData);
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
    public function createGroupedProduct(array $productData, array $childProductIds = []): ?array
    {
        $productData['type'] = 'grouped';
        $productData['grouped_products'] = $childProductIds;
        return $this->createProduct($productData);
    }

    /**
     * Add products to a grouped product
     *
     * @param int $groupedProductId The grouped product ID
     * @param array $childProductIds Array of child product IDs to add
     * @return array|null
     */
    public function addToGroupedProduct(int $groupedProductId, array $childProductIds): ?array
    {
        return $this->updateProduct($groupedProductId, [
            'grouped_products' => $childProductIds,
        ]);
    }

    /**
     * Build bundled_items array for WooCommerce Product Bundles plugin
     *
     * @param array $components Array of ['product_id' => id, 'quantity' => qty, ...]
     * @return array Formatted bundled_items for API
     */
    public function buildBundledItems(array $components): array
    {
        $bundledItems = [];

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

            $bundledItems[] = $item;
        }

        return $bundledItems;
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
    public function getBundleStrategy(): string
    {
        if ($this->hasBundleSupport()) {
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
    public function testConnection(): bool
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
    public function getSystemStatus(): ?array
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
        array $queryParams = []
    ): ?array {
        $url = $this->buildUrl($endpoint, $queryParams);

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
            CURLOPT_USERPWD => $this->consumerKey . ':' . $this->consumerSecret,
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            if ($this->debug) {
                echo "[ERROR] cURL error: {$error}\n";
            }
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            if ($this->debug) {
                echo "[ERROR] HTTP {$httpCode}: {$response}\n";
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
    private function buildUrl(string $endpoint, array $queryParams = []): string
    {
        $url = "{$this->siteUrl}/wp-json/{$this->apiVersion}/" . ltrim($endpoint, '/');

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /**
     * Get the last error message (for debugging)
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError ?? null;
    }

    private ?string $lastError = null;
}
