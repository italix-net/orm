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
