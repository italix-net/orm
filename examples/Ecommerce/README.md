# E-commerce Example

A comprehensive Schema.org-based e-commerce data model using Italix ORM's DelegatedTypes pattern.

## Overview

This example demonstrates how to build a flexible, Schema.org-compliant e-commerce system using:

- **DelegatedTypes** for polymorphic relationships (Products, Orders, Customers)
- **Schema.org** vocabulary for semantic data modeling
- **PrestaShop integration** for importing real e-commerce data

## Architecture

### Data Model

```
Thing (Base)
├── Product (physical products)
│   ├── ProductGroup (configurable products with variants)
│   ├── Virtual Products (downloadable files, services)
│   └── Product Packs/Bundles
├── Order
└── OrderItem
    └── Bundle components (items that are part of a pack)

Customer (Agent)
├── Person (individual customers)
└── Organization (business customers with VAT)

PostalAddress (for billing and delivery)
```

### Schema.org Compliance

The data model follows Schema.org types:
- [Thing](https://schema.org/Thing) - Base type
- [Product](https://schema.org/Product) / [ProductGroup](https://schema.org/ProductGroup)
- [Order](https://schema.org/Order) / [OrderItem](https://schema.org/OrderItem)
- [Person](https://schema.org/Person) / [Organization](https://schema.org/Organization)
- [PostalAddress](https://schema.org/PostalAddress)

## Product Types

### 1. Simple Products (Physical)

Standard physical products that are shipped to customers.

```php
$product = Thing::create_product([
    'name' => 'MacBook Pro 14"',
    'description' => 'Apple MacBook Pro with M3 chip',
], [
    'sku' => 'MBP-14-M3',
    'price' => 1999.00,
    'currency' => 'USD',
    'availability' => 'InStock',
    'inventory_level' => 50,
]);
```

### 2. Product Groups with Variants

Products with configurable options like size, color, material.

```php
// Create the parent ProductGroup
$tshirt = Thing::create_product_group([
    'name' => 'Classic Cotton T-Shirt',
], [
    'sku' => 'TSHIRT-001',
    'varies_by' => 'size, color',
]);

// Create variants
$redSmall = Thing::create_variant($tshirt, [
    'color' => 'Red',
    'size' => 'S',
], [
    'sku' => 'TSHIRT-001-RED-S',
    'price' => 29.99,
]);

$blueM = Thing::create_variant($tshirt, [
    'color' => 'Blue',
    'size' => 'M',
], [
    'sku' => 'TSHIRT-001-BLUE-M',
    'price' => 29.99,
]);
```

**Supported variant attributes:**
- `size`, `size_system`, `size_group` - Size variations
- `color` - Color variations
- `material` - Material type
- `pattern` - Pattern (stripes, solid, etc.)
- Custom attributes stored in JSON

### 3. Virtual Products (Downloadable)

Digital products like e-books, software, media files.

```php
$ebook = Thing::create_product([
    'name' => 'PHP Design Patterns E-Book',
], [
    'sku' => 'EBOOK-PHP-001',
    'price' => 29.99,
    'is_virtual' => true,
    'is_downloadable' => true,
    'download_url' => 'https://example.com/downloads/book.pdf',
    'download_limit' => 5,          // Max downloads allowed
    'download_expiry_days' => 365,  // Days until download expires
]);

// Check product type
$ebook->delegate()->is_virtual();       // true
$ebook->delegate()->is_downloadable();  // true
$ebook->delegate()->is_physical();      // false
$ebook->delegate()->product_type();     // "DownloadableProduct"
```

### 4. Service Products

Non-physical services like consultations, subscriptions.

```php
$service = Thing::create_product([
    'name' => 'PHP Code Review Session',
], [
    'sku' => 'SERVICE-REVIEW',
    'price' => 150.00,
    'is_virtual' => true,
    'is_service' => true,
    'service_duration' => '1 hour',
]);

$service->delegate()->is_service();     // true
$service->delegate()->product_type();   // "ServiceProduct"
```

### 5. Product Packs/Bundles

Collections of products sold together at a bundled price.

```php
// Create bundle as a ProductGroup
$bundle = Thing::create_product_group([
    'name' => 'PHP Developer Starter Kit',
], [
    'sku' => 'BUNDLE-PHP',
    'price' => 199.99,  // Bundle price
]);

// Create component products separately
$book = Thing::create_product(['name' => 'PHP Book'], ['sku' => 'BOOK-001', 'price' => 49.99]);
$course = Thing::create_product(['name' => 'Video Course'], ['sku' => 'COURSE-001', 'price' => 99.99]);
```

## Customer Model

### Person (Individual Customer)

```php
$person = Customer::create_person([
    'email' => 'john@example.com',
    'telephone' => '+1-555-1234',
    'customer_number' => 'CUST-001',
], [
    'given_name' => 'John',
    'family_name' => 'Doe',
    'gender' => 'Male',
    'birth_date' => '1990-05-15',
]);

$person->delegate()->display_name();  // "John Doe"
$person->delegate()->initials();      // "JD"
```

### Organization (Business Customer)

```php
$org = Customer::create_organization([
    'email' => 'billing@acme.com',
    'telephone' => '+1-555-5678',
], [
    'legal_name' => 'ACME Corporation',
    'trading_name' => 'ACME',
    'vat_id' => 'IT12345678901',
    'contact_name' => 'Jane Smith',
    'contact_email' => 'jane@acme.com',
]);

$org->delegate()->has_vat();      // true
$org->delegate()->vat_country();  // "IT"
```

### Auto-Detection (based on VAT)

```php
// Creates Organization if VAT present, Person otherwise
$customer = Customer::create_auto(
    $customerData,
    $personData,
    $orgData,
    $vatId  // If set, creates Organization
);
```

## Orders

### Creating Orders

```php
// Create addresses
$billingAddr = PostalAddress::make_billing_address([
    'street_address' => '123 Main St',
    'address_locality' => 'New York',
    'postal_code' => '10001',
    'address_country' => 'US',
], $customer);

$shippingAddr = PostalAddress::make_shipping_address([
    'street_address' => '456 Delivery Ave',
    'address_locality' => 'Brooklyn',
    'postal_code' => '11201',
], $customer);

// Create order with customer and addresses
$order = Thing::create_order_for_customer(
    $customer,
    $billingAddr,
    $shippingAddr,
    ['name' => 'Order #12345'],
    [
        'order_number' => 'ORD-12345',
        'order_status' => 'OrderProcessing',
        'subtotal' => 100.00,
        'tax' => 8.00,
        'shipping_cost' => 5.99,
        'total_price' => 113.99,
    ]
);
```

### Order Items

```php
// Simple order item
$item = Thing::create_order_item($order, $product, 2, [], [
    'unit_price' => 25.00,
]);

// Bundle order item with components
$bundleItem = Thing::create_order_item($order, $bundle, 1, [], [
    'unit_price' => 199.99,
    'is_bundle_component' => false,
]);

// Bundle component (part of the bundle)
$componentItem = Thing::create_order_item($order, $book, 1, [
    'name' => '[Bundle: PHP Starter Kit] PHP Book',
], [
    'unit_price' => 0,  // Price included in bundle
    'parent_bundle_item_id' => $bundleItem['id'],
    'is_bundle_component' => true,
]);

// Query bundle components
$bundleItem->delegate()->bundle_components();  // Returns component items
$componentItem->delegate()->parent_bundle_item();  // Returns parent bundle item
```

### Customer Order History

```php
$customer->order_count();            // 5
$customer->total_spent();            // 549.99
$customer->average_order_value();    // 109.99
$customer->first_order_date();       // "2024-01-15"
$customer->last_order_date();        // "2024-06-20"
$customer->days_since_last_order();  // 45
$customer->orders();                 // Array of Order Things
```

## PrestaShop Integration

### Configuration

Create `PrestaShop/config.php`:

```php
return [
    // PrestaShop API
    'prestashop_url' => 'https://your-shop.com',
    'prestashop_api_key' => 'YOUR_API_KEY',

    // Local database
    'db_type' => 'sqlite',  // or 'mysql', 'postgresql'
    'db_sqlite_path' => __DIR__ . '/ecommerce.db',

    // Import settings
    'min_order_id' => 1,
    'orders_limit' => 100,
    'default_currency' => 'EUR',

    // Order status mapping (PrestaShop state ID => Schema.org status)
    'order_status_map' => [
        1 => 'OrderPaymentDue',
        2 => 'OrderProcessing',
        3 => 'OrderProcessing',
        4 => 'OrderInTransit',
        5 => 'OrderDelivered',
        6 => 'OrderCancelled',
        7 => 'OrderReturned',
    ],
];
```

### Running the Import

```bash
# Import orders
php examples/Ecommerce/PrestaShop/ImportOrders.php

# With options
php examples/Ecommerce/PrestaShop/ImportOrders.php --min-id=1000 --limit=50 --debug
```

### Supported PrestaShop Features

| PrestaShop Feature | Support |
|-------------------|---------|
| Simple products | Full |
| Products with combinations (variants) | Full |
| Product packs/bundles | Full |
| Virtual products (downloadable) | Full |
| Service products | Full |
| Customer Person/Organization | Full (VAT-based detection) |
| Multiple addresses | Full |
| Order import | Full |
| Order item variants | Full |
| Bundle component tracking | Full |

### How Product Types Are Detected

The importer automatically detects PrestaShop product types:

```php
// PrestaShop client provides type info
$typeInfo = $client->getProductTypeInfo($productId);
// Returns:
// [
//     'type' => 'simple'|'combinations'|'pack'|'downloadable'|'service',
//     'is_virtual' => bool,
//     'is_pack' => bool,
//     'has_combinations' => bool,
//     'is_downloadable' => bool,
//     'download_info' => [...] or null,
// ]
```

## Running Tests

```bash
php examples/Ecommerce/ecommerce_test.php
```

Tests cover:
- Product creation (simple, groups, variants)
- Virtual products (downloadable, service)
- Customer creation (Person, Organization)
- Address management
- Order creation with customer/address relations
- Order items and bundle components
- Order history tracking
- Schema.org JSON-LD generation

## File Structure

```
examples/Ecommerce/
├── Schema.php              # Database schema definition
├── Models/
│   ├── Thing.php          # Base model with DelegatedTypes
│   ├── Product.php        # Product delegate (including virtual)
│   ├── Order.php          # Order delegate
│   ├── OrderItem.php      # OrderItem delegate (with bundle support)
│   ├── Customer.php       # Customer base (Person/Organization)
│   ├── Person.php         # Person delegate
│   ├── Organization.php   # Organization delegate
│   └── PostalAddress.php  # Address model
├── PrestaShop/
│   ├── PrestaShopClient.php   # API client
│   ├── ImportOrders.php       # Order importer
│   ├── config.example.php     # Configuration template
│   └── config.php             # Your configuration (gitignored)
├── ecommerce_test.php     # Test suite
└── README.md              # This documentation
```

## Schema.org JSON-LD Output

All models generate Schema.org-compliant JSON-LD:

```php
$product->delegate()->to_schema_org($product);
// {
//     "@context": "https://schema.org",
//     "@type": "Product",
//     "name": "MacBook Pro",
//     "sku": "MBP-14",
//     "offers": {
//         "@type": "Offer",
//         "price": 1999.00,
//         "priceCurrency": "USD",
//         "availability": "https://schema.org/InStock"
//     }
// }

// Virtual products include additionalType
$ebook->delegate()->to_schema_org($ebook);
// {
//     "@type": "Product",
//     "additionalType": "https://schema.org/DigitalDocument",
//     ...
// }

// Services use Service type
$service->delegate()->to_schema_org($service);
// {
//     "@type": "Service",
//     ...
// }
```
