<?php
/**
 * Italix ORM - PolymorphicColumnMeta Interface
 *
 * Compatible with italix/forms library for automatic form generation.
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Contracts;

/**
 * Interface for polymorphic relationship columns.
 *
 * Implement this on the ID column of polymorphic relationships
 * (e.g., commentable_id). This enables dependent field pairs
 * for type + ID switching in forms.
 */
interface PolymorphicColumnMeta extends ColumnMeta
{
    /**
     * Get the name of the type discriminator column.
     *
     * E.g., for 'commentable_id', return 'commentable_type'
     *
     * @return string
     */
    public function get_polymorphic_type_column(): string;

    /**
     * Get the available polymorphic targets.
     *
     * @return array<string, TableMeta>
     * E.g., ['post' => $posts_table, 'video' => $videos_table]
     */
    public function get_polymorphic_targets(): array;
}
