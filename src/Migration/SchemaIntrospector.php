<?php
/**
 * Italix ORM - Schema Introspector
 * 
 * Introspects database schema and generates schema definitions.
 * Used for pull, push, diff, and auto-suggest features.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;

/**
 * Introspects existing database schemas and compares them.
 */
class SchemaIntrospector
{
    protected IxOrm $db;
    protected string $dialect;

    public function __construct(IxOrm $db)
    {
        $this->db = $db;
        $this->dialect = $db->get_driver()->get_dialect_name();
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
            $result = $this->db->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'ix_migrations'"
            );
            return array_column($result, 'name');
        }
        
        if ($this->dialect === 'mysql') {
            $result = $this->db->query("SHOW TABLES");
            $tables = array_map(fn($row) => array_values($row)[0], $result);
            return array_filter($tables, fn($t) => $t !== 'ix_migrations');
        }
        
        // PostgreSQL / Supabase
        $result = $this->db->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename != 'ix_migrations'"
        );
        return array_column($result, 'tablename');
    }

    /**
     * Get column information for a table
     */
    public function get_columns(string $table): array
    {
        if ($this->dialect === 'sqlite') {
            return $this->get_sqlite_columns($table);
        }
        
        if ($this->dialect === 'mysql') {
            return $this->get_mysql_columns($table);
        }
        
        return $this->get_postgresql_columns($table);
    }

    /**
     * Get SQLite columns
     */
    protected function get_sqlite_columns(string $table): array
    {
        $result = $this->db->query("PRAGMA table_info({$table})");
        $columns = [];
        
        foreach ($result as $row) {
            $type = strtoupper($row['type']);
            $length = null;
            $precision = null;
            $scale = null;
            
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
                'auto_increment' => $row['pk'] && strtoupper($row['type']) === 'INTEGER',
                'unsigned' => false,
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
        $result = $this->db->query("SHOW FULL COLUMNS FROM {$table}");
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
        $result = $this->db->query("
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
            WHERE c.table_name = \$1 AND c.table_schema = 'public'
            ORDER BY c.ordinal_position
        ", [$table]);
        
        // Get primary key info
        $pk_result = $this->db->query("
            SELECT a.attname
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = \$1::regclass AND i.indisprimary
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
        $result = $this->db->query("PRAGMA index_list({$table})");
        $indexes = [];
        
        foreach ($result as $row) {
            if (strpos($row['name'], 'sqlite_autoindex') === 0) {
                continue;
            }
            
            $cols = $this->db->query("PRAGMA index_info({$row['name']})");
            
            $indexes[] = [
                'name' => $row['name'],
                'columns' => array_column($cols, 'name'),
                'unique' => (bool)$row['unique'],
                'primary' => false,
            ];
        }
        
        return $indexes;
    }

    /**
     * Get MySQL indexes
     */
    protected function get_mysql_indexes(string $table): array
    {
        $result = $this->db->query("SHOW INDEX FROM {$table}");
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
        $result = $this->db->query("
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
            WHERE t.relname = \$1
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
        $result = $this->db->query("PRAGMA foreign_key_list({$table})");
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
        $result = $this->db->query("
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
        $constraints = $this->db->query("
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
        $result = $this->db->query("
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
            AND tc.table_name = \$1
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
        $code = "<?php\n\n";
        $code .= "use function Italix\\Orm\\Schema\\{$this->get_table_function()};\n";
        $code .= "use function Italix\\Orm\\Schema\\{integer, varchar, text, boolean, timestamp, serial, decimal, bigint};\n\n";
        
        foreach ($tables as $table) {
            $code .= $this->generate_table_code($table) . "\n\n";
        }
        
        return $code;
    }

    /**
     * Generate PHP code for a single table
     */
    public function generate_table_code(string $table): string
    {
        $schema = $this->get_table_schema($table);
        $func = $this->get_table_function();
        
        $lines = ["\${$table} = {$func}('{$table}', ["];
        
        foreach ($schema['columns'] as $col) {
            $lines[] = "    '{$col['name']}' => " . $this->column_to_code($col) . ',';
        }
        
        $lines[] = ']);';
        
        return implode("\n", $lines);
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
    protected function column_to_code(array $col): string
    {
        $type = strtolower($col['type']);
        $code = '';
        
        // Map type to function
        $type_map = [
            'int' => 'integer',
            'integer' => 'integer',
            'bigint' => 'bigint',
            'serial' => 'serial',
            'bigserial' => 'serial', // Will use bigint()->auto_increment()
            'varchar' => 'varchar',
            'char' => 'varchar',
            'text' => 'text',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'timestamp' => 'timestamp',
            'timestamptz' => 'timestamp',
            'datetime' => 'timestamp',
            'date' => 'timestamp',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'decimal',
            'double' => 'decimal',
            'json' => 'text',
            'jsonb' => 'text',
        ];
        
        $func = $type_map[$type] ?? 'varchar';
        
        // Build function call
        if ($func === 'varchar' && $col['length']) {
            $code = "varchar({$col['length']})";
        } elseif ($func === 'decimal' && $col['precision']) {
            $scale = $col['scale'] ?? 0;
            $code = "decimal({$col['precision']}, {$scale})";
        } else {
            $code = "{$func}()";
        }
        
        // Add modifiers
        if ($col['primary']) {
            $code .= '->primary_key()';
        }
        if ($col['auto_increment'] && !in_array($type, ['serial', 'bigserial'])) {
            $code .= '->auto_increment()';
        }
        if (!$col['nullable'] && !$col['primary']) {
            $code .= '->not_null()';
        }
        if ($col['unique'] && !$col['primary']) {
            $code .= '->unique()';
        }
        if ($col['default'] !== null && !$col['auto_increment']) {
            $default = $col['default'];
            if (is_numeric($default)) {
                $code .= "->default({$default})";
            } elseif (strtoupper($default) === 'CURRENT_TIMESTAMP' || strpos($default, 'now()') !== false) {
                $code .= "->default('CURRENT_TIMESTAMP')";
            } else {
                $code .= "->default('" . addslashes($default) . "')";
            }
        }
        
        return $code;
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
