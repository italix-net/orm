<?php

/**
 * ActiveRow functions and autoloading
 *
 * This file ensures all ActiveRow classes and traits are loaded.
 */

namespace Italix\Orm\ActiveRow;

// Load base class
require_once __DIR__ . '/ActiveRow.php';

// Load traits
require_once __DIR__ . '/Traits/Persistable.php';
require_once __DIR__ . '/Traits/HasTimestamps.php';
require_once __DIR__ . '/Traits/SoftDeletes.php';
require_once __DIR__ . '/Traits/HasDisplayName.php';
require_once __DIR__ . '/Traits/CanBeAuthor.php';
require_once __DIR__ . '/Traits/HasSlug.php';

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
