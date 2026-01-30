<?php
/**
 * Contribution - Join table for Agent-CreativeWork relationships
 *
 * Links agents (Person, Organization) to creative works (Book, Movie, Article)
 * with a specific role (author, director, translator, etc.)
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

class Contribution extends ActiveRow
{
    use Persistable;

    /**
     * Cache for work Thing
     * @var Thing|null
     */
    protected ?Thing $work_cache = null;

    /**
     * Cache for agent Thing
     * @var Thing|null
     */
    protected ?Thing $agent_cache = null;

    // =========================================
    // RELATION ACCESSORS
    // =========================================

    /**
     * Get the creative work (Book, Movie, Article, etc.)
     *
     * @return Thing
     */
    public function work(): Thing
    {
        if ($this->work_cache === null) {
            $this->work_cache = Thing::find_with_delegate($this['work_id']);
        }
        return $this->work_cache;
    }

    /**
     * Get the agent (Person or Organization)
     *
     * @return Thing
     */
    public function agent(): Thing
    {
        if ($this->agent_cache === null) {
            $this->agent_cache = Thing::find_with_delegate($this['agent_id']);
        }
        return $this->agent_cache;
    }

    /**
     * Set cached work (for eager loading)
     *
     * @param Thing $work
     * @return static
     */
    public function set_work(Thing $work): static
    {
        $this->work_cache = $work;
        return $this;
    }

    /**
     * Set cached agent (for eager loading)
     *
     * @param Thing $agent
     * @return static
     */
    public function set_agent(Thing $agent): static
    {
        $this->agent_cache = $agent;
        return $this;
    }

    // =========================================
    // ROLE METHODS
    // =========================================

    /**
     * Get the role
     *
     * @return string
     */
    public function role(): string
    {
        return $this['role'] ?? '';
    }

    /**
     * Get the position in ordering
     *
     * @return int
     */
    public function position(): int
    {
        return (int) ($this['position'] ?? 0);
    }

    /**
     * Check if this is an author contribution
     *
     * @return bool
     */
    public function is_author(): bool
    {
        return $this['role'] === 'author';
    }

    /**
     * Check if this is a director contribution
     *
     * @return bool
     */
    public function is_director(): bool
    {
        return $this['role'] === 'director';
    }

    /**
     * Check if this is a translator contribution
     *
     * @return bool
     */
    public function is_translator(): bool
    {
        return $this['role'] === 'translator';
    }

    // =========================================
    // STATIC QUERY HELPERS
    // =========================================

    /**
     * Find all contributions for a work
     *
     * @param int $work_id
     * @param string|null $role Optional role filter
     * @return array<static>
     */
    public static function for_work(int $work_id, ?string $role = null): array
    {
        $table = static::get_table();
        $conditions = [eq($table->work_id, $work_id)];

        if ($role !== null) {
            $conditions[] = eq($table->role, $role);
        }

        return static::find_all([
            'where' => count($conditions) === 1 ? $conditions[0] : and_(...$conditions),
            'order_by' => 'position',
        ]);
    }

    /**
     * Find all contributions by an agent
     *
     * @param int $agent_id
     * @param string|null $role Optional role filter
     * @return array<static>
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
     * Check if a contribution already exists
     *
     * @param int $work_id
     * @param int $agent_id
     * @param string $role
     * @return bool
     */
    public static function exists_for(int $work_id, int $agent_id, string $role): bool
    {
        $table = static::get_table();
        $existing = static::find_one([
            'where' => and_(
                eq($table->work_id, $work_id),
                eq($table->agent_id, $agent_id),
                eq($table->role, $role)
            ),
        ]);

        return $existing !== null;
    }

    /**
     * Get or create a contribution
     *
     * @param int $work_id
     * @param int $agent_id
     * @param string $role
     * @param int $position
     * @return static
     */
    public static function get_or_create(int $work_id, int $agent_id, string $role, int $position = 0): static
    {
        $table = static::get_table();
        $existing = static::find_one([
            'where' => and_(
                eq($table->work_id, $work_id),
                eq($table->agent_id, $agent_id),
                eq($table->role, $role)
            ),
        ]);

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'work_id'  => $work_id,
            'agent_id' => $agent_id,
            'role'     => $role,
            'position' => $position,
        ]);
    }
}
