<?php
/**
 * CreativeWorkBehavior Trait
 *
 * Shared behavior for all creative work types (Book, Movie, Article, etc.)
 * Provides methods for working with contributors (authors, directors, etc.)
 */

namespace Examples\DelegatedTypes\Traits;

use Examples\DelegatedTypes\Models\Contribution;
use Examples\DelegatedTypes\Models\Thing;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

trait CreativeWorkBehavior
{
    /**
     * Get the thing_id (foreign key to things table)
     *
     * @return int
     */
    abstract public function thing_id(): int;

    /**
     * Get the parent Thing
     *
     * @return Thing
     */
    abstract public function thing(): Thing;

    // =========================================
    // CONTRIBUTOR ACCESS
    // =========================================

    /**
     * Get all contributors by role
     *
     * @param string $role
     * @return array<Thing>
     */
    public function contributors_by_role(string $role): array
    {
        $table = Contribution::get_table();
        $contributions = Contribution::find_all([
            'where' => and_(
                eq($table->work_id, $this->thing_id()),
                eq($table->role, $role)
            ),
            'order_by' => 'position',
        ]);

        return array_map(function ($c) {
            return $c->agent();
        }, $contributions);
    }

    /**
     * Get authors
     *
     * @return array<Thing>
     */
    public function authors(): array
    {
        return $this->contributors_by_role('author');
    }

    /**
     * Get translators
     *
     * @return array<Thing>
     */
    public function translators(): array
    {
        return $this->contributors_by_role('translator');
    }

    /**
     * Get editors
     *
     * @return array<Thing>
     */
    public function editors(): array
    {
        return $this->contributors_by_role('editor');
    }

    // =========================================
    // CONTRIBUTOR MANAGEMENT
    // =========================================

    /**
     * Add a contributor with a specific role
     *
     * @param Thing $agent Person or Organization
     * @param string $role Role name
     * @param int $position Position in ordering
     * @return Contribution
     */
    public function add_contributor(Thing $agent, string $role, int $position = 0): Contribution
    {
        return Contribution::create([
            'work_id'  => $this->thing_id(),
            'agent_id' => $agent['id'],
            'role'     => $role,
            'position' => $position,
        ]);
    }

    /**
     * Add an author
     *
     * @param Thing $agent
     * @param int $position
     * @return Contribution
     */
    public function add_author(Thing $agent, int $position = 0): Contribution
    {
        return $this->add_contributor($agent, 'author', $position);
    }

    /**
     * Add a translator
     *
     * @param Thing $agent
     * @param int $position
     * @return Contribution
     */
    public function add_translator(Thing $agent, int $position = 0): Contribution
    {
        return $this->add_contributor($agent, 'translator', $position);
    }

    /**
     * Add an editor
     *
     * @param Thing $agent
     * @param int $position
     * @return Contribution
     */
    public function add_editor(Thing $agent, int $position = 0): Contribution
    {
        return $this->add_contributor($agent, 'editor', $position);
    }

    /**
     * Remove all contributors with a specific role
     *
     * @param string $role
     * @return void
     */
    public function clear_contributors(string $role): void
    {
        $table = Contribution::get_table();
        $contributions = Contribution::find_all([
            'where' => and_(
                eq($table->work_id, $this->thing_id()),
                eq($table->role, $role)
            ),
        ]);

        foreach ($contributions as $contribution) {
            $contribution->delete();
        }
    }

    // =========================================
    // FORMATTING
    // =========================================

    /**
     * Get author names as a formatted string
     *
     * @param string $separator
     * @return string
     */
    public function authors_string(string $separator = ', '): string
    {
        $authors = $this->authors();
        $names = array_map(function ($author) {
            $delegate = $author->delegate();
            if ($delegate && method_exists($delegate, 'display_name')) {
                return $delegate->display_name();
            }
            return $author['name'];
        }, $authors);

        return implode($separator, $names);
    }

    /**
     * Get citation-formatted author names
     *
     * @return string
     */
    public function citation_authors(): string
    {
        $authors = $this->authors();
        $names = array_map(function ($author) {
            $delegate = $author->delegate();
            if ($delegate && method_exists($delegate, 'citation_name')) {
                return $delegate->citation_name();
            }
            return $author['name'];
        }, $authors);

        if (count($names) === 0) {
            return '';
        } elseif (count($names) === 1) {
            return $names[0];
        } elseif (count($names) === 2) {
            return $names[0] . ' & ' . $names[1];
        } else {
            $last = array_pop($names);
            return implode(', ', $names) . ' & ' . $last;
        }
    }
}
