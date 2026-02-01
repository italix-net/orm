<?php
/**
 * E-commerce Schema Definition
 *
 * Schema.org-based e-commerce data model using DelegatedTypes pattern.
 *
 * Hierarchy:
 * - Thing (base) → Product, Order, OrderItem (delegates)
 * - ProductGroup is handled as a Product with is_group=true
 *
 * @see https://schema.org/Product
 * @see https://schema.org/ProductGroup
 * @see https://schema.org/Order
 * @see https://schema.org/OrderItem
 */

namespace Examples\Ecommerce;

use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\bigint;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\decimal;
use function Italix\Orm\Schema\boolean;
use function Italix\Orm\Schema\timestamp;

/**
 * E-commerce Schema
 *
 * Defines tables for a Schema.org-compliant e-commerce system.
 */
class Schema
{
    public Table $things;
    public Table $products;
    public Table $orders;
    public Table $order_items;

    public function __construct()
    {
        // =====================================================
        // THINGS TABLE (Base for all Schema.org types)
        // =====================================================
        // Common properties shared by Product, Order, OrderItem
        // @see https://schema.org/Thing
        $this->things = new Table('things', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'uuid'        => varchar(36)->not_null()->unique(),
            'type'        => varchar(50)->not_null(),      // 'Product', 'Order', 'OrderItem'
            'type_path'   => varchar(200),                 // 'Thing/Product', 'Thing/Order', etc.

            // Thing properties (Schema.org)
            'name'        => varchar(255)->not_null(),     // The name of the item
            'description' => text(),                        // A description of the item
            'url'         => varchar(500),                  // URL of the item
            'image'       => varchar(500),                  // URL to an image of the item

            // Hierarchy flags for efficient querying
            'is_product'    => boolean()->default(false),
            'is_order'      => boolean()->default(false),
            'is_order_item' => boolean()->default(false),

            // Timestamps
            'created_at'  => timestamp(),
            'updated_at'  => timestamp(),
        ], 'sqlite');

        // =====================================================
        // PRODUCTS TABLE (Delegate for Product and ProductGroup)
        // =====================================================
        // @see https://schema.org/Product
        // @see https://schema.org/ProductGroup
        $this->products = new Table('products', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'thing_id'    => bigint()->not_null(),         // FK to things

            // Product identifiers
            'sku'         => varchar(100),                  // Stock Keeping Unit
            'gtin'        => varchar(14),                   // Global Trade Item Number (UPC/EAN)
            'mpn'         => varchar(100),                  // Manufacturer Part Number

            // Product details
            'brand'       => varchar(100),                  // Brand name
            'category'    => varchar(200),                  // Product category

            // Pricing (simplified Offer)
            'price'       => decimal(10, 2),                // Price amount
            'currency'    => varchar(3)->default('USD'),    // ISO 4217 currency code
            'price_valid_until' => timestamp(),             // Price validity

            // Availability
            'availability' => varchar(50)->default('InStock'), // InStock, OutOfStock, PreOrder, etc.
            'inventory_level' => integer()->default(0),

            // ProductGroup support
            // @see https://schema.org/ProductGroup
            'is_group'    => boolean()->default(false),     // True if this is a ProductGroup
            'variant_of_id' => bigint(),                    // FK to parent ProductGroup's thing_id
            'varies_by'   => varchar(200),                  // What varies: 'color', 'size', etc.

            // Physical properties
            'weight'      => decimal(10, 3),                // Weight in kg
            'weight_unit' => varchar(10)->default('kg'),
        ], 'sqlite');

        // =====================================================
        // ORDERS TABLE (Delegate for Order)
        // =====================================================
        // @see https://schema.org/Order
        $this->orders = new Table('orders', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'thing_id'    => bigint()->not_null(),         // FK to things

            // Order identifiers
            'order_number'     => varchar(50)->not_null()->unique(),
            'confirmation_number' => varchar(50),

            // Order status
            // @see https://schema.org/OrderStatus
            'order_status'     => varchar(50)->default('OrderCreated'),
            // Values: OrderCancelled, OrderDelivered, OrderInTransit,
            //         OrderPaymentDue, OrderPickupAvailable, OrderProblem,
            //         OrderProcessing, OrderReturned

            // Dates
            'order_date'       => timestamp(),              // When order was placed
            'payment_due_date' => timestamp(),

            // Customer info (simplified - would typically be FK to Person/Organization)
            'customer_name'    => varchar(200),
            'customer_email'   => varchar(200),
            'billing_address'  => text(),
            'shipping_address' => text(),

            // Totals
            'subtotal'         => decimal(10, 2),
            'tax'              => decimal(10, 2),
            'shipping_cost'    => decimal(10, 2),
            'discount'         => decimal(10, 2)->default(0),
            'total_price'      => decimal(10, 2),
            'currency'         => varchar(3)->default('USD'),

            // Payment
            'payment_method'   => varchar(50),              // CreditCard, PayPal, etc.
            'payment_status'   => varchar(50)->default('Pending'),
        ], 'sqlite');

        // =====================================================
        // ORDER_ITEMS TABLE (Delegate for OrderItem)
        // =====================================================
        // @see https://schema.org/OrderItem
        $this->order_items = new Table('order_items', [
            'id'               => bigint()->primary_key()->auto_increment(),
            'thing_id'         => bigint()->not_null(),    // FK to things

            // Relationships
            'order_thing_id'   => bigint()->not_null(),    // FK to Order's thing_id
            'product_thing_id' => bigint()->not_null(),    // FK to Product's thing_id

            // Item identifiers
            'order_item_number' => varchar(50),             // Line item number

            // Quantity and pricing
            'order_quantity'   => integer()->not_null()->default(1),
            'unit_price'       => decimal(10, 2),           // Price per unit at time of order
            'currency'         => varchar(3)->default('USD'),
            'line_total'       => decimal(10, 2),           // quantity * unit_price

            // Item status
            // @see https://schema.org/OrderStatus (applies to items too)
            'order_item_status' => varchar(50)->default('OrderProcessing'),
        ], 'sqlite');
    }

    /**
     * Get all tables in creation order
     *
     * @return array<Table>
     */
    public function get_tables(): array
    {
        return [
            $this->things,
            $this->products,
            $this->orders,
            $this->order_items,
        ];
    }
}
