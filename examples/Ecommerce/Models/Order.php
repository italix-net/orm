<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Order Model (Delegate)
 *
 * Represents an order in the e-commerce system.
 * Delegated from Thing for Order-specific attributes.
 *
 * @see https://schema.org/Order
 * @see https://schema.org/OrderStatus
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class Order extends ActiveRow
{
    use Persistable;

    // Order status constants (Schema.org OrderStatus)
    public const STATUS_CREATED = 'OrderCreated';
    public const STATUS_PROCESSING = 'OrderProcessing';
    public const STATUS_PAYMENT_DUE = 'OrderPaymentDue';
    public const STATUS_IN_TRANSIT = 'OrderInTransit';
    public const STATUS_PICKUP_AVAILABLE = 'OrderPickupAvailable';
    public const STATUS_DELIVERED = 'OrderDelivered';
    public const STATUS_RETURNED = 'OrderReturned';
    public const STATUS_CANCELLED = 'OrderCancelled';
    public const STATUS_PROBLEM = 'OrderProblem';

    // =========================================
    // ORDER STATUS
    // =========================================

    /**
     * Get order status
     *
     * @return string
     */
    public function status(): string
    {
        return $this['order_status'] ?? self::STATUS_CREATED;
    }

    /**
     * Get human-readable status text
     *
     * @return string
     */
    public function status_text(): string
    {
        $texts = [
            self::STATUS_CREATED => 'Order Created',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_PAYMENT_DUE => 'Payment Due',
            self::STATUS_IN_TRANSIT => 'In Transit',
            self::STATUS_PICKUP_AVAILABLE => 'Ready for Pickup',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_PROBLEM => 'Problem',
        ];

        return $texts[$this->status()] ?? $this->status();
    }

    /**
     * Check if order is completed (delivered or picked up)
     *
     * @return bool
     */
    public function is_completed(): bool
    {
        return in_array($this->status(), [
            self::STATUS_DELIVERED,
            self::STATUS_PICKUP_AVAILABLE,
        ]);
    }

    /**
     * Check if order is cancelled
     *
     * @return bool
     */
    public function is_cancelled(): bool
    {
        return $this->status() === self::STATUS_CANCELLED;
    }

    /**
     * Check if order can be cancelled
     *
     * @return bool
     */
    public function can_cancel(): bool
    {
        return in_array($this->status(), [
            self::STATUS_CREATED,
            self::STATUS_PROCESSING,
            self::STATUS_PAYMENT_DUE,
        ]);
    }

    // =========================================
    // PRICING
    // =========================================

    /**
     * Get formatted total price
     *
     * @return string
     */
    public function formatted_total(): string
    {
        $total = $this['total_price'] ?? 0;
        $currency = $this['currency'] ?? 'USD';

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format((float) $total, 2);
    }

    /**
     * Get subtotal
     *
     * @return float
     */
    public function subtotal(): float
    {
        return (float) ($this['subtotal'] ?? 0);
    }

    /**
     * Get tax amount
     *
     * @return float
     */
    public function tax(): float
    {
        return (float) ($this['tax'] ?? 0);
    }

    /**
     * Get shipping cost
     *
     * @return float
     */
    public function shipping_cost(): float
    {
        return (float) ($this['shipping_cost'] ?? 0);
    }

    /**
     * Get discount amount
     *
     * @return float
     */
    public function discount(): float
    {
        return (float) ($this['discount'] ?? 0);
    }

    /**
     * Get total price
     *
     * @return float
     */
    public function total(): float
    {
        return (float) ($this['total_price'] ?? 0);
    }

    // =========================================
    // ORDER ITEMS
    // =========================================

    /**
     * Get all items in this order
     *
     * @param Thing $order_thing The Thing wrapper for this order
     * @return array<Thing>
     */
    public function items(Thing $order_thing): array
    {
        return Thing::find_order_items($order_thing);
    }

    /**
     * Calculate order totals from items
     * Useful for recalculating after items change
     *
     * @param array $items Array of OrderItem delegates
     * @param float $tax_rate Tax rate as decimal (e.g., 0.08 for 8%)
     * @param float $shipping Shipping cost
     * @param float $discount Discount amount
     * @return array ['subtotal', 'tax', 'shipping_cost', 'discount', 'total_price']
     */
    public static function calculate_totals(
        array $items,
        float $tax_rate = 0,
        float $shipping = 0,
        float $discount = 0
    ): array {
        $subtotal = 0;

        foreach ($items as $item) {
            // Handle both Thing (with delegate) and OrderItem directly
            if ($item instanceof Thing) {
                $delegate = $item->delegate();
                $subtotal += (float) ($delegate['line_total'] ?? 0);
            } else {
                $subtotal += (float) ($item['line_total'] ?? 0);
            }
        }

        $tax = $subtotal * $tax_rate;
        $total = $subtotal + $tax + $shipping - $discount;

        return [
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'shipping_cost' => round($shipping, 2),
            'discount' => round($discount, 2),
            'total_price' => round($total, 2),
        ];
    }

    // =========================================
    // CUSTOMER RELATIONSHIP
    // =========================================

    /**
     * Get the customer (Person or Organization) for this order
     *
     * @return Customer|null
     */
    public function customer(): ?Customer
    {
        if (!$this['customer_id']) {
            return null;
        }

        return Customer::find_with_delegate((int) $this['customer_id']);
    }

    /**
     * Get customer display name (for convenience)
     *
     * @return string
     */
    public function customer_name(): string
    {
        $customer = $this->customer();
        return $customer ? $customer->display_name() : 'Unknown Customer';
    }

    /**
     * Get customer email (for convenience)
     *
     * @return string|null
     */
    public function customer_email(): ?string
    {
        $customer = $this->customer();
        return $customer ? $customer['email'] : null;
    }

    /**
     * Check if customer is a Person
     *
     * @return bool
     */
    public function customer_is_person(): bool
    {
        $customer = $this->customer();
        return $customer && $customer->is_person();
    }

    /**
     * Check if customer is an Organization
     *
     * @return bool
     */
    public function customer_is_organization(): bool
    {
        $customer = $this->customer();
        return $customer && $customer->is_organization();
    }

    // =========================================
    // ADDRESS RELATIONSHIPS
    // =========================================

    /**
     * Get the billing address
     *
     * @return PostalAddress|null
     */
    public function billing_address(): ?PostalAddress
    {
        if (!$this['billing_address_id']) {
            return null;
        }

        return PostalAddress::find((int) $this['billing_address_id']);
    }

    /**
     * Get the delivery/shipping address
     *
     * @return PostalAddress|null
     */
    public function delivery_address(): ?PostalAddress
    {
        if (!$this['delivery_address_id']) {
            return null;
        }

        return PostalAddress::find((int) $this['delivery_address_id']);
    }

    /**
     * Get formatted billing address
     *
     * @return string
     */
    public function formatted_billing_address(): string
    {
        $address = $this->billing_address();
        return $address ? $address->formatted() : '';
    }

    /**
     * Get formatted delivery address
     *
     * @return string
     */
    public function formatted_delivery_address(): string
    {
        $address = $this->delivery_address();
        return $address ? $address->formatted() : '';
    }

    /**
     * Check if billing and delivery addresses are the same
     *
     * @return bool
     */
    public function same_billing_delivery(): bool
    {
        return $this['billing_address_id'] === $this['delivery_address_id'];
    }

    // =========================================
    // DATES
    // =========================================

    /**
     * Get order date as DateTime
     *
     * @return \DateTime|null
     */
    public function order_date(): ?\DateTime
    {
        $date = $this['order_date'];
        return $date ? new \DateTime($date) : null;
    }

    /**
     * Get formatted order date
     *
     * @param string $format Date format
     * @return string
     */
    public function formatted_date(string $format = 'M j, Y'): string
    {
        $date = $this->order_date();
        return $date ? $date->format($format) : '';
    }

    // =========================================
    // SCHEMA.ORG JSON-LD
    // =========================================

    /**
     * Generate Schema.org JSON-LD for this order
     *
     * @param Thing $thing The parent Thing
     * @param array $items Array of OrderItem Things
     * @return array
     */
    public function to_schema_org(Thing $thing, array $items = []): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Order',
            'orderNumber' => $this['order_number'],
            'orderStatus' => 'https://schema.org/' . $this->status(),
            'orderDate' => $this['order_date'],
        ];

        // Customer (Person or Organization)
        $customer = $this->customer();
        if ($customer) {
            $delegate = $customer->delegate();
            if ($delegate) {
                $schema['customer'] = $delegate->to_schema_org($customer);
            }
        }

        // Billing address
        $billing_address = $this->billing_address();
        if ($billing_address) {
            $schema['billingAddress'] = $billing_address->to_schema_org();
        }

        // Delivery (we put it directly on order for simplicity)
        $delivery_address = $this->delivery_address();
        if ($delivery_address) {
            $schema['orderDelivery'] = [
                '@type' => 'ParcelDelivery',
                'deliveryAddress' => $delivery_address->to_schema_org(),
            ];
        }

        // Order items
        if (!empty($items)) {
            $schema['orderedItem'] = array_map(function ($item) {
                $delegate = $item->delegate();
                return [
                    '@type' => 'OrderItem',
                    'orderItemNumber' => $delegate['order_item_number'],
                    'orderQuantity' => $delegate['order_quantity'],
                    'orderItemStatus' => 'https://schema.org/' . ($delegate['order_item_status'] ?? 'OrderProcessing'),
                ];
            }, $items);
        }

        // Price specification
        $schema['acceptedOffer'] = [
            '@type' => 'Offer',
            'price' => $this['total_price'],
            'priceCurrency' => $this['currency'] ?? 'USD',
        ];

        return $schema;
    }
}
