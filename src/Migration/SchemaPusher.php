<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Schema Pusher
 * 
 * Pushes schema changes directly to database without migration files.
 * Useful for rapid prototyping and development.
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\DataManager;
use Italix\Orm\Schema\Table;

/**
 * Pushes schema definitions directly to the database.
 */
class SchemaPusher
{
    protected DataManager $dm;
    protected SchemaDiffer $differ;
    protected SchemaIntrospector $introspector;
    protected string $dialect;
    protected bool $output_enabled = true;

    public function __construct(DataManager $dm)
    {
        $this->dm = $dm;
        $this->differ = new SchemaDiffer($dm);
        $this->introspector = $this->differ->get_introspector();
        $this->dialect = $dm->get_driver()->get_dialect_name();
        
        Schema::set_connection($dm);
    }

    /**
     * Bring the database in line with these table declarations.
     *
     * Additive changes — a table that is missing, a column that is missing — are
     * applied. Everything that can lose data is gated, and the two gates are
     * **not the same flag**:
     *
     * | | |
     * |---|---|
     * | `$force` | drop the columns this declaration no longer has |
     * | `$drop_undeclared` **and** `$force` | drop the tables it does not mention |
     *
     * They were one flag, and that was a trap with teeth. `diff()` calls every
     * table it did not receive a "drop", because it cannot tell a partial
     * declaration from a complete one — and passing *part* of a schema is the
     * ordinary case: two or three tables somebody is working on. So
     * `push($some_tables, true)` meant "align these, and delete the rest of the
     * database", which is not what anybody types that line to mean.
     *
     * The author of this method found that out by running it against a
     * development database with 21 tables in it, from a test written to check
     * that it was safe. The backup was good. The flag is now two flags.
     *
     * Changes to an existing column — a type, a length — are **reported and not
     * applied**, whatever the flags: they are dialect-specific and can lose data,
     * and half of a conversion is worse than none of it.
     *
     * @param Table[] $tables the declarations to apply
     * @param bool $force accept the destructive changes shown for these tables
     * @param bool $drop_undeclared also drop tables that are not in $tables
     */
    public function push(array $tables, bool $force = false, bool $drop_undeclared = false): array
    {
        $diff = $this->differ->diff($tables);
        $result = [
            'created_tables' => [],
            'dropped_tables' => [],
            'altered_tables' => [],
            'skipped' => [],
            'errors' => [],
        ];
        
        // Create new tables
        foreach ($diff['create_tables'] as $table_name) {
            try {
                $table = $this->find_table($tables, $table_name);
                if ($table) {
                    $this->create_table($table);
                    $result['created_tables'][] = $table_name;
                    $this->output("Created table: {$table_name}");
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "Failed to create {$table_name}: " . $e->getMessage();
                $this->output("Error creating {$table_name}: " . $e->getMessage());
            }
        }
        
        // Drop tables: only when the caller has said **both** that destructive
        // changes are acceptable and that the tables it did not name are meant
        // to go. See the note on this method for why one flag was not enough.
        foreach ($diff['drop_tables'] as $table_name) {
            if ($force && $drop_undeclared) {
                try {
                    Schema::drop_if_exists($table_name);
                    $result['dropped_tables'][] = $table_name;
                    $this->output("Dropped table: {$table_name}");
                } catch (\Throwable $e) {
                    $result['errors'][] = "Failed to drop {$table_name}: " . $e->getMessage();
                }
            } else {
                $result['skipped'][] = "Would drop table: {$table_name} "
                    . '(needs force AND drop_undeclared — it is not in the declaration you passed)';
                $this->output("Skipped dropping: {$table_name}");
            }
        }
        
        // Alter existing tables
        foreach ($diff['alter_tables'] as $table_name => $changes) {
            try {
                $skipped = [];
                $applied = $this->apply_table_changes($table_name, $changes, $force, $skipped);

                foreach ($skipped as $note) {
                    $result['skipped'][] = $note;
                    $this->output("Skipped: {$note}");
                }

                if (!empty($applied)) {
                    $result['altered_tables'][$table_name] = $applied;
                    $this->output("Altered table: {$table_name}");
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "Failed to alter {$table_name}: " . $e->getMessage();
                $this->output("Error altering {$table_name}: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    /**
     * Preview changes without applying
     * 
     * @param Table[] $tables Array of Table definitions
     * @return array Preview of changes
     */
    public function preview(array $tables): array
    {
        $diff = $this->differ->diff($tables);
        
        $preview = [
            'create_tables' => $diff['create_tables'],
            'drop_tables' => $diff['drop_tables'],
            'alter_tables' => [],
        ];
        
        foreach ($diff['alter_tables'] as $table_name => $changes) {
            $preview['alter_tables'][$table_name] = [];
            
            foreach ($changes['add_columns'] as $col) {
                $preview['alter_tables'][$table_name][] = "+ Add column: {$col['name']} ({$col['type']})";
            }
            
            foreach ($changes['drop_columns'] as $col) {
                $preview['alter_tables'][$table_name][] = "- Drop column: {$col}";
            }
            
            foreach ($changes['modify_columns'] as $col => $mods) {
                $changes_str = [];
                foreach ($mods as $prop => $change) {
                    $changes_str[] = "{$prop}: {$change['from']} → {$change['to']}";
                }
                $preview['alter_tables'][$table_name][] = "~ Modify column: {$col} (" . implode(', ', $changes_str) . ")";
            }
        }
        
        return $preview;
    }

    /**
     * Create a table from Table definition
     */
    protected function create_table(Table $table): void
    {
        $sql = $table->to_create_sql();
        $this->dm->execute($sql);
    }

    /**
     * Apply changes to an existing table
     */
    protected function apply_table_changes(string $table_name, array $changes, bool $force, array &$skipped = []): array
    {
        $applied = [];
        $table_quoted = $this->quote_identifier($table_name);

        // Add new columns
        foreach ($changes['add_columns'] as $col) {
            $col_def = $this->build_column_definition($col);
            $sql = "ALTER TABLE {$table_quoted} ADD COLUMN {$col_def}";
            $this->dm->execute($sql);
            $applied[] = "Added column: {$col['name']}";
        }

        // Drop columns — only with force, and only where the server can.
        foreach ($changes['drop_columns'] as $col_name) {
            if (!$force) {
                // Said out loud. A column that has left the model and stays in
                // the database is a thing to decide about, and silence is the
                // one answer that guarantees nobody does.
                $skipped[] = "Would drop column: {$table_name}.{$col_name} (use force)";
                continue;
            }

            $refusal = $this->cannot_drop_column();

            if ($refusal !== null) {
                $skipped[] = "Cannot drop {$table_name}.{$col_name}: {$refusal}";
                continue;
            }

            $col_quoted = $this->quote_identifier($col_name);
            $this->dm->execute("ALTER TABLE {$table_quoted} DROP COLUMN {$col_quoted}");
            $applied[] = "Dropped column: {$col_name}";
        }

        // Modify columns: reported, never guessed at. Changing a type is
        // dialect-specific and can lose data — half of it applied is worse than
        // none of it, and knowing which is which is the caller's job.
        foreach ($changes['modify_columns'] as $col_name => $mods) {
            $described = [];

            foreach ($mods as $property => $change) {
                $described[] = $property . ': ' . var_export($change['from'], true)
                    . ' → ' . var_export($change['to'], true);
            }

            $note = "Would modify column: {$col_name} (" . implode(', ', $described)
                . ') — manual intervention needed';

            if ($force) {
                $applied[] = $note;
            } else {
                $skipped[] = $note;
            }
        }

        return $applied;
    }

    /**
     * Why this server will not drop a column, or null when it will.
     *
     * SQLite grew `ALTER TABLE … DROP COLUMN` in **3.35**; before that the only
     * way is to rebuild the table, copy the rows and swap it in. That is a
     * migration, with data in it, and doing it silently on the way past is not
     * something a push should decide.
     */
    protected function cannot_drop_column(): ?string
    {
        if ($this->dm->get_driver()->get_dialect_name() !== 'sqlite') {
            return null;
        }

        $version = (string) ($this->dm->query('SELECT sqlite_version() AS v')[0]['v'] ?? '0');

        if (version_compare($version, '3.35', '>=')) {
            return null;
        }

        return "SQLite {$version} has no ALTER TABLE … DROP COLUMN (3.35 added it). "
            . 'Rebuild the table in a migration, where the copy is visible.';
    }

    /**
     * Build column definition SQL
     */
    protected function build_column_definition(array $col): string
    {
        $name = $this->quote_identifier($col['name']);
        $type = strtoupper($col['type']);
        
        // Add length if present
        if (!empty($col['length'])) {
            $type .= "({$col['length']})";
        } elseif (!empty($col['precision'])) {
            $scale = $col['scale'] ?? 0;
            $type .= "({$col['precision']}, {$scale})";
        }
        
        $parts = [$name, $type];
        
        // NULL / NOT NULL
        if ($col['nullable'] ?? false) {
            $parts[] = 'NULL';
        } else {
            $parts[] = 'NOT NULL';
        }
        
        // Default
        if (isset($col['default']) && $col['default'] !== null) {
            $default = $col['default'];
            if (is_bool($default)) {
                $default = $default ? '1' : '0';
            } elseif (!is_numeric($default)) {
                $default = "'" . addslashes($default) . "'";
            }
            $parts[] = "DEFAULT {$default}";
        }
        
        // Unique
        if ($col['unique'] ?? false) {
            $parts[] = 'UNIQUE';
        }
        
        return implode(' ', $parts);
    }

    /**
     * Find table by name in array of tables
     */
    protected function find_table(array $tables, string $name): ?Table
    {
        foreach ($tables as $table) {
            if ($table->get_name() === $name) {
                return $table;
            }
        }
        return null;
    }

    /**
     * Quote identifier for current dialect
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Enable/disable output
     */
    public function set_output(bool $enabled): void
    {
        $this->output_enabled = $enabled;
    }

    /**
     * Output a message
     */
    protected function output(string $message): void
    {
        if ($this->output_enabled) {
            echo $message . "\n";
        }
    }
}
