<?php
/**
 * Italix ORM - Column Class
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

/**
 * Represents a database column with its type and constraints.
 */
class Column
{
    /** @var string Column name in code */
    protected string $name;
    
    /** @var string Column name in database */
    protected string $db_name;
    
    /** @var string Column type */
    protected string $type;
    
    /** @var bool Is primary key */
    protected bool $is_primary_key = false;
    
    /** @var bool Is auto increment */
    protected bool $is_auto_increment = false;
    
    /** @var bool Is nullable */
    protected bool $is_nullable = true;
    
    /** @var bool Has unique constraint */
    protected bool $is_unique = false;
    
    /** @var mixed Default value */
    protected $default_value = null;
    
    /** @var bool Has default value */
    protected bool $has_default = false;
    
    /** @var int|null Length for varchar/char types */
    protected ?int $length = null;
    
    /** @var int|null Precision for decimal types */
    protected ?int $precision = null;
    
    /** @var int|null Scale for decimal types */
    protected ?int $scale = null;
    
    /** @var Table|null Parent table reference */
    protected ?Table $table = null;
    
    /** @var array Column references for foreign keys */
    protected array $references = [];

    /**
     * Create a new Column instance
     */
    public function __construct(string $type, ?int $length = null)
    {
        $this->type = $type;
        $this->length = $length;
        $this->name = '';
        $this->db_name = '';
    }

    /**
     * Set column name
     */
    public function set_name(string $name): self
    {
        $this->name = $name;
        if (empty($this->db_name)) {
            $this->db_name = $name;
        }
        return $this;
    }

    /**
     * Get column name in code
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Set database column name
     */
    public function set_db_name(string $db_name): self
    {
        $this->db_name = $db_name;
        return $this;
    }

    /**
     * Get database column name
     */
    public function get_db_name(): string
    {
        return $this->db_name ?: $this->name;
    }

    /**
     * Get column type
     */
    public function get_type(): string
    {
        return $this->type;
    }

    /**
     * Get column length
     */
    public function get_length(): ?int
    {
        return $this->length;
    }

    /**
     * Set as primary key
     */
    public function primary_key(): self
    {
        $this->is_primary_key = true;
        $this->is_nullable = false;
        return $this;
    }

    /**
     * Check if primary key
     */
    public function is_primary_key(): bool
    {
        return $this->is_primary_key;
    }

    /**
     * Set as auto increment
     */
    public function auto_increment(): self
    {
        $this->is_auto_increment = true;
        return $this;
    }

    /**
     * Check if auto increment
     */
    public function is_auto_increment(): bool
    {
        return $this->is_auto_increment;
    }

    /**
     * Set as not nullable
     */
    public function not_null(): self
    {
        $this->is_nullable = false;
        return $this;
    }

    /**
     * Check if nullable
     */
    public function is_nullable(): bool
    {
        return $this->is_nullable;
    }

    /**
     * Set as unique
     */
    public function unique(): self
    {
        $this->is_unique = true;
        return $this;
    }

    /**
     * Check if unique
     */
    public function is_unique(): bool
    {
        return $this->is_unique;
    }

    /**
     * Set default value
     * 
     * @param mixed $value
     */
    public function default($value): self
    {
        $this->default_value = $value;
        $this->has_default = true;
        return $this;
    }

    /**
     * Get default value
     * 
     * @return mixed
     */
    public function get_default()
    {
        return $this->default_value;
    }

    /**
     * Check if has default value
     */
    public function has_default(): bool
    {
        return $this->has_default;
    }

    /**
     * Set precision and scale for decimal types
     */
    public function set_precision(int $precision, ?int $scale = null): self
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    /**
     * Get precision
     */
    public function get_precision(): ?int
    {
        return $this->precision;
    }

    /**
     * Get scale
     */
    public function get_scale(): ?int
    {
        return $this->scale;
    }

    /**
     * Set parent table
     */
    public function set_table(Table $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Get parent table
     */
    public function get_table(): ?Table
    {
        return $this->table;
    }

    /**
     * Add foreign key reference
     */
    public function references(string $table, string $column): self
    {
        $this->references = [
            'table' => $table,
            'column' => $column
        ];
        return $this;
    }

    /**
     * Get foreign key references
     */
    public function get_references(): array
    {
        return $this->references;
    }

    /**
     * Generate SQL for column definition
     */
    public function to_sql(string $dialect = 'mysql'): string
    {
        $parts = [];
        
        // Column name
        $quoted_name = $this->quote_identifier($this->get_db_name(), $dialect);
        $parts[] = $quoted_name;
        
        // Type
        $type_sql = $this->get_type_sql($dialect);
        $parts[] = $type_sql;
        
        // Primary key
        if ($this->is_primary_key) {
            $parts[] = 'PRIMARY KEY';
        }
        
        // Auto increment
        if ($this->is_auto_increment) {
            if ($dialect === 'mysql') {
                $parts[] = 'AUTO_INCREMENT';
            } elseif ($dialect === 'sqlite') {
                // SQLite requires AUTOINCREMENT after PRIMARY KEY
                $parts[] = 'AUTOINCREMENT';
            }
            // PostgreSQL uses SERIAL type instead
        }
        
        // Not null
        if (!$this->is_nullable && !$this->is_primary_key) {
            $parts[] = 'NOT NULL';
        }
        
        // Unique
        if ($this->is_unique && !$this->is_primary_key) {
            $parts[] = 'UNIQUE';
        }
        
        // Default
        if ($this->has_default) {
            $default_sql = $this->get_default_sql($dialect);
            $parts[] = 'DEFAULT ' . $default_sql;
        }
        
        return implode(' ', $parts);
    }

    /**
     * Get SQL type for dialect
     */
    protected function get_type_sql(string $dialect): string
    {
        $type = strtoupper($this->type);
        
        // Handle serial/auto-increment types
        if ($this->is_auto_increment && $this->is_primary_key) {
            if ($dialect === 'sqlite') {
                return 'INTEGER';
            }
            if ($dialect === 'postgresql') {
                return $type === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
            }
        }
        
        // Handle length
        if ($this->length !== null && in_array($type, ['VARCHAR', 'CHAR', 'BINARY', 'VARBINARY'])) {
            return $type . '(' . $this->length . ')';
        }
        
        // Handle precision/scale
        if ($this->precision !== null && in_array($type, ['DECIMAL', 'NUMERIC'])) {
            if ($this->scale !== null) {
                return $type . '(' . $this->precision . ',' . $this->scale . ')';
            }
            return $type . '(' . $this->precision . ')';
        }
        
        // Type mappings per dialect
        $type_map = [
            'mysql' => [
                'TEXT' => 'TEXT',
                'BOOLEAN' => 'TINYINT(1)',
                'JSON' => 'JSON',
                'UUID' => 'CHAR(36)',
                'TIMESTAMP' => 'TIMESTAMP',
                'DATETIME' => 'DATETIME',
            ],
            'postgresql' => [
                'TEXT' => 'TEXT',
                'BOOLEAN' => 'BOOLEAN',
                'JSON' => 'JSON',
                'JSONB' => 'JSONB',
                'UUID' => 'UUID',
                'TIMESTAMP' => 'TIMESTAMP',
                'DATETIME' => 'TIMESTAMP',
                'DOUBLE_PRECISION' => 'DOUBLE PRECISION',
            ],
            'sqlite' => [
                'TEXT' => 'TEXT',
                'BOOLEAN' => 'INTEGER',
                'JSON' => 'TEXT',
                'UUID' => 'TEXT',
                'TIMESTAMP' => 'TEXT',
                'DATETIME' => 'TEXT',
                'BIGINT' => 'INTEGER',
                'SMALLINT' => 'INTEGER',
                'DOUBLE_PRECISION' => 'REAL',
            ],
        ];
        
        return $type_map[$dialect][$type] ?? $type;
    }

    /**
     * Get default value SQL
     */
    protected function get_default_sql(string $dialect): string
    {
        $value = $this->default_value;
        
        if ($value === null) {
            return 'NULL';
        }
        
        if (is_bool($value)) {
            if ($dialect === 'mysql' || $dialect === 'sqlite') {
                return $value ? '1' : '0';
            }
            return $value ? 'TRUE' : 'FALSE';
        }
        
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        
        if (is_string($value)) {
            // Check for SQL expressions like NOW(), CURRENT_TIMESTAMP
            $sql_expressions = ['NOW()', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'];
            if (in_array(strtoupper($value), $sql_expressions)) {
                return strtoupper($value);
            }
            // SQLite datetime function
            if (strpos($value, "datetime(") === 0) {
                return $value;
            }
            return "'" . addslashes($value) . "'";
        }
        
        return "'" . addslashes((string)$value) . "'";
    }

    /**
     * Quote identifier based on dialect
     * Properly escapes quote characters to prevent SQL injection
     */
    protected function quote_identifier(string $name, string $dialect): string
    {
        if ($dialect === 'mysql') {
            // MySQL: escape backticks by doubling them
            return '`' . str_replace('`', '``', $name) . '`';
        }
        // PostgreSQL/SQLite: escape double quotes by doubling them
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
