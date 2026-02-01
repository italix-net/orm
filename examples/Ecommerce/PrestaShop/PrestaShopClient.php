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
