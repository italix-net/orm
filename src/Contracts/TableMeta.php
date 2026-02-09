<?php
/**
 * Italix ORM - TableMeta Interface
 *
 * Compatible with italix/forms library for automatic form generation.
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Contracts;

/**
 * Interface for table metadata.
 *
 * Provides the minimum contract for describing a database table
 * that can be used for form generation.
 */
interface TableMeta
{
    /**
     * Return an iterable of column descriptors.
     *
     * @return iterable<string, ColumnMeta>
     */
    public function describe_columns(): iterable;

    /**
     * Get a specific column descriptor by name.
     *
     * @param string $name Column name
     * @return ColumnMeta|null
     */
    public function describe_column(string $name): ?ColumnMeta;
}
