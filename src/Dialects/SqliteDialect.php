<?php
/**
 * Italix ORM - SQLite Dialect
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

/**
 * SQLite database dialect implementation.
 */
class SqliteDialect extends BaseDialect
{
    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return 'sqlite';
    }

    /**
     * {@inheritdoc}
     */
    public function quote_identifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * {@inheritdoc}
     */
    public function get_placeholder(int $index): string
    {
        return '?';
    }

    /**
     * {@inheritdoc}
     */
    public function supports_returning(): bool
    {
        return true; // SQLite 3.35.0+ supports RETURNING
    }

    /**
     * {@inheritdoc}
     */
    public function get_auto_increment_keyword(): string
    {
        return 'AUTOINCREMENT';
    }

    /**
     * {@inheritdoc}
     */
    public function get_serial_type(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * {@inheritdoc}
     */
    public function get_current_timestamp_sql(): string
    {
        return "datetime('now')";
    }

    /**
     * {@inheritdoc}
     */
    public function build_dsn(array $config): string
    {
        $database = $config['database'] ?? ':memory:';
        return "sqlite:{$database}";
    }

    /**
     * {@inheritdoc}
     */
    public function get_table_exists_sql(string $table_name): string
    {
        return "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1";
    }

    /**
     * {@inheritdoc}
     */
    public function get_boolean_true(): string
    {
        return '1';
    }

    /**
     * {@inheritdoc}
     */
    public function get_boolean_false(): string
    {
        return '0';
    }

    /**
     * {@inheritdoc}
     */
    public function get_connection_options(): array
    {
        $options = parent::get_connection_options();
        
        // Enable foreign keys for SQLite
        $options[\PDO::SQLITE_ATTR_OPEN_FLAGS] = \PDO::SQLITE_OPEN_READWRITE | \PDO::SQLITE_OPEN_CREATE;
        
        return $options;
    }
}
