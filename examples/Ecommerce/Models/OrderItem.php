<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * OrderItem Model (Delegate)
 *
 * Represents a line item in an order.
 * Delegated from Thing for OrderItem-specific attributes.
 *
 * @see https://schema.org/OrderItem
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Operators\eq;

class OrderItem extends ActiveRow
{
    use Persistable;

    // =========================================
    // RELATIONSHIPS
    // =========================================

    /**
     * Get the Order this item belongs to
     *
     * @return Thing|null
     */
    public function order(): ?Thing
    {
        $order_thing_id = $this['order_thing_id'];
        if (!$order_thing_id) {
            return null;
        }

        return Thing::find_with_delegate($order_thing_id);
    }

    /**
     * Get the Product being ordered
     *
     * @return Thing|null
     */
    public function product(): ?Thing
    {
        $product_thing_id = $this['product_thing_id'];
        if (!$product_thing_id) {
            return null;
        }

        return Thing::find_with_delegate($product_thing_id);
    }

    // =========================================
    // QUANTITY AND PRICING
    // =========================================

    /**
     * Get quantity ordered
     *
     * @return int
     */
    public function quantity(): int
    {
        return (int) ($this['order_quantity'] ?? 1);
    }

    /**
     * Get unit price
     *
     * @return float
     */
    public function unit_price(): float
    {
        return (float) ($this['unit_price'] ?? 0);
    }

    /**
     * Get line total
     *
     * @return float
     */
    public function line_total(): float
    {
        return (float) ($this['line_total'] ?? ($this->unit_price() * $this->quantity()));
    }

    /**
     * Get formatted unit price
     *
     * @return string
     */
    public function formatted_unit_price(): string
    {
        return $this->format_price($this->unit_price());
    }

    /**
     * Get formatted line total
     *
     * @return string
     */
    public function formatted_line_total(): string
    {
        return $this->format_price($this->line_total());
    }

    /**
     * Format a price with currency
     *
     * @param float $price
     * @return string
     */
    protected function format_price(float $price): string
    {
        $currency = $this['currency'] ?? 'USD';

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format($price, 2);
    }

    // =========================================
    // BUNDLE/PACK SUPPORT
    // =========================================

    /**
     * Check if this item is part of a bundle/pack
     *
     * @return bool
     */
    public function is_bundle_component(): bool
    {
        return (bool) ($this['is_bundle_component'] ?? false);
    }

    /**
     * Check if this item is a bundle/pack (contains other items)
     *
     * @return bool
     */
    public function is_bundle(): bool
    {
        $product = $this->product();
        if (!$product) {
            return false;
        }
        $delegate = $product->delegate();
        return $delegate instanceof Product && $delegate->is_group();
    }

    /**
     * Get the parent bundle item (if this is a component)
     *
     * @return Thing|null
     */
    public function parent_bundle_item(): ?Thing
    {
        $parent_id = $this['parent_bundle_item_id'];
        if (!$parent_id) {
            return null;
        }
        return Thing::find_with_delegate($parent_id);
    }

    /**
     * Get bundle component items (if this is a bundle)
     *
     * @return array<Thing>
     */
    public function bundle_components(): array
    {
        if (!$this->is_bundle()) {
            return [];
        }

        $thing_id = $this['thing_id'];
        $table = self::get_table();

        // Find all items with this item as parent
        $components = self::find_all([
            'where' => eq($table->parent_bundle_item_id, $thing_id),
        ]);

        $things = [];
        foreach ($components as $component) {
            $thing = Thing::find_with_delegate($component['thing_id']);
            if ($thing) {
                $things[] = $thing;
            }
        }

        return $things;
    }

    // =========================================
    // STATUS
    // =========================================

    /**
     * Get item status
     *
     * @return string
     */
    public function status(): string
    {
        return $this['order_item_status'] ?? 'OrderProcessing';
    }

    /**
     * Get human-readable status text
     *
     * @return string
     */
    public function status_text(): string
    {
        $texts = [
            'OrderCreated' => 'Created',
            'OrderProcessing' => 'Processing',
            'OrderInTransit' => 'In Transit',
            'OrderDelivered' => 'Delivered',
            'OrderReturned' => 'Returned',
            'OrderCancelled' => 'Cancelled',
        ];

        return $texts[$this->status()] ?? $this->status();
    }

    // =========================================
    // DISPLAY HELPERS
    // =========================================

    /**
     * Get a display string for this item
     *
     * @param Thing|null $parent_thing The parent Thing (to get name)
     * @return string
     */
    public function display_string(?Thing $parent_thing = null): string
    {
        $name = $parent_thing ? $parent_thing['name'] : 'Unknown Product';
        $qty = $this->quantity();
        $price = $this->formatted_line_total();

        return "{$qty}x {$name} = {$price}";
    }

    /**
     * Get item number
     *
     * @return string|null
     */
    public function item_number(): ?string
    {
        return $this['order_item_number'];
    }

    // =========================================
    // SCHEMA.ORG JSON-LD
    // =========================================

    /**
     * Generate Schema.org JSON-LD for this order item
     *
     * @param Thing $thing The parent Thing
     * @return array
     */
    public function to_schema_org(Thing $thing): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'OrderItem',
            'orderItemNumber' => $this['order_item_number'],
            'orderQuantity' => $this->quantity(),
            'orderItemStatus' => 'https://schema.org/' . $this->status(),
        ];

        // Add product reference if available
        $product = $this->product();
        if ($product) {
            $schema['orderedItem'] = [
                '@type' => 'Product',
                'name' => $product['name'],
                'sku' => $product->delegate()['sku'] ?? null,
            ];
        }

        return $schema;
    }
}
