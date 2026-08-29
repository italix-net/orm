<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Thing Model (Base Class)
 *
 * The root of the Schema.org type hierarchy for e-commerce entities.
 * Uses DelegatedTypes to delegate to Product, Order, or OrderItem.
 *
 * @see https://schema.org/Thing
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\DelegatedTypes;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;

use function Italix\Orm\Operators\eq;

class Thing extends ActiveRow
{
    use Persistable, DelegatedTypes, HasTimestamps;

    // =========================================
    // DELEGATED TYPES CONFIGURATION
    // =========================================

    /**
     * Map of type names to delegate classes
     */
    protected function get_delegated_types(): array
    {
        return [
            'Product'   => Product::class,
            'Order'     => Order::class,
            'OrderItem' => OrderItem::class,
        ];
    }

    /**
     * Column that stores the type name
     */
    protected function get_type_column(): string
    {
        return 'type';
    }

    /**
     * Column that stores the hierarchy path
     */
    protected function get_type_path_column(): ?string
    {
        return 'type_path';
    }

    /**
     * Foreign key column in delegate tables
     */
    protected function get_delegate_foreign_key(): string
    {
        return 'thing_id';
    }

    // =========================================
    // FACTORY METHODS
    // =========================================

    /**
     * Create a Product
     *
     * @param array $thing_data Base thing data (name, description, etc.)
     * @param array $product_data Product-specific data (sku, price, etc.)
     * @return static
     */
    public static function create_product(array $thing_data, array $product_data = []): static
    {
        $thing_data['uuid'] = $thing_data['uuid'] ?? self::generate_uuid();
        $thing_data['is_product'] = true;
        $thing_data['type_path'] = 'Thing/Product';

        return static::create_with_delegate('Product', $thing_data, $product_data);
    }

    /**
     * Create a ProductGroup (a Product that groups variants)
     *
     * @param array $thing_data Base thing data
     * @param array $product_data Product-specific data
     * @return static
     */
    public static function create_product_group(array $thing_data, array $product_data = []): static
    {
        $product_data['is_group'] = true;
        return static::create_product($thing_data, $product_data);
    }

    /**
     * Create a Product variant that belongs to a ProductGroup
     *
     * @param Thing $product_group The parent ProductGroup
     * @param array $thing_data Base thing data
     * @param array $product_data Product-specific data (must include varies_by value)
     * @return static
     */
    public static function create_product_variant(
        Thing $product_group,
        array $thing_data,
        array $product_data = []
    ): static {
        $product_data['variant_of_id'] = $product_group['id'];
        $product_data['is_group'] = false;
        return static::create_product($thing_data, $product_data);
    }

    /**
     * Create a Product variant with variant attributes
     *
     * Convenience method that auto-generates name with variant description
     *
     * @param Thing $product_group The parent ProductGroup
     * @param array $variant_attrs Variant attributes (size, color, etc.)
     * @param array $product_data Additional product data (sku, price, etc.)
     * @param array $thing_data Override thing data (name auto-generated if not set)
     * @return static
     */
    public static function create_variant(
        Thing $product_group,
        array $variant_attrs,
        array $product_data = [],
        array $thing_data = []
    ): static {
        // Standard variant attributes
        $standard = ['size', 'size_system', 'size_group', 'color', 'material', 'pattern'];
        $additional = [];

        foreach ($variant_attrs as $key => $value) {
            if (in_array($key, $standard, true)) {
                $product_data[$key] = $value;
            } else {
                $additional[$key] = $value;
            }
        }

        // Store additional attributes as JSON
        if (!empty($additional)) {
            $product_data['variant_attributes'] = json_encode($additional, JSON_UNESCAPED_UNICODE);
        }

        // Auto-generate variant name from parent + attributes
        if (!isset($thing_data['name'])) {
            $parent_product = $product_group->delegate();
            $variant_desc = self::build_variant_description($variant_attrs, $parent_product);
            $thing_data['name'] = $product_group['name'] . ' - ' . $variant_desc;
        }

        return static::create_product_variant($product_group, $thing_data, $product_data);
    }

    /**
     * Build variant description string from attributes
     *
     * @param array $attrs Variant attributes
     * @param Product|null $parentProduct Parent product (to get varies_by order)
     * @return string
     */
    private static function build_variant_description(array $attrs, ?Product $parent_product = null): string
    {
        // Use varies_by order if available
        if ($parent_product && method_exists($parent_product, 'varies_by')) {
            $ordered = [];
            foreach ($parent_product->varies_by() as $attr) {
                if (isset($attrs[$attr])) {
                    $ordered[] = $attrs[$attr];
                }
            }
            if (!empty($ordered)) {
                return implode(', ', $ordered);
            }
        }

        // Fallback: use display-friendly order
        $priority = ['color', 'size', 'material', 'pattern'];
        $parts = [];

        foreach ($priority as $attr) {
            if (isset($attrs[$attr])) {
                $parts[] = $attrs[$attr];
                unset($attrs[$attr]);
            }
        }

        // Add any remaining attributes
        foreach ($attrs as $value) {
            $parts[] = $value;
        }

        return implode(', ', $parts);
    }

    /**
     * Create an Order
     *
     * @param array $thing_data Base thing data (name = order title/reference)
     * @param array $order_data Order-specific data
     * @return static
     */
    public static function create_order(array $thing_data, array $order_data = []): static
    {
        $thing_data['uuid'] = $thing_data['uuid'] ?? self::generate_uuid();
        $thing_data['is_order'] = true;
        $thing_data['type_path'] = 'Thing/Order';

        return static::create_with_delegate('Order', $thing_data, $order_data);
    }

    /**
     * Create an Order for a Customer with addresses
     *
     * @param Customer $customer The customer placing the order
     * @param PostalAddress $billing_address Billing address
     * @param PostalAddress|null $delivery_address Delivery address (uses billing if null)
     * @param array $thing_data Base thing data
     * @param array $order_data Order-specific data
     * @return static
     */
    public static function create_order_for_customer(
        Customer $customer,
        PostalAddress $billing_address,
        ?PostalAddress $delivery_address = null,
        array $thing_data = [],
        array $order_data = []
    ): static {
        // Set customer and address IDs
        $order_data['customer_id'] = $customer['id'];
        $order_data['billing_address_id'] = $billing_address['id'];
        $order_data['delivery_address_id'] = $delivery_address ? $delivery_address['id'] : $billing_address['id'];

        // Auto-generate order name if not provided
        if (!isset($thing_data['name'])) {
            $order_number = $order_data['order_number'] ?? 'ORD-' . time();
            $thing_data['name'] = "Order {$order_number}";
        }

        return static::create_order($thing_data, $order_data);
    }

    /**
     * Create an OrderItem
     *
     * @param Thing $order The Order this item belongs to
     * @param Thing $product The Product being ordered
     * @param int $quantity Quantity ordered
     * @param array $thing_data Additional thing data
     * @param array $item_data Additional order item data
     * @return static
     */
    public static function create_order_item(
        Thing $order,
        Thing $product,
        int $quantity = 1,
        array $thing_data = [],
        array $item_data = []
    ): static {
        // Auto-generate name if not provided
        $thing_data['name'] = $thing_data['name'] ?? $product['name'];
        $thing_data['uuid'] = $thing_data['uuid'] ?? self::generate_uuid();
        $thing_data['is_order_item'] = true;
        $thing_data['type_path'] = 'Thing/OrderItem';

        // Set relationships and quantity
        $item_data['order_thing_id'] = $order['id'];
        $item_data['product_thing_id'] = $product['id'];
        $item_data['order_quantity'] = $quantity;

        // Copy price from product if not specified
        $product_delegate = $product->delegate();
        if (!isset($item_data['unit_price']) && $product_delegate) {
            $item_data['unit_price'] = $product_delegate['price'];
            $item_data['currency'] = $product_delegate['currency'] ?? 'USD';
        }

        // Calculate line total
        if (isset($item_data['unit_price'])) {
            $item_data['line_total'] = $item_data['unit_price'] * $quantity;
        }

        return static::create_with_delegate('OrderItem', $thing_data, $item_data);
    }

    // =========================================
    // QUERY HELPERS
    // =========================================

    /**
     * Find all Products
     *
     * @return array<static>
     */
    public static function find_products(): array
    {
        $table = static::get_table();
        return static::find_with_delegates([
            'where' => eq($table->is_product, true),
        ]);
    }

    /**
     * Find all ProductGroups
     *
     * @return array<static>
     */
    public static function find_product_groups(): array
    {
        $things = static::find_products();
        return array_filter($things, fn($t) => $t->delegate()['is_group'] ?? false);
    }

    /**
     * Find all Orders
     *
     * @return array<static>
     */
    public static function find_orders(): array
    {
        $table = static::get_table();
        return static::find_with_delegates([
            'where' => eq($table->is_order, true),
        ]);
    }

    /**
     * Find all Orders for a specific Customer
     *
     * @param Customer $customer The customer
     * @return array<static>
     */
    public static function find_customer_orders(Customer $customer): array
    {
        $orders = static::find_orders();

        return array_filter($orders, function ($order) use ($customer) {
            $delegate = $order->delegate();
            return $delegate && (int) $delegate['customer_id'] === (int) $customer['id'];
        });
    }

    /**
     * Find all OrderItems for an Order
     *
     * @param Thing $order The Order
     * @return array<static>
     */
    public static function find_order_items(Thing $order): array
    {
        $table = static::get_table();
        $things = static::find_with_delegates([
            'where' => eq($table->is_order_item, true),
        ]);

        return array_filter($things, function ($item) use ($order) {
            $delegate = $item->delegate();
            return $delegate && $delegate['order_thing_id'] === $order['id'];
        });
    }

    // =========================================
    // TYPE CHECKING HELPERS
    // =========================================

    /**
     * Check if this is a Product
     */
    public function is_product(): bool
    {
        return (bool) ($this['is_product'] ?? false);
    }

    /**
     * Check if this is a ProductGroup
     */
    public function is_product_group(): bool
    {
        if (!$this->is_product()) {
            return false;
        }
        $delegate = $this->delegate();
        return $delegate && ($delegate['is_group'] ?? false);
    }

    /**
     * Check if this is an Order
     */
    public function is_order(): bool
    {
        return (bool) ($this['is_order'] ?? false);
    }

    /**
     * Check if this is an OrderItem
     */
    public function is_order_item(): bool
    {
        return (bool) ($this['is_order_item'] ?? false);
    }

    // =========================================
    // UTILITIES
    // =========================================

    /**
     * Generate a UUID v4
     */
    protected static function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
