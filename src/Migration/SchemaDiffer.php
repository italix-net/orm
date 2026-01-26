<?php
/**
 * Italix ORM - Schema Differ
 * 
 * Compares database schemas and generates migration suggestions.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;
use Italix\Orm\Schema\Table;

/**
 * Compares schemas and generates diffs for migration suggestions.
 */
class SchemaDiffer
{
    protected SchemaIntrospector $introspector;
    protected string $dialect;

    public function __construct(IxOrm $db)
    {
        $this->introspector = new SchemaIntrospector($db);
        $this->dialect = $db->get_driver()->get_dialect_name();
    }

    /**
     * Compare defined schema with database and generate diff
     * 
     * @param Table[] $tables Array of Table definitions
     * @return array Diff result with changes
     */
    public function diff(array $tables): array
    {
        $db_tables = $this->introspector->get_tables();
        $defined_tables = [];
        
        foreach ($tables as $table) {
            $defined_tables[$table->get_name()] = $table;
        }
        
        $diff = [
            'create_tables' => [],
            'drop_tables' => [],
            'alter_tables' => [],
        ];
        
        // Tables to create (in definition but not in DB)
        foreach ($defined_tables as $name => $table) {
            if (!in_array($name, $db_tables)) {
                $diff['create_tables'][] = $name;
            }
        }
        
        // Tables to potentially drop (in DB but not in definition)
        foreach ($db_tables as $name) {
            if (!isset($defined_tables[$name])) {
                $diff['drop_tables'][] = $name;
            }
        }
        
        // Tables to alter (exist in both)
        foreach ($defined_tables as $name => $table) {
            if (in_array($name, $db_tables)) {
                $table_diff = $this->diff_table($table);
                if (!empty($table_diff['add_columns']) || 
                    !empty($table_diff['drop_columns']) || 
                    !empty($table_diff['modify_columns']) ||
                    !empty($table_diff['add_indexes']) ||
                    !empty($table_diff['drop_indexes'])) {
                    $diff['alter_tables'][$name] = $table_diff;
                }
            }
        }
        
        return $diff;
    }

    /**
     * Diff a single table against database
     */
    public function diff_table(Table $table): array
    {
        $name = $table->get_name();
        $db_schema = $this->introspector->get_table_schema($name);
        
        $diff = [
            'add_columns' => [],
            'drop_columns' => [],
            'modify_columns' => [],
            'add_indexes' => [],
            'drop_indexes' => [],
            'add_foreign_keys' => [],
            'drop_foreign_keys' => [],
        ];
        
        // Get defined columns
        $defined_columns = [];
        foreach ($table->get_columns() as $col) {
            $defined_columns[$col->get_db_name()] = $col;
        }
        
        // Get DB columns as map
        $db_columns = [];
        foreach ($db_schema['columns'] as $col) {
            $db_columns[$col['name']] = $col;
        }
        
        // Columns to add
        foreach ($defined_columns as $name => $col) {
            if (!isset($db_columns[$name])) {
                $diff['add_columns'][] = $this->column_to_definition($col);
            }
        }
        
        // Columns to drop
        foreach ($db_columns as $name => $col) {
            if (!isset($defined_columns[$name])) {
                $diff['drop_columns'][] = $name;
            }
        }
        
        // Columns that may need modification
        foreach ($defined_columns as $name => $defined_col) {
            if (isset($db_columns[$name])) {
                $changes = $this->diff_column($defined_col, $db_columns[$name]);
                if (!empty($changes)) {
                    $diff['modify_columns'][$name] = $changes;
                }
            }
        }
        
        return $diff;
    }

    /**
     * Diff a single column
     */
    protected function diff_column($defined, array $db_col): array
    {
        $changes = [];
        
        // Get defined column properties
        $def_type = strtoupper($defined->get_type());
        $def_length = $defined->get_length();
        $def_nullable = $defined->is_nullable();
        
        // Compare type (simplified)
        $db_type = strtoupper($db_col['type']);
        if ($this->normalize_type($def_type) !== $this->normalize_type($db_type)) {
            $changes['type'] = ['from' => $db_type, 'to' => $def_type];
        }
        
        // Compare length for string types
        if ($def_length !== null && $db_col['length'] !== null) {
            if ($def_length !== $db_col['length']) {
                $changes['length'] = ['from' => $db_col['length'], 'to' => $def_length];
            }
        }
        
        // Compare nullable
        if ($def_nullable !== $db_col['nullable']) {
            $changes['nullable'] = ['from' => $db_col['nullable'], 'to' => $def_nullable];
        }
        
        return $changes;
    }

    /**
     * Normalize type names for comparison
     */
    protected function normalize_type(string $type): string
    {
        $map = [
            'INT' => 'INTEGER',
            'BOOL' => 'BOOLEAN',
            'SERIAL' => 'INTEGER',
            'BIGSERIAL' => 'BIGINT',
        ];
        
        return $map[$type] ?? $type;
    }

    /**
     * Convert Column object to definition array
     */
    protected function column_to_definition($col): array
    {
        return [
            'name' => $col->get_db_name(),
            'type' => $col->get_type(),
            'length' => $col->get_length(),
            'nullable' => $col->is_nullable(),
            'default' => $col->get_default_value(),
            'primary' => $col->is_primary_key(),
            'auto_increment' => $col->is_auto_increment(),
            'unique' => $col->is_unique(),
        ];
    }

    /**
     * Generate migration code from diff
     */
    public function generate_migration_from_diff(array $diff): string
    {
        $up_lines = [];
        $down_lines = [];
        
        // Create tables
        foreach ($diff['create_tables'] as $table) {
            $up_lines[] = "// Create table: {$table}";
            $up_lines[] = "Schema::create('{$table}', function (Blueprint \$table) {";
            $up_lines[] = "    \$table->id();";
            $up_lines[] = "    \$table->timestamps();";
            $up_lines[] = "});";
            $up_lines[] = "";
            
            $down_lines[] = "Schema::drop_if_exists('{$table}');";
        }
        
        // Drop tables (commented out for safety)
        foreach ($diff['drop_tables'] as $table) {
            $up_lines[] = "// WARNING: Table '{$table}' exists in database but not in schema";
            $up_lines[] = "// Uncomment to drop: Schema::drop_if_exists('{$table}');";
            $up_lines[] = "";
        }
        
        // Alter tables
        foreach ($diff['alter_tables'] as $table => $changes) {
            $up_lines[] = "Schema::table('{$table}', function (Blueprint \$table) {";
            
            foreach ($changes['add_columns'] as $col) {
                $method = $this->type_to_method($col['type']);
                $line = "    \$table->{$method}('{$col['name']}')";
                if ($col['nullable']) $line .= '->nullable()';
                if ($col['unique']) $line .= '->unique()';
                $line .= ';';
                $up_lines[] = $line;
            }
            
            foreach ($changes['drop_columns'] as $col) {
                $up_lines[] = "    // \$table->drop_column('{$col}'); // Uncomment to drop";
            }
            
            foreach ($changes['modify_columns'] as $col => $mods) {
                $up_lines[] = "    // Column '{$col}' may need modification: " . json_encode($mods);
            }
            
            $up_lines[] = "});";
            $up_lines[] = "";
            
            // Down: reverse
            if (!empty($changes['add_columns'])) {
                $down_lines[] = "Schema::table('{$table}', function (Blueprint \$table) {";
                foreach ($changes['add_columns'] as $col) {
                    $down_lines[] = "    \$table->drop_column('{$col['name']}');";
                }
                $down_lines[] = "});";
            }
        }
        
        $class_name = 'AutoGeneratedMigration' . date('YmdHis');
        
        return <<<PHP
<?php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

/**
 * Auto-generated migration based on schema diff.
 * Review carefully before running!
 */
class {$class_name} extends Migration
{
    public function up(): void
    {
        {$this->indent_lines($up_lines, 2)}
    }

    public function down(): void
    {
        {$this->indent_lines($down_lines, 2)}
    }
}

PHP;
    }

    /**
     * Convert type to Blueprint method
     */
    protected function type_to_method(string $type): string
    {
        $map = [
            'INTEGER' => 'integer',
            'INT' => 'integer',
            'BIGINT' => 'big_integer',
            'SMALLINT' => 'small_integer',
            'VARCHAR' => 'string',
            'TEXT' => 'text',
            'BOOLEAN' => 'boolean',
            'TIMESTAMP' => 'timestamp',
            'DATETIME' => 'datetime',
            'DATE' => 'date',
            'DECIMAL' => 'decimal',
            'FLOAT' => 'float',
            'JSON' => 'json',
        ];
        
        return $map[strtoupper($type)] ?? 'string';
    }

    /**
     * Indent lines of code
     */
    protected function indent_lines(array $lines, int $level): string
    {
        if (empty($lines)) {
            return '//';
        }
        
        $indent = str_repeat('    ', $level);
        $first = true;
        $result = '';
        
        foreach ($lines as $line) {
            if ($first) {
                $result .= $line;
                $first = false;
            } else {
                $result .= "\n" . $indent . $line;
            }
        }
        
        return $result;
    }

    /**
     * Get the introspector
     */
    public function get_introspector(): SchemaIntrospector
    {
        return $this->introspector;
    }

    /**
     * Check if database has any tables
     */
    public function has_tables(): bool
    {
        return !empty($this->introspector->get_tables());
    }

    /**
     * Get summary of differences
     */
    public function get_diff_summary(array $diff): array
    {
        $summary = [
            'tables_to_create' => count($diff['create_tables']),
            'tables_to_drop' => count($diff['drop_tables']),
            'tables_to_alter' => count($diff['alter_tables']),
            'total_changes' => 0,
        ];
        
        $summary['total_changes'] = $summary['tables_to_create'] + 
                                   $summary['tables_to_drop'] + 
                                   $summary['tables_to_alter'];
        
        foreach ($diff['alter_tables'] as $changes) {
            $summary['total_changes'] += count($changes['add_columns'] ?? []);
            $summary['total_changes'] += count($changes['drop_columns'] ?? []);
            $summary['total_changes'] += count($changes['modify_columns'] ?? []);
        }
        
        return $summary;
    }
}
