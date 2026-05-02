<?php
/**
 * Participation - Junction table for Thing-Agent relationships
 *
 * Links any Thing (typically Events) to Agents (Person/Organization)
 * with a role (organizer, performer, instructor, etc.)
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

class Participation extends ActiveRow
{
    use Persistable;

    protected ?Thing $thing_cache = null;
    protected ?Thing $agent_cache = null;

    // =========================================
    // RELATION ACCESSORS
    // =========================================

    /**
     * Get the subject Thing (typically an Event)
     */
    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find_with_delegate($this['thing_id']);
        }
        return $this->thing_cache;
    }

    /**
     * Get the agent (Person or Organization)
     */
    public function agent(): Thing
    {
        if ($this->agent_cache === null) {
            $this->agent_cache = Thing::find_with_delegate($this['agent_id']);
        }
        return $this->agent_cache;
    }

    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    public function set_agent(Thing $agent): static
    {
        $this->agent_cache = $agent;
        return $this;
    }

    // =========================================
    // ROLE METHODS
    // =========================================

    public function role(): string
    {
        return $this['role'] ?? '';
    }

    public function position(): int
    {
        return (int) ($this['position'] ?? 0);
    }

    public function billing(): ?string
    {
        return $this['billing'];
    }

    public function is_organizer(): bool
    {
        return $this['role'] === 'organizer';
    }

    public function is_performer(): bool
    {
        return $this['role'] === 'performer';
    }

    public function is_instructor(): bool
    {
        return $this['role'] === 'instructor';
    }

    // =========================================
    // STATIC QUERY HELPERS
    // =========================================

    /**
     * Find all participations for a thing (event)
     */
    public static function for_thing(int $thing_id, ?string $role = null): array
    {
        $table = static::get_table();
        $conditions = [eq($table->thing_id, $thing_id)];

        if ($role !== null) {
            $conditions[] = eq($table->role, $role);
        }

        return static::find_all([
            'where' => count($conditions) === 1 ? $conditions[0] : and_(...$conditions),
            'order_by' => 'position',
        ]);
    }

    /**
     * Find all participations by an agent
     */
    public static function for_agent(int $agent_id, ?string $role = null): array
    {
        $table = static::get_table();
        $conditions = [eq($table->agent_id, $agent_id)];

        if ($role !== null) {
            $conditions[] = eq($table->role, $role);
        }

        return static::find_all([
            'where' => count($conditions) === 1 ? $conditions[0] : and_(...$conditions),
        ]);
    }

    /**
     * Get or create a participation
     */
    public static function get_or_create(int $thing_id, int $agent_id, string $role, int $position = 0, ?string $billing = null): static
    {
        $table = static::get_table();
        $existing = static::find_one([
            'where' => and_(
                eq($table->thing_id, $thing_id),
                eq($table->agent_id, $agent_id),
                eq($table->role, $role)
            ),
        ]);

        if ($existing !== null) {
            return $existing;
        }

        $data = [
            'thing_id' => $thing_id,
            'agent_id' => $agent_id,
            'role'     => $role,
            'position' => $position,
        ];

        if ($billing !== null) {
            $data['billing'] = $billing;
        }

        return static::create($data);
    }
}
