<?php
/**
 * Italix ORM - DelegatedTableMeta Interface
 *
 * Compatible with italix/forms library for automatic form generation.
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Contracts;

/**
 * Interface for tables that use the Delegated Types pattern.
 *
 * Extends TableMeta to add support for type-discriminated hierarchies
 * (e.g., Thing → Book → TextBook). This enables automatic resolution
 * of full delegation chains in forms.
 */
interface DelegatedTableMeta extends TableMeta
{
    /**
     * Get the type discriminator column name (e.g., 'type').
     *
     * @return string|null
     */
    public function get_type_column(): ?string;

    /**
     * Get the type path column name (e.g., 'type_path').
     *
     * Stores the full hierarchy (e.g., 'Thing/Book/ComicsBook').
     *
     * @return string|null
     */
    public function get_type_path_column(): ?string;

    /**
     * Get the foreign key column name used in delegate tables
     * to reference this table (e.g., 'thing_id').
     *
     * @return string
     */
    public function get_delegate_foreign_key(): string;

    /**
     * Get the direct delegate sub-tables.
     *
     * Each delegate may itself implement DelegatedTableMeta for deeper chains.
     *
     * @return array<string, TableMeta>
     * E.g., ['Book' => $books_table, 'Movie' => $movies_table]
     */
    public function get_delegate_tables(): array;
}
