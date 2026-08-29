<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Column Type Factory Functions
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

/**
 * Create an INTEGER column
 */
function integer(): Column
{
    return new Column('INTEGER');
}

/**
 * Create a BIGINT column
 */
function bigint(): Column
{
    return new Column('BIGINT');
}

/**
 * Create a SMALLINT column
 */
function smallint(): Column
{
    return new Column('SMALLINT');
}

/**
 * Create a SERIAL column (auto-incrementing integer)
 */
function serial(): Column
{
    return (new Column('INTEGER'))->primary_key()->auto_increment();
}

/**
 * Create a BIGSERIAL column (auto-incrementing bigint)
 */
function bigserial(): Column
{
    return (new Column('BIGINT'))->primary_key()->auto_increment();
}

/**
 * Create a TEXT column
 */
function text(): Column
{
    return new Column('TEXT');
}

/**
 * Create a VARCHAR column
 */
function varchar(int $length = 255): Column
{
    return new Column('VARCHAR', $length);
}

/**
 * Create a CHAR column
 */
function char(int $length): Column
{
    return new Column('CHAR', $length);
}

/**
 * Create a BOOLEAN column
 */
function boolean(): Column
{
    return new Column('BOOLEAN');
}

/**
 * Create a TIMESTAMP column
 */
function timestamp(): Column
{
    return new Column('TIMESTAMP');
}

/**
 * Create a DATETIME column
 */
function datetime(): Column
{
    return new Column('DATETIME');
}

/**
 * Create a DATE column
 */
function date(): Column
{
    return new Column('DATE');
}

/**
 * Create a TIME column
 */
function time(): Column
{
    return new Column('TIME');
}

/**
 * Create a JSON column
 */
function json(): Column
{
    return new Column('JSON');
}

/**
 * Create a JSONB column (PostgreSQL)
 */
function jsonb(): Column
{
    return new Column('JSONB');
}

/**
 * Create a UUID column
 */
function uuid(): Column
{
    return new Column('UUID');
}

/**
 * Create a REAL column
 */
function real(): Column
{
    return new Column('REAL');
}

/**
 * Create a DOUBLE PRECISION column
 */
function double_precision(): Column
{
    return new Column('DOUBLE_PRECISION');
}

/**
 * Create a DECIMAL column
 */
function decimal(int $precision = 10, int $scale = 2): Column
{
    $col = new Column('DECIMAL');
    $col->set_precision($precision, $scale);
    return $col;
}

/**
 * Create a NUMERIC column (alias for decimal)
 */
function numeric(int $precision = 10, int $scale = 2): Column
{
    $col = new Column('NUMERIC');
    $col->set_precision($precision, $scale);
    return $col;
}

/**
 * Create an ENUM column: the value must be one of `$values`.
 *
 * Native `ENUM(...)` on MySQL. PostgreSQL and SQLite have no equivalent
 * column type, so there it becomes `VARCHAR(255)` plus a `CHECK (col IN
 * (...))` carrying the same values — enforced identically, one SQL type this
 * package does not have to introspect back on those two dialects.
 *
 * A `BackedEnum` class name is accepted in place of the array — the allowed
 * values are read from `$class::cases()`, once, instead of being written a
 * second time by hand where the DDL is declared:
 *
 * ```php
 * enum OrderStatus: string { case Draft = 'draft'; case Placed = 'placed'; }
 *
 * 'status' => enum(OrderStatus::class)->not_null(),
 * ```
 *
 * A column declared this way also hydrates: reading it back gives an
 * `OrderStatus` instance, not the raw string — see `Italix\Orm\Casts\Cast`.
 * A plain `enum(['draft', 'placed'])` has no PHP type to hydrate into and
 * stays a string, exactly as before.
 *
 * @param string[]|class-string<\BackedEnum> $values
 */
function enum($values): Column
{
    if (is_string($values)) {
        if (!enum_exists($values) || !is_subclass_of($values, \BackedEnum::class)) {
            throw new \InvalidArgumentException(
                "enum(): '{$values}' is not a backed enum (enum ... : string|int { ... })."
            );
        }

        $cases = array_map(static fn ($case) => $case->value, $values::cases());

        return (new Column('ENUM'))->enum_values($cases)->enum_class($values);
    }

    return (new Column('ENUM'))->enum_values($values);
}

/**
 * Create a BLOB column
 */
function blob(): Column
{
    return new Column('BLOB');
}

/**
 * Create a BINARY column
 */
function binary(int $length): Column
{
    return new Column('BINARY', $length);
}

/**
 * Create a VARBINARY column
 */
function varbinary(int $length): Column
{
    return new Column('VARBINARY', $length);
}

// ============================================
// Table Factory Functions
// ============================================

/**
 * Create a MySQL table schema
 */
function mysql_table(string $name, array $columns): Table
{
    return new Table($name, $columns, 'mysql');
}

/**
 * Create a PostgreSQL table schema
 */
function pg_table(string $name, array $columns): Table
{
    return new Table($name, $columns, 'postgresql');
}

/**
 * Create a SQLite table schema
 */
function sqlite_table(string $name, array $columns): Table
{
    return new Table($name, $columns, 'sqlite');
}

/**
 * Create a Supabase table schema
 */
function supabase_table(string $name, array $columns): Table
{
    return new Table($name, $columns, 'postgresql');
}

// ============================================
// View Factory Functions
// ============================================

/**
 * Create a MySQL/MariaDB view schema
 */
function mysql_view(string $name, array $columns = []): View
{
    return new View($name, $columns, 'mysql');
}

/**
 * Create a PostgreSQL view schema
 */
function pg_view(string $name, array $columns = []): View
{
    return new View($name, $columns, 'postgresql');
}

/**
 * Create a SQLite view schema
 */
function sqlite_view(string $name, array $columns = []): View
{
    return new View($name, $columns, 'sqlite');
}

/**
 * Create a Supabase view schema
 */
function supabase_view(string $name, array $columns = []): View
{
    return new View($name, $columns, 'postgresql');
}

// ============================================
// Materialized View Factory Functions
// ============================================

/**
 * Create a PostgreSQL materialized view schema
 */
function pg_materialized_view(string $name, array $columns = []): MaterializedView
{
    return new MaterializedView($name, $columns, 'postgresql');
}

/**
 * Create a Supabase materialized view schema
 */
function supabase_materialized_view(string $name, array $columns = []): MaterializedView
{
    return new MaterializedView($name, $columns, 'postgresql');
}
