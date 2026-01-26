<?php
/**
 * Italix ORM - Global Factory Functions
 * 
 * These functions provide a convenient way to create ORM instances
 * and are automatically loaded by Composer.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm;

use Italix\Orm\Dialects\Driver;

/**
 * Create an IxOrm instance with configuration
 */
function ix_orm(Driver $driver): IxOrm
{
    return new IxOrm($driver);
}

/**
 * Create a MySQL ORM instance
 */
function mysql(array $config): IxOrm
{
    return new IxOrm(Driver::mysql($config));
}

/**
 * Create a PostgreSQL ORM instance
 */
function postgres(array $config): IxOrm
{
    return new IxOrm(Driver::postgres($config));
}

/**
 * Create a SQLite ORM instance
 */
function sqlite(array $config): IxOrm
{
    return new IxOrm(Driver::sqlite($config));
}

/**
 * Create a SQLite in-memory ORM instance
 */
function sqlite_memory(): IxOrm
{
    return new IxOrm(Driver::sqlite_memory());
}

/**
 * Create a Supabase ORM instance
 */
function supabase(array $config): IxOrm
{
    return new IxOrm(Driver::supabase($config));
}

/**
 * Create a Supabase ORM instance from credentials
 */
function supabase_from_credentials(
    string $project_ref,
    string $password,
    string $database = 'postgres',
    string $region = 'us-east-1',
    bool $pooling = true
): IxOrm {
    return new IxOrm(Driver::supabase_from_credentials(
        $project_ref,
        $password,
        $database,
        $region,
        $pooling
    ));
}

/**
 * Create an ORM instance from a connection string
 */
function from_connection_string(string $connection_string): IxOrm
{
    return new IxOrm(Driver::from_connection_string($connection_string));
}

/**
 * Create a SQL builder for custom queries with safe parameter binding
 * 
 * This function allows building custom SQL while maintaining protection
 * against SQL injection through parameterized queries.
 * 
 * Usage:
 *   // Create SQL fragment
 *   $condition = sql('age > ? AND status = ?', [18, 'active']);
 *   
 *   // Build SQL fluently
 *   $query = sql()
 *       ->append('SELECT * FROM users WHERE ')
 *       ->identifier('email')
 *       ->append(' LIKE ')
 *       ->value('%@gmail.com');
 *   
 *   // Execute with a connection
 *   $db->sql('SELECT * FROM users WHERE id = ?', [$id])->all();
 * 
 * @param string $query SQL query with ? placeholders
 * @param array $params Parameter bindings
 * @return Sql
 */
function sql(string $query = '', array $params = []): Sql
{
    return new Sql($query, $params);
}
