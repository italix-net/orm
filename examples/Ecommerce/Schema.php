<?php
/**
 * E-commerce Schema Definition
 *
 * Schema.org-based e-commerce data model using DelegatedTypes pattern.
 *
 * Hierarchy:
 * - Thing (base) → Product, Order, OrderItem (delegates)
 * - Customer (base) → Person, Organization (delegates) - Agent pattern
 * - PostalAddress (standalone) for billing/delivery addresses
 * - ProductGroup is handled as a Product with is_group=true
 *
 * @see https://schema.org/Product
 * @see https://schema.org/ProductGroup
 * @see https://schema.org/Order
 * @see https://schema.org/OrderItem
 * @see https://schema.org/Person
 * @see https://schema.org/Organization
 * @see https://schema.org/PostalAddress
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
    // Thing hierarchy
    public Table $things;
    public Table $products;
    public Table $orders;
    public Table $order_items;

    // Customer hierarchy (Agent: Person or Organization)
    public Table $customers;
    public Table $persons;
    public Table $organizations;

    // Address
    public Table $postal_addresses;

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
            'varies_by'   => varchar(200),                  // What varies: 'color,size', etc.

            // Bundle/Pack support (collection of products sold together)
            'is_bundle'   => boolean()->default(false),     // True if this is a product bundle/pack
            'bundle_items' => text(),                       // JSON: [{"product_id": 123, "quantity": 2}, ...]

            // ===================================================
            // VARIANT ATTRIBUTES (Schema.org properties)
            // ===================================================
            // @see https://schema.org/Product variant properties

            // Size (for apparel, shoes, etc.)
            // @see https://schema.org/size
            'size'        => varchar(50),                   // e.g., 'S', 'M', 'L', 'XL', '42', '10.5'
            'size_system' => varchar(50),                   // e.g., 'US', 'EU', 'UK'
            'size_group'  => varchar(50),                   // e.g., 'regular', 'petite', 'plus'

            // Color
            // @see https://schema.org/color
            'color'       => varchar(100),                  // e.g., 'Red', 'Navy Blue', 'Black/White'

            // Material
            // @see https://schema.org/material
            'material'    => varchar(200),                  // e.g., '100% Cotton', 'Leather'

            // Pattern
            // @see https://schema.org/pattern
            'pattern'     => varchar(100),                  // e.g., 'Striped', 'Solid', 'Plaid'

            // Additional variant properties (JSON for flexibility)
            // @see https://schema.org/additionalProperty (PropertyValue)
            'variant_attributes' => text(),                 // JSON: {"width": "32", "length": "30"}

            // Physical properties
            'weight'      => decimal(10, 3),                // Weight in kg
            'weight_unit' => varchar(10)->default('kg'),

            // ===================================================
            // VIRTUAL PRODUCT SUPPORT
            // ===================================================
            // @see https://schema.org/Product (additionalType: DigitalDocument/Service)
            // PrestaShop virtual products: downloadable files or services
            'is_virtual'     => boolean()->default(false),  // True if not a physical product
            'is_downloadable' => boolean()->default(false), // True if has downloadable file
            'download_url'   => varchar(500),               // URL to download file
            'download_limit' => integer(),                  // Max number of downloads allowed
            'download_expiry_days' => integer(),            // Days until download expires

            // Service product properties
            'is_service'     => boolean()->default(false),  // True if this is a service
            'service_duration' => varchar(50),              // e.g., "1 hour", "30 days"
        ], 'sqlite');

        // =====================================================
        // CUSTOMERS TABLE (Base for Person/Organization - Agent)
        // =====================================================
        // Schema.org Agent pattern: customer can be Person or Organization
        // @see https://schema.org/Person
        // @see https://schema.org/Organization
        $this->customers = new Table('customers', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'uuid'        => varchar(36)->not_null()->unique(),
            'type'        => varchar(50)->not_null(),       // 'Person' or 'Organization'
            'type_path'   => varchar(200),                  // 'Customer/Person', 'Customer/Organization'

            // Common properties (shared by Person and Organization)
            'email'       => varchar(200),                  // Primary contact email
            'telephone'   => varchar(50),                   // Primary phone number

            // Customer metadata
            'customer_number' => varchar(50)->unique(),     // Internal customer ID/code
            'customer_since'  => timestamp(),               // Registration date
            'customer_type'   => varchar(50)->default('registered'), // guest, registered, vip

            // Flags for efficient querying
            'is_person'       => boolean()->default(false),
            'is_organization' => boolean()->default(false),

            // Timestamps
            'created_at'  => timestamp(),
            'updated_at'  => timestamp(),
        ], 'sqlite');

        // =====================================================
        // PERSONS TABLE (Delegate for Person customers)
        // =====================================================
        // @see https://schema.org/Person
        $this->persons = new Table('persons', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'customer_id' => bigint()->not_null(),          // FK to customers

            // Person name (Schema.org)
            'given_name'  => varchar(100),                  // First name
            'family_name' => varchar(100),                  // Last name
            'additional_name' => varchar(100),              // Middle name
            'honorific_prefix' => varchar(20),              // Mr., Mrs., Dr., etc.
            'honorific_suffix' => varchar(20),              // Jr., III, PhD, etc.

            // Personal identifiers
            'tax_id'      => varchar(50),                   // Personal tax ID (SSN, fiscal code, etc.)
            'birth_date'  => varchar(10),                   // YYYY-MM-DD format

            // Gender (Schema.org)
            'gender'      => varchar(20),                   // Male, Female, Other
        ], 'sqlite');

        // =====================================================
        // ORGANIZATIONS TABLE (Delegate for Organization customers)
        // =====================================================
        // @see https://schema.org/Organization
        $this->organizations = new Table('organizations', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'customer_id' => bigint()->not_null(),          // FK to customers

            // Organization name
            'legal_name'  => varchar(255),                  // Official registered name
            'trading_name' => varchar(255),                 // DBA / trading as name

            // Tax/Legal identifiers
            'vat_id'      => varchar(50),                   // VAT number (EU)
            'tax_id'      => varchar(50),                   // Tax ID / EIN
            'duns_number' => varchar(20),                   // D-U-N-S number
            'lei_code'    => varchar(20),                   // Legal Entity Identifier

            // Organization details
            'founding_date' => varchar(10),                 // YYYY-MM-DD format
            'number_of_employees' => integer(),

            // Contact person at organization
            'contact_name'  => varchar(200),
            'contact_email' => varchar(200),
            'contact_phone' => varchar(50),
        ], 'sqlite');

        // =====================================================
        // POSTAL_ADDRESSES TABLE
        // =====================================================
        // @see https://schema.org/PostalAddress
        $this->postal_addresses = new Table('postal_addresses', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'uuid'        => varchar(36)->not_null()->unique(),

            // Owner of this address (polymorphic - can belong to Customer or be standalone)
            'owner_type'  => varchar(50),                   // 'Customer', null for standalone
            'owner_id'    => bigint(),                      // FK to customers.id

            // Address label
            'address_name' => varchar(100),                 // "Home", "Office", "Warehouse", etc.

            // Schema.org PostalAddress properties
            'street_address' => text(),                     // Street number, name, apt/suite
            'address_locality' => varchar(100),             // City
            'address_region' => varchar(100),               // State/Province/Region
            'postal_code' => varchar(20),                   // ZIP/Postal code
            'address_country' => varchar(100),              // Country name or ISO code

            // Additional address details
            'post_office_box_number' => varchar(20),        // P.O. Box

            // Contact info for this address
            'contact_name' => varchar(200),                 // Recipient name
            'telephone'   => varchar(50),                   // Phone for this address

            // Address type flags
            'is_billing'  => boolean()->default(false),     // Can be used for billing
            'is_shipping' => boolean()->default(false),     // Can be used for shipping

            // Timestamps
            'created_at'  => timestamp(),
            'updated_at'  => timestamp(),
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

            // ===================================================
            // CUSTOMER RELATIONSHIP (Polymorphic: Person or Organization)
            // ===================================================
            // @see https://schema.org/Order.customer
            'customer_id'      => bigint(),                 // FK to customers (Person or Organization)

            // ===================================================
            // ADDRESS RELATIONSHIPS
            // ===================================================
            // @see https://schema.org/Order.billingAddress
            // @see https://schema.org/Order.orderDelivery (simplified to direct FK)
            'billing_address_id'  => bigint(),              // FK to postal_addresses
            'delivery_address_id' => bigint(),              // FK to postal_addresses

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

            // ===================================================
            // BUNDLE/PACK RELATIONSHIPS
            // ===================================================
            // For tracking items that are part of a product bundle/pack
            'parent_bundle_item_id' => bigint(),            // FK to parent OrderItem thing_id (if part of bundle)
            'is_bundle_component'  => boolean()->default(false), // True if this item is part of a bundle

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
     * Tables are ordered so that dependencies are created first:
     * 1. Independent tables (things, customers, postal_addresses)
     * 2. Delegate tables (products, persons, organizations)
     * 3. Tables with FKs to above (orders - needs customers and addresses)
     * 4. Child tables (order_items - needs orders)
     *
     * @return array<Table>
     */
    public function get_tables(): array
    {
        return [
            // Base tables
            $this->things,
            $this->customers,
            $this->postal_addresses,

            // Delegate tables
            $this->products,
            $this->persons,
            $this->organizations,

            // Order (depends on customers and addresses)
            $this->orders,

            // Order items (depends on orders)
            $this->order_items,
        ];
    }
}
