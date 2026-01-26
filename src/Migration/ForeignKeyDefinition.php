<?php
/**
 * Italix ORM - Foreign Key Definition for Migrations
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

/**
 * Fluent builder for foreign key constraints.
 */
class ForeignKeyDefinition
{
    protected string $column;
    protected ?string $references_column = null;
    protected ?string $references_table = null;
    protected string $on_delete = 'CASCADE';
    protected string $on_update = 'CASCADE';
    protected ?string $name = null;

    public function __construct(string $column)
    {
        $this->column = $column;
    }

    /**
     * Set the referenced column
     */
    public function references(string $column): self
    {
        $this->references_column = $column;
        return $this;
    }

    /**
     * Set the referenced table
     */
    public function on(string $table): self
    {
        $this->references_table = $table;
        return $this;
    }

    /**
     * Set ON DELETE action
     */
    public function on_delete(string $action): self
    {
        $this->on_delete = strtoupper($action);
        return $this;
    }

    /**
     * Set ON UPDATE action
     */
    public function on_update(string $action): self
    {
        $this->on_update = strtoupper($action);
        return $this;
    }

    /**
     * Shortcut: references('id')->on($table)
     * If no table provided, infers from column name (e.g., user_id -> users)
     */
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        $this->references_column = $column;
        
        if ($table !== null) {
            $this->references_table = $table;
        } else {
            // Infer table name from column (user_id -> users)
            $this->references_table = $this->infer_table_name();
        }
        
        return $this;
    }

    /**
     * Set CASCADE on delete
     */
    public function cascade_on_delete(): self
    {
        return $this->on_delete('CASCADE');
    }

    /**
     * Set SET NULL on delete
     */
    public function null_on_delete(): self
    {
        return $this->on_delete('SET NULL');
    }

    /**
     * Set RESTRICT on delete
     */
    public function restrict_on_delete(): self
    {
        return $this->on_delete('RESTRICT');
    }

    /**
     * Set NO ACTION on delete
     */
    public function no_action_on_delete(): self
    {
        return $this->on_delete('NO ACTION');
    }

    /**
     * Set custom constraint name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the local column
     */
    public function get_column(): string
    {
        return $this->column;
    }

    /**
     * Get constraint name
     */
    public function get_name(string $table): string
    {
        if ($this->name !== null) {
            return $this->name;
        }
        
        // Auto-generate name: {table}_{column}_foreign
        return "{$table}_{$this->column}_foreign";
    }

    /**
     * Generate SQL for this foreign key
     */
    public function to_sql(string $table, string $dialect): string
    {
        if ($this->references_table === null || $this->references_column === null) {
            throw new \RuntimeException(
                "Foreign key on '{$this->column}' must specify references() and on()"
            );
        }
        
        $constraint_name = $this->quote_identifier($this->get_name($table), $dialect);
        $column = $this->quote_identifier($this->column, $dialect);
        $ref_table = $this->quote_identifier($this->references_table, $dialect);
        $ref_column = $this->quote_identifier($this->references_column, $dialect);
        
        return "CONSTRAINT {$constraint_name} FOREIGN KEY ({$column}) " .
               "REFERENCES {$ref_table} ({$ref_column}) " .
               "ON DELETE {$this->on_delete} ON UPDATE {$this->on_update}";
    }

    /**
     * Generate ALTER TABLE ADD CONSTRAINT SQL
     */
    public function to_add_sql(string $table, string $dialect): string
    {
        $table_quoted = $this->quote_identifier($table, $dialect);
        return "ALTER TABLE {$table_quoted} ADD " . $this->to_sql($table, $dialect);
    }

    /**
     * Generate ALTER TABLE DROP CONSTRAINT SQL
     */
    public function to_drop_sql(string $table, string $dialect): string
    {
        $table_quoted = $this->quote_identifier($table, $dialect);
        $name = $this->quote_identifier($this->get_name($table), $dialect);
        
        if ($dialect === 'mysql') {
            return "ALTER TABLE {$table_quoted} DROP FOREIGN KEY {$name}";
        }
        
        return "ALTER TABLE {$table_quoted} DROP CONSTRAINT {$name}";
    }

    /**
     * Infer referenced table from column name
     */
    protected function infer_table_name(): string
    {
        // user_id -> users, category_id -> categories
        if (preg_match('/^(.+)_id$/', $this->column, $matches)) {
            $singular = $matches[1];
            // Simple pluralization
            if (substr($singular, -1) === 'y') {
                return substr($singular, 0, -1) . 'ies';
            }
            return $singular . 's';
        }
        
        throw new \RuntimeException(
            "Cannot infer table name from column '{$this->column}'. " .
            "Please specify table name explicitly."
        );
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
}
