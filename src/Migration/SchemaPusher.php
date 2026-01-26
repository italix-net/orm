<?php
/**
 * Italix ORM - Schema Pusher
 * 
 * Pushes schema changes directly to database without migration files.
 * Useful for rapid prototyping and development.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;
use Italix\Orm\Schema\Table;

/**
 * Pushes schema definitions directly to the database.
 */
class SchemaPusher
{
    protected IxOrm $db;
    protected SchemaDiffer $differ;
    protected SchemaIntrospector $introspector;
    protected string $dialect;
    protected bool $output_enabled = true;

    public function __construct(IxOrm $db)
    {
        $this->db = $db;
        $this->differ = new SchemaDiffer($db);
        $this->introspector = $this->differ->get_introspector();
        $this->dialect = $db->get_driver()->get_dialect_name();
        
        Schema::set_connection($db);
    }

    /**
     * Push schema changes to database
     * 
     * @param Table[] $tables Array of Table definitions
     * @param bool $force Whether to apply destructive changes
     * @return array Result with applied changes
     */
    public function push(array $tables, bool $force = false): array
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
        
        // Drop tables (only with --force)
        foreach ($diff['drop_tables'] as $table_name) {
            if ($force) {
                try {
                    Schema::drop_if_exists($table_name);
                    $result['dropped_tables'][] = $table_name;
                    $this->output("Dropped table: {$table_name}");
                } catch (\Throwable $e) {
                    $result['errors'][] = "Failed to drop {$table_name}: " . $e->getMessage();
                }
            } else {
                $result['skipped'][] = "Would drop table: {$table_name} (use --force)";
                $this->output("Skipped dropping: {$table_name} (use --force)");
            }
        }
        
        // Alter existing tables
        foreach ($diff['alter_tables'] as $table_name => $changes) {
            try {
                $applied = $this->apply_table_changes($table_name, $changes, $force);
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
        $this->db->execute($sql);
    }

    /**
     * Apply changes to an existing table
     */
    protected function apply_table_changes(string $table_name, array $changes, bool $force): array
    {
        $applied = [];
        $table_quoted = $this->quote_identifier($table_name);
        
        // Add new columns
        foreach ($changes['add_columns'] as $col) {
            $col_def = $this->build_column_definition($col);
            $sql = "ALTER TABLE {$table_quoted} ADD COLUMN {$col_def}";
            $this->db->execute($sql);
            $applied[] = "Added column: {$col['name']}";
        }
        
        // Drop columns (only with --force)
        foreach ($changes['drop_columns'] as $col_name) {
            if ($force) {
                $col_quoted = $this->quote_identifier($col_name);
                $sql = "ALTER TABLE {$table_quoted} DROP COLUMN {$col_quoted}";
                $this->db->execute($sql);
                $applied[] = "Dropped column: {$col_name}";
            }
        }
        
        // Modify columns (only with --force, as it may lose data)
        foreach ($changes['modify_columns'] as $col_name => $mods) {
            if ($force) {
                // This is complex and dialect-specific
                // For now, just note it
                $applied[] = "Would modify column: {$col_name} (manual intervention needed)";
            }
        }
        
        return $applied;
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
