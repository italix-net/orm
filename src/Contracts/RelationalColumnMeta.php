<?php
/**
 * Italix ORM - RelationalColumnMeta Interface
 *
 * Compatible with italix/forms library for automatic form generation.
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Contracts;

/**
 * Interface for columns that can have foreign key relationships.
 *
 * Extends ColumnMeta to add foreign key relation support.
 * Implement this on columns that reference other tables.
 */
interface RelationalColumnMeta extends ColumnMeta
{
    /**
     * Get foreign key relation metadata, if this column is a foreign key.
     *
     * Return null if this column is not a foreign key.
     *
     * @return RelationMeta|null
     */
    public function get_relation(): ?RelationMeta;
}
