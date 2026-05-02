<?php
/**
 * Place - Delegate class for Place-type Things
 *
 * First-level delegate. LocalBusiness is a second-level delegate
 * loaded through sub_delegate().
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Operators\eq;

class Place extends ActiveRow
{
    use Persistable;

    protected ?Thing $thing_cache = null;
    protected ?PostalAddress $address_cache = null;

    public function thing_id(): int
    {
        return (int) $this['thing_id'];
    }

    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find($this['thing_id']);
        }
        return $this->thing_cache;
    }

    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    // =========================================
    // SUB-DELEGATION
    // =========================================

    /**
     * Get the sub-delegate (LocalBusiness) if this Place is a LocalBusiness
     *
     * @return LocalBusiness|null
     */
    public function sub_delegate(): ?ActiveRow
    {
        $thing = $this->thing();
        if ($thing['type'] === 'LocalBusiness') {
            $table = LocalBusiness::get_table();
            return LocalBusiness::find_one([
                'where' => eq($table->place_id, $this['id']),
            ]);
        }
        return null;
    }

    // =========================================
    // PLACE-SPECIFIC METHODS
    // =========================================

    public function latitude(): ?string
    {
        return $this['latitude'];
    }

    public function longitude(): ?string
    {
        return $this['longitude'];
    }

    public function coordinates(): ?string
    {
        $lat = $this['latitude'];
        $lng = $this['longitude'];
        if ($lat === null || $lng === null) {
            return null;
        }
        return $lat . ', ' . $lng;
    }

    public function telephone(): ?string
    {
        return $this['telephone'];
    }

    public function max_capacity(): ?int
    {
        return $this['max_capacity'] ? (int) $this['max_capacity'] : null;
    }

    // =========================================
    // POSTAL ADDRESS
    // =========================================

    /**
     * Get the postal address for this place
     *
     * @return PostalAddress|null
     */
    public function address(): ?PostalAddress
    {
        if ($this->address_cache === null) {
            $table = PostalAddress::get_table();
            $this->address_cache = PostalAddress::find_one([
                'where' => eq($table->place_id, $this['id']),
            ]);
        }
        return $this->address_cache;
    }

    /**
     * Set or create the postal address
     *
     * @param array $address_data
     * @return PostalAddress
     */
    public function set_address(array $address_data): PostalAddress
    {
        $existing = $this->address();
        if ($existing) {
            foreach ($address_data as $key => $value) {
                $existing[$key] = $value;
            }
            $existing->save();
            return $existing;
        }

        $address = PostalAddress::create(array_merge($address_data, [
            'place_id' => $this['id'],
        ]));
        $this->address_cache = $address;
        return $address;
    }

    /**
     * Get a formatted one-line address
     *
     * @return string|null
     */
    public function formatted_address(): ?string
    {
        $address = $this->address();
        if ($address === null) {
            return null;
        }
        return $address->formatted();
    }
}
