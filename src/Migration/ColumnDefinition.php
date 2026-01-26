<?php
/**
 * Italix ORM - Column Definition for Migrations
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

/**
 * Fluent builder for column definitions in migrations.
 */
class ColumnDefinition
{
    protected string $name;
    protected string $type;
    protected ?int $length = null;
    protected ?int $precision = null;
    protected ?int $scale = null;
    protected bool $nullable = false;
    protected bool $unsigned = false;
    protected bool $auto_increment = false;
    protected bool $primary = false;
    protected bool $unique = false;
    protected bool $has_default = false;
    /** @var mixed */
    protected $default_value = null;
    protected ?string $after = null;
    protected bool $first = false;
    protected ?string $comment = null;
    protected ?string $charset = null;
    protected ?string $collation = null;
    protected ?string $change_from = null;
    protected array $enum_values = [];

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * Get column name
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Get column type
     */
    public function get_type(): string
    {
        return $this->type;
    }

    /**
     * Set column length
     */
    public function length(int $length): self
    {
        $this->length = $length;
        return $this;
    }

    /**
     * Allow NULL values
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * Disallow NULL values (default)
     */
    public function not_null(): self
    {
        $this->nullable = false;
        return $this;
    }

    /**
     * Set default value
     * @param mixed $value
     */
    public function default($value): self
    {
        $this->has_default = true;
        $this->default_value = $value;
        return $this;
    }

    /**
     * Mark as unique
     */
    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    /**
     * Mark as primary key
     */
    public function primary(): self
    {
        $this->primary = true;
        return $this;
    }

    /**
     * Mark as auto-increment
     */
    public function auto_increment(): self
    {
        $this->auto_increment = true;
        return $this;
    }

    /**
     * Mark as unsigned (MySQL)
     */
    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    /**
     * Place column after another (MySQL)
     */
    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    /**
     * Place column first (MySQL)
     */
    public function first(): self
    {
        $this->first = true;
        return $this;
    }

    /**
     * Add column comment
     */
    public function comment(string $text): self
    {
        $this->comment = $text;
        return $this;
    }

    /**
     * Set charset (MySQL)
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set collation (MySQL)
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set precision and scale for decimal types
     */
    public function precision(int $precision, int $scale = 0): self
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    /**
     * Set enum values
     */
    public function enum_values(array $values): self
    {
        $this->enum_values = $values;
        return $this;
    }

    /**
     * Mark this as a column modification (not creation)
     */
    public function change(): self
    {
        $this->change_from = $this->name;
        return $this;
    }

    /**
     * Generate SQL for this column
     */
    public function to_sql(string $dialect): string
    {
        $parts = [];
        
        // Column name
        $parts[] = $this->quote_identifier($this->name, $dialect);
        
        // Type with length/precision
        $parts[] = $this->get_type_sql($dialect);
        
        // Unsigned (MySQL only)
        if ($this->unsigned && $dialect === 'mysql') {
            $parts[] = 'UNSIGNED';
        }
        
        // Primary key
        if ($this->primary) {
            $parts[] = 'PRIMARY KEY';
        }
        
        // Auto increment
        if ($this->auto_increment) {
            if ($dialect === 'mysql') {
                $parts[] = 'AUTO_INCREMENT';
            } elseif ($dialect === 'sqlite') {
                $parts[] = 'AUTOINCREMENT';
            }
            // PostgreSQL uses SERIAL type
        }
        
        // NULL / NOT NULL
        if (!$this->nullable && !$this->primary) {
            $parts[] = 'NOT NULL';
        } elseif ($this->nullable) {
            $parts[] = 'NULL';
        }
        
        // Unique
        if ($this->unique && !$this->primary) {
            $parts[] = 'UNIQUE';
        }
        
        // Default
        if ($this->has_default) {
            $parts[] = 'DEFAULT ' . $this->format_default($dialect);
        }
        
        // Comment (MySQL)
        if ($this->comment !== null && $dialect === 'mysql') {
            $parts[] = "COMMENT '" . addslashes($this->comment) . "'";
        }
        
        // Collation (MySQL)
        if ($this->collation !== null && $dialect === 'mysql') {
            $parts[] = "COLLATE {$this->collation}";
        }
        
        return implode(' ', $parts);
    }

    /**
     * Generate SQL for ALTER TABLE ADD COLUMN
     */
    public function to_add_sql(string $dialect): string
    {
        $sql = $this->to_sql($dialect);
        
        // MySQL column positioning
        if ($dialect === 'mysql') {
            if ($this->first) {
                $sql .= ' FIRST';
            } elseif ($this->after !== null) {
                $sql .= ' AFTER ' . $this->quote_identifier($this->after, $dialect);
            }
        }
        
        return $sql;
    }

    /**
     * Get SQL type with length/precision
     */
    protected function get_type_sql(string $dialect): string
    {
        $type = strtoupper($this->type);
        
        // Handle auto-increment types
        if ($this->auto_increment && $this->primary) {
            if ($dialect === 'postgresql' || $dialect === 'supabase') {
                return $type === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
            }
            if ($dialect === 'sqlite') {
                return 'INTEGER';
            }
        }
        
        // Handle enum
        if ($type === 'ENUM') {
            if ($dialect === 'mysql') {
                $values = array_map(fn($v) => "'" . addslashes($v) . "'", $this->enum_values);
                return "ENUM(" . implode(', ', $values) . ")";
            } else {
                // PostgreSQL/SQLite: use VARCHAR with CHECK constraint (handled separately)
                return 'VARCHAR(255)';
            }
        }
        
        // Handle types with length
        if ($this->length !== null) {
            return "{$type}({$this->length})";
        }
        
        // Handle types with precision
        if ($this->precision !== null) {
            if ($this->scale !== null && $this->scale > 0) {
                return "{$type}({$this->precision}, {$this->scale})";
            }
            return "{$type}({$this->precision})";
        }
        
        // Map types between dialects
        return $this->map_type($type, $dialect);
    }

    /**
     * Map type names between dialects
     */
    protected function map_type(string $type, string $dialect): string
    {
        $maps = [
            'sqlite' => [
                'BOOLEAN' => 'INTEGER',
                'TINYINT' => 'INTEGER',
                'SMALLINT' => 'INTEGER',
                'MEDIUMINT' => 'INTEGER',
                'BIGINT' => 'INTEGER',
                'DOUBLE' => 'REAL',
                'FLOAT' => 'REAL',
                'DATETIME' => 'TEXT',
                'TIMESTAMP' => 'TEXT',
            ],
            'postgresql' => [
                'TINYINT' => 'SMALLINT',
                'MEDIUMINT' => 'INTEGER',
                'DOUBLE' => 'DOUBLE PRECISION',
                'DATETIME' => 'TIMESTAMP',
                'LONGTEXT' => 'TEXT',
                'MEDIUMTEXT' => 'TEXT',
            ],
        ];
        
        $dialect_map = $maps[$dialect] ?? [];
        return $dialect_map[$type] ?? $type;
    }

    /**
     * Format default value for SQL
     */
    protected function format_default(string $dialect): string
    {
        if ($this->default_value === null) {
            return 'NULL';
        }
        
        if (is_bool($this->default_value)) {
            if ($dialect === 'postgresql' || $dialect === 'supabase') {
                return $this->default_value ? 'TRUE' : 'FALSE';
            }
            return $this->default_value ? '1' : '0';
        }
        
        if (is_numeric($this->default_value)) {
            return (string)$this->default_value;
        }
        
        // SQL expressions
        $expressions = ['CURRENT_TIMESTAMP', 'NOW()', 'CURRENT_DATE', 'CURRENT_TIME', 'UUID()'];
        if (in_array(strtoupper($this->default_value), $expressions)) {
            if ($dialect === 'sqlite' && strtoupper($this->default_value) === 'CURRENT_TIMESTAMP') {
                return "(datetime('now'))";
            }
            if ($dialect === 'postgresql' && strtoupper($this->default_value) === 'CURRENT_TIMESTAMP') {
                return 'NOW()';
            }
            return strtoupper($this->default_value);
        }
        
        // String value
        return "'" . addslashes($this->default_value) . "'";
    }

    /**
     * Quote identifier based on dialect
     */
    protected function quote_identifier(string $name, string $dialect): string
    {
        if ($dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    // ============================================
    // Getters for introspection
    // ============================================

    public function is_nullable(): bool { return $this->nullable; }
    public function is_unsigned(): bool { return $this->unsigned; }
    public function is_auto_increment(): bool { return $this->auto_increment; }
    public function is_primary(): bool { return $this->primary; }
    public function is_unique(): bool { return $this->unique; }
    public function has_default(): bool { return $this->has_default; }
    public function get_default() { return $this->default_value; }
    public function get_length(): ?int { return $this->length; }
    public function get_precision(): ?int { return $this->precision; }
    public function get_scale(): ?int { return $this->scale; }
    public function get_after(): ?string { return $this->after; }
    public function is_first(): bool { return $this->first; }
    public function get_comment(): ?string { return $this->comment; }
    public function is_change(): bool { return $this->change_from !== null; }
}
