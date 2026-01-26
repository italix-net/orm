<?php
/**
 * Italix ORM - PostgreSQL Dialect
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

/**
 * PostgreSQL database dialect implementation.
 */
class PostgresqlDialect extends BaseDialect
{
    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return 'postgresql';
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
        return '$' . $index;
    }

    /**
     * {@inheritdoc}
     */
    public function supports_returning(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function get_auto_increment_keyword(): string
    {
        return ''; // PostgreSQL uses SERIAL types instead
    }

    /**
     * {@inheritdoc}
     */
    public function get_serial_type(): string
    {
        return 'SERIAL';
    }

    /**
     * {@inheritdoc}
     */
    public function get_current_timestamp_sql(): string
    {
        return 'NOW()';
    }

    /**
     * {@inheritdoc}
     */
    public function build_dsn(array $config): string
    {
        $config = $this->parse_config($config);
        
        $dsn = "pgsql:host={$config['host']};dbname={$config['database']}";
        
        if (!empty($config['port'])) {
            $dsn .= ";port={$config['port']}";
        }
        
        return $dsn;
    }

    /**
     * {@inheritdoc}
     */
    public function get_table_exists_sql(string $table_name): string
    {
        return "SELECT 1 FROM information_schema.tables WHERE table_name = \$1 LIMIT 1";
    }

    /**
     * {@inheritdoc}
     */
    protected function parse_config(array $config): array
    {
        return array_merge([
            'host' => 'localhost',
            'port' => 5432,
            'database' => '',
            'username' => '',
            'password' => '',
        ], $config);
    }
}
