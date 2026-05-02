<?php
/**
 * AgentBehavior Trait
 *
 * Shared behavior for all agent types (Person, Organization).
 * Provides methods for working with participations.
 */

namespace Examples\Events\Traits;

use Examples\Events\Models\Participation;
use Examples\Events\Models\Thing;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

trait AgentBehavior
{
    abstract public function thing_id(): int;

    abstract public function display_name(): string;

    abstract public function citation_name(): string;

    // =========================================
    // PARTICIPATIONS
    // =========================================

    /**
     * Get things where this agent has a specific role
     *
     * @param string $role
     * @return array<Thing>
     */
    public function participations_as(string $role): array
    {
        $table = Participation::get_table();
        $participations = Participation::find_all([
            'where' => and_(
                eq($table->agent_id, $this->thing_id()),
                eq($table->role, $role)
            ),
        ]);

        return array_map(fn($p) => $p->thing(), $participations);
    }

    /**
     * Get events organized by this agent
     *
     * @return array<Thing>
     */
    public function organized_events(): array
    {
        return $this->participations_as('organizer');
    }

    /**
     * Get events where this agent performed
     *
     * @return array<Thing>
     */
    public function performed_at(): array
    {
        return $this->participations_as('performer');
    }

    /**
     * Get events where this agent was instructor
     *
     * @return array<Thing>
     */
    public function instructed_at(): array
    {
        return $this->participations_as('instructor');
    }

    /**
     * Get all participations by this agent
     *
     * @return array<Participation>
     */
    public function participations(): array
    {
        $table = Participation::get_table();
        return Participation::find_all([
            'where' => eq($table->agent_id, $this->thing_id()),
        ]);
    }

    /**
     * Count participations by role
     *
     * @param string $role
     * @return int
     */
    public function count_participations_as(string $role): int
    {
        return count($this->participations_as($role));
    }

    // =========================================
    // TYPE CHECKS
    // =========================================

    public function is_person(): bool
    {
        return false;
    }

    public function is_organization(): bool
    {
        return false;
    }

    public function agent_type(): string
    {
        return $this->is_person() ? 'Person' : 'Organization';
    }

    public function author_label(): string
    {
        return $this->display_name();
    }
}
