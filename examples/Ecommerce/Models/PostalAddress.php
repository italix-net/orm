<?php
/**
 * PostalAddress Model
 *
 * Represents a physical mailing address.
 * Can be associated with a Customer or used standalone for orders.
 *
 * @see https://schema.org/PostalAddress
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

class PostalAddress extends ActiveRow
{
    use Persistable, HasTimestamps;

    // =========================================
    // FACTORY METHODS
    // =========================================

    /**
     * Create a new postal address
     *
     * @param array $data Address data
     * @param Customer|null $owner Optional owner (Customer)
     * @return static
     */
    public static function make_address(array $data, ?Customer $owner = null): static
    {
        $data['uuid'] = $data['uuid'] ?? self::generate_uuid();

        if ($owner) {
            $data['owner_type'] = 'Customer';
            $data['owner_id'] = $owner['id'];
        }

        return static::create($data);
    }

    /**
     * Create a billing address for a customer
     *
     * @param Customer $customer
     * @param array $data Address data
     * @return static
     */
    public static function make_billing_address(Customer $customer, array $data): static
    {
        $data['is_billing'] = true;
        $data['is_shipping'] = $data['is_shipping'] ?? false;
        return static::make_address($data, $customer);
    }

    /**
     * Create a shipping address for a customer
     *
     * @param Customer $customer
     * @param array $data Address data
     * @return static
     */
    public static function make_shipping_address(Customer $customer, array $data): static
    {
        $data['is_billing'] = $data['is_billing'] ?? false;
        $data['is_shipping'] = true;
        return static::make_address($data, $customer);
    }

    // =========================================
    // QUERY HELPERS
    // =========================================

    /**
     * Find addresses by owner (Customer)
     *
     * @param Customer $owner
     * @return array<static>
     */
    public static function find_by_owner(Customer $owner): array
    {
        $table = static::get_table();
        return static::find_all([
            'where' => and_(
                eq($table->owner_type, 'Customer'),
                eq($table->owner_id, $owner['id'])
            ),
        ]);
    }

    /**
     * Find address by UUID
     *
     * @param string $uuid
     * @return static|null
     */
    public static function find_by_uuid(string $uuid): ?static
    {
        $table = static::get_table();
        return static::find_one([
            'where' => eq($table->uuid, $uuid),
        ]);
    }

    // =========================================
    // ADDRESS PROPERTIES
    // =========================================

    /**
     * Get address name/label
     *
     * @return string|null
     */
    public function address_name(): ?string
    {
        return $this['address_name'];
    }

    /**
     * Get street address
     *
     * @return string|null
     */
    public function street_address(): ?string
    {
        return $this['street_address'];
    }

    /**
     * Get city (address locality)
     *
     * @return string|null
     */
    public function city(): ?string
    {
        return $this['address_locality'];
    }

    /**
     * Get address locality (city) - Schema.org naming
     *
     * @return string|null
     */
    public function address_locality(): ?string
    {
        return $this['address_locality'];
    }

    /**
     * Get state/province/region
     *
     * @return string|null
     */
    public function region(): ?string
    {
        return $this['address_region'];
    }

    /**
     * Get address region - Schema.org naming
     *
     * @return string|null
     */
    public function address_region(): ?string
    {
        return $this['address_region'];
    }

    /**
     * Get postal/ZIP code
     *
     * @return string|null
     */
    public function postal_code(): ?string
    {
        return $this['postal_code'];
    }

    /**
     * Get country
     *
     * @return string|null
     */
    public function country(): ?string
    {
        return $this['address_country'];
    }

    /**
     * Get address country - Schema.org naming
     *
     * @return string|null
     */
    public function address_country(): ?string
    {
        return $this['address_country'];
    }

    /**
     * Get P.O. Box number
     *
     * @return string|null
     */
    public function po_box(): ?string
    {
        return $this['post_office_box_number'];
    }

    /**
     * Get contact name for this address
     *
     * @return string|null
     */
    public function contact_name(): ?string
    {
        return $this['contact_name'];
    }

    /**
     * Get phone for this address
     *
     * @return string|null
     */
    public function telephone(): ?string
    {
        return $this['telephone'];
    }

    // =========================================
    // TYPE FLAGS
    // =========================================

    /**
     * Check if this is a billing address
     *
     * @return bool
     */
    public function is_billing(): bool
    {
        return (bool) ($this['is_billing'] ?? false);
    }

    /**
     * Check if this is a shipping address
     *
     * @return bool
     */
    public function is_shipping(): bool
    {
        return (bool) ($this['is_shipping'] ?? false);
    }

    // =========================================
    // OWNER
    // =========================================

    /**
     * Get the owner (Customer) of this address
     *
     * @return Customer|null
     */
    public function owner(): ?Customer
    {
        if ($this['owner_type'] !== 'Customer' || !$this['owner_id']) {
            return null;
        }

        return Customer::find_with_delegate((int) $this['owner_id']);
    }

    // =========================================
    // FORMATTING
    // =========================================

    /**
     * Format address as a single line
     *
     * @param string $separator Separator between parts
     * @return string
     */
    public function one_line(string $separator = ', '): string
    {
        $parts = array_filter([
            $this['street_address'],
            $this['address_locality'],
            $this['address_region'],
            $this['postal_code'],
            $this['address_country'],
        ]);

        return implode($separator, $parts);
    }

    /**
     * Format address as multiple lines
     *
     * @return string
     */
    public function formatted(): string
    {
        $lines = [];

        if ($this['contact_name']) {
            $lines[] = $this['contact_name'];
        }

        if ($this['street_address']) {
            $lines[] = $this['street_address'];
        }

        if ($this['post_office_box_number']) {
            $lines[] = 'P.O. Box ' . $this['post_office_box_number'];
        }

        // City, State ZIP
        $cityLine = array_filter([
            $this['address_locality'],
            $this['address_region'],
            $this['postal_code'],
        ]);
        if (!empty($cityLine)) {
            $lines[] = implode(' ', $cityLine);
        }

        if ($this['address_country']) {
            $lines[] = $this['address_country'];
        }

        return implode("\n", $lines);
    }

    /**
     * Get a short display version of the address
     *
     * @return string
     */
    public function short(): string
    {
        $parts = array_filter([
            $this['address_locality'],
            $this['address_country'],
        ]);

        return implode(', ', $parts) ?: 'Unknown Address';
    }

    // =========================================
    // SCHEMA.ORG JSON-LD
    // =========================================

    /**
     * Generate Schema.org JSON-LD for this address
     *
     * @return array
     */
    public function to_schema_org(): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'PostalAddress',
        ];

        if ($this['street_address']) {
            $schema['streetAddress'] = $this['street_address'];
        }

        if ($this['address_locality']) {
            $schema['addressLocality'] = $this['address_locality'];
        }

        if ($this['address_region']) {
            $schema['addressRegion'] = $this['address_region'];
        }

        if ($this['postal_code']) {
            $schema['postalCode'] = $this['postal_code'];
        }

        if ($this['address_country']) {
            $schema['addressCountry'] = $this['address_country'];
        }

        if ($this['post_office_box_number']) {
            $schema['postOfficeBoxNumber'] = $this['post_office_box_number'];
        }

        return $schema;
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
