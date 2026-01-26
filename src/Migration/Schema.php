<?php
/**
 * Italix ORM - Schema Facade for Migrations
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;

/**
 * Schema facade for database schema operations.
 * Provides a static interface for migrations.
 */
class Schema
{
    /** @var IxOrm|null Database connection */
    protected static ?IxOrm $db = null;

    /**
     * Set the database connection
     */
    public static function set_connection(IxOrm $db): void
    {
        self::$db = $db;
    }

    /**
     * Get the database connection
     */
    public static function get_connection(): IxOrm
    {
        if (self::$db === null) {
            throw new \RuntimeException('No database connection set for Schema');
        }
        return self::$db;
    }

    /**
     * Get current dialect
     */
    public static function get_dialect(): string
    {
        return self::get_connection()->get_driver()->get_dialect_name();
    }

    /**
     * Create a new table
     */
    public static function create(string $table, callable $callback): void
    {
        $dialect = self::get_dialect();
        $blueprint = new Blueprint($table, $dialect);
        
        $callback($blueprint);
        
        $db = self::get_connection();
        
        // Execute CREATE TABLE
        $sql = $blueprint->to_create_sql();
        $db->execute($sql);
        
        // Execute CREATE INDEX statements
        foreach ($blueprint->to_index_sql() as $index_sql) {
            $db->execute($index_sql);
        }
    }

    /**
     * Create a new table if it doesn't exist
     */
    public static function create_if_not_exists(string $table, callable $callback): void
    {
        if (!self::has_table($table)) {
            self::create($table, $callback);
        }
    }

    /**
     * Modify an existing table
     */
    public static function table(string $table, callable $callback): void
    {
        $dialect = self::get_dialect();
        $blueprint = new Blueprint($table, $dialect);
        
        $callback($blueprint);
        
        $db = self::get_connection();
        
        // Execute ALTER TABLE statements
        foreach ($blueprint->to_alter_sql() as $sql) {
            $db->execute($sql);
        }
    }

    /**
     * Drop a table
     */
    public static function drop(string $table): void
    {
        $dialect = self::get_dialect();
        $table_name = self::quote_identifier($table, $dialect);
        self::get_connection()->execute("DROP TABLE {$table_name}");
    }

    /**
     * Drop a table if it exists
     */
    public static function drop_if_exists(string $table): void
    {
        $dialect = self::get_dialect();
        $table_name = self::quote_identifier($table, $dialect);
        self::get_connection()->execute("DROP TABLE IF EXISTS {$table_name}");
    }

    /**
     * Drop multiple tables
     */
    public static function drop_all_tables(): void
    {
        $tables = self::get_tables();
        
        // Disable foreign key checks
        self::disable_foreign_key_constraints();
        
        foreach ($tables as $table) {
            self::drop($table);
        }
        
        // Re-enable foreign key checks
        self::enable_foreign_key_constraints();
    }

    /**
     * Rename a table
     */
    public static function rename(string $from, string $to): void
    {
        $dialect = self::get_dialect();
        $from_name = self::quote_identifier($from, $dialect);
        $to_name = self::quote_identifier($to, $dialect);
        
        if ($dialect === 'mysql') {
            self::get_connection()->execute("RENAME TABLE {$from_name} TO {$to_name}");
        } else {
            self::get_connection()->execute("ALTER TABLE {$from_name} RENAME TO {$to_name}");
        }
    }

    /**
     * Check if a table exists
     */
    public static function has_table(string $table): bool
    {
        return self::get_connection()->table_exists($table);
    }

    /**
     * Check if a column exists
     */
    public static function has_column(string $table, string $column): bool
    {
        $columns = self::get_columns($table);
        return in_array($column, array_column($columns, 'name'));
    }

    /**
     * Check if columns exist
     */
    public static function has_columns(string $table, array $columns): bool
    {
        $existing = array_column(self::get_columns($table), 'name');
        foreach ($columns as $column) {
            if (!in_array($column, $existing)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get all table names
     */
    public static function get_tables(): array
    {
        $dialect = self::get_dialect();
        $db = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $db->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            );
        } elseif ($dialect === 'mysql') {
            $result = $db->query("SHOW TABLES");
        } else {
            // PostgreSQL
            $result = $db->query(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
            );
        }
        
        return array_map(fn($row) => array_values($row)[0], $result);
    }

    /**
     * Get column information for a table
     */
    public static function get_columns(string $table): array
    {
        $dialect = self::get_dialect();
        $db = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $db->query("PRAGMA table_info({$table})");
            return array_map(fn($row) => [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => !$row['notnull'],
                'default' => $row['dflt_value'],
                'primary' => (bool)$row['pk'],
            ], $result);
        } elseif ($dialect === 'mysql') {
            $result = $db->query("DESCRIBE {$table}");
            return array_map(fn($row) => [
                'name' => $row['Field'],
                'type' => $row['Type'],
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primary' => $row['Key'] === 'PRI',
            ], $result);
        } else {
            // PostgreSQL
            $result = $db->query("
                SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = ?
                ORDER BY ordinal_position
            ", [$table]);
            return array_map(fn($row) => [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => $row['column_default'],
                'primary' => false, // Would need additional query
            ], $result);
        }
    }

    /**
     * Get indexes for a table
     */
    public static function get_indexes(string $table): array
    {
        $dialect = self::get_dialect();
        $db = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $db->query("PRAGMA index_list({$table})");
            return array_map(fn($row) => [
                'name' => $row['name'],
                'unique' => (bool)$row['unique'],
            ], $result);
        } elseif ($dialect === 'mysql') {
            $result = $db->query("SHOW INDEX FROM {$table}");
            $indexes = [];
            foreach ($result as $row) {
                $name = $row['Key_name'];
                if (!isset($indexes[$name])) {
                    $indexes[$name] = [
                        'name' => $name,
                        'unique' => !$row['Non_unique'],
                        'columns' => [],
                    ];
                }
                $indexes[$name]['columns'][] = $row['Column_name'];
            }
            return array_values($indexes);
        } else {
            // PostgreSQL
            $result = $db->query("
                SELECT indexname, indexdef
                FROM pg_indexes
                WHERE tablename = ?
            ", [$table]);
            return array_map(fn($row) => [
                'name' => $row['indexname'],
                'definition' => $row['indexdef'],
            ], $result);
        }
    }

    /**
     * Get foreign keys for a table
     */
    public static function get_foreign_keys(string $table): array
    {
        $dialect = self::get_dialect();
        $db = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $db->query("PRAGMA foreign_key_list({$table})");
            return array_map(fn($row) => [
                'column' => $row['from'],
                'references_table' => $row['table'],
                'references_column' => $row['to'],
                'on_delete' => $row['on_delete'],
                'on_update' => $row['on_update'],
            ], $result);
        } elseif ($dialect === 'mysql') {
            $result = $db->query("
                SELECT 
                    CONSTRAINT_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ", [$table]);
            return array_map(fn($row) => [
                'name' => $row['CONSTRAINT_NAME'],
                'column' => $row['COLUMN_NAME'],
                'references_table' => $row['REFERENCED_TABLE_NAME'],
                'references_column' => $row['REFERENCED_COLUMN_NAME'],
            ], $result);
        } else {
            // PostgreSQL
            $result = $db->query("
                SELECT
                    tc.constraint_name,
                    kcu.column_name,
                    ccu.table_name AS foreign_table_name,
                    ccu.column_name AS foreign_column_name
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name
                JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?
            ", [$table]);
            return array_map(fn($row) => [
                'name' => $row['constraint_name'],
                'column' => $row['column_name'],
                'references_table' => $row['foreign_table_name'],
                'references_column' => $row['foreign_column_name'],
            ], $result);
        }
    }

    /**
     * Disable foreign key constraints
     */
    public static function disable_foreign_key_constraints(): void
    {
        $dialect = self::get_dialect();
        $db = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $db->execute('PRAGMA foreign_keys = OFF');
        } elseif ($dialect === 'mysql') {
            $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        } else {
            // PostgreSQL
            $db->execute('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /**
     * Enable foreign key constraints
     */
    public static function enable_foreign_key_constraints(): void
    {
        $dialect = self::get_dialect();
        $db = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $db->execute('PRAGMA foreign_keys = ON');
        } elseif ($dialect === 'mysql') {
            $db->execute('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            // PostgreSQL
            $db->execute('SET CONSTRAINTS ALL IMMEDIATE');
        }
    }

    /**
     * Quote identifier based on dialect
     */
    protected static function quote_identifier(string $name, string $dialect): string
    {
        if ($dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
