<?php

/**
 * ActiveRow helper functions
 *
 * These functions provide convenient wrappers for working with ActiveRow instances.
 * Classes and traits are autoloaded via PSR-4 in composer.json.
 */

namespace Italix\Orm\ActiveRow;

/**
 * Helper function to wrap an array of results into ActiveRow instances
 *
 * @param array $rows Array of row data
 * @param string $class ActiveRow class to use
 * @return array Array of ActiveRow instances
 */
function wrap_rows(array $rows, string $class): array
{
    if (!is_subclass_of($class, ActiveRow::class)) {
        throw new \InvalidArgumentException("$class must extend ActiveRow");
    }

    return $class::wrap_many($rows);
}

/**
 * Helper function to wrap a single row
 *
 * @param array $row Row data
 * @param string $class ActiveRow class to use
 * @return ActiveRow
 */
function wrap_row(array $row, string $class): ActiveRow
{
    if (!is_subclass_of($class, ActiveRow::class)) {
        throw new \InvalidArgumentException("$class must extend ActiveRow");
    }

    return $class::wrap($row);
}

/**
 * Unwrap ActiveRow instances back to plain arrays
 *
 * @param array $rows Array of ActiveRow instances or mixed
 * @return array Array of plain arrays
 */
function unwrap_rows(array $rows): array
{
    return array_map(function ($row) {
        return $row instanceof ActiveRow ? $row->to_array() : $row;
    }, $rows);
}
