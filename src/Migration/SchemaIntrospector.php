<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Schema Introspector
 * 
 * Introspects database schema and generates schema definitions.
 * Used for pull, push, diff, and auto-suggest features.
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\DataManager;

/**
 * Introspects existing database schemas and compares them.
 */
class SchemaIntrospector
{
    /**
     * Every column factory this package ships.
     *
     * Used to work out which ones a generated file imports. Kept as a list
     * rather than derived from the type map, because the map's *values* are the
     * subset a server can report and this is the subset that exists.
     */
    protected const COLUMN_FACTORIES = [
        'integer', 'bigint', 'smallint', 'serial', 'bigserial', 'text', 'varchar', 'char',
        'boolean', 'timestamp', 'datetime', 'date', 'time', 'json', 'jsonb', 'uuid',
        'real', 'double_precision', 'decimal', 'numeric', 'blob', 'binary', 'varbinary',
    ];

    protected DataManager $dm;
    protected string $dialect;

    public function __construct(DataManager $dm)
    {
        $this->dm = $dm;
        $this->dialect = $dm->get_driver()->get_dialect_name();
    }

    /**
     * Get complete schema information for a table
     */
    public function get_table_schema(string $table): array
    {
        return [
            'name' => $table,
            'columns' => $this->get_columns($table),
            'indexes' => $this->get_indexes($table),
            'foreign_keys' => $this->get_foreign_keys($table),
            'primary_key' => $this->get_primary_key($table),
        ];
    }

    /**
     * Get all tables in the database
     */
    public function get_tables(): array
    {
        if ($this->dialect === 'sqlite') {
            $result = $this->dm->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'ix_migrations'"
            );
            return array_column($result, 'name');
        }
        
        if ($this->dialect === 'mysql') {
            // Not `SHOW TABLES`: it lists views too, and a pull then described
            // every view as a table — columns and all, with no hint that the
            // thing has a definition somewhere.
            $result = $this->dm->query(
                'SELECT table_name FROM information_schema.tables '
                . "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' "
                . "AND table_name != 'ix_migrations' ORDER BY table_name"
            );

            return array_map(fn($row) => array_values($row)[0], $result);
        }
        
        // PostgreSQL / Supabase
        $result = $this->dm->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename != 'ix_migrations'"
        );
        return array_column($result, 'tablename');
    }

    /**
     * The views in this database, with the SELECT each one stands for.
     *
     * Every server hands back a definition it has rewritten for itself, and none
     * of them gives back what you typed:
     *
     * | | |
     * |---|---|
     * | SQLite | the original `CREATE VIEW …` text, verbatim, in `sqlite_master` |
     * | MySQL / MariaDB | a normalised SELECT with **the database name written into every reference** |
     * | PostgreSQL | a normalised SELECT, re-indented, with casts made explicit (`'a'::text`) |
     *
     * The MySQL qualification is stripped here. A definition that names the
     * database it was pulled from is a definition that only works in a database
     * with that name, which is not much of a schema.
     *
     * @return array<int, array{name: string, definition: string, materialized: bool}>
     */
    public function get_views(): array
    {
        if ($this->dialect === 'sqlite') {
            $result = $this->dm->query(
                "SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name"
            );

            return array_map(fn(array $row): array => [
                'name'         => $row['name'],
                'definition'   => $this->strip_create_view((string) $row['sql']),
                'materialized' => false,
            ], $result);
        }

        if ($this->dialect === 'mysql') {
            $result = $this->dm->query(
                'SELECT table_name, view_definition FROM information_schema.views '
                . 'WHERE table_schema = DATABASE() ORDER BY table_name'
            );

            $database = (string) ($this->dm->query('SELECT DATABASE() AS d')[0]['d'] ?? '');

            return array_map(fn(array $row): array => [
                'name'         => array_values($row)[0],
                'definition'   => $this->strip_database_qualification((string) array_values($row)[1], $database),
                'materialized' => false,
            ], $result);
        }

        $views = array_map(fn(array $row): array => [
            'name'         => $row['viewname'],
            'definition'   => trim((string) $row['definition']),
            'materialized' => false,
        ], $this->dm->query(
            "SELECT viewname, definition FROM pg_views WHERE schemaname = ANY(current_schemas(false)) ORDER BY viewname"
        ));

        foreach ($this->dm->query(
            'SELECT matviewname, definition FROM pg_matviews '
            . 'WHERE schemaname = ANY(current_schemas(false)) ORDER BY matviewname'
        ) as $row) {
            $views[] = [
                'name'         => $row['matviewname'],
                'definition'   => trim((string) $row['definition']),
                'materialized' => true,
            ];
        }

        return $views;
    }

    /**
     * The columns of a view.
     *
     * The ordinary path works for a plain view everywhere. A **materialized**
     * view on PostgreSQL does not appear in `information_schema.columns` at all
     * — measured, it comes back empty — so that one is read from `pg_attribute`,
     * which is where PostgreSQL actually keeps it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_view_columns(string $view, bool $materialized = false): array
    {
        if (!$materialized || $this->dialect === 'sqlite' || $this->dialect === 'mysql') {
            return $this->get_columns($view);
        }

        $result = $this->dm->query(
            'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type '
            . 'FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid '
            . 'WHERE c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
            [$view]
        );

        $columns = [];

        foreach ($result as $row) {
            $type   = (string) $row['type'];
            $length = null;
            $scale  = null;

            if (preg_match('/^([^(]+)\((\d+)(?:,(\d+))?\)$/', $type, $matches) === 1) {
                $type   = trim($matches[1]);
                $length = (int) $matches[2];
                $scale  = isset($matches[3]) ? (int) $matches[3] : null;
            }

            $columns[] = [
                'name'           => $row['name'],
                'type'           => strtoupper($type),
                'length'         => $scale === null ? $length : null,
                'precision'      => $scale === null ? null : $length,
                'scale'          => $scale,
                'nullable'       => true,
                'default'        => null,
                'primary'        => false,
                'auto_increment' => false,
                'unique'         => false,
            ];
        }

        return $columns;
    }

    /** `CREATE VIEW x AS SELECT …` is a definition with a preamble on it. */
    protected function strip_create_view(string $sql): string
    {
        $position = stripos($sql, ' AS ');

        return $position === false ? trim($sql) : trim(substr($sql, $position + 4));
    }

    /** ``​`db`.`t`.`c` `` is the same column as ``​`t`.`c` ``, in one database fewer. */
    protected function strip_database_qualification(string $definition, string $database): string
    {
        if ($database === '') {
            return $definition;
        }

        return str_replace('`' . $database . '`.', '', $definition);
    }

    /**
     * Get column information for a table
     */
    public function get_columns(string $table): array
    {
        if ($this->dialect === 'sqlite') {
            $columns = $this->get_sqlite_columns($table);
        } elseif ($this->dialect === 'mysql') {
            $columns = $this->get_mysql_columns($table);
        } else {
            $columns = $this->get_postgresql_columns($table);
        }

        return $this->mark_unique_columns($table, $columns);
    }

    /**
     * A column covered by a one-column unique index is a unique column.
     *
     * Only MySQL reports this on the column itself (`Key = 'UNI'`); SQLite and
     * PostgreSQL keep it in the index catalogue, where it was being read and
     * then dropped. A schema pulled without it declares a constraint-free copy
     * of a table that has constraints — which then passes a round trip and
     * fails on the first duplicate.
     *
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, array<string, mixed>>
     */
    protected function mark_unique_columns(string $table, array $columns): array
    {
        $unique = [];

        foreach ($this->get_indexes($table) as $index) {
            if (($index['unique'] ?? false) && count($index['columns']) === 1) {
                $unique[$index['columns'][0]] = true;
            }
        }

        foreach ($columns as $position => $column) {
            if (isset($unique[$column['name']])) {
                $columns[$position]['unique'] = true;
            }
        }

        return $columns;
    }

    /**
     * Get SQLite columns
     */
    protected function get_sqlite_columns(string $table): array
    {
        $result = $this->dm->query("PRAGMA table_info({$table})");
        $columns = [];

        // SQLite's rowid-alias autoincrement behaviour applies only to a
        // table's *sole* INTEGER primary key column — never to a composite
        // one, even though PRAGMA table_info sets `pk` (a 1-based ordinal,
        // not a plain boolean) on every column that is part of one. Counted
        // once, up front: checking a single row's own `pk` in isolation
        // cannot tell "the only primary key column" from "one of several".
        $pk_column_n = 0;

        foreach ($result as $row) {
            if ($row['pk']) {
                $pk_column_n++;
            }
        }

        foreach ($result as $row) {
            $type = strtoupper($row['type']);
            $length = null;
            $precision = null;
            $scale = null;

            // SQLite stores the declared type verbatim, `INTEGER UNSIGNED`
            // included, so the word comes back the way it went in — and has to
            // be lifted out before the rest of the type is parsed.
            $unsigned = strpos($type, 'UNSIGNED') !== false;
            $type     = trim(str_replace('UNSIGNED', '', $type));

            // Parse type with length: VARCHAR(255)
            if (preg_match('/^(\w+)\((\d+)(?:,\s*(\d+))?\)$/', $type, $matches)) {
                $type = $matches[1];
                $length = (int)$matches[2];
                if (isset($matches[3])) {
                    $precision = $length;
                    $scale = (int)$matches[3];
                    $length = null;
                }
            }
            
            $columns[] = [
                'name' => $row['name'],
                'type' => $type,
                'length' => $length,
                'precision' => $precision,
                'scale' => $scale,
                'nullable' => !$row['notnull'],
                'default' => $row['dflt_value'],
                'primary' => (bool)$row['pk'],
                'auto_increment' => $row['pk'] && $pk_column_n === 1 && strpos(strtoupper($row['type']), 'INTEGER') === 0,
                'unsigned' => $unsigned,
                'unique' => false,
            ];
        }
        
        return $columns;
    }

    /**
     * Get MySQL columns
     */
    protected function get_mysql_columns(string $table): array
    {
        $result = $this->dm->query("SHOW FULL COLUMNS FROM {$table}");
        $columns = [];
        
        foreach ($result as $row) {
            $type = strtoupper($row['Type']);
            $length = null;
            $precision = null;
            $scale = null;
            $unsigned = stripos($type, 'UNSIGNED') !== false;
            $enum_values = [];
            
            // Remove unsigned for parsing
            $type = str_ireplace(' UNSIGNED', '', $type);
            
            // Parse enum: ENUM('a','b','c')
            if (preg_match("/^ENUM\((.+)\)$/i", $type, $matches)) {
                $type = 'ENUM';
                preg_match_all("/'([^']+)'/", $matches[1], $enum_matches);
                $enum_values = $enum_matches[1];
            }
            // Parse type with length/precision
            elseif (preg_match('/^(\w+)\((\d+)(?:,\s*(\d+))?\)$/', $type, $matches)) {
                $type = $matches[1];
                $length = (int)$matches[2];
                if (isset($matches[3])) {
                    $precision = $length;
                    $scale = (int)$matches[3];
                    $length = null;
                }
            }
            
            $columns[] = [
                'name' => $row['Field'],
                'type' => $type,
                'length' => $length,
                'precision' => $precision,
                'scale' => $scale,
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primary' => $row['Key'] === 'PRI',
                'auto_increment' => stripos($row['Extra'], 'auto_increment') !== false,
                'unsigned' => $unsigned,
                'unique' => $row['Key'] === 'UNI',
                'comment' => $row['Comment'] ?: null,
                'enum_values' => $enum_values,
            ];
        }
        
        return $columns;
    }

    /**
     * Get PostgreSQL columns
     */
    protected function get_postgresql_columns(string $table): array
    {
        $result = $this->dm->query("
            SELECT 
                c.column_name,
                c.data_type,
                c.character_maximum_length,
                c.numeric_precision,
                c.numeric_scale,
                c.is_nullable,
                c.column_default,
                c.udt_name
            FROM information_schema.columns c
            WHERE c.table_name = ? AND c.table_schema = 'public'
            ORDER BY c.ordinal_position
        ", [$table]);
        
        // Get primary key info
        $pk_result = $this->dm->query("
            SELECT a.attname
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = ?::regclass AND i.indisprimary
        ", [$table]);
        $primary_keys = array_column($pk_result, 'attname');
        
        $columns = [];
        
        foreach ($result as $row) {
            $type = strtoupper($row['data_type']);
            $is_serial = false;
            
            // Detect SERIAL/BIGSERIAL from default
            if ($row['column_default'] && preg_match("/nextval\('.*_seq'/", $row['column_default'])) {
                $is_serial = true;
                if ($type === 'INTEGER') {
                    $type = 'SERIAL';
                } elseif ($type === 'BIGINT') {
                    $type = 'BIGSERIAL';
                }
            }
            
            // Map PostgreSQL types
            $type_map = [
                'CHARACTER VARYING' => 'VARCHAR',
                'CHARACTER' => 'CHAR',
                'TIMESTAMP WITHOUT TIME ZONE' => 'TIMESTAMP',
                'TIMESTAMP WITH TIME ZONE' => 'TIMESTAMPTZ',
                'DOUBLE PRECISION' => 'DOUBLE',
            ];
            $type = $type_map[$type] ?? $type;
            
            $columns[] = [
                'name' => $row['column_name'],
                'type' => $type,
                'length' => $row['character_maximum_length'],
                'precision' => $row['numeric_precision'],
                'scale' => $row['numeric_scale'],
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => $is_serial ? null : $row['column_default'],
                'primary' => in_array($row['column_name'], $primary_keys),
                'auto_increment' => $is_serial,
                'unsigned' => false,
                'unique' => false,
            ];
        }
        
        return $columns;
    }

    /**
     * Get indexes for a table
     */
    public function get_indexes(string $table): array
    {
        if ($this->dialect === 'sqlite') {
            return $this->get_sqlite_indexes($table);
        }
        
        if ($this->dialect === 'mysql') {
            return $this->get_mysql_indexes($table);
        }
        
        return $this->get_postgresql_indexes($table);
    }

    /**
     * Get SQLite indexes
     */
    protected function get_sqlite_indexes(string $table): array
    {
        $result = $this->dm->query("PRAGMA index_list({$table})");
        $indexes = [];
        
        foreach ($result as $row) {
            $cols = $this->dm->query("PRAGMA index_info({$row['name']})");

            // `sqlite_autoindex_*` is what SQLite creates for a UNIQUE column
            // constraint, and it is the only place that constraint is visible —
            // skipping these, as this used to, lost every `unique()` in the
            // schema. Kept, and flagged, so the generator can tell a constraint
            // the user wrote from an index they named.
            $indexes[] = [
                'name' => $row['name'],
                'columns' => array_column($cols, 'name'),
                'unique' => (bool) $row['unique'],
                'primary' => false,
                'implicit' => strpos($row['name'], 'sqlite_autoindex') === 0,
            ];
        }
        
        return $indexes;
    }

    /**
     * Get MySQL indexes
     */
    protected function get_mysql_indexes(string $table): array
    {
        $result = $this->dm->query("SHOW INDEX FROM {$table}");
        $indexes = [];
        
        foreach ($result as $row) {
            $name = $row['Key_name'];
            
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => !$row['Non_unique'],
                    'primary' => $name === 'PRIMARY',
                    'type' => $row['Index_type'] ?? 'BTREE',
                ];
            }
            
            $indexes[$name]['columns'][] = $row['Column_name'];
        }
        
        return array_values($indexes);
    }

    /**
     * Get PostgreSQL indexes
     */
    protected function get_postgresql_indexes(string $table): array
    {
        $result = $this->dm->query("
            SELECT
                i.relname as index_name,
                ix.indisunique as is_unique,
                ix.indisprimary as is_primary,
                array_agg(a.attname ORDER BY x.n) as columns
            FROM pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS x(attnum, n)
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = x.attnum
            WHERE t.relname = ?
            GROUP BY i.relname, ix.indisunique, ix.indisprimary
        ", [$table]);
        
        $indexes = [];
        
        foreach ($result as $row) {
            // Parse array format from PostgreSQL
            $columns = $row['columns'];
            if (is_string($columns)) {
                $columns = trim($columns, '{}');
                $columns = $columns ? explode(',', $columns) : [];
            }
            
            $indexes[] = [
                'name' => $row['index_name'],
                'columns' => $columns,
                'unique' => (bool)$row['is_unique'],
                'primary' => (bool)$row['is_primary'],
            ];
        }
        
        return $indexes;
    }

    /**
     * Get primary key columns for a table
     */
    public function get_primary_key(string $table): array
    {
        $indexes = $this->get_indexes($table);
        
        foreach ($indexes as $index) {
            if ($index['primary'] ?? false) {
                return $index['columns'];
            }
        }
        
        // Fallback: check columns
        $columns = $this->get_columns($table);
        $pk = [];
        
        foreach ($columns as $col) {
            if ($col['primary']) {
                $pk[] = $col['name'];
            }
        }
        
        return $pk;
    }

    /**
     * Get foreign keys for a table
     */
    public function get_foreign_keys(string $table): array
    {
        if ($this->dialect === 'sqlite') {
            return $this->get_sqlite_foreign_keys($table);
        }
        
        if ($this->dialect === 'mysql') {
            return $this->get_mysql_foreign_keys($table);
        }
        
        return $this->get_postgresql_foreign_keys($table);
    }

    /**
     * Get SQLite foreign keys
     */
    protected function get_sqlite_foreign_keys(string $table): array
    {
        $result = $this->dm->query("PRAGMA foreign_key_list({$table})");
        $fks = [];
        
        foreach ($result as $row) {
            $fks[] = [
                'name' => "{$table}_{$row['from']}_foreign",
                'column' => $row['from'],
                'references_table' => $row['table'],
                'references_column' => $row['to'],
                'on_delete' => $row['on_delete'],
                'on_update' => $row['on_update'],
            ];
        }
        
        return $fks;
    }

    /**
     * Get MySQL foreign keys
     */
    protected function get_mysql_foreign_keys(string $table): array
    {
        $result = $this->dm->query("
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = ? 
            AND REFERENCED_TABLE_NAME IS NOT NULL
            AND TABLE_SCHEMA = DATABASE()
        ", [$table]);
        
        // Get ON DELETE/UPDATE actions
        $constraints = $this->dm->query("
            SELECT
                CONSTRAINT_NAME,
                DELETE_RULE,
                UPDATE_RULE
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
            WHERE TABLE_NAME = ?
            AND CONSTRAINT_SCHEMA = DATABASE()
        ", [$table]);
        
        $actions = [];
        foreach ($constraints as $c) {
            $actions[$c['CONSTRAINT_NAME']] = [
                'on_delete' => $c['DELETE_RULE'],
                'on_update' => $c['UPDATE_RULE'],
            ];
        }
        
        $fks = [];
        foreach ($result as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $fks[] = [
                'name' => $name,
                'column' => $row['COLUMN_NAME'],
                'references_table' => $row['REFERENCED_TABLE_NAME'],
                'references_column' => $row['REFERENCED_COLUMN_NAME'],
                'on_delete' => $actions[$name]['on_delete'] ?? 'RESTRICT',
                'on_update' => $actions[$name]['on_update'] ?? 'RESTRICT',
            ];
        }
        
        return $fks;
    }

    /**
     * Get PostgreSQL foreign keys
     */
    protected function get_postgresql_foreign_keys(string $table): array
    {
        $result = $this->dm->query("
            SELECT
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name,
                rc.delete_rule,
                rc.update_rule
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            JOIN information_schema.referential_constraints AS rc
                ON tc.constraint_name = rc.constraint_name
                AND tc.table_schema = rc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' 
            AND tc.table_name = ?
        ", [$table]);
        
        $fks = [];
        foreach ($result as $row) {
            $fks[] = [
                'name' => $row['constraint_name'],
                'column' => $row['column_name'],
                'references_table' => $row['foreign_table_name'],
                'references_column' => $row['foreign_column_name'],
                'on_delete' => $row['delete_rule'],
                'on_update' => $row['update_rule'],
            ];
        }
        
        return $fks;
    }

    /**
     * Generate PHP schema code from database schema
     */
    public function generate_schema_code(?array $tables = null): string
    {
        $tables = $tables ?? $this->get_tables();
        $bodies = [];

        foreach ($tables as $table) {
            $bodies[] = $this->generate_table_code($table);
        }

        // Views last, because they read the tables above them and a file is
        // easier to follow in the order the database would have to be built.
        foreach ($this->get_views() as $view) {
            $bodies[] = $this->generate_view_code($view['name'], $view['materialized']);
        }

        $body = implode("\n\n", $bodies);

        // The import list is read back out of the code that was just generated,
        // rather than being a fixed line. A fixed one imports names the file
        // does not use and — the half that matters — misses the ones it does,
        // which is a fatal error the moment the file is included.
        $used = [];

        if (preg_match_all('/(?:=> |->)?\\b([a-z_]+)\\(/', $body, $matches) !== false) {
            foreach ($matches[1] as $name) {
                if (in_array($name, self::COLUMN_FACTORIES, true)) {
                    $used[$name] = true;
                }
            }
        }

        ksort($used);

        $code  = "<?php\n\n";
        $code .= "use function Italix\\Orm\\Schema\\{$this->get_table_function()};\n";

        foreach (['sqlite_view', 'mysql_view', 'pg_view', 'pg_materialized_view'] as $factory) {
            if (strpos($body, $factory . '(') !== false) {
                $code .= "use function Italix\\Orm\\Schema\\{$factory};\n";
            }
        }

        if ($used !== []) {
            $code .= 'use function Italix\\Orm\\Schema\\{' . implode(', ', array_keys($used)) . "};\n";
        }

        return $code . "\n" . $body . "\n";
    }

    /**
     * Generate PHP code for a single table
     */
    public function generate_table_code(string $table): string
    {
        $schema = $this->get_table_schema($table);
        $func   = $this->get_table_function();

        // A foreign key belongs on the column that carries it, so the two are
        // joined here rather than emitted as a second list nobody reads.
        $references = [];

        foreach ($schema['foreign_keys'] as $foreign_key) {
            $references[$foreign_key['column']] = [
                'table'  => $foreign_key['references_table'],
                'column' => $foreign_key['references_column'],
            ];
        }

        // A composite primary key is declared once, at the table level —
        // see Table::primary_key(). Each component column's own individual
        // ->primary_key() would try to declare a second, single-column
        // primary key on top of it, which to_create_sql() then has to
        // detect and suppress anyway; simpler for the generated code to
        // never write it in the first place.
        $composite_primary_key = count($schema['primary_key']) > 1;

        $variable = $this->variable_name($table);
        $lines    = ["\${$variable} = {$func}('{$table}', ["];

        foreach ($schema['columns'] as $column) {
            $column['references'] = $references[$column['name']] ?? null;
            $lines[]              = "    '{$column['name']}' => "
                . $this->column_to_code($column, $composite_primary_key) . ',';
        }

        $lines[] = ']);';

        if ($composite_primary_key) {
            $columns = "'" . implode("', '", $schema['primary_key']) . "'";
            $lines[] = "\${$variable}->primary_key([{$columns}]);";
        }

        foreach ($this->declarable_indexes($schema) as $index) {
            $columns = "'" . implode("', '", $index['columns']) . "'";
            $method  = ($index['unique'] ?? false) ? 'add_unique' : 'add_index';
            $lines[] = "\${$variable}->{$method}('{$index['name']}', [{$columns}]);";
        }

        return implode("\n", $lines);
    }

    /**
     * One view, as the PHP that declares it.
     *
     * Columns and a definition: the first so the query builder knows what
     * reading it gives back, the second because that is what the view *is*. The
     * definition comes out of the server as the server rewrote it — see
     * {@see get_views()} — so it is emitted as a string rather than as a query
     * this package pretends to have built.
     */
    public function generate_view_code(string $view, bool $materialized = false): string
    {
        $factory = $this->get_view_function($materialized);

        foreach ($this->get_views() as $found) {
            if ($found['name'] === $view) {
                $definition = $found['definition'];
                break;
            }
        }

        if (!isset($definition)) {
            throw new \RuntimeException('There is no view called "' . $view . '" in this database.');
        }

        $variable = $this->variable_name($view);
        $lines    = ["\${$variable} = {$factory}('{$view}', ["];

        foreach ($this->get_view_columns($view, $materialized) as $column) {
            $column['references'] = null;
            $lines[]              = "    '{$column['name']}' => " . $this->column_to_code($column) . ',';
        }

        $lines[] = "])->as_query(" . $this->quote_definition($definition) . ');';

        return implode("\n", $lines);
    }

    /** The factory that makes a view for this dialect. */
    protected function get_view_function(bool $materialized = false): string
    {
        if ($materialized) {
            return 'pg_materialized_view';
        }

        if ($this->dialect === 'mysql') {
            return 'mysql_view';
        }

        return $this->dialect === 'sqlite' ? 'sqlite_view' : 'pg_view';
    }

    /**
     * A definition as a PHP string literal.
     *
     * Single-quoted, with the two characters that matter escaped and nothing
     * else touched: a view's SELECT is full of quotes and backslashes and the
     * point is to hand it back exactly as the server gave it.
     */
    protected function quote_definition(string $definition): string
    {
        $definition = trim(rtrim(trim($definition), ';'));

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $definition) . "'";
    }

    /**
     * The indexes worth writing down.
     *
     * Not the primary key — the column says that. Not a one-column unique index
     * either, for the same reason: `->unique()` on the column already declared
     * it, and declaring it twice would create it twice. What is left is the
     * indexes somebody added on purpose, which are exactly the ones a pull would
     * otherwise lose.
     *
     * @param array<string, mixed> $schema
     * @return array<int, array<string, mixed>>
     */
    protected function declarable_indexes(array $schema): array
    {
        $worth = [];

        // MySQL creates an index behind every foreign key whether you ask or
        // not, and names it after the constraint. Declaring it back would be
        // redundant everywhere and wrong here: the constraint this package
        // generates is unnamed, so the server names the index after the column
        // instead, and the pull would differ from the one before it.
        $foreign_key_columns = [];

        if ($this->dialect === 'mysql') {
            foreach ($schema['foreign_keys'] as $foreign_key) {
                $foreign_key_columns[$foreign_key['column']] = true;
            }
        }

        foreach ($schema['indexes'] as $index) {
            if (
                count($index['columns']) === 1
                && !($index['unique'] ?? false)
                && isset($foreign_key_columns[$index['columns'][0]])
            ) {
                continue;
            }

            if (($index['primary'] ?? false) || ($index['implicit'] ?? false)) {
                continue;
            }

            if (($index['unique'] ?? false) && count($index['columns']) === 1) {
                continue;
            }

            if ($index['columns'] === []) {
                continue;
            }

            $worth[] = $index;
        }

        // By name, because a server returns them in creation order and that is
        // not a fact about the schema: the same tables built in a different
        // order would generate a different file, and a generated file that
        // cannot be diffed is most of the reason to generate one.
        usort($worth, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $worth;
    }

    /** A table name as a PHP variable: `order_items` stays, `public.users` does not. */
    protected function variable_name(string $table): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $table) ?? $table;

        return preg_match('/^[0-9]/', $name) === 1 ? 't_' . $name : $name;
    }

    /**
     * Generate migration code from table schema
     */
    public function generate_migration_code(string $table): string
    {
        $schema = $this->get_table_schema($table);
        
        $lines = ["Schema::create('{$table}', function (Blueprint \$table) {"];
        
        foreach ($schema['columns'] as $col) {
            $lines[] = '    ' . $this->column_to_blueprint($col) . ';';
        }
        
        // Add indexes
        foreach ($schema['indexes'] as $index) {
            if ($index['primary'] ?? false) continue;
            if ($index['unique'] ?? false) {
                $cols = "'" . implode("', '", $index['columns']) . "'";
                $lines[] = "    \$table->unique([{$cols}], '{$index['name']}');";
            } else {
                $cols = "'" . implode("', '", $index['columns']) . "'";
                $lines[] = "    \$table->index([{$cols}], '{$index['name']}');";
            }
        }
        
        // Add foreign keys
        foreach ($schema['foreign_keys'] as $fk) {
            $line = "    \$table->foreign('{$fk['column']}')";
            $line .= "->references('{$fk['references_column']}')";
            $line .= "->on('{$fk['references_table']}')";
            if ($fk['on_delete'] !== 'RESTRICT' && $fk['on_delete'] !== 'NO ACTION') {
                $line .= "->on_delete('{$fk['on_delete']}')";
            }
            $line .= ';';
            $lines[] = $line;
        }
        
        $lines[] = '});';
        
        return implode("\n", $lines);
    }

    /**
     * Convert column info to PHP schema code
     */
    /**
     * One column, as the PHP that declares it.
     *
     * The type map is the whole fidelity of a pull. It used to send `date` and
     * `datetime` to `timestamp()`, `char` to `varchar()`, `float` and `double`
     * to `decimal()`, and `json` to `text()` — every one of those a column this
     * package can express exactly, described as something else. A schema that
     * comes back different from the one that went in is not a schema, and the
     * round trip in `IntrospectionTest` is what says so.
     */
    /** Which dialect this is reading. */
    public function get_dialect(): string
    {
        return $this->dialect;
    }

    /**
     * A server's type name, as the factory this package would declare it with.
     *
     * The one authority on that question: the generator uses it to write a
     * schema, and {@see SchemaDiffer} uses it to compare one against a database.
     * Two answers to "is `TINYINT(1)` a boolean" is one answer too many — that is
     * how a differ starts reporting changes nobody made.
     */
    public static function canonical_type(string $type, ?int $length = null): string
    {
        $type = strtolower(trim($type));

        // `INT UNSIGNED` is an INT. Signedness is a property of the column, kept
        // beside the type rather than inside it — and left in here it made every
        // unsigned column look like a type this package had never heard of.
        $type = trim(str_replace('unsigned', '', $type));

        // MySQL has no boolean: it stores one as TINYINT(1), and reports it that
        // way. The length is the only thing that distinguishes it from a small
        // integer, so it has to be part of the question.
        if ($type === 'tinyint' && $length === 1) {
            return 'boolean';
        }

        $type_map = [
            'int'                => 'integer',
            'int4'               => 'integer',
            'integer'            => 'integer',
            'mediumint'          => 'integer',
            'smallint'           => 'smallint',
            'int2'               => 'smallint',
            'tinyint'            => 'smallint',
            'bigint'             => 'bigint',
            'int8'               => 'bigint',
            'serial'             => 'serial',
            'bigserial'          => 'bigserial',
            'varchar'            => 'varchar',
            'char'               => 'char',
            'bpchar'             => 'char',
            'text'               => 'text',
            'mediumtext'         => 'text',
            'longtext'           => 'text',
            'boolean'            => 'boolean',
            'bool'               => 'boolean',
            'tinyint(1)'         => 'boolean',
            'timestamp'          => 'timestamp',
            'timestamptz'        => 'timestamp',
            'datetime'           => 'datetime',
            'date'               => 'date',
            'time'               => 'time',
            // One type with two names on every server here: PostgreSQL reports
            // a DECIMAL column as `numeric`, and MySQL implements NUMERIC as
            // DECIMAL. Two canonical names for it means a differ reporting a
            // change on a column nobody touched.
            'decimal'            => 'decimal',
            'numeric'            => 'decimal',
            'float'              => 'real',
            'real'               => 'real',
            'float4'             => 'real',
            'double'             => 'double_precision',
            'double precision'   => 'double_precision',
            'float8'             => 'double_precision',
            'json'               => 'json',
            'jsonb'              => 'jsonb',
            'uuid'               => 'uuid',
            'blob'               => 'blob',
            'bytea'              => 'blob',
            'binary'             => 'binary',
            'varbinary'          => 'varbinary',
                ];

        return $type_map[$type] ?? 'text';
    }

    /**
     * @param bool $composite_primary_key This column is one part of a
     *             multi-column primary key, declared separately at the
     *             table level (see `generate_table_code()`) — so this one
     *             column does not also claim `->primary_key()` for itself.
     */
    protected function column_to_code(array $col, bool $composite_primary_key = false): string
    {
        $type = strtolower($col['type']);

        $func = self::canonical_type($type, $col['length'] ?? null);

        if (in_array($func, ['varchar', 'char', 'binary', 'varbinary'], true) && $col['length']) {
            $code = "{$func}({$col['length']})";
        } elseif ($func === 'varchar' || $func === 'char') {
            $code = "{$func}()";
        } elseif (in_array($func, ['decimal', 'numeric'], true) && $col['precision']) {
            $code = "{$func}({$col['precision']}, " . ($col['scale'] ?? 0) . ')';
        } else {
            $code = "{$func}()";
        }

        // serial() and bigserial() already are a primary key that increments.
        $is_serial = in_array($func, ['serial', 'bigserial'], true);

        $declares_primary_here = $col['primary'] && !$is_serial && !$composite_primary_key;

        if ($declares_primary_here) {
            $code .= '->primary_key()';
        }

        if ($col['auto_increment'] && !$is_serial) {
            $code .= '->auto_increment()';
        }

        // ->primary_key() (single-column) already implies NOT NULL. A
        // composite key's component columns are marked primary by the
        // table-level call instead, so their own NOT NULL has to be said
        // here explicitly — the same fix Column::to_sql() needed for the
        // DDL side of the same asymmetry.
        if (!$col['nullable'] && !$declares_primary_here) {
            $code .= '->not_null()';
        }

        if (!empty($col['unsigned'])) {
            $code .= '->unsigned()';
        }

        if ($col['unique'] && !$col['primary']) {
            $code .= '->unique()';
        }

        if (isset($col['references']) && $col['references'] !== null) {
            $code .= "->references('{$col['references']['table']}', '{$col['references']['column']}')";
        }

        if ($col['default'] !== null && !$col['auto_increment'] && !$is_serial) {
            $default = $col['default'];

            if (is_numeric($default)) {
                $code .= "->default({$default})";
            } elseif ($this->is_now_default((string) $default)) {
                // Every server spells this differently — `CURRENT_TIMESTAMP` on
                // SQLite, `current_timestamp()` on MariaDB, `now()` on
                // PostgreSQL — and quoting any of them as a string produces a
                // default the server then refuses: `Invalid default value`.
                $code .= "->default('CURRENT_TIMESTAMP')";
            } else {
                $code .= "->default('" . addslashes($this->unquote_default((string) $default)) . "')";
            }
        }

        return $code;
    }

    /** Is this default the server's way of saying "now"? */
    protected function is_now_default(string $default): bool
    {
        $normalised = strtolower(trim(str_replace(' ', '', $default), '()'));

        return \in_array($normalised, ['current_timestamp', 'now', 'localtimestamp'], true);
    }

    /**
     * A default as the value, not as the server's way of writing it.
     *
     * SQLite hands back `'draft'` with the quotes, PostgreSQL
     * `'draft'::character varying` with quotes and a cast. Passing either through
     * `->default()` stores the punctuation as part of the value.
     */
    protected function unquote_default(string $default): string
    {
        $default = preg_replace('/::[a-zA-Z ]+$/', '', $default) ?? $default;

        if (strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        return $default;
    }

    /**
     * Convert column info to Blueprint method call
     */
    protected function column_to_blueprint(array $col): string
    {
        $type = strtolower($col['type']);
        $name = $col['name'];
        $code = '';
        
        // Special cases
        if ($col['primary'] && $col['auto_increment']) {
            if ($type === 'bigint' || $type === 'bigserial') {
                return "\$table->id('{$name}')";
            }
            return "\$table->id('{$name}')";
        }
        
        // Map type to Blueprint method
        $method_map = [
            'int' => 'integer',
            'integer' => 'integer',
            'bigint' => 'big_integer',
            'smallint' => 'small_integer',
            'tinyint' => 'tiny_integer',
            'varchar' => 'string',
            'char' => 'char',
            'text' => 'text',
            'mediumtext' => 'medium_text',
            'longtext' => 'long_text',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'timestamp' => 'timestamp',
            'timestamptz' => 'timestamp',
            'datetime' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'json' => 'json',
            'jsonb' => 'jsonb',
            'blob' => 'blob',
            'binary' => 'binary',
        ];
        
        $method = $method_map[$type] ?? 'string';
        
        // Build method call
        if ($method === 'string' && $col['length']) {
            $code = "\$table->string('{$name}', {$col['length']})";
        } elseif ($method === 'decimal' && $col['precision']) {
            $scale = $col['scale'] ?? 2;
            $code = "\$table->decimal('{$name}', {$col['precision']}, {$scale})";
        } else {
            $code = "\$table->{$method}('{$name}')";
        }
        
        // Add modifiers
        if ($col['unsigned'] ?? false) {
            $code .= '->unsigned()';
        }
        if ($col['nullable']) {
            $code .= '->nullable()';
        }
        if ($col['unique'] && !$col['primary']) {
            $code .= '->unique()';
        }
        if ($col['default'] !== null) {
            $default = $col['default'];
            if (is_bool($default)) {
                $code .= '->default(' . ($default ? 'true' : 'false') . ')';
            } elseif (is_numeric($default)) {
                $code .= "->default({$default})";
            } elseif (strtoupper($default) === 'CURRENT_TIMESTAMP' || strpos(strtolower($default), 'now()') !== false) {
                $code .= "->default('CURRENT_TIMESTAMP')";
            } else {
                $code .= "->default('" . addslashes($default) . "')";
            }
        }
        if (!empty($col['comment'])) {
            $code .= "->comment('" . addslashes($col['comment']) . "')";
        }
        
        return $code;
    }

    /**
     * Get table function name for dialect
     */
    protected function get_table_function(): string
    {
        return match ($this->dialect) {
            'mysql' => 'mysql_table',
            'sqlite' => 'sqlite_table',
            default => 'pg_table',
        };
    }
}
