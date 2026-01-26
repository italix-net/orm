<?php
/**
 * Italix ORM - Column Type Factory Functions
 * 
 * @package Italix\Orm
 * @license Apache-2.0
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
