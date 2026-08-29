<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Product Model (Delegate)
 *
 * Represents a product or ProductGroup in the e-commerce system.
 * Delegated from Thing for Product-specific attributes.
 *
 * @see https://schema.org/Product
 * @see https://schema.org/ProductGroup
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Operators\eq;

class Product extends ActiveRow
{
    use Persistable;

    // =========================================
    // PRICE FORMATTING
    // =========================================

    /**
     * Get formatted price with currency symbol
     *
     * @return string
     */
    public function formatted_price(): string
    {
        $price = $this['price'] ?? 0;
        $currency = $this['currency'] ?? 'USD';

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format((float) $price, 2);
    }

    /**
     * Get price as float
     *
     * @return float
     */
    public function price(): float
    {
        return (float) ($this['price'] ?? 0);
    }

    // =========================================
    // AVAILABILITY
    // =========================================

    /**
     * Check if product is in stock
     *
     * @return bool
     */
    public function is_in_stock(): bool
    {
        return $this['availability'] === 'InStock' && ($this['inventory_level'] ?? 0) > 0;
    }

    /**
     * Check if product is available for pre-order
     *
     * @return bool
     */
    public function is_preorder(): bool
    {
        return $this['availability'] === 'PreOrder';
    }

    /**
     * Get availability status text
     *
     * @return string
     */
    public function availability_text(): string
    {
        $status = $this['availability'] ?? 'Unknown';

        $texts = [
            'InStock' => 'In Stock',
            'OutOfStock' => 'Out of Stock',
            'PreOrder' => 'Pre-Order',
            'Discontinued' => 'Discontinued',
            'BackOrder' => 'Back Order',
            'LimitedAvailability' => 'Limited Availability',
        ];

        return $texts[$status] ?? $status;
    }

    // =========================================
    // PRODUCT GROUP / VARIANTS
    // =========================================

    /**
     * Check if this is a ProductGroup
     *
     * @return bool
     */
    public function is_group(): bool
    {
        return (bool) ($this['is_group'] ?? false);
    }

    /**
     * Check if this is a variant of a ProductGroup
     *
     * @return bool
     */
    public function is_variant(): bool
    {
        return $this['variant_of_id'] !== null;
    }

    /**
     * Get the parent ProductGroup (if this is a variant)
     *
     * @return Thing|null
     */
    public function parent_group(): ?Thing
    {
        if (!$this->is_variant()) {
            return null;
        }

        return Thing::find($this['variant_of_id']);
    }

    /**
     * Get all variants of this ProductGroup
     *
     * @return array<Thing>
     */
    public function variants(): array
    {
        if (!$this->is_group()) {
            return [];
        }

        $table = self::get_table();
        $variants = self::find_all([
            'where' => eq($table->variant_of_id, $this['thing_id']),
        ]);

        // Load the full Thing with delegates for each variant
        $things = [];
        foreach ($variants as $variant) {
            $thing = Thing::find_with_delegate($variant['thing_id']);
            if ($thing) {
                $things[] = $thing;
            }
        }

        return $things;
    }

    /**
     * Get what this product varies by (e.g., "color", "size")
     *
     * @return array
     */
    public function varies_by(): array
    {
        $varies = $this['varies_by'] ?? '';
        if (empty($varies)) {
            return [];
        }
        return array_map('trim', explode(',', $varies));
    }

    /**
     * Find variants matching specific attribute values
     *
     * @param array $attributes Key-value pairs of attributes to match
     * @return array<Thing>
     */
    public function find_variants_by(array $attributes): array
    {
        $variants = $this->variants();

        return array_filter($variants, function (Thing $variant) use ($attributes) {
            $product = $variant->delegate();
            if (!$product instanceof self) {
                return false;
            }

            foreach ($attributes as $key => $value) {
                if ($product->get_variant_attribute($key) !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Get unique values for a variant attribute across all variants
     *
     * @param string $attribute Attribute name (size, color, etc.)
     * @return array
     */
    public function get_variant_options(string $attribute): array
    {
        if (!$this->is_group()) {
            return [];
        }

        $values = [];
        foreach ($this->variants() as $variant) {
            $product = $variant->delegate();
            if ($product instanceof self) {
                $value = $product->get_variant_attribute($attribute);
                if ($value !== null && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Get sibling variants (other variants of the same parent group)
     *
     * @return array<Thing>
     */
    public function sibling_variants(): array
    {
        if (!$this->is_variant()) {
            return [];
        }

        $parent = $this->parent_group();
        if (!$parent) {
            return [];
        }

        $parent_product = $parent->delegate();
        if (!$parent_product instanceof self) {
            return [];
        }

        $thing_id = $this['thing_id'];
        return array_filter($parent_product->variants(), function (Thing $variant) use ($thing_id) {
            return $variant['id'] !== $thing_id;
        });
    }

    // =========================================
    // BUNDLE/PACK SUPPORT
    // =========================================

    /**
     * Check if this is a product bundle/pack
     *
     * A bundle is a collection of products sold together at a bundled price.
     * Different from a ProductGroup with variants (size/color variations).
     *
     * @return bool
     */
    public function is_bundle(): bool
    {
        return (bool) ($this['is_bundle'] ?? false);
    }

    /**
     * Get bundle component items
     *
     * Returns array of components with product_id and quantity.
     *
     * @return array Array of ['product_id' => id, 'quantity' => qty, ...]
     */
    public function bundle_items(): array
    {
        $json = $this['bundle_items'] ?? '';
        if (empty($json)) {
            return [];
        }

        $items = json_decode($json, true);
        return is_array($items) ? $items : [];
    }

    /**
     * Set bundle component items
     *
     * @param array $items Array of ['product_id' => id, 'quantity' => qty, ...]
     * @return void
     */
    public function set_bundle_items(array $items): void
    {
        $this['bundle_items'] = json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Add a product to this bundle
     *
     * @param int $productThingId The Thing ID of the product to add
     * @param int $quantity Quantity of this product in the bundle
     * @param array $options Additional options (discount, optional, etc.)
     * @return void
     */
    public function add_bundle_item(int $product_thing_id, int $quantity = 1, array $options = []): void
    {
        $items = $this->bundle_items();
        $items[] = array_merge([
            'product_id' => $product_thing_id,
            'quantity' => $quantity,
        ], $options);
        $this->set_bundle_items($items);
    }

    /**
     * Get bundle component products as Things
     *
     * @return array<Thing>
     */
    public function bundle_products(): array
    {
        if (!$this->is_bundle()) {
            return [];
        }

        $products = [];
        foreach ($this->bundle_items() as $item) {
            $product_id = $item['product_id'] ?? null;
            if ($product_id) {
                $thing = Thing::find_with_delegate($product_id);
                if ($thing) {
                    $products[] = $thing;
                }
            }
        }

        return $products;
    }

    /**
     * Calculate bundle value (sum of component prices)
     *
     * @return float
     */
    public function bundle_value(): float
    {
        if (!$this->is_bundle()) {
            return 0;
        }

        $total = 0;
        foreach ($this->bundle_items() as $item) {
            $product_id = $item['product_id'] ?? null;
            $quantity = $item['quantity'] ?? 1;

            if ($product_id) {
                $thing = Thing::find_with_delegate($product_id);
                if ($thing) {
                    $delegate = $thing->delegate();
                    if ($delegate instanceof self) {
                        $total += $delegate->price() * $quantity;
                    }
                }
            }
        }

        return $total;
    }

    /**
     * Calculate bundle savings (value minus bundle price)
     *
     * @return float
     */
    public function bundle_savings(): float
    {
        return $this->bundle_value() - $this->price();
    }

    /**
     * Get bundle savings as percentage
     *
     * @return float
     */
    public function bundle_savings_percent(): float
    {
        $value = $this->bundle_value();
        if ($value <= 0) {
            return 0;
        }
        return round(($this->bundle_savings() / $value) * 100, 1);
    }

    // =========================================
    // VARIANT ATTRIBUTES
    // =========================================

    // Standard Schema.org variant properties
    private const STANDARD_VARIANT_ATTRIBUTES = ['size', 'size_system', 'size_group', 'color', 'material', 'pattern'];

    /**
     * Get size
     *
     * @return string|null
     */
    public function size(): ?string
    {
        return $this['size'];
    }

    /**
     * Get size system (US, EU, UK, etc.)
     *
     * @return string|null
     */
    public function size_system(): ?string
    {
        return $this['size_system'];
    }

    /**
     * Get size group (regular, petite, plus, etc.)
     *
     * @return string|null
     */
    public function size_group(): ?string
    {
        return $this['size_group'];
    }

    /**
     * Get color
     *
     * @return string|null
     */
    public function color(): ?string
    {
        return $this['color'];
    }

    /**
     * Get material
     *
     * @return string|null
     */
    public function material(): ?string
    {
        return $this['material'];
    }

    /**
     * Get pattern
     *
     * @return string|null
     */
    public function pattern(): ?string
    {
        return $this['pattern'];
    }

    /**
     * Get additional variant attributes (from JSON)
     *
     * @return array
     */
    public function additional_attributes(): array
    {
        $json = $this['variant_attributes'] ?? '';
        if (empty($json)) {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Get a specific additional attribute
     *
     * @param string $key
     * @return mixed|null
     */
    public function additional_attribute(string $key): mixed
    {
        return $this->additional_attributes()[$key] ?? null;
    }

    /**
     * Set additional variant attributes (stores as JSON)
     *
     * @param array $attributes
     * @return void
     */
    public function set_additional_attributes(array $attributes): void
    {
        $this['variant_attributes'] = json_encode($attributes, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Add or update a single additional attribute
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set_additional_attribute(string $key, mixed $value): void
    {
        $attributes = $this->additional_attributes();
        $attributes[$key] = $value;
        $this->set_additional_attributes($attributes);
    }

    /**
     * Get any variant attribute (standard or additional)
     *
     * @param string $attribute
     * @return mixed|null
     */
    public function get_variant_attribute(string $attribute): mixed
    {
        // Check standard attributes first
        if (in_array($attribute, self::STANDARD_VARIANT_ATTRIBUTES, true)) {
            return $this[$attribute];
        }

        // Check additional attributes
        return $this->additional_attribute($attribute);
    }

    /**
     * Set any variant attribute (standard or additional)
     *
     * @param string $attribute
     * @param mixed $value
     * @return void
     */
    public function set_variant_attribute(string $attribute, mixed $value): void
    {
        if (in_array($attribute, self::STANDARD_VARIANT_ATTRIBUTES, true)) {
            $this[$attribute] = $value;
        } else {
            $this->set_additional_attribute($attribute, $value);
        }
    }

    /**
     * Get all variant attributes as a flat array
     *
     * @return array
     */
    public function all_variant_attributes(): array
    {
        $attrs = [];

        // Collect standard attributes
        foreach (self::STANDARD_VARIANT_ATTRIBUTES as $attr) {
            if ($this[$attr] !== null) {
                $attrs[$attr] = $this[$attr];
            }
        }

        // Merge additional attributes
        return array_merge($attrs, $this->additional_attributes());
    }

    /**
     * Get the variant description string (e.g., "Red, Size M")
     *
     * @return string
     */
    public function variant_description(): string
    {
        $parts = [];

        // Use varies_by order from parent if available
        $parent = $this->parent_group();
        if ($parent) {
            $parent_product = $parent->delegate();
            if ($parent_product instanceof self) {
                foreach ($parent_product->varies_by() as $attr) {
                    $value = $this->get_variant_attribute($attr);
                    if ($value !== null) {
                        $parts[] = $value;
                    }
                }
                return implode(', ', $parts);
            }
        }

        // Fallback: use all set attributes
        $attrs = $this->all_variant_attributes();
        foreach ($attrs as $value) {
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    // =========================================
    // VIRTUAL PRODUCT SUPPORT
    // =========================================

    /**
     * Check if this is a virtual product (not physical)
     *
     * Virtual products include downloadable files and services.
     * They are not shipped to customers.
     *
     * @return bool
     */
    public function is_virtual(): bool
    {
        return (bool) ($this['is_virtual'] ?? false);
    }

    /**
     * Check if this is a downloadable product
     *
     * @return bool
     */
    public function is_downloadable(): bool
    {
        return (bool) ($this['is_downloadable'] ?? false);
    }

    /**
     * Check if this is a service product
     *
     * @return bool
     */
    public function is_service(): bool
    {
        return (bool) ($this['is_service'] ?? false);
    }

    /**
     * Check if this is a physical product (requires shipping)
     *
     * @return bool
     */
    public function is_physical(): bool
    {
        return !$this->is_virtual();
    }

    /**
     * Get download URL (for downloadable products)
     *
     * @return string|null
     */
    public function download_url(): ?string
    {
        return $this['download_url'];
    }

    /**
     * Get download limit (max downloads allowed)
     *
     * @return int|null
     */
    public function download_limit(): ?int
    {
        return $this['download_limit'] !== null ? (int) $this['download_limit'] : null;
    }

    /**
     * Get download expiry days
     *
     * @return int|null
     */
    public function download_expiry_days(): ?int
    {
        return $this['download_expiry_days'] !== null ? (int) $this['download_expiry_days'] : null;
    }

    /**
     * Get service duration (for service products)
     *
     * @return string|null
     */
    public function service_duration(): ?string
    {
        return $this['service_duration'];
    }

    /**
     * Get product type description
     *
     * @return string
     */
    public function product_type(): string
    {
        if ($this->is_group()) {
            return 'ProductGroup';
        }
        if ($this->is_variant()) {
            return 'ProductVariant';
        }
        if ($this->is_downloadable()) {
            return 'DownloadableProduct';
        }
        if ($this->is_service()) {
            return 'ServiceProduct';
        }
        if ($this->is_virtual()) {
            return 'VirtualProduct';
        }
        return 'Product';
    }

    // =========================================
    // IDENTIFIERS
    // =========================================

    /**
     * Get SKU
     *
     * @return string|null
     */
    public function sku(): ?string
    {
        return $this['sku'];
    }

    /**
     * Get GTIN (barcode)
     *
     * @return string|null
     */
    public function gtin(): ?string
    {
        return $this['gtin'];
    }

    /**
     * Get brand
     *
     * @return string|null
     */
    public function brand(): ?string
    {
        return $this['brand'];
    }

    // =========================================
    // SCHEMA.ORG JSON-LD
    // =========================================

    /**
     * Generate Schema.org JSON-LD for this product
     *
     * @param Thing $thing The parent Thing
     * @return array
     */
    public function to_schema_org(Thing $thing): array
    {
        // Determine @type based on product type
        $type = 'Product';
        if ($this->is_group()) {
            $type = 'ProductGroup';
        } elseif ($this->is_service()) {
            $type = 'Service';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $thing['name'],
            'description' => $thing['description'],
        ];

        // Add additionalType for virtual/downloadable products
        if ($this->is_downloadable()) {
            $schema['additionalType'] = 'https://schema.org/DigitalDocument';
        } elseif ($this->is_virtual() && !$this->is_service()) {
            $schema['additionalType'] = 'https://schema.org/Intangible';
        }

        if ($this['sku']) {
            $schema['sku'] = $this['sku'];
        }

        if ($this['gtin']) {
            $schema['gtin'] = $this['gtin'];
        }

        if ($this['brand']) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $this['brand'],
            ];
        }

        if ($thing['image']) {
            $schema['image'] = $thing['image'];
        }

        if ($thing['url']) {
            $schema['url'] = $thing['url'];
        }

        // Add offer (price) if not a group
        if (!$this->is_group() && $this['price']) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => $this['price'],
                'priceCurrency' => $this['currency'] ?? 'USD',
                'availability' => 'https://schema.org/' . ($this['availability'] ?? 'InStock'),
            ];
        }

        // ProductGroup-specific
        if ($this->is_group() && $this['varies_by']) {
            $schema['variesBy'] = $this->varies_by();

            // Include hasVariant for variants
            $variants = $this->variants();
            if (!empty($variants)) {
                $schema['hasVariant'] = array_map(function (Thing $variant) {
                    $product = $variant->delegate();
                    if ($product instanceof self) {
                        return $product->to_schema_org($variant);
                    }
                    return null;
                }, $variants);
                $schema['hasVariant'] = array_filter($schema['hasVariant']);
            }
        }

        // Variant-specific properties
        if ($this->is_variant()) {
            // Add link to parent group
            if ($this['variant_of_id']) {
                $schema['isVariantOf'] = [
                    '@type' => 'ProductGroup',
                    '@id' => '#product-group-' . $this['variant_of_id'],
                ];
            }

            // Standard variant attributes
            if ($this['size']) {
                $schema['size'] = $this['size'];
            }
            if ($this['color']) {
                $schema['color'] = $this['color'];
            }
            if ($this['material']) {
                $schema['material'] = $this['material'];
            }
            if ($this['pattern']) {
                $schema['pattern'] = $this['pattern'];
            }

            // Additional properties using Schema.org PropertyValue
            $additional = $this->additional_attributes();
            if (!empty($additional)) {
                $schema['additionalProperty'] = [];
                foreach ($additional as $name => $value) {
                    $schema['additionalProperty'][] = [
                        '@type' => 'PropertyValue',
                        'name' => $name,
                        'value' => $value,
                    ];
                }
            }
        }

        return $schema;
    }
}
