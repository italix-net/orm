<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Table Class
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use Italix\Contracts\TableMeta;
use Italix\Contracts\ColumnMeta;
use Italix\Contracts\DelegatedTableMeta;
use Italix\Contracts\NamedTableMeta;

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
class Table implements DelegatedTableMeta, NamedTableMeta
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

    /** @var array<string, array<int, string>> Full-text index definitions, name => columns. See fulltext(). */
    protected array $fulltext_indexes = [];

    /** @var array Foreign key constraints */
    protected array $foreign_keys = [];

    /** @var array<string, string> Table-level CHECK constraints: name => expression */
    protected array $check_constraints = [];

    /**
     * Timestamp column names, or `null` for a table with no automatic
     * timestamps. Declared here — not only on an `ActiveRow` subclass —
     * because `DataManager::insert()`/`update()` need this fact too, and
     * they exist on the Data Mapper side, which has no `ActiveRow` at all.
     * See `timestamps()`.
     */
    protected ?string $timestamp_insert_column = null;
    protected ?string $timestamp_update_column = null;

    /** The soft-delete column name, or `null` for a table with none. See `soft_deletes()`. */
    protected ?string $soft_delete_column = null;

    /** The optimistic-locking version column name, or `null` for a table with none. See `optimistic_locking()`. */
    protected ?string $version_column = null;

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

    /**
     * Full-text index definitions, name => columns.
     *
     * @return array<string, array<int, string>>
     */
    public function get_fulltext_indexes(): array
    {
        return $this->fulltext_indexes;
    }

    /** `_fts` for SQLite's companion FTS5 virtual table — the name `Operators\fulltext_match()` targets. */
    public function fulltext_table_name(): string
    {
        return $this->name . '_fts';
    }

    /**
     * Declare a composite primary key spanning more than one column —
     * `$table->primary_key(['tenant_id', 'order_id'])`.
     *
     * A single-column key needs none of this: `id => serial()` (or any
     * column's own `->primary_key()`) already works and is unaffected. This
     * exists for the case a single column's own flag cannot express — mirrors
     * `Migration\Blueprint::primary($columns)`, which already declares a
     * composite key this same way, as a fact about the *table*, not inferred
     * from how many columns happen to carry the flag.
     *
     * Marks every named column `not_null()` (a primary key column, composite
     * or not, cannot be null) and records the exact order given — the order
     * `to_create_sql()` renders the table-level `PRIMARY KEY (…)` clause in.
     */
    public function primary_key(array $columns): self
    {
        foreach ($columns as $name) {
            $column = $this->columns[$name] ?? null;

            if ($column === null) {
                throw new \RuntimeException(
                    "primary_key(): no column named '{$name}' on table '{$this->name}'"
                );
            }

            $column->primary_key();
        }

        $this->primary_keys = $columns;

        return $this;
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
     * Declare a full-text search index over `$columns` — searched with the
     * new `Operators\fulltext_match($table, $columns, $query)`, whose WHERE
     * clause assumes exactly this index exists (the way `MATCH(...) AGAINST
     * (...)` on MySQL always has).
     *
     * `get_index_sql()` renders it three different ways, because there is no
     * SQL standard for full-text search the way there almost is for
     * `CHECK`/`ENUM`:
     *
     * - **MySQL**: a native `FULLTEXT INDEX` on `$columns`.
     * - **PostgreSQL/Supabase**: a `GIN` index over
     *   `to_tsvector('english', col1 || ' ' || col2 || ...)` — not required
     *   for `@@` to work at all, only for it to not be a sequential scan.
     * - **SQLite**, which has no full-text *index* concept, only a
     *   completely separate FTS5 *virtual table*: an external-content FTS5
     *   table (`content = this table`, so the text is not duplicated) plus
     *   three triggers that keep it in sync on INSERT/UPDATE/DELETE. This
     *   requires the table to have exactly one, single-column, integer
     *   primary key — FTS5's `content_rowid` needs SQLite's own rowid alias,
     *   and there is no correct way to give it one otherwise;
     *   `get_index_sql()` raises immediately, rather than emitting SQL that
     *   would fail, when that is not the case.
     */
    public function fulltext(array $columns, ?string $name = null): self
    {
        $name = $name ?? $this->name . '_' . implode('_', $columns) . '_fulltext';
        $this->fulltext_indexes[$name] = $columns;

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
     * Add a table-level `CHECK` constraint: `$expression` is rendered
     * verbatim inside `CHECK (...)`.
     *
     * Reach for this over `Column::check()` when the rule spans more than
     * one column of the same row — `CHECK (period_start_dt < period_end_dt)`
     * cannot be attached to either column alone, only to the row.
     *
     * @param string $name Constraint name, for the same reason `add_unique()` takes one:
     *                     it is what `ALTER TABLE ... DROP CONSTRAINT` and a database's own
     *                     error message will call it later.
     */
    public function add_check(string $name, string $expression): self
    {
        $this->check_constraints[$name] = $expression;
        return $this;
    }

    /**
     * Table-level CHECK constraints, name => expression.
     *
     * @return array<string, string>
     */
    public function get_checks(): array
    {
        return $this->check_constraints;
    }

    /**
     * Declare that rows in this table carry creation/update timestamps, and
     * name the two columns.
     *
     * Once declared, `DataManager::insert()` fills `$insert_column` and
     * `$update_column` with the same instant on every INSERT, and
     * `DataManager::update()` refills `$update_column` on every UPDATE —
     * **unless the caller already put a value under that key**, which is
     * always trusted over the automatic one, INSERT and UPDATE alike. This
     * runs at the query-builder level, so it applies equally whether the
     * write came from `$dm->insert($table)->values([...])` directly or from
     * an `ActiveRow`'s `save()` — one mechanism, not one per style.
     *
     * `ActiveRow\Traits\HasTimestamps` already existed and is unaffected: it
     * sets both columns in PHP before `save()` ever reaches the query
     * builder, so the automatic fill here simply finds the columns already
     * present and does nothing further. Declaring `timestamps()` on a table
     * only changes behaviour for writes that were not already setting these
     * columns themselves — Data Mapper calls, and any `ActiveRow` class that
     * does not use that trait.
     */
    public function timestamps(string $insert_column = 'created_at', string $update_column = 'updated_at'): self
    {
        $this->timestamp_insert_column = $insert_column;
        $this->timestamp_update_column = $update_column;

        return $this;
    }

    public function has_timestamps(): bool
    {
        return $this->timestamp_insert_column !== null;
    }

    public function timestamp_insert_column(): ?string
    {
        return $this->timestamp_insert_column;
    }

    public function timestamp_update_column(): ?string
    {
        return $this->timestamp_update_column;
    }

    /**
     * Declare that deleting a row in this table means setting `$column`
     * rather than removing it.
     *
     * Once declared, `DataManager::delete($table)` compiles to an `UPDATE`
     * setting `$column` to the current instant instead of a `DELETE` — for
     * every caller, Data Mapper or `ActiveRow` alike, the same way
     * `timestamps()` above reaches both. A genuine delete is still
     * available: `$dm->delete($table)->force()->where(...)->execute()`.
     *
     * This is declared independently from `ActiveRow\Traits\SoftDeletes`,
     * which still works exactly as it did — its own `soft_delete()` sets the
     * column directly and its `force_delete()` already bypasses the query
     * builder's DELETE compilation entirely (`perform_hard_delete()` calls
     * `->force()` for exactly this reason). Declaring `soft_deletes()` here
     * is what extends the same default to a plain `->delete()` call and to
     * every caller that is not using that trait.
     */
    public function soft_deletes(string $column = 'deleted_at'): self
    {
        $this->soft_delete_column = $column;

        return $this;
    }

    public function has_soft_deletes(): bool
    {
        return $this->soft_delete_column !== null;
    }

    public function soft_delete_column(): ?string
    {
        return $this->soft_delete_column;
    }

    /**
     * Declare that rows in this table carry an optimistic-locking version
     * number, and name the column.
     *
     * Once declared, `DataManager::insert()` defaults `$column` to `1`
     * unless the caller already set it — the same "trusted if already
     * present" rule `timestamps()` follows — and `DataManager::update()`
     * always compiles `SET {$column} = {$column} + 1` into the statement,
     * silently discarding any value a caller put under that key in
     * `->set([...])`: the whole point of a version counter is that nothing
     * but this increment ever moves it, the way `Table::timestamps()`'s
     * `update_dt` is *not* protected this way is exactly the difference
     * between "a fact worth recording" and "a fact this scheme depends on
     * being correct".
     *
     * A `->expect_version($n)` call on the `QueryBuilder` additionally ANDs
     * `{$column} = ?` onto the `UPDATE`'s `WHERE`, and `execute()` throws
     * `Locking\OptimisticLockException` when that leaves zero rows affected
     * — the row was deleted, or (the case this exists for) another writer's
     * UPDATE already moved the version out from under this one. `ActiveRow`'s
     * `Persistable::save()` calls `expect_version()` automatically whenever
     * `has_optimistic_locking()` is true, using the version it last read.
     *
     * Out of scope: `DELETE` is not version-checked — the row's identity,
     * not its content, is what a delete acts on, and `Table::soft_deletes()`
     * already covers "do not really remove it" for callers who want the row
     * to stay reachable.
     */
    public function optimistic_locking(string $column = 'version'): self
    {
        $this->version_column = $column;

        return $this;
    }

    public function has_optimistic_locking(): bool
    {
        return $this->version_column !== null;
    }

    public function version_column(): ?string
    {
        return $this->version_column;
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

        // Column definitions.
        //
        // A composite primary key (more than one column in $this->primary_keys)
        // renders without any column claiming PRIMARY KEY on its own — every
        // server here refuses two single-column primary keys on one table —
        // and gets one table-level PRIMARY KEY (…) clause instead, right
        // after the columns. count() rather than a separately tracked flag:
        // whether the columns arrived at more-than-one via primary_key() or
        // by each having ->primary_key() called on it individually makes no
        // difference to what has to be rendered.
        $composite_pk = count($this->primary_keys) > 1;

        $column_defs = [];
        foreach ($this->columns as $column) {
            $column_defs[] = '    ' . $column->to_sql($this->dialect, !$composite_pk);
        }

        if ($composite_pk) {
            $pk_cols = array_map(fn ($c) => $this->quote_identifier($c), $this->primary_keys);
            $column_defs[] = '    PRIMARY KEY (' . implode(', ', $pk_cols) . ')';
        }

        // Composite unique constraints.
        //
        // Not on SQLite, which discards the name: it calls every constraint's
        // index `sqlite_autoindex_<table>_N`, so a schema read back out has lost
        // it. There the same guarantee is created as a named UNIQUE index —
        // see get_index_sql().
        if ($this->dialect !== 'sqlite') {
            foreach ($this->unique_constraints as $name => $columns) {
                $cols = array_map(fn($c) => $this->quote_identifier($c), $columns);
                $column_defs[] = '    CONSTRAINT ' . $this->quote_identifier($name) .
                               ' UNIQUE (' . implode(', ', $cols) . ')';
            }
        }

        // Foreign keys a column declared with ->references().
        //
        // These used to go nowhere: the reference was stored, used to resolve
        // relations, and left out of the DDL — so the constraint the developer
        // wrote down did not exist in the database, and nothing said so.
        foreach ($this->columns as $column) {
            $references = $column->get_references();

            if ($references === []) {
                continue;
            }

            $column_defs[] = '    FOREIGN KEY (' . $this->quote_identifier($column->get_db_name()) . ')'
                . ' REFERENCES ' . $this->quote_identifier($references['table'])
                . '(' . $this->quote_identifier($references['column']) . ')';
        }

        // Foreign keys added to the table
        foreach ($this->foreign_keys as $name => $fk) {
            $column_defs[] = '    CONSTRAINT ' . $this->quote_identifier($name) .
                           ' FOREIGN KEY (' . $this->quote_identifier($fk['column']) . ')' .
                           ' REFERENCES ' . $this->quote_identifier($fk['ref_table']) .
                           '(' . $this->quote_identifier($fk['ref_column']) . ')' .
                           ' ON DELETE ' . $fk['on_delete'] .
                           ' ON UPDATE ' . $fk['on_update'];
        }

        // Table-level CHECK constraints
        foreach ($this->check_constraints as $name => $expression) {
            $column_defs[] = '    CONSTRAINT ' . $this->quote_identifier($name) .
                           " CHECK ({$expression})";
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

        // On SQLite a unique constraint's name does not survive; a named unique
        // index says the same thing and can be read back. See to_create_sql().
        if ($this->dialect === 'sqlite') {
            foreach ($this->unique_constraints as $name => $columns) {
                $cols = array_map(fn($c) => $this->quote_identifier($c), $columns);

                $statements[] = 'CREATE UNIQUE INDEX ' . $this->quote_identifier($name)
                    . ' ON ' . $this->quote_identifier($this->get_full_name())
                    . ' (' . implode(', ', $cols) . ')';
            }
        }

        foreach ($this->indexes as $name => $columns) {
            $table_name = $this->quote_identifier($this->get_full_name());
            $idx_name = $this->quote_identifier($name);
            $cols = array_map(fn($c) => $this->quote_identifier($c), $columns);

            $statements[] = "CREATE INDEX {$idx_name} ON {$table_name} (" .
                          implode(', ', $cols) . ')';
        }

        foreach ($this->fulltext_indexes as $name => $columns) {
            $statements = array_merge($statements, $this->fulltext_index_sql($name, $columns));
        }

        return $statements;
    }

    /**
     * The statement(s) that create one `fulltext()` index — one per
     * dialect, per {@see fulltext()}'s docblock.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    protected function fulltext_index_sql(string $name, array $columns): array
    {
        $table_name = $this->quote_identifier($this->get_full_name());
        $cols = array_map(fn($c) => $this->quote_identifier($c), $columns);

        if ($this->dialect === 'mysql') {
            $idx_name = $this->quote_identifier($name);

            return ["CREATE FULLTEXT INDEX {$idx_name} ON {$table_name} (" . implode(', ', $cols) . ')'];
        }

        if ($this->dialect === 'postgresql' || $this->dialect === 'supabase') {
            $idx_name = $this->quote_identifier($name);
            $concat   = implode(" || ' ' || ", $cols);

            return ["CREATE INDEX {$idx_name} ON {$table_name} USING GIN (to_tsvector('english', {$concat}))"];
        }

        // sqlite: no full-text index on the table itself — a separate FTS5
        // virtual table, kept in sync by three triggers. See fulltext_match().
        if (count($this->primary_keys) !== 1) {
            throw new \RuntimeException(
                "Table::fulltext() on SQLite needs the table to have exactly one, single-column primary "
                . "key — '{$this->name}' has " . count($this->primary_keys) . '. FTS5\'s external-content '
                . 'table needs a rowid alias to key the sync triggers on, and a composite (or absent) '
                . 'primary key gives it none to use.'
            );
        }

        $pk_col     = $this->quote_identifier($this->primary_keys[0]);
        $fts_table  = $this->quote_identifier($this->fulltext_table_name());
        $fts_cols   = implode(', ', $cols);
        $new_cols   = implode(', ', array_map(fn($c) => 'new.' . $this->quote_identifier($c), $columns));
        $old_cols   = implode(', ', array_map(fn($c) => 'old.' . $this->quote_identifier($c), $columns));

        return [
            "CREATE VIRTUAL TABLE IF NOT EXISTS {$fts_table} USING fts5({$fts_cols}, "
                . "content='{$this->name}', content_rowid='{$this->primary_keys[0]}')",

            'CREATE TRIGGER ' . $this->quote_identifier($name . '_ai') . " AFTER INSERT ON {$table_name} BEGIN "
                . "INSERT INTO {$fts_table}(rowid, {$fts_cols}) VALUES (new.{$pk_col}, {$new_cols}); END",

            'CREATE TRIGGER ' . $this->quote_identifier($name . '_ad') . " AFTER DELETE ON {$table_name} BEGIN "
                . "INSERT INTO {$fts_table}({$fts_table}, rowid, {$fts_cols}) VALUES('delete', old.{$pk_col}, {$old_cols}); END",

            'CREATE TRIGGER ' . $this->quote_identifier($name . '_au') . " AFTER UPDATE ON {$table_name} BEGIN "
                . "INSERT INTO {$fts_table}({$fts_table}, rowid, {$fts_cols}) VALUES('delete', old.{$pk_col}, {$old_cols}); "
                . "INSERT INTO {$fts_table}(rowid, {$fts_cols}) VALUES (new.{$pk_col}, {$new_cols}); END",
        ];
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
