<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Schema Facade for Migrations
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\DataManager;
use Italix\Orm\Schema\MaterializedView;
use Italix\Orm\Schema\View;

/**
 * Schema facade for database schema operations.
 * Provides a static interface for migrations.
 */
class Schema
{
    /** @var DataManager|null Database connection */
    protected static ?DataManager $dm = null;

    /**
     * Set the database connection
     */
    public static function set_connection(DataManager $dm): void
    {
        self::$dm = $dm;
    }

    /**
     * Get the database connection
     */
    public static function get_connection(): DataManager
    {
        if (self::$dm === null) {
            throw new \RuntimeException('No database connection set for Schema');
        }
        return self::$dm;
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
        
        $dm = self::get_connection();
        
        // Execute CREATE TABLE
        $sql = $blueprint->to_create_sql();
        $dm->execute($sql);
        
        // Execute CREATE INDEX statements
        foreach ($blueprint->to_index_sql() as $index_sql) {
            $dm->execute($index_sql);
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
        
        $dm = self::get_connection();

        // Execute ALTER TABLE statements
        foreach ($blueprint->to_alter_sql() as $sql) {
            self::assert_alter_is_possible($sql);
            $dm->execute($sql);
        }
    }

    /**
     * Refuse a statement this server cannot run, before it becomes a syntax error.
     *
     * SQLite grew `ALTER TABLE … DROP COLUMN` in **3.35**. Below that the only
     * way is to rebuild the table and copy the rows, which is a migration with
     * data in it — a thing to write on purpose, not to have happen inside a
     * `Schema::table()` call. The server's own answer is `near "DROP": syntax
     * error`, which says nothing about any of that.
     */
    protected static function assert_alter_is_possible(string $sql): void
    {
        if (self::get_dialect() !== 'sqlite' || stripos($sql, 'DROP COLUMN') === false) {
            return;
        }

        $version = (string) (self::get_connection()->query('SELECT sqlite_version() AS v')[0]['v'] ?? '0');

        if (version_compare($version, '3.35', '>=')) {
            return;
        }

        throw new \RuntimeException(
            "SQLite {$version} has no ALTER TABLE … DROP COLUMN (3.35 added it), so this migration "
            . 'cannot run here. Rebuild the table and copy the rows across, where the copy is visible.'
        );
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

    // =========================================
    // Views
    // =========================================

    /**
     * Create a view.
     *
     * The view carries its own dialect; migrations run against one connection,
     * so a view built for another one is refused rather than rendered wrong.
     */
    public static function create_view(View $view): void
    {
        self::refuse_materialized($view, 'create_view');
        self::assert_same_dialect($view);
        self::get_connection()->execute($view->to_create_sql());
    }

    /**
     * Create a view, or redefine it if it is already there.
     *
     * SQLite has no `CREATE OR REPLACE VIEW`, so there it takes two statements
     * — which is why {@see View::to_replace_sql()} returns a list and this runs
     * all of it.
     */
    public static function create_or_replace_view(View $view): void
    {
        self::refuse_materialized($view, 'create_or_replace_view');
        self::assert_same_dialect($view);
        $dm = self::get_connection();

        foreach ($view->to_replace_sql() as $sql) {
            $dm->execute($sql);
        }
    }

    /**
     * Drop a view if it exists.
     *
     * Takes a name or a {@see View}. `DROP TABLE` will not drop a view and
     * `DROP VIEW` will not drop a table, on every dialect we support.
     *
     * @param View|string $view
     */
    public static function drop_view($view): void
    {
        if ($view instanceof View) {
            self::get_connection()->execute($view->to_drop_sql());
            return;
        }

        $name = self::quote_identifier($view, self::get_dialect());
        self::get_connection()->execute("DROP VIEW IF EXISTS {$name}");
    }

    /**
     * Does this view exist?
     *
     * Asked of the catalogue rather than of `table_exists()`, which answers for
     * tables — and on some dialects for views too, which is exactly the
     * ambiguity a migration cannot afford.
     *
     * @param View|string $view
     */
    public static function has_view($view): bool
    {
        // Materialized views live in their own catalogue, so asking pg_views
        // about one would answer "no" about a view that is plainly there.
        if ($view instanceof MaterializedView) {
            return self::has_materialized_view($view);
        }

        $name    = $view instanceof View ? $view->get_name() : $view;
        $dialect = self::get_dialect();
        $dm      = self::get_connection();

        if ($dialect === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type = 'view' AND name = ?";
        } elseif ($dialect === 'mysql') {
            $sql = 'SELECT table_name FROM information_schema.views '
                . 'WHERE table_schema = DATABASE() AND table_name = ?';
        } else {
            $sql = 'SELECT viewname FROM pg_views '
                . 'WHERE viewname = ? AND schemaname = ANY(current_schemas(false))';
        }

        return $dm->query_one($sql, [$name]) !== null;
    }

    /**
     * Every view name in the database.
     *
     * @return string[]
     */
    public static function get_views(): array
    {
        $dialect = self::get_dialect();
        $dm      = self::get_connection();

        if ($dialect === 'sqlite') {
            $result = $dm->query("SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name");
        } elseif ($dialect === 'mysql') {
            $result = $dm->query(
                'SELECT table_name FROM information_schema.views '
                . 'WHERE table_schema = DATABASE() ORDER BY table_name'
            );
        } else {
            $result = $dm->query(
                'SELECT viewname FROM pg_views '
                . 'WHERE schemaname = ANY(current_schemas(false)) ORDER BY viewname'
            );
        }

        return array_map(fn($row) => array_values($row)[0], $result);
    }

    /**
     * A view built for one dialect cannot be created on another: the rendering
     * differs, and the failure would otherwise be a syntax error at the server.
     */
    protected static function assert_same_dialect(View $view): void
    {
        $connection_dialect = self::get_dialect();

        if ($view->get_dialect() !== $connection_dialect) {
            throw new \RuntimeException(
                'The view "' . $view->get_name() . '" is declared for ' . $view->get_dialect()
                . ' and this connection speaks ' . $connection_dialect . '.'
            );
        }
    }

    // =========================================
    // Materialized views (PostgreSQL)
    // =========================================

    /**
     * Create a materialized view, and its indexes.
     *
     * The indexes are part of creating it, not an afterthought: a concurrent
     * refresh needs a unique one, and a view created without it cannot get a
     * concurrent refresh until somebody notices.
     */
    public static function create_materialized_view(MaterializedView $view, bool $if_not_exists = false): void
    {
        self::assert_same_dialect($view);
        $dm = self::get_connection();

        $dm->execute($view->to_create_sql($if_not_exists));

        foreach ($view->get_index_sql() as $sql) {
            $dm->execute($sql);
        }
    }

    /** Create it only if it is not already there. */
    public static function create_materialized_view_if_not_exists(MaterializedView $view): void
    {
        if (self::has_materialized_view($view)) {
            return;
        }

        self::create_materialized_view($view, true);
    }

    /**
     * Redefine it: `DROP` then `CREATE`, because PostgreSQL has no
     * `CREATE OR REPLACE MATERIALIZED VIEW` — measured, not assumed.
     *
     * The rows go with the drop, so the new one is populated by its own
     * `CREATE` (or empty, if the view defers with `with_no_data()`).
     */
    public static function create_or_replace_materialized_view(MaterializedView $view): void
    {
        self::assert_same_dialect($view);
        $dm = self::get_connection();

        foreach ($view->to_replace_sql() as $sql) {
            $dm->execute($sql);
        }

        foreach ($view->get_index_sql() as $sql) {
            $dm->execute($sql);
        }
    }

    /**
     * Recompute the stored rows.
     *
     * `$concurrently` keeps readers unblocked while it runs, and needs a unique
     * index on the view and a view that is already populated. PostgreSQL says
     * which of the two is missing, so this does not guess.
     */
    public static function refresh_materialized_view(MaterializedView $view, bool $concurrently = false): void
    {
        self::assert_same_dialect($view);
        self::get_connection()->execute($view->to_refresh_sql($concurrently));
    }

    /**
     * Drop it if it exists.
     *
     * `DROP VIEW` will not do this — PostgreSQL answers `"x" is not a view`.
     *
     * @param MaterializedView|string $view
     */
    public static function drop_materialized_view($view): void
    {
        if ($view instanceof MaterializedView) {
            self::get_connection()->execute($view->to_drop_sql());
            return;
        }

        $name = self::quote_identifier($view, self::get_dialect());
        self::get_connection()->execute("DROP MATERIALIZED VIEW IF EXISTS {$name}");
    }

    /** @param MaterializedView|string $view */
    public static function has_materialized_view($view): bool
    {
        $name = $view instanceof MaterializedView ? $view->get_name() : $view;

        return self::get_connection()->query_one(
            'SELECT matviewname FROM pg_matviews '
            . 'WHERE matviewname = ? AND schemaname = ANY(current_schemas(false))',
            [$name]
        ) !== null;
    }

    /**
     * Does it hold rows yet?
     *
     * A view created `WITH NO DATA` is not empty — **reading it is an error**
     * until its first refresh. Worth asking before a job assumes otherwise.
     *
     * @param MaterializedView|string $view
     */
    public static function is_materialized_view_populated($view): bool
    {
        $name = $view instanceof MaterializedView ? $view->get_name() : $view;

        $row = self::get_connection()->query_one(
            'SELECT ispopulated FROM pg_matviews '
            . 'WHERE matviewname = ? AND schemaname = ANY(current_schemas(false))',
            [$name]
        );

        if ($row === null) {
            throw new \RuntimeException('There is no materialized view called "' . $name . '" here.');
        }

        return (bool) reset($row);
    }

    /**
     * Every materialized view name in the database.
     *
     * @return string[]
     */
    public static function get_materialized_views(): array
    {
        $result = self::get_connection()->query(
            'SELECT matviewname FROM pg_matviews '
            . 'WHERE schemaname = ANY(current_schemas(false)) ORDER BY matviewname'
        );

        return array_map(fn($row) => array_values($row)[0], $result);
    }

    /** The two kinds share a parent class and nothing else about their DDL. */
    protected static function refuse_materialized(View $view, string $method): void
    {
        if ($view instanceof MaterializedView) {
            throw new \RuntimeException(
                $method . '() is for plain views. "' . $view->get_name() . '" is materialized: use '
                . 'create_materialized_view(), which also creates the indexes a concurrent refresh needs.'
            );
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
        $dm = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $dm->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            );
        } elseif ($dialect === 'mysql') {
            $result = $dm->query("SHOW TABLES");
        } else {
            // PostgreSQL
            $result = $dm->query(
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
        $dm = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $dm->query("PRAGMA table_info({$table})");
            return array_map(fn($row) => [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => !$row['notnull'],
                'default' => $row['dflt_value'],
                'primary' => (bool)$row['pk'],
            ], $result);
        } elseif ($dialect === 'mysql') {
            $result = $dm->query("DESCRIBE {$table}");
            return array_map(fn($row) => [
                'name' => $row['Field'],
                'type' => $row['Type'],
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primary' => $row['Key'] === 'PRI',
            ], $result);
        } else {
            // PostgreSQL
            $result = $dm->query("
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
        $dm = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $dm->query("PRAGMA index_list({$table})");
            return array_map(fn($row) => [
                'name' => $row['name'],
                'unique' => (bool)$row['unique'],
            ], $result);
        } elseif ($dialect === 'mysql') {
            $result = $dm->query("SHOW INDEX FROM {$table}");
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
            $result = $dm->query("
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
        $dm = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $result = $dm->query("PRAGMA foreign_key_list({$table})");
            return array_map(fn($row) => [
                'column' => $row['from'],
                'references_table' => $row['table'],
                'references_column' => $row['to'],
                'on_delete' => $row['on_delete'],
                'on_update' => $row['on_update'],
            ], $result);
        } elseif ($dialect === 'mysql') {
            $result = $dm->query("
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
            $result = $dm->query("
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
        $dm = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $dm->execute('PRAGMA foreign_keys = OFF');
        } elseif ($dialect === 'mysql') {
            $dm->execute('SET FOREIGN_KEY_CHECKS = 0');
        } else {
            // PostgreSQL
            $dm->execute('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /**
     * Enable foreign key constraints
     */
    public static function enable_foreign_key_constraints(): void
    {
        $dialect = self::get_dialect();
        $dm = self::get_connection();
        
        if ($dialect === 'sqlite') {
            $dm->execute('PRAGMA foreign_keys = ON');
        } elseif ($dialect === 'mysql') {
            $dm->execute('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            // PostgreSQL
            $dm->execute('SET CONSTRAINTS ALL IMMEDIATE');
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
