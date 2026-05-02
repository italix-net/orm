<?php
/**
 * Person - Delegate class for Person-type Things
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\Events\Traits\AgentBehavior;

class Person extends ActiveRow
{
    use Persistable, AgentBehavior;

    protected ?Thing $thing_cache = null;

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
    // NAME METHODS
    // =========================================

    public function display_name(): string
    {
        $given = $this['given_name'] ?? '';
        $family = $this['family_name'] ?? '';
        return trim($given . ' ' . $family);
    }

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

    public function given_name(): ?string
    {
        return $this['given_name'];
    }

    public function family_name(): ?string
    {
        return $this['family_name'];
    }

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
    // CONTACT METHODS
    // =========================================

    public function email(): ?string
    {
        return $this['email'];
    }

    public function telephone(): ?string
    {
        return $this['telephone'];
    }

    // =========================================
    // DATE METHODS
    // =========================================

    public function birth_date(): ?\DateTime
    {
        if (empty($this['birth_date'])) {
            return null;
        }
        return new \DateTime($this['birth_date']);
    }

    public function age(): ?int
    {
        $birth = $this->birth_date();
        if ($birth === null) {
            return null;
        }
        return $birth->diff(new \DateTime())->y;
    }

    // =========================================
    // TYPE METHODS
    // =========================================

    public function is_person(): bool
    {
        return true;
    }
}
