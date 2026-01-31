<?php
/**
 * Organization - Delegate class for Organization-type Things
 *
 * Represents organization-specific attributes and behaviors.
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\DelegatedTypes\Traits\AgentBehavior;

class Organization extends ActiveRow
{
    use Persistable, AgentBehavior;

    /**
     * Cache for parent Thing
     * @var Thing|null
     */
    protected ?Thing $thing_cache = null;

    /**
     * Get the thing_id (foreign key)
     *
     * @return int
     */
    public function thing_id(): int
    {
        return (int) $this['thing_id'];
    }

    /**
     * Get the parent Thing
     *
     * @return Thing
     */
    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find($this['thing_id']);
        }
        return $this->thing_cache;
    }

    /**
     * Set the cached Thing (for eager loading)
     *
     * @param Thing $thing
     * @return static
     */
    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    // =========================================
    // NAME METHODS
    // =========================================

    /**
     * Get the display name
     *
     * @return string
     */
    public function display_name(): string
    {
        return $this['legal_name'] ?? $this->thing()['name'] ?? '';
    }

    /**
     * Get citation-formatted name (same as display for organizations)
     *
     * @return string
     */
    public function citation_name(): string
    {
        return $this->display_name();
    }

    /**
     * Get the legal name
     *
     * @return string|null
     */
    public function legal_name(): ?string
    {
        return $this['legal_name'];
    }

    // =========================================
    // DATE METHODS
    // =========================================

    /**
     * Get founding date
     *
     * @return \DateTime|null
     */
    public function founding_date(): ?\DateTime
    {
        if (empty($this['founding_date'])) {
            return null;
        }
        return new \DateTime($this['founding_date']);
    }

    /**
     * Get dissolution date
     *
     * @return \DateTime|null
     */
    public function dissolution_date(): ?\DateTime
    {
        if (empty($this['dissolution_date'])) {
            return null;
        }
        return new \DateTime($this['dissolution_date']);
    }

    /**
     * Check if organization is active
     *
     * @return bool
     */
    public function is_active(): bool
    {
        return $this['dissolution_date'] === null;
    }

    /**
     * Get founding year
     *
     * @return int|null
     */
    public function founding_year(): ?int
    {
        $date = $this->founding_date();
        return $date ? (int) $date->format('Y') : null;
    }

    /**
     * Get dissolution year
     *
     * @return int|null
     */
    public function dissolution_year(): ?int
    {
        $date = $this->dissolution_date();
        return $date ? (int) $date->format('Y') : null;
    }

    /**
     * Get years in operation
     *
     * @return int|null
     */
    public function years_in_operation(): ?int
    {
        $founding = $this->founding_date();
        if ($founding === null) {
            return null;
        }

        $end = $this->dissolution_date() ?? new \DateTime();
        return $founding->diff($end)->y;
    }

    // =========================================
    // TYPE METHODS
    // =========================================

    /**
     * Check if this is an organization
     *
     * @return bool
     */
    public function is_organization(): bool
    {
        return true;
    }
}
