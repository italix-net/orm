<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Blueprint for Migrations
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

/**
 * Blueprint for defining table structure in migrations.
 * Provides a fluent interface for creating and modifying tables.
 */
class Blueprint
{
    protected string $table;
    protected string $dialect;
    
    /** @var ColumnDefinition[] */
    protected array $columns = [];
    
    /** @var array Index definitions */
    protected array $indexes = [];
    
    /** @var ForeignKeyDefinition[] */
    protected array $foreign_keys = [];

    /** @var array<int, array{name: string, expression: string}> Table-level CHECK constraints */
    protected array $checks = [];
    
    /** @var array Columns to drop */
    protected array $drop_columns = [];
    
    /** @var array Indexes to drop */
    protected array $drop_indexes = [];
    
    /** @var array Foreign keys to drop */
    protected array $drop_foreign_keys = [];
    
    /** @var array Column renames [from => to] */
    protected array $renames = [];
    
    /** @var array Primary key columns */
    protected array $primary_columns = [];
    
    /** @var string|null Table comment */
    protected ?string $comment = null;
    
    /** @var string|null Table engine (MySQL) */
    protected ?string $engine = null;
    
    /** @var string|null Table charset (MySQL) */
    protected ?string $charset = null;
    
    /** @var string|null Table collation (MySQL) */
    protected ?string $collation = null;

    public function __construct(string $table, string $dialect = 'mysql')
    {
        $this->table = $table;
        $this->dialect = $dialect;
    }

    // ============================================
    // Primary Key / ID Columns
    // ============================================

    /**
     * Add auto-incrementing big integer primary key
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->big_integer($name)->unsigned()->auto_increment()->primary();
    }

    /**
     * Add UUID primary key
     */
    public function uuid(string $name = 'id'): ColumnDefinition
    {
        $col = $this->add_column($name, 'UUID');
        if ($this->dialect === 'mysql') {
            $col = $this->add_column($name, 'CHAR');
            $col->length(36);
        }
        return $col->primary();
    }

    /**
     * Add foreign ID column (unsigned big integer)
     */
    public function foreign_id(string $name): ColumnDefinition
    {
        return $this->unsigned_big_integer($name);
    }

    // ============================================
    // Integer Types
    // ============================================

    /**
     * Add tiny integer column
     */
    public function tiny_integer(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'TINYINT');
    }

    /**
     * Add small integer column
     */
    public function small_integer(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'SMALLINT');
    }

    /**
     * Add integer column
     */
    public function integer(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'INTEGER');
    }

    /**
     * Add medium integer column
     */
    public function medium_integer(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'MEDIUMINT');
    }

    /**
     * Add big integer column
     */
    public function big_integer(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'BIGINT');
    }

    /**
     * Add unsigned big integer column
     */
    public function unsigned_big_integer(string $name): ColumnDefinition
    {
        return $this->big_integer($name)->unsigned();
    }

    /**
     * Add unsigned integer column
     */
    public function unsigned_integer(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    // ============================================
    // String Types
    // ============================================

    /**
     * Add string (VARCHAR) column
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add_column($name, 'VARCHAR')->length($length);
    }

    /**
     * Add char column
     */
    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add_column($name, 'CHAR')->length($length);
    }

    /**
     * Add text column
     */
    public function text(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'TEXT');
    }

    /**
     * Add medium text column
     */
    public function medium_text(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'MEDIUMTEXT');
    }

    /**
     * Add long text column
     */
    public function long_text(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'LONGTEXT');
    }

    // ============================================
    // Numeric Types
    // ============================================

    /**
     * Add decimal column
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->add_column($name, 'DECIMAL')->precision($precision, $scale);
    }

    /**
     * Add float column
     */
    public function float(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'FLOAT');
    }

    /**
     * Add double column
     */
    public function double(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'DOUBLE');
    }

    // ============================================
    // Boolean Type
    // ============================================

    /**
     * Add boolean column
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'BOOLEAN');
    }

    // ============================================
    // Date/Time Types
    // ============================================

    /**
     * Add date column
     */
    public function date(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'DATE');
    }

    /**
     * Add time column
     */
    public function time(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'TIME');
    }

    /**
     * Add datetime column
     */
    public function datetime(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'DATETIME');
    }

    /**
     * Add timestamp column
     */
    public function timestamp(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'TIMESTAMP');
    }

    /**
     * Add created_at and updated_at timestamp columns
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $this->timestamp('updated_at')->nullable()->default('CURRENT_TIMESTAMP');
    }

    /**
     * Add deleted_at timestamp column (soft deletes)
     */
    public function soft_deletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($name)->nullable();
    }

    // ============================================
    // JSON Type
    // ============================================

    /**
     * Add JSON column
     */
    public function json(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'JSON');
    }

    /**
     * Add JSONB column (PostgreSQL)
     */
    public function jsonb(string $name): ColumnDefinition
    {
        $type = ($this->dialect === 'postgresql' || $this->dialect === 'supabase') ? 'JSONB' : 'JSON';
        return $this->add_column($name, $type);
    }

    // ============================================
    // Binary Types
    // ============================================

    /**
     * Add binary column
     */
    public function binary(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add_column($name, 'BINARY')->length($length);
    }

    /**
     * Add blob column
     */
    public function blob(string $name): ColumnDefinition
    {
        return $this->add_column($name, 'BLOB');
    }

    // ============================================
    // Enum Type
    // ============================================

    /**
     * Add enum column
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        return $this->add_column($name, 'ENUM')->enum_values($values);
    }

    // ============================================
    // Indexes
    // ============================================

    /**
     * Add primary key
     * @param string|array $columns
     */
    public function primary($columns, ?string $name = null): self
    {
        $columns = (array)$columns;
        $this->primary_columns = $columns;
        return $this;
    }

    /**
     * Add unique index
     * @param string|array $columns
     */
    public function unique($columns, ?string $name = null): self
    {
        $columns = (array)$columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_unique';
        $this->indexes[] = [
            'type' => 'UNIQUE',
            'name' => $name,
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * Add index
     * @param string|array $columns
     */
    public function index($columns, ?string $name = null): self
    {
        $columns = (array)$columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_index';
        $this->indexes[] = [
            'type' => 'INDEX',
            'name' => $name,
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * Add a full-text search index over `$columns` — a native `FULLTEXT
     * INDEX` on MySQL, a GIN index over `to_tsvector(...)` on
     * PostgreSQL/Supabase, and an external-content FTS5 virtual table plus
     * sync triggers on SQLite (which has no full-text index at all — see
     * `fulltext_index_sql()`). Search it with `Operators\fulltext_match()`.
     *
     * @param string|array $columns
     */
    public function fulltext($columns, ?string $name = null): self
    {
        $columns = (array)$columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_fulltext';
        $this->indexes[] = [
            'type' => 'FULLTEXT',
            'name' => $name,
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * Add a table-level `CHECK` constraint: `$expression` is rendered
     * verbatim inside `CHECK (...)`.
     *
     * Reach for this over a column's own `->check()` when the rule spans
     * more than one column — `$table->check('start_dt < end_dt')` cannot be
     * attached to either column alone, only to the row. Refused when this
     * blueprint is building `ALTER TABLE` for SQLite — see `to_alter_sql()`.
     */
    public function check(string $expression, ?string $name = null): self
    {
        $name = $name ?? $this->table . '_check_' . (count($this->checks) + 1);
        $this->checks[] = ['name' => $name, 'expression' => $expression];
        return $this;
    }

    // ============================================
    // Foreign Keys
    // ============================================

    /**
     * Add foreign key constraint
     */
    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreign_keys[] = $fk;
        return $fk;
    }

    // ============================================
    // Modifications (for alter table)
    // ============================================

    /**
     * Drop a column
     */
    public function drop_column(string $name): self
    {
        $this->drop_columns[] = $name;
        return $this;
    }

    /**
     * Drop multiple columns
     */
    public function drop_columns(array $names): self
    {
        $this->drop_columns = array_merge($this->drop_columns, $names);
        return $this;
    }

    /**
     * Rename a column
     */
    public function rename_column(string $from, string $to): self
    {
        $this->renames[$from] = $to;
        return $this;
    }

    /**
     * Drop an index
     */
    public function drop_index(string $name): self
    {
        $this->drop_indexes[] = $name;
        return $this;
    }

    /**
     * Drop a foreign key constraint
     */
    public function drop_foreign(string $name): self
    {
        $this->drop_foreign_keys[] = $name;
        return $this;
    }

    /**
     * Drop primary key
     */
    public function drop_primary(): self
    {
        $this->drop_indexes[] = 'PRIMARY';
        return $this;
    }

    // ============================================
    // Table Options
    // ============================================

    /**
     * Set table comment
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Set table engine (MySQL)
     */
    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set table charset (MySQL)
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set table collation (MySQL)
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    // ============================================
    // SQL Generation
    // ============================================

    /**
     * Generate CREATE TABLE SQL
     */
    public function to_create_sql(): string
    {
        $table_name = $this->quote_identifier($this->table, $this->dialect);
        $lines = [];
        
        // Column definitions
        foreach ($this->columns as $column) {
            $lines[] = '    ' . $column->to_sql($this->dialect);
        }
        
        // Composite primary key (if not defined on column)
        if (!empty($this->primary_columns)) {
            $cols = array_map(fn($c) => $this->quote_identifier($c, $this->dialect), $this->primary_columns);
            $lines[] = '    PRIMARY KEY (' . implode(', ', $cols) . ')';
        }
        
        // Unique constraints
        foreach ($this->indexes as $index) {
            if ($index['type'] === 'UNIQUE') {
                $cols = array_map(fn($c) => $this->quote_identifier($c, $this->dialect), $index['columns']);
                $name = $this->quote_identifier($index['name'], $this->dialect);
                $lines[] = "    CONSTRAINT {$name} UNIQUE (" . implode(', ', $cols) . ')';
            }
        }
        
        // Foreign keys
        foreach ($this->foreign_keys as $fk) {
            $lines[] = '    ' . $fk->to_sql($this->table, $this->dialect);
        }

        // Table-level CHECK constraints
        foreach ($this->checks as $check) {
            $name = $this->quote_identifier($check['name'], $this->dialect);
            $lines[] = "    CONSTRAINT {$name} CHECK ({$check['expression']})";
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (\n";
        $sql .= implode(",\n", $lines);
        $sql .= "\n)";
        
        // Table options (MySQL)
        if ($this->dialect === 'mysql') {
            $options = [];
            $options[] = 'ENGINE=' . ($this->engine ?? 'InnoDB');
            $options[] = 'DEFAULT CHARSET=' . ($this->charset ?? 'utf8mb4');
            if ($this->collation !== null) {
                $options[] = "COLLATE={$this->collation}";
            }
            if ($this->comment !== null) {
                $options[] = "COMMENT='" . addslashes($this->comment) . "'";
            }
            $sql .= ' ' . implode(' ', $options);
        }
        
        return $sql;
    }

    /**
     * Generate CREATE INDEX statements (separate from CREATE TABLE)
     */
    public function to_index_sql(): array
    {
        $statements = [];
        $table_name = $this->quote_identifier($this->table, $this->dialect);

        foreach ($this->indexes as $index) {
            if ($index['type'] === 'UNIQUE') {
                continue; // Handled in CREATE TABLE
            }

            if ($index['type'] === 'FULLTEXT') {
                $statements = array_merge($statements, $this->fulltext_index_sql($index['name'], $index['columns']));
                continue;
            }

            $name = $this->quote_identifier($index['name'], $this->dialect);
            $cols = array_map(fn($c) => $this->quote_identifier($c, $this->dialect), $index['columns']);

            $statements[] = "CREATE INDEX {$name} ON {$table_name} (" . implode(', ', $cols) . ')';
        }

        return $statements;
    }

    /**
     * The statement(s) that create one `fulltext()` index — dialect-specific
     * the same way `Schema\Table::fulltext_index_sql()` is, for the same
     * reason: there is no `FULLTEXT INDEX` syntax on PostgreSQL or SQLite at
     * all. Before this, `fulltext()` on either of those dialects rendered
     * MySQL's syntax anyway and failed at execution — silently, since
     * nothing here ever ran it against a real PostgreSQL/SQLite server
     * to notice.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    protected function fulltext_index_sql(string $name, array $columns): array
    {
        $table_name = $this->quote_identifier($this->table, $this->dialect);
        $cols = array_map(fn($c) => $this->quote_identifier($c, $this->dialect), $columns);

        if ($this->dialect === 'mysql') {
            $idx_name = $this->quote_identifier($name, $this->dialect);

            return ["CREATE FULLTEXT INDEX {$idx_name} ON {$table_name} (" . implode(', ', $cols) . ')'];
        }

        if ($this->dialect === 'postgresql' || $this->dialect === 'supabase') {
            $idx_name = $this->quote_identifier($name, $this->dialect);
            $concat   = implode(" || ' ' || ", $cols);

            return ["CREATE INDEX {$idx_name} ON {$table_name} USING GIN (to_tsvector('english', {$concat}))"];
        }

        // sqlite — see Schema\Table::fulltext_index_sql()'s docblock for why
        // this is a virtual table plus sync triggers rather than an index.
        $pk_col_name = $this->single_primary_key_column();
        $pk_col      = $this->quote_identifier($pk_col_name, $this->dialect);
        $fts_table   = $this->quote_identifier($this->table . '_fts', $this->dialect);
        $fts_cols    = implode(', ', $cols);
        $new_cols    = implode(', ', array_map(fn($c) => 'new.' . $this->quote_identifier($c, $this->dialect), $columns));
        $old_cols    = implode(', ', array_map(fn($c) => 'old.' . $this->quote_identifier($c, $this->dialect), $columns));

        return [
            "CREATE VIRTUAL TABLE IF NOT EXISTS {$fts_table} USING fts5({$fts_cols}, "
                . "content='{$this->table}', content_rowid='{$pk_col_name}')",

            'CREATE TRIGGER ' . $this->quote_identifier($name . '_ai', $this->dialect) . " AFTER INSERT ON {$table_name} BEGIN "
                . "INSERT INTO {$fts_table}(rowid, {$fts_cols}) VALUES (new.{$pk_col}, {$new_cols}); END",

            'CREATE TRIGGER ' . $this->quote_identifier($name . '_ad', $this->dialect) . " AFTER DELETE ON {$table_name} BEGIN "
                . "INSERT INTO {$fts_table}({$fts_table}, rowid, {$fts_cols}) VALUES('delete', old.{$pk_col}, {$old_cols}); END",

            'CREATE TRIGGER ' . $this->quote_identifier($name . '_au', $this->dialect) . " AFTER UPDATE ON {$table_name} BEGIN "
                . "INSERT INTO {$fts_table}({$fts_table}, rowid, {$fts_cols}) VALUES('delete', old.{$pk_col}, {$old_cols}); "
                . "INSERT INTO {$fts_table}(rowid, {$fts_cols}) VALUES (new.{$pk_col}, {$new_cols}); END",
        ];
    }

    /**
     * The one column carrying this blueprint's primary key — whether
     * declared as `->primary([...])` (composite, but must resolve to
     * exactly one column here) or as a single column's own `->primary()`.
     * Raised, not defaulted, when that is not exactly one column: FTS5's
     * `content_rowid` needs SQLite's rowid alias, and a composite or absent
     * key gives it none to use.
     */
    protected function single_primary_key_column(): string
    {
        $pk_columns = !empty($this->primary_columns)
            ? $this->primary_columns
            : array_values(array_map(
                fn ($c) => $c->get_name(),
                array_filter($this->columns, fn ($c) => $c->is_primary())
            ));

        if (count($pk_columns) !== 1) {
            throw new \RuntimeException(
                "fulltext() on SQLite needs the table to have exactly one, single-column primary key — "
                . "'{$this->table}' has " . count($pk_columns) . '.'
            );
        }

        return $pk_columns[0];
    }

    /**
     * Generate ALTER TABLE statements for modifications
     */
    public function to_alter_sql(): array
    {
        $statements = [];
        $table_name = $this->quote_identifier($this->table, $this->dialect);
        
        // Drop foreign keys first (must be done before dropping columns)
        foreach ($this->drop_foreign_keys as $name) {
            $fk_name = $this->quote_identifier($name, $this->dialect);
            if ($this->dialect === 'mysql') {
                $statements[] = "ALTER TABLE {$table_name} DROP FOREIGN KEY {$fk_name}";
            } else {
                $statements[] = "ALTER TABLE {$table_name} DROP CONSTRAINT {$fk_name}";
            }
        }
        
        // Drop indexes
        foreach ($this->drop_indexes as $name) {
            $idx_name = $this->quote_identifier($name, $this->dialect);
            if ($name === 'PRIMARY') {
                $statements[] = "ALTER TABLE {$table_name} DROP PRIMARY KEY";
            } elseif ($this->dialect === 'mysql') {
                $statements[] = "ALTER TABLE {$table_name} DROP INDEX {$idx_name}";
            } else {
                $statements[] = "DROP INDEX {$idx_name}";
            }
        }
        
        // Drop columns
        foreach ($this->drop_columns as $column) {
            $col_name = $this->quote_identifier($column, $this->dialect);
            $statements[] = "ALTER TABLE {$table_name} DROP COLUMN {$col_name}";
        }
        
        // Rename columns
        foreach ($this->renames as $from => $to) {
            $from_name = $this->quote_identifier($from, $this->dialect);
            $to_name = $this->quote_identifier($to, $this->dialect);
            if ($this->dialect === 'mysql') {
                // MySQL requires column definition for CHANGE
                $statements[] = "ALTER TABLE {$table_name} RENAME COLUMN {$from_name} TO {$to_name}";
            } else {
                $statements[] = "ALTER TABLE {$table_name} RENAME COLUMN {$from_name} TO {$to_name}";
            }
        }
        
        // Add columns
        foreach ($this->columns as $column) {
            if ($column->is_change()) {
                // Modify existing column
                $col_sql = $column->to_sql($this->dialect);
                if ($this->dialect === 'mysql') {
                    $statements[] = "ALTER TABLE {$table_name} MODIFY COLUMN {$col_sql}";
                } else {
                    // PostgreSQL uses ALTER COLUMN
                    $col_name = $this->quote_identifier($column->get_name(), $this->dialect);
                    $statements[] = "ALTER TABLE {$table_name} ALTER COLUMN {$col_name} TYPE " . 
                                  strtoupper($column->get_type());
                }
            } else {
                // Add new column
                $col_sql = $column->to_add_sql($this->dialect);
                $statements[] = "ALTER TABLE {$table_name} ADD COLUMN {$col_sql}";
            }
        }
        
        // Add indexes
        foreach ($this->indexes as $index) {
            if ($index['type'] === 'FULLTEXT') {
                $statements = array_merge($statements, $this->fulltext_index_sql($index['name'], $index['columns']));
                continue;
            }

            $name = $this->quote_identifier($index['name'], $this->dialect);
            $cols = array_map(fn($c) => $this->quote_identifier($c, $this->dialect), $index['columns']);

            if ($index['type'] === 'UNIQUE') {
                $statements[] = "CREATE UNIQUE INDEX {$name} ON {$table_name} (" . implode(', ', $cols) . ')';
            } else {
                $statements[] = "CREATE INDEX {$name} ON {$table_name} (" . implode(', ', $cols) . ')';
            }
        }
        
        // Add foreign keys
        foreach ($this->foreign_keys as $fk) {
            $statements[] = $fk->to_add_sql($this->table, $this->dialect);
        }

        // Add CHECK constraints. SQLite has no `ALTER TABLE ... ADD
        // CONSTRAINT` at all — not a version gap like DROP COLUMN, categorical
        // — so this is refused outright rather than emitting SQL that would
        // fail on every version. Declare the check on Schema::create() for a
        // new table; an existing one needs the rebuild-and-copy Schema.php's
        // own DROP COLUMN guard already describes.
        if (!empty($this->checks) && $this->dialect === 'sqlite') {
            throw new \RuntimeException(
                'SQLite has no ALTER TABLE ... ADD CONSTRAINT, for a CHECK or anything else. '
                . 'Adding one to an existing table means rebuilding it: CREATE the new shape, '
                . 'copy the rows across, drop the old table, rename. Declare the check on '
                . 'Schema::create() instead if this is a new table.'
            );
        }

        foreach ($this->checks as $check) {
            $table_quoted = $this->quote_identifier($this->table, $this->dialect);
            $name = $this->quote_identifier($check['name'], $this->dialect);
            $statements[] = "ALTER TABLE {$table_quoted} ADD CONSTRAINT {$name} CHECK ({$check['expression']})";
        }

        return $statements;
    }

    // ============================================
    // Internal Methods
    // ============================================

    /**
     * Add a column definition
     */
    protected function add_column(string $name, string $type): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type);
        $this->columns[] = $column;
        return $column;
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

    /**
     * Get table name
     */
    public function get_table(): string
    {
        return $this->table;
    }

    /**
     * Get all columns
     * @return ColumnDefinition[]
     */
    public function get_columns(): array
    {
        return $this->columns;
    }

    /**
     * Get all indexes
     */
    public function get_indexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get all foreign keys
     * @return ForeignKeyDefinition[]
     */
    public function get_foreign_keys(): array
    {
        return $this->foreign_keys;
    }

    /**
     * Get all table-level CHECK constraints
     * @return array<int, array{name: string, expression: string}>
     */
    public function get_checks(): array
    {
        return $this->checks;
    }
}
