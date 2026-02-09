<?php
/**
 * Italix ORM - RelationMeta Interface
 *
 * Compatible with italix/forms library for automatic form generation.
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Contracts;

/**
 * Interface for relation/foreign key metadata.
 *
 * Describes a foreign key relationship and provides a method
 * to fetch related options for select/autocomplete fields.
 */
interface RelationMeta
{
    /**
     * Get the foreign table name.
     *
     * @return string E.g., "countries"
     */
    public function get_foreign_table(): string;

    /**
     * Get the foreign key column name in the target table.
     *
     * @return string E.g., "id"
     */
    public function get_foreign_key(): string;

    /**
     * Get the column to use as display label in the foreign table.
     *
     * @return string E.g., "name"
     */
    public function get_foreign_label(): string;

    /**
     * Fetch option pairs from the related table.
     *
     * Returns an associative array of [key => label] pairs for populating
     * select dropdowns or autocomplete fields.
     *
     * Returns null if there are too many rows (autocomplete should be used instead).
     *
     * @param int $max_options Maximum number of options to fetch (default 100)
     * @return array|null [key => label] pairs or null if too many
     */
    public function fetch_options(int $max_options = 100): ?array;
}
