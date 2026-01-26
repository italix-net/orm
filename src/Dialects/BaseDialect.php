<?php
/**
 * Italix ORM - Base Dialect
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

use PDO;

/**
 * Abstract base class for database dialects.
 * Provides common functionality and default implementations.
 */
abstract class BaseDialect implements DialectInterface
{
    /**
     * {@inheritdoc}
     */
    public function get_connection_options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_boolean_true(): string
    {
        return 'TRUE';
    }

    /**
     * {@inheritdoc}
     */
    public function get_boolean_false(): string
    {
        return 'FALSE';
    }

    /**
     * {@inheritdoc}
     */
    public function supports_limit_in_delete(): bool
    {
        return false;
    }

    /**
     * Parse connection configuration and set defaults
     */
    protected function parse_config(array $config): array
    {
        return array_merge([
            'host' => 'localhost',
            'port' => null,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ], $config);
    }
}
