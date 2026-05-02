<?php
/**
 * Organization - Delegate class for Organization-type Things
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\Events\Traits\AgentBehavior;

class Organization extends ActiveRow
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
        return $this['legal_name'] ?? $this->thing()['name'] ?? '';
    }

    public function citation_name(): string
    {
        return $this->display_name();
    }

    public function legal_name(): ?string
    {
        return $this['legal_name'];
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

    public function founding_date(): ?\DateTime
    {
        if (empty($this['founding_date'])) {
            return null;
        }
        return new \DateTime($this['founding_date']);
    }

    public function founding_year(): ?int
    {
        $date = $this->founding_date();
        return $date ? (int) $date->format('Y') : null;
    }

    public function is_organization(): bool
    {
        return true;
    }
}
