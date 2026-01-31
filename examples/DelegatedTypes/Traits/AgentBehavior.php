<?php
/**
 * AgentBehavior Trait
 *
 * Shared behavior for all agent types (Person, Organization)
 * Provides methods for working with contributions and authored works.
 */

namespace Examples\DelegatedTypes\Traits;

use Examples\DelegatedTypes\Models\Contribution;
use Examples\DelegatedTypes\Models\Thing;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

trait AgentBehavior
{
    /**
     * Get the thing_id (foreign key to things table)
     *
     * @return int
     */
    abstract public function thing_id(): int;

    /**
     * Get the display name for this agent
     *
     * @return string
     */
    abstract public function display_name(): string;

    /**
     * Get the citation-formatted name
     *
     * @return string
     */
    abstract public function citation_name(): string;

    // =========================================
    // CONTRIBUTED WORKS
    // =========================================

    /**
     * Get works where this agent has a specific role
     *
     * @param string $role
     * @return array<Thing>
     */
    public function works_as(string $role): array
    {
        $table = Contribution::get_table();
        $contributions = Contribution::find_all([
            'where' => and_(
                eq($table->agent_id, $this->thing_id()),
                eq($table->role, $role)
            ),
        ]);

        return array_map(function ($c) {
            return $c->work();
        }, $contributions);
    }

    /**
     * Get all works authored by this agent
     *
     * @return array<Thing>
     */
    public function authored_works(): array
    {
        return $this->works_as('author');
    }

    /**
     * Get all works translated by this agent
     *
     * @return array<Thing>
     */
    public function translated_works(): array
    {
        return $this->works_as('translator');
    }

    /**
     * Get all works edited by this agent
     *
     * @return array<Thing>
     */
    public function edited_works(): array
    {
        return $this->works_as('editor');
    }

    /**
     * Get all contributions by this agent
     *
     * @return array<Contribution>
     */
    public function contributions(): array
    {
        $table = Contribution::get_table();
        return Contribution::find_all([
            'where' => eq($table->agent_id, $this->thing_id()),
        ]);
    }

    /**
     * Count works by role
     *
     * @param string $role
     * @return int
     */
    public function count_works_as(string $role): int
    {
        return count($this->works_as($role));
    }

    // =========================================
    // TYPE CHECKS
    // =========================================

    /**
     * Check if this is a person
     *
     * @return bool
     */
    public function is_person(): bool
    {
        return false;  // Override in Person class
    }

    /**
     * Check if this is an organization
     *
     * @return bool
     */
    public function is_organization(): bool
    {
        return false;  // Override in Organization class
    }

    // =========================================
    // FORMATTING
    // =========================================

    /**
     * Get a label suitable for author credits
     *
     * @return string
     */
    public function author_label(): string
    {
        return $this->display_name();
    }

    /**
     * Get the agent type for display
     *
     * @return string
     */
    public function agent_type(): string
    {
        return $this->is_person() ? 'Person' : 'Organization';
    }
}
