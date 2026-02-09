<?php
/**
 * Italix ORM - Table Class
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use Italix\Contracts\TableMeta;
use Italix\Contracts\ColumnMeta;
use Italix\Contracts\DelegatedTableMeta;

/**
 * Represents a database table with its columns and constraints.
 *
 * Implements DelegatedTableMeta (which extends TableMeta) for full
 * compatibility with italix/forms library, including delegated types support.
 *
 * @example Basic table
 * $users = mysql_table('users', [
 *     'id'    => serial(),
 *     'name'  => varchar(255)->not_null(),
 *     'email' => varchar(255)->unique(),
 * ]);
 *
 * @example Table with delegated types
 * $things = mysql_table('things', [
 *     'id'        => serial(),
 *     'type'      => varchar(50)->not_null(),
 *     'type_path' => varchar(255),
 *     'name'      => varchar(255)->not_null(),
 * ])
 * ->type_column('type')
 * ->type_path_column('type_path')
 * ->delegate_foreign_key('thing_id')
 * ->delegates([
 *     'Book'  => $books_table,
 *     'Movie' => $movies_table,
 * ]);
 *
 * // Use directly with italix/forms
 * $form = new FormMeta($things);
 * $form->delegate('Book');
 */
class Table implements DelegatedTableMeta
{
    /** @var string Table name */
    protected string $name;

    /** @var string Database dialect */
    protected string $dialect;

    /** @var array<string, Column> Columns */
    protected array $columns = [];

    /** @var string|null Schema name */
    protected ?string $schema = null;

    /** @var array Primary key columns */
    protected array $primary_keys = [];

    /** @var array Unique constraints */
    protected array $unique_constraints = [];

    /** @var array Index definitions */
    protected array $indexes = [];

    /** @var array Foreign key constraints */
    protected array $foreign_keys = [];

    // =========================================
    // Delegated Types Configuration
    // =========================================

    /** @var string|null Type discriminator column */
    protected ?string $dt_type_column = null;

    /** @var string|null Type path column */
    protected ?string $dt_type_path_column = null;

    /** @var string Delegate foreign key column */
    protected string $dt_foreign_key = 'thing_id';

    /** @var array<string, TableMeta> Delegate tables */
    protected array $dt_delegates = [];

    /**
     * Create a new Table instance
     * 
     * @param string $name Table name
     * @param array $columns Column definitions
     * @param string $dialect Database dialect
     */
    public function __construct(string $name, array $columns, string $dialect = 'mysql')
    {
        $this->name = $name;
        $this->dialect = $dialect;
        
        foreach ($columns as $col_name => $column) {
            if ($column instanceof Column) {
                $column->set_name($col_name);
                $column->set_table($this);
                $this->columns[$col_name] = $column;
                
                if ($column->is_primary_key()) {
                    $this->primary_keys[] = $col_name;
                }
            }
        }
    }

    /**
     * Get table name
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Get dialect
     */
    public function get_dialect(): string
    {
        return $this->dialect;
    }

    /**
     * Set schema name
     */
    public function set_schema(string $schema): self
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * Get schema name
     */
    public function get_schema(): ?string
    {
        return $this->schema;
    }

    /**
     * Get full table name (with schema if set)
     */
    public function get_full_name(): string
    {
        if ($this->schema !== null) {
            return $this->schema . '.' . $this->name;
        }
        return $this->name;
    }

    /**
     * Get all columns
     * 
     * @return array<string, Column>
     */
    public function get_columns(): array
    {
        return $this->columns;
    }

    /**
     * Get a specific column
     */
    public function get_column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    // =========================================
    // TableMeta Interface (Forms Integration)
    // =========================================

    /**
     * Return an iterable of column descriptors.
     *
     * Implements TableMeta interface for italix/forms compatibility.
     *
     * @return iterable<string, ColumnMeta>
     */
    public function describe_columns(): iterable
    {
        return $this->columns;
    }

    /**
     * Get a specific column descriptor by name.
     *
     * Implements TableMeta interface for italix/forms compatibility.
     *
     * @param string $name Column name
     * @return ColumnMeta|null
     */
    public function describe_column(string $name): ?ColumnMeta
    {
        return $this->columns[$name] ?? null;
    }

    /**
     * Get primary key columns
     *
     * @return array<string>
     */
    public function get_primary_keys(): array
    {
        return $this->primary_keys;
    }

    // =========================================
    // DelegatedTableMeta Interface
    // =========================================

    /**
     * Get the type discriminator column name.
     *
     * @return string|null
     */
    public function get_type_column(): ?string
    {
        return $this->dt_type_column;
    }

    /**
     * Get the type path column name.
     *
     * @return string|null
     */
    public function get_type_path_column(): ?string
    {
        return $this->dt_type_path_column;
    }

    /**
     * Get the foreign key column name used in delegate tables.
     *
     * @return string
     */
    public function get_delegate_foreign_key(): string
    {
        return $this->dt_foreign_key;
    }

    /**
     * Get the direct delegate sub-tables.
     *
     * @return array<string, TableMeta>
     */
    public function get_delegate_tables(): array
    {
        return $this->dt_delegates;
    }

    // =========================================
    // Delegation Configuration (Fluent API)
    // =========================================

    /**
     * Set the type discriminator column name.
     *
     * @param string $column Column name (e.g., 'type')
     * @return self
     */
    public function type_column(string $column): self
    {
        $this->dt_type_column = $column;
        return $this;
    }

    /**
     * Set the type path column name.
     *
     * @param string|null $column Column name (e.g., 'type_path'), or null to disable
     * @return self
     */
    public function type_path_column(?string $column): self
    {
        $this->dt_type_path_column = $column;
        return $this;
    }

    /**
     * Set the foreign key column name used in delegate tables.
     *
     * @param string $column Column name (e.g., 'thing_id')
     * @return self
     */
    public function delegate_foreign_key(string $column): self
    {
        $this->dt_foreign_key = $column;
        return $this;
    }

    /**
     * Set delegate tables.
     *
     * @param array<string, TableMeta> $delegates Map of type name => table
     * @return self
     */
    public function delegates(array $delegates): self
    {
        $this->dt_delegates = $delegates;
        return $this;
    }

    /**
     * Add a single delegate table.
     *
     * @param string $type_name Type identifier (e.g., 'Book')
     * @param TableMeta $table The delegate table
     * @return self
     */
    public function add_delegate(string $type_name, TableMeta $table): self
    {
        $this->dt_delegates[$type_name] = $table;
        return $this;
    }

    /**
     * Check if this table has delegation configured.
     *
     * @return bool
     */
    public function has_delegates(): bool
    {
        return !empty($this->dt_delegates);
    }

    /**
     * Add a unique constraint
     * 
     * @param string $name Constraint name
     * @param array $columns Column names
     */
    public function add_unique(string $name, array $columns): self
    {
        $this->unique_constraints[$name] = $columns;
        return $this;
    }

    /**
     * Add an index
     * 
     * @param string $name Index name
     * @param array $columns Column names
     */
    public function add_index(string $name, array $columns): self
    {
        $this->indexes[$name] = $columns;
        return $this;
    }

    /**
     * Add a foreign key
     * 
     * @param string $name Constraint name
     * @param string $column Local column
     * @param string $ref_table Referenced table
     * @param string $ref_column Referenced column
     * @param string $on_delete ON DELETE action
     * @param string $on_update ON UPDATE action
     */
    public function add_foreign_key(
        string $name,
        string $column,
        string $ref_table,
        string $ref_column,
        string $on_delete = 'CASCADE',
        string $on_update = 'CASCADE'
    ): self {
        $this->foreign_keys[$name] = [
            'column' => $column,
            'ref_table' => $ref_table,
            'ref_column' => $ref_column,
            'on_delete' => $on_delete,
            'on_update' => $on_update,
        ];
        return $this;
    }

    /**
     * Magic getter for column access
     * 
     * @param string $name
     * @return Column|null
     */
    public function __get(string $name)
    {
        return $this->columns[$name] ?? null;
    }

    /**
     * Check if column exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    /**
     * Generate CREATE TABLE SQL
     */
    public function to_create_sql(): string
    {
        $parts = [];
        $table_name = $this->quote_identifier($this->get_full_name());
        
        $parts[] = "CREATE TABLE IF NOT EXISTS {$table_name} (";
        
        // Column definitions
        $column_defs = [];
        foreach ($this->columns as $column) {
            $column_defs[] = '    ' . $column->to_sql($this->dialect);
        }
        
        // Composite unique constraints
        foreach ($this->unique_constraints as $name => $columns) {
            $cols = array_map(fn($c) => $this->quote_identifier($c), $columns);
            $column_defs[] = '    CONSTRAINT ' . $this->quote_identifier($name) . 
                           ' UNIQUE (' . implode(', ', $cols) . ')';
        }
        
        // Foreign keys
        foreach ($this->foreign_keys as $name => $fk) {
            $column_defs[] = '    CONSTRAINT ' . $this->quote_identifier($name) .
                           ' FOREIGN KEY (' . $this->quote_identifier($fk['column']) . ')' .
                           ' REFERENCES ' . $this->quote_identifier($fk['ref_table']) .
                           '(' . $this->quote_identifier($fk['ref_column']) . ')' .
                           ' ON DELETE ' . $fk['on_delete'] .
                           ' ON UPDATE ' . $fk['on_update'];
        }
        
        $parts[] = implode(",\n", $column_defs);
        $parts[] = ')';
        
        // Engine for MySQL
        if ($this->dialect === 'mysql') {
            $parts[] = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        
        return implode("\n", $parts);
    }

    /**
     * Generate DROP TABLE SQL
     */
    public function to_drop_sql(): string
    {
        $table_name = $this->quote_identifier($this->get_full_name());
        return "DROP TABLE IF EXISTS {$table_name}";
    }

    /**
     * Generate CREATE INDEX statements
     * 
     * @return array<string>
     */
    public function get_index_sql(): array
    {
        $statements = [];
        
        foreach ($this->indexes as $name => $columns) {
            $table_name = $this->quote_identifier($this->get_full_name());
            $idx_name = $this->quote_identifier($name);
            $cols = array_map(fn($c) => $this->quote_identifier($c), $columns);
            
            $statements[] = "CREATE INDEX {$idx_name} ON {$table_name} (" . 
                          implode(', ', $cols) . ')';
        }
        
        return $statements;
    }

    /**
     * Quote identifier based on dialect
     * Properly escapes quote characters to prevent SQL injection
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            // MySQL: escape backticks by doubling them
            return '`' . str_replace('`', '``', $name) . '`';
        }
        // PostgreSQL/SQLite: escape double quotes by doubling them
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
