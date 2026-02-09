<?php
/**
 * Italix ORM - ColumnMeta Interface
 *
 * Compatible with italix/forms library for automatic form generation.
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Contracts;

/**
 * Interface for column metadata.
 *
 * Provides the minimum contract for describing a database column
 * that can be used for form field generation.
 */
interface ColumnMeta
{
    /**
     * Get the column name.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Get the column data type.
     *
     * Common types: VARCHAR, INTEGER, TEXT, BOOLEAN, DATE, DATETIME,
     * TIME, NUMERIC, DECIMAL, JSON, JSONB, etc.
     *
     * @return string
     */
    public function get_type(): string;

    /**
     * Check if the column allows NULL values.
     *
     * @return bool
     */
    public function is_nullable(): bool;

    /**
     * Check if this column is a primary key.
     *
     * @return bool
     */
    public function is_primary_key(): bool;

    /**
     * Get the column length (for VARCHAR, CHAR, etc.).
     *
     * @return int|null Length or null if not applicable
     */
    public function get_length(): ?int;

    /**
     * Get the default value for this column.
     *
     * @return mixed
     */
    public function get_default();

    /**
     * Check if this column has a default value.
     *
     * @return bool
     */
    public function has_default(): bool;
}
