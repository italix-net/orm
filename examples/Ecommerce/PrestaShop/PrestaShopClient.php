<?php
/**
 * PrestaShop API Client
 *
 * A simple client for interacting with PrestaShop 1.7+ WebService API.
 *
 * @see https://devdocs.prestashop-project.org/1.7/webservice/
 */

namespace Examples\Ecommerce\PrestaShop;

/**
 * PrestaShop WebService API Client
 */
class PrestaShopClient
{
    private string $apiUrl;
    private string $apiKey;
    private bool $debug;

    /**
     * @param string $shopUrl Base URL of the PrestaShop store (e.g., https://myshop.com)
     * @param string $apiKey WebService API key
     * @param bool $debug Enable debug output
     */
    public function __construct(string $shopUrl, string $apiKey, bool $debug = false)
    {
        $this->apiUrl = rtrim($shopUrl, '/') . '/api';
        $this->apiKey = $apiKey;
        $this->debug = $debug;
    }

    /**
     * Get orders from PrestaShop
     *
     * @param int $minId Minimum order ID to fetch
     * @param int $limit Maximum number of orders to fetch
     * @param array $options Additional options (sort, filters)
     * @return array Array of order data
     */
    public function getOrders(int $minId = 1, int $limit = 100, array $options = []): array
    {
        $params = [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id]' => "[{$minId},999999999]",
            'limit' => $limit,
            'sort' => '[id_ASC]',
        ];

        // Merge additional options
        $params = array_merge($params, $options);

        $response = $this->request('orders', $params);

        if (!$response || !isset($response['orders'])) {
            return [];
        }

        return $response['orders'];
    }

    /**
     * Get a single order by ID
     *
     * @param int $orderId
     * @return array|null
     */
    public function getOrder(int $orderId): ?array
    {
        $response = $this->request("orders/{$orderId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['order'])) {
            return null;
        }

        return $response['order'];
    }

    /**
     * Get order details (line items) for an order
     *
     * @param int $orderId
     * @return array
     */
    public function getOrderDetails(int $orderId): array
    {
        $response = $this->request('order_details', [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id_order]' => $orderId,
        ]);

        if (!$response || !isset($response['order_details'])) {
            return [];
        }

        return $response['order_details'];
    }

    /**
     * Get a product by ID
     *
     * @param int $productId
     * @return array|null
     */
    public function getProduct(int $productId): ?array
    {
        $response = $this->request("products/{$productId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['product'])) {
            return null;
        }

        return $response['product'];
    }

    /**
     * Get products (with optional filters)
     *
     * @param array $ids Product IDs to fetch
     * @return array
     */
    public function getProducts(array $ids = []): array
    {
        $params = [
            'display' => 'full',
            'output_format' => 'JSON',
        ];

        if (!empty($ids)) {
            $params['filter[id]'] = '[' . implode('|', $ids) . ']';
        }

        $response = $this->request('products', $params);

        if (!$response || !isset($response['products'])) {
            return [];
        }

        return $response['products'];
    }

    /**
     * Get customer by ID
     *
     * @param int $customerId
     * @return array|null
     */
    public function getCustomer(int $customerId): ?array
    {
        $response = $this->request("customers/{$customerId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['customer'])) {
            return null;
        }

        return $response['customer'];
    }

    /**
     * Get address by ID
     *
     * @param int $addressId
     * @return array|null
     */
    public function getAddress(int $addressId): ?array
    {
        $response = $this->request("addresses/{$addressId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['address'])) {
            return null;
        }

        return $response['address'];
    }

    /**
     * Get order states (statuses)
     *
     * @return array
     */
    public function getOrderStates(): array
    {
        $response = $this->request('order_states', [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['order_states'])) {
            return [];
        }

        return $response['order_states'];
    }

    /**
     * Get product pack items (for bundles)
     *
     * @param int $productId The pack/bundle product ID
     * @return array Array of pack items with product_id and quantity
     */
    public function getPackItems(int $productId): array
    {
        // PrestaShop stores pack items in stock_availables or via the product's associations
        $product = $this->getProduct($productId);

        if (!$product || empty($product['associations']['product_bundle'])) {
            return [];
        }

        return $product['associations']['product_bundle'];
    }

    /**
     * Check if a product is a pack/bundle
     *
     * @param int $productId
     * @return bool
     */
    public function isProductPack(int $productId): bool
    {
        $product = $this->getProduct($productId);

        if (!$product) {
            return false;
        }

        // In PrestaShop, type = 'pack' or cache_is_pack = 1 indicates a bundle
        return ($product['type'] ?? '') === 'pack'
            || ($product['cache_is_pack'] ?? '0') === '1';
    }

    /**
     * Check if a product is virtual (not physical)
     *
     * Virtual products in PrestaShop include downloadable files and services.
     * They are not shipped to customers.
     *
     * @param int $productId
     * @return bool
     */
    public function isProductVirtual(int $productId): bool
    {
        $product = $this->getProduct($productId);

        if (!$product) {
            return false;
        }

        // In PrestaShop, is_virtual = 1 indicates a virtual product
        return ($product['is_virtual'] ?? '0') === '1';
    }

    /**
     * Get virtual product download info
     *
     * Returns download details for a virtual product (file, expiration, etc.)
     *
     * @param int $productId
     * @return array|null
     */
    public function getProductDownloadInfo(int $productId): ?array
    {
        $response = $this->request('product_downloads', [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id_product]' => $productId,
        ]);

        if (!$response || empty($response['product_downloads'])) {
            return null;
        }

        // Return first download info (products typically have one download)
        return $response['product_downloads'][0] ?? null;
    }

    /**
     * Get product type information
     *
     * Returns detailed product type info: simple, pack, virtual, combinations
     *
     * @param int $productId
     * @return array
     */
    public function getProductTypeInfo(int $productId): array
    {
        $product = $this->getProduct($productId);

        if (!$product) {
            return [
                'type' => 'unknown',
                'is_virtual' => false,
                'is_pack' => false,
                'has_combinations' => false,
                'is_downloadable' => false,
            ];
        }

        $isVirtual = ($product['is_virtual'] ?? '0') === '1';
        $isPack = ($product['type'] ?? '') === 'pack' || ($product['cache_is_pack'] ?? '0') === '1';
        $hasCombinations = !empty($product['associations']['combinations']);

        // Check if there's a downloadable file
        $downloadInfo = $isVirtual ? $this->getProductDownloadInfo($productId) : null;
        $isDownloadable = $downloadInfo !== null && !empty($downloadInfo['filename']);

        // Determine primary type
        $type = 'simple';
        if ($isPack) {
            $type = 'pack';
        } elseif ($hasCombinations) {
            $type = 'combinations';
        } elseif ($isVirtual) {
            $type = $isDownloadable ? 'downloadable' : 'service';
        }

        return [
            'type' => $type,
            'is_virtual' => $isVirtual,
            'is_pack' => $isPack,
            'has_combinations' => $hasCombinations,
            'is_downloadable' => $isDownloadable,
            'download_info' => $downloadInfo,
        ];
    }

    // =========================================
    // PRODUCT COMBINATIONS (VARIANTS)
    // =========================================

    /**
     * Get all combinations (variants) for a product
     *
     * @param int $productId
     * @return array Array of combinations
     */
    public function getProductCombinations(int $productId): array
    {
        $response = $this->request('combinations', [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id_product]' => $productId,
        ]);

        if (!$response || !isset($response['combinations'])) {
            return [];
        }

        return $response['combinations'];
    }

    /**
     * Get a single combination by ID
     *
     * @param int $combinationId
     * @return array|null
     */
    public function getCombination(int $combinationId): ?array
    {
        $response = $this->request("combinations/{$combinationId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['combination'])) {
            return null;
        }

        return $response['combination'];
    }

    /**
     * Check if a product has combinations (is a variant parent)
     *
     * @param int $productId
     * @return bool
     */
    public function hasProductCombinations(int $productId): bool
    {
        $product = $this->getProduct($productId);

        if (!$product) {
            return false;
        }

        // Check associations for combinations
        return !empty($product['associations']['combinations']);
    }

    // =========================================
    // PRODUCT ATTRIBUTES (size, color, etc.)
    // =========================================

    /**
     * Get a product attribute by ID
     *
     * @param int $attributeId
     * @return array|null
     */
    public function getProductAttribute(int $attributeId): ?array
    {
        $response = $this->request("product_option_values/{$attributeId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['product_option_value'])) {
            return null;
        }

        return $response['product_option_value'];
    }

    /**
     * Get a product attribute group (like "Size", "Color") by ID
     *
     * @param int $attributeGroupId
     * @return array|null
     */
    public function getProductAttributeGroup(int $attributeGroupId): ?array
    {
        $response = $this->request("product_options/{$attributeGroupId}", [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['product_option'])) {
            return null;
        }

        return $response['product_option'];
    }

    /**
     * Get all product attribute groups
     *
     * @return array
     */
    public function getProductAttributeGroups(): array
    {
        $response = $this->request('product_options', [
            'display' => 'full',
            'output_format' => 'JSON',
        ]);

        if (!$response || !isset($response['product_options'])) {
            return [];
        }

        return $response['product_options'];
    }

    /**
     * Get variant attributes for a combination
     *
     * Returns an array like ['color' => 'Red', 'size' => 'M']
     *
     * @param array $combination Combination data
     * @return array
     */
    public function getCombinationAttributes(array $combination): array
    {
        $attributes = [];

        // Combinations have associations with product_option_values
        $attrValues = $combination['associations']['product_option_values'] ?? [];

        foreach ($attrValues as $attrValue) {
            $attrId = (int) ($attrValue['id'] ?? 0);
            if ($attrId === 0) {
                continue;
            }

            // Get the attribute value details
            $attrDetail = $this->getProductAttribute($attrId);
            if (!$attrDetail) {
                continue;
            }

            // Get the attribute group (color, size, etc.)
            $groupId = (int) ($attrDetail['id_attribute_group'] ?? 0);
            $attrGroup = $this->getProductAttributeGroup($groupId);

            // Get attribute name (localized)
            $attrName = $this->getLocalizedValue($attrDetail['name'] ?? '');
            $groupName = $this->getLocalizedValue($attrGroup['name'] ?? 'attribute');

            // Normalize the group name for Schema.org mapping
            $normalizedGroup = $this->normalizeAttributeName($groupName);
            $attributes[$normalizedGroup] = $attrName;
        }

        return $attributes;
    }

    /**
     * Normalize attribute group name to Schema.org property
     *
     * Maps PrestaShop attribute names like "Taglia", "Colore" to
     * standard names like "size", "color"
     *
     * @param string $name
     * @return string
     */
    private function normalizeAttributeName(string $name): string
    {
        $name = strtolower(trim($name));

        // Common mappings (add more as needed)
        $mappings = [
            // English
            'size' => 'size',
            'color' => 'color',
            'colour' => 'color',
            'material' => 'material',
            'pattern' => 'pattern',

            // Italian
            'taglia' => 'size',
            'misura' => 'size',
            'colore' => 'color',
            'materiale' => 'material',
            'fantasia' => 'pattern',
            'motivo' => 'pattern',

            // Spanish
            'talla' => 'size',
            'tamaño' => 'size',

            // French
            'taille' => 'size',
            'couleur' => 'color',
            'matière' => 'material',

            // German
            'größe' => 'size',
            'farbe' => 'color',
        ];

        return $mappings[$name] ?? $name;
    }

    /**
     * Get localized value from PrestaShop multi-language field
     *
     * @param mixed $value
     * @param int $langId Preferred language ID (0 = first available)
     * @return string
     */
    private function getLocalizedValue(mixed $value, int $langId = 0): string
    {
        if (!is_array($value)) {
            return (string) $value;
        }

        // Multi-language format: [{'id': langId, 'value': 'text'}, ...]
        if ($langId > 0) {
            foreach ($value as $lang) {
                if (isset($lang['id']) && (int) $lang['id'] === $langId && !empty($lang['value'])) {
                    return $lang['value'];
                }
            }
        }

        // Return first non-empty value
        foreach ($value as $lang) {
            if (isset($lang['value']) && !empty($lang['value'])) {
                return $lang['value'];
            }
        }

        return '';
    }

    /**
     * Make an API request
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|null
     */
    private function request(string $endpoint, array $params = []): ?array
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        if ($this->debug) {
            echo "[DEBUG] Request: {$url}\n";
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->apiKey . ':'),
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

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

        if ($httpCode !== 200) {
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
     * Test the API connection
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        $response = $this->request('', ['output_format' => 'JSON']);
        return $response !== null;
    }
}
