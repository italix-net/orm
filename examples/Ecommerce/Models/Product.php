<?php
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
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $this->is_group() ? 'ProductGroup' : 'Product',
            'name' => $thing['name'],
            'description' => $thing['description'],
        ];

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
        }

        return $schema;
    }
}
