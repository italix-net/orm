<?php
/**
 * PostalAddress - Auxiliary table for Place addresses
 *
 * Not a Thing; linked one-to-one with a Place.
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class PostalAddress extends ActiveRow
{
    use Persistable;

    public function place_id(): int
    {
        return (int) $this['place_id'];
    }

    public function street_address(): ?string
    {
        return $this['street_address'];
    }

    public function address_locality(): ?string
    {
        return $this['address_locality'];
    }

    public function address_region(): ?string
    {
        return $this['address_region'];
    }

    public function postal_code(): ?string
    {
        return $this['postal_code'];
    }

    public function address_country(): ?string
    {
        return $this['address_country'];
    }

    /**
     * Get a formatted one-line address
     */
    public function formatted(): string
    {
        $parts = array_filter([
            $this['street_address'],
            $this['address_locality'],
            $this['address_region'],
            $this['postal_code'],
            $this['address_country'],
        ]);
        return implode(', ', $parts);
    }

    /**
     * Get a multi-line address
     */
    public function multiline(): string
    {
        $lines = [];

        if ($this['street_address']) {
            $lines[] = $this['street_address'];
        }

        $city_line = array_filter([
            $this['postal_code'],
            $this['address_locality'],
            $this['address_region'],
        ]);
        if ($city_line) {
            $lines[] = implode(' ', $city_line);
        }

        if ($this['address_country']) {
            $lines[] = $this['address_country'];
        }

        return implode("\n", $lines);
    }
}
