<?php
/**
 * Italix ORM - Dialect Interface
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

/**
 * Interface for database dialect implementations.
 * Each dialect handles database-specific SQL generation and connection management.
 */
interface DialectInterface
{
    /**
     * Get the dialect name
     */
    public function get_name(): string;

    /**
     * Quote an identifier (table name, column name, etc.)
     */
    public function quote_identifier(string $identifier): string;

    /**
     * Get parameter placeholder for prepared statements
     * 
     * @param int $index 1-based parameter index
     */
    public function get_placeholder(int $index): string;

    /**
     * Check if dialect supports RETURNING clause
     */
    public function supports_returning(): bool;

    /**
     * Check if dialect supports LIMIT in DELETE/UPDATE
     */
    public function supports_limit_in_delete(): bool;

    /**
     * Get the auto-increment keyword for column definitions
     */
    public function get_auto_increment_keyword(): string;

    /**
     * Get the serial/auto-increment type name
     */
    public function get_serial_type(): string;

    /**
     * Get SQL for current timestamp default
     */
    public function get_current_timestamp_sql(): string;

    /**
     * Build connection DSN string
     */
    public function build_dsn(array $config): string;

    /**
     * Get default connection options for PDO
     */
    public function get_connection_options(): array;

    /**
     * Get SQL for checking if a table exists
     */
    public function get_table_exists_sql(string $table_name): string;

    /**
     * Get the boolean true value representation
     */
    public function get_boolean_true(): string;

    /**
     * Get the boolean false value representation
     */
    public function get_boolean_false(): string;
}
