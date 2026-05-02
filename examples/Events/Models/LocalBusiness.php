<?php
/**
 * LocalBusiness - Second-level delegate under Place
 *
 * Represents a business at a physical location.
 * In schema.org, LocalBusiness extends both Organization and Place;
 * here we model it as a Place sub-delegate with business-specific fields.
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class LocalBusiness extends ActiveRow
{
    use Persistable;

    protected ?Place $place_cache = null;

    public function place_id(): int
    {
        return (int) $this['place_id'];
    }

    /**
     * Get the parent Place
     */
    public function place(): Place
    {
        if ($this->place_cache === null) {
            $this->place_cache = Place::find($this['place_id']);
        }
        return $this->place_cache;
    }

    /**
     * Set the cached Place (for eager loading)
     */
    public function set_place(Place $place): static
    {
        $this->place_cache = $place;
        return $this;
    }

    /**
     * Get the parent Thing (through Place)
     */
    public function thing(): Thing
    {
        return $this->place()->thing();
    }

    // =========================================
    // BUSINESS-SPECIFIC METHODS
    // =========================================

    public function legal_name(): ?string
    {
        return $this['legal_name'];
    }

    public function price_range(): ?string
    {
        return $this['price_range'];
    }

    public function opening_hours(): ?string
    {
        return $this['opening_hours'];
    }

    public function telephone(): ?string
    {
        return $this['telephone'];
    }

    public function email(): ?string
    {
        return $this['email'];
    }

    /**
     * Get display name: legal_name or fallback to Thing name
     */
    public function display_name(): string
    {
        return $this['legal_name'] ?? $this->thing()['name'] ?? '';
    }
}
