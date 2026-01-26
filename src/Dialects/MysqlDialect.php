<?php
/**
 * Italix ORM - MySQL Dialect
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

/**
 * MySQL database dialect implementation.
 */
class MysqlDialect extends BaseDialect
{
    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return 'mysql';
    }

    /**
     * {@inheritdoc}
     */
    public function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function supports_limit_in_delete(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function get_auto_increment_keyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    /**
     * {@inheritdoc}
     */
    public function get_serial_type(): string
    {
        return 'INT AUTO_INCREMENT';
    }

    /**
     * {@inheritdoc}
     */
    public function get_current_timestamp_sql(): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    /**
     * {@inheritdoc}
     */
    public function build_dsn(array $config): string
    {
        $config = $this->parse_config($config);
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']}";
        
        if (!empty($config['port'])) {
            $dsn .= ";port={$config['port']}";
        }
        
        if (!empty($config['charset'])) {
            $dsn .= ";charset={$config['charset']}";
        }
        
        return $dsn;
    }

    /**
     * {@inheritdoc}
     */
    public function get_table_exists_sql(string $table_name): string
    {
        return "SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1";
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
}
