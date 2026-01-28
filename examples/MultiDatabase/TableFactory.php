<?php

/**
 * Table Factory for Multi-Database Compatibility
 *
 * This class creates table definitions that work across all supported databases.
 * Use this pattern to write database-agnostic schema definitions.
 */

namespace App\Schema;

use function Italix\Orm\Schema\{mysql_table, pg_table, sqlite_table};

class TableFactory
{
    /**
     * @var string Database dialect (mysql, postgresql, sqlite, supabase)
     */
    private $dialect;

    /**
     * Create a new TableFactory
     *
     * @param string $dialect Database dialect
     */
    public function __construct(string $dialect)
    {
        $valid_dialects = ['mysql', 'postgresql', 'sqlite', 'supabase'];

        if (!in_array($dialect, $valid_dialects)) {
            throw new \InvalidArgumentException(
                "Invalid dialect: $dialect. Valid options: " . implode(', ', $valid_dialects)
            );
        }

        $this->dialect = $dialect;
    }

    /**
     * Create a table definition for the current dialect
     *
     * @param string $name Table name
     * @param array $columns Column definitions
     * @return mixed Table object
     */
    public function create_table(string $name, array $columns)
    {
        switch ($this->dialect) {
            case 'mysql':
                return mysql_table($name, $columns);

            case 'postgresql':
            case 'supabase':
                return pg_table($name, $columns);

            case 'sqlite':
                return sqlite_table($name, $columns);

            default:
                throw new \RuntimeException("Unsupported dialect: {$this->dialect}");
        }
    }

    /**
     * Get the current dialect
     *
     * @return string
     */
    public function get_dialect(): string
    {
        return $this->dialect;
    }

    /**
     * Check if current dialect is MySQL
     *
     * @return bool
     */
    public function is_mysql(): bool
    {
        return $this->dialect === 'mysql';
    }

    /**
     * Check if current dialect is PostgreSQL (including Supabase)
     *
     * @return bool
     */
    public function is_postgresql(): bool
    {
        return in_array($this->dialect, ['postgresql', 'supabase']);
    }

    /**
     * Check if current dialect is SQLite
     *
     * @return bool
     */
    public function is_sqlite(): bool
    {
        return $this->dialect === 'sqlite';
    }
}
