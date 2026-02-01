# PrestaShop Order Importer

Import orders from PrestaShop 1.7+ into the local e-commerce database using the Schema.org-based data model.

## Features

- Fetches orders via PrestaShop WebService REST API
- Configurable minimum order ID and fetch limit
- Skips already imported orders (idempotent)
- Handles product bundles/packs
- Maps PrestaShop order states to Schema.org OrderStatus
- Caches products to avoid duplicate API calls
- Supports SQLite, MySQL, and PostgreSQL

## Setup

### 1. Enable PrestaShop WebService

1. Go to **Back Office > Advanced Parameters > Webservice**
2. Enable the webservice
3. Click "Add new webservice key"
4. Set permissions (GET access) for:
   - `orders`
   - `order_details`
   - `order_states`
   - `products`
   - `customers`
   - `addresses`
5. Copy the generated API key

### 2. Configure the Importer

```bash
cd examples/Ecommerce/PrestaShop
cp config.example.php config.php
```

Edit `config.php` with your settings:

```php
return [
    'prestashop_url' => 'https://your-fashion-store.com',
    'prestashop_api_key' => 'YOUR_API_KEY_HERE',

    'min_order_id' => 1,      // Start from order ID
    'orders_limit' => 100,     // Max orders per run

    // ... other settings
];
```

### 3. Run the Import

```bash
# Basic usage (uses config settings)
php ImportOrders.php

# Override settings via command line
php ImportOrders.php --min-id=5000 --limit=50

# Enable debug output
php ImportOrders.php --debug

# Show help
php ImportOrders.php --help
```

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `prestashop_url` | PrestaShop store URL | Required |
| `prestashop_api_key` | WebService API key | Required |
| `min_order_id` | Minimum order ID to fetch | `1` |
| `orders_limit` | Maximum orders per run | `100` |
| `default_currency` | ISO 4217 currency code | `EUR` |
| `db_type` | Database type (`sqlite`, `mysql`, `postgresql`) | `sqlite` |
| `db_sqlite_path` | Path to SQLite database file | `./ecommerce.db` |
| `order_status_map` | PrestaShop state ID → Schema.org status | See config |
| `debug` | Enable verbose output | `false` |
| `log_file` | Log file path | `./import.log` |

## Order Status Mapping

PrestaShop order states are mapped to Schema.org OrderStatus:

| PrestaShop State | Schema.org Status |
|------------------|-------------------|
| Awaiting payment | `OrderPaymentDue` |
| Payment accepted | `OrderProcessing` |
| Processing | `OrderProcessing` |
| Shipped | `OrderInTransit` |
| Delivered | `OrderDelivered` |
| Canceled | `OrderCancelled` |
| Refunded | `OrderReturned` |
| Payment error | `OrderProblem` |

Customize the mapping in `config.php` under `order_status_map`.

## Product Bundles

The importer handles PrestaShop product bundles/packs:

1. **Bundle products** are marked as ProductGroups (`is_group = true`)
2. **Bundle components** are imported as separate OrderItems with:
   - Name prefixed with `[Bundle: Parent Name]`
   - Unit price set to `0` (price is on the bundle)
   - Order item number format: `BUNDLE-{packId}-{productId}`

## Duplicate Detection

The importer skips orders that already exist in the local database, matching by order reference number. This makes it safe to run multiple times.

## API Documentation

- [PrestaShop WebService API](https://devdocs.prestashop-project.org/1.7/webservice/)
- [Creating API Access](https://devdocs.prestashop-project.org/8/webservice/tutorials/creating-access/)
- [Order Resource](https://devdocs.prestashop-project.org/1.7/webservice/resources/orders/)

## Troubleshooting

### "Failed to connect to PrestaShop API"

1. Check that the API URL is correct
2. Verify the API key has proper permissions
3. Ensure HTTPS is working on your PrestaShop store
4. Run with `--debug` to see detailed error messages

### "HTTP 401 Unauthorized"

The API key is invalid or doesn't have the required permissions.

### Products not found

Make sure the API key has GET permission for the `products` resource.

### Bundle items not importing

PrestaShop's bundle/pack API has [known limitations](https://github.com/PrestaShop/PrestaShop/issues/16785). Some bundle configurations may not be fully accessible via the API.
