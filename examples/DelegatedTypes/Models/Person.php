<?php
/**
 * Person - Delegate class for Person-type Things
 *
 * Represents person-specific attributes and behaviors.
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\DelegatedTypes\Traits\AgentBehavior;

class Person extends ActiveRow
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
        $given = $this['given_name'] ?? '';
        $family = $this['family_name'] ?? '';
        return trim($given . ' ' . $family);
    }

    /**
     * Get citation-formatted name (Family, G.)
     *
     * @return string
     */
    public function citation_name(): string
    {
        $family = $this['family_name'] ?? '';
        $given = $this['given_name'] ?? '';

        if ($family && $given) {
            $initial = mb_substr($given, 0, 1);
            return $family . ', ' . $initial . '.';
        } elseif ($family) {
            return $family;
        }
        return $given;
    }

    /**
     * Get given name (first name)
     *
     * @return string|null
     */
    public function given_name(): ?string
    {
        return $this['given_name'];
    }

    /**
     * Get family name (last name)
     *
     * @return string|null
     */
    public function family_name(): ?string
    {
        return $this['family_name'];
    }

    /**
     * Get full name in "Family, Given" format
     *
     * @return string
     */
    public function formal_name(): string
    {
        $family = $this['family_name'] ?? '';
        $given = $this['given_name'] ?? '';

        if ($family && $given) {
            return $family . ', ' . $given;
        }
        return $family ?: $given;
    }

    // =========================================
    // DATE METHODS
    // =========================================

    /**
     * Get birth date
     *
     * @return \DateTime|null
     */
    public function birth_date(): ?\DateTime
    {
        if (empty($this['birth_date'])) {
            return null;
        }
        return new \DateTime($this['birth_date']);
    }

    /**
     * Get death date
     *
     * @return \DateTime|null
     */
    public function death_date(): ?\DateTime
    {
        if (empty($this['death_date'])) {
            return null;
        }
        return new \DateTime($this['death_date']);
    }

    /**
     * Check if person is alive
     *
     * @return bool
     */
    public function is_alive(): bool
    {
        return $this['death_date'] === null;
    }

    /**
     * Get age (current age if alive, age at death if deceased)
     *
     * @return int|null
     */
    public function age(): ?int
    {
        $birth = $this->birth_date();
        if ($birth === null) {
            return null;
        }

        $end = $this->death_date() ?? new \DateTime();
        return $birth->diff($end)->y;
    }

    /**
     * Get formatted birth year
     *
     * @return int|null
     */
    public function birth_year(): ?int
    {
        $date = $this->birth_date();
        return $date ? (int) $date->format('Y') : null;
    }

    /**
     * Get formatted death year
     *
     * @return int|null
     */
    public function death_year(): ?int
    {
        $date = $this->death_date();
        return $date ? (int) $date->format('Y') : null;
    }

    /**
     * Get life span string (e.g., "1920-2010" or "1985-")
     *
     * @return string|null
     */
    public function life_span(): ?string
    {
        $birth = $this->birth_year();
        if ($birth === null) {
            return null;
        }

        $death = $this->death_year();
        if ($death !== null) {
            return $birth . '-' . $death;
        }
        return $birth . '-';
    }

    // =========================================
    // TYPE METHODS
    // =========================================

    /**
     * Check if this is a person
     *
     * @return bool
     */
    public function is_person(): bool
    {
        return true;
    }
}
