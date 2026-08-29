<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
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
    private string $api_url;
    private string $api_key;
    private bool $debug;

    /**
     * @param string $shopUrl Base URL of the PrestaShop store (e.g., https://myshop.com)
     * @param string $apiKey WebService API key
     * @param bool $debug Enable debug output
     */
    public function __construct(string $shop_url, string $api_key, bool $debug = false)
    {
        $this->api_url = rtrim($shop_url, '/') . '/api';
        $this->api_key = $api_key;
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
    public function get_orders(int $min_id = 1, int $limit = 100, array $options = []): array
    {
        $params = [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id]' => "[{$min_id},999999999]",
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
    public function get_order(int $order_id): ?array
    {
        $response = $this->request("orders/{$order_id}", [
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
    public function get_order_details(int $order_id): array
    {
        $response = $this->request('order_details', [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id_order]' => $order_id,
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
    public function get_product(int $product_id): ?array
    {
        $response = $this->request("products/{$product_id}", [
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
    public function get_products(array $ids = []): array
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
    public function get_customer(int $customer_id): ?array
    {
        $response = $this->request("customers/{$customer_id}", [
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
    public function get_address(int $address_id): ?array
    {
        $response = $this->request("addresses/{$address_id}", [
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
    public function get_order_states(): array
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
    public function get_pack_items(int $product_id): array
    {
        // PrestaShop stores pack items in stock_availables or via the product's associations
        $product = $this->get_product($product_id);

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
    public function is_product_pack(int $product_id): bool
    {
        $product = $this->get_product($product_id);

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
    public function is_product_virtual(int $product_id): bool
    {
        $product = $this->get_product($product_id);

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
    public function get_product_download_info(int $product_id): ?array
    {
        $response = $this->request('product_downloads', [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id_product]' => $product_id,
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
    public function get_product_type_info(int $product_id): array
    {
        $product = $this->get_product($product_id);

        if (!$product) {
            return [
                'type' => 'unknown',
                'is_virtual' => false,
                'is_pack' => false,
                'has_combinations' => false,
                'is_downloadable' => false,
            ];
        }

        $is_virtual = ($product['is_virtual'] ?? '0') === '1';
        $is_pack = ($product['type'] ?? '') === 'pack' || ($product['cache_is_pack'] ?? '0') === '1';
        $has_combinations = !empty($product['associations']['combinations']);

        // Check if there's a downloadable file
        $download_info = $is_virtual ? $this->get_product_download_info($product_id) : null;
        $is_downloadable = $download_info !== null && !empty($download_info['filename']);

        // Determine primary type
        $type = 'simple';
        if ($is_pack) {
            $type = 'pack';
        } elseif ($has_combinations) {
            $type = 'combinations';
        } elseif ($is_virtual) {
            $type = $is_downloadable ? 'downloadable' : 'service';
        }

        return [
            'type' => $type,
            'is_virtual' => $is_virtual,
            'is_pack' => $is_pack,
            'has_combinations' => $has_combinations,
            'is_downloadable' => $is_downloadable,
            'download_info' => $download_info,
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
    public function get_product_combinations(int $product_id): array
    {
        $response = $this->request('combinations', [
            'display' => 'full',
            'output_format' => 'JSON',
            'filter[id_product]' => $product_id,
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
    public function get_combination(int $combination_id): ?array
    {
        $response = $this->request("combinations/{$combination_id}", [
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
    public function has_product_combinations(int $product_id): bool
    {
        $product = $this->get_product($product_id);

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
    public function get_product_attribute(int $attribute_id): ?array
    {
        $response = $this->request("product_option_values/{$attribute_id}", [
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
    public function get_product_attribute_group(int $attribute_group_id): ?array
    {
        $response = $this->request("product_options/{$attribute_group_id}", [
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
    public function get_product_attribute_groups(): array
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
    public function get_combination_attributes(array $combination): array
    {
        $attributes = [];

        // Combinations have associations with product_option_values
        $attr_values = $combination['associations']['product_option_values'] ?? [];

        foreach ($attr_values as $attr_value) {
            $attr_id = (int) ($attr_value['id'] ?? 0);
            if ($attr_id === 0) {
                continue;
            }

            // Get the attribute value details
            $attr_detail = $this->get_product_attribute($attr_id);
            if (!$attr_detail) {
                continue;
            }

            // Get the attribute group (color, size, etc.)
            $group_id = (int) ($attr_detail['id_attribute_group'] ?? 0);
            $attr_group = $this->get_product_attribute_group($group_id);

            // Get attribute name (localized)
            $attr_name = $this->get_localized_value($attr_detail['name'] ?? '');
            $group_name = $this->get_localized_value($attr_group['name'] ?? 'attribute');

            // Normalize the group name for Schema.org mapping
            $normalized_group = $this->normalize_attribute_name($group_name);
            $attributes[$normalized_group] = $attr_name;
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
    private function normalize_attribute_name(string $name): string
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
    private function get_localized_value(mixed $value, int $lang_id = 0): string
    {
        if (!is_array($value)) {
            return (string) $value;
        }

        // Multi-language format: [{'id': langId, 'value': 'text'}, ...]
        if ($lang_id > 0) {
            foreach ($value as $lang) {
                if (isset($lang['id']) && (int) $lang['id'] === $lang_id && !empty($lang['value'])) {
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
        $url = $this->api_url . '/' . ltrim($endpoint, '/');

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
                'Authorization: Basic ' . base64_encode($this->api_key . ':'),
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

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

        if ($http_code !== 200) {
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
     * Test the API connection
     *
     * @return bool
     */
    public function test_connection(): bool
    {
        $response = $this->request('', ['output_format' => 'JSON']);
        return $response !== null;
    }
}
