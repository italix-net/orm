<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - PolymorphicColumn Class
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use Italix\Contracts\PolymorphicColumnMeta;
use Italix\Contracts\TableMeta;

/**
 * Represents a polymorphic relationship column.
 *
 * This is used for the ID column of polymorphic relationships
 * (e.g., commentable_id) where the target table varies based on
 * a type discriminator column (e.g., commentable_type).
 *
 * Implements PolymorphicColumnMeta for compatibility with italix/forms library.
 *
 * @example
 * $commentable_id = polymorphic_column('commentable_id', 'commentable_type', [
 *     'post'  => $posts_table,
 *     'video' => $videos_table,
 * ]);
 */
class PolymorphicColumn extends Column implements PolymorphicColumnMeta
{
    /** @var string Name of the type discriminator column */
    protected string $type_column;

    /** @var array<string, TableMeta> Map of type names to target tables */
    protected array $targets = [];

    /**
     * Create a new PolymorphicColumn instance.
     *
     * @param string $type_column The type discriminator column name
     * @param string $type Data type (default: INTEGER)
     * @param int|null $length Length for VARCHAR types
     */
    public function __construct(string $type_column, string $type = 'INTEGER', ?int $length = null)
    {
        parent::__construct($type, $length);
        $this->type_column = $type_column;
    }

    /**
     * Get the name of the type discriminator column.
     *
     * @return string E.g., 'commentable_type'
     */
    public function get_polymorphic_type_column(): string
    {
        return $this->type_column;
    }

    /**
     * Get the available polymorphic targets.
     *
     * @return array<string, TableMeta>
     */
    public function get_polymorphic_targets(): array
    {
        return $this->targets;
    }

    /**
     * Set the polymorphic targets.
     *
     * @param array<string, TableMeta> $targets Map of type names to tables
     * @return self
     */
    public function targets(array $targets): self
    {
        $this->targets = $targets;
        return $this;
    }

    /**
     * Add a polymorphic target.
     *
     * @param string $type_name The type identifier (e.g., 'post')
     * @param TableMeta $table The target table
     * @return self
     */
    public function add_target(string $type_name, TableMeta $table): self
    {
        $this->targets[$type_name] = $table;
        return $this;
    }

    /**
     * Check if this column has a specific target type.
     *
     * @param string $type_name
     * @return bool
     */
    public function has_target(string $type_name): bool
    {
        return isset($this->targets[$type_name]);
    }

    /**
     * Get a specific target table.
     *
     * @param string $type_name
     * @return TableMeta|null
     */
    public function get_target(string $type_name): ?TableMeta
    {
        return $this->targets[$type_name] ?? null;
    }
}

/**
 * Factory function to create a polymorphic column.
 *
 * @param string $type_column The type discriminator column name
 * @param array<string, TableMeta> $targets Map of type names to tables
 * @param string $type Data type (default: INTEGER)
 * @return PolymorphicColumn
 */
function polymorphic_column(
    string $type_column,
    array $targets = [],
    string $type = 'INTEGER'
): PolymorphicColumn {
    $column = new PolymorphicColumn($type_column, $type);
    if (!empty($targets)) {
        $column->targets($targets);
    }
    return $column;
}
