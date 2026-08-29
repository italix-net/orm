<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Column Class
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use Italix\Contracts\RelationalColumnMeta;
use Italix\Contracts\RelationMeta;

/**
 * Represents a database column with its type and constraints.
 *
 * Implements RelationalColumnMeta (which extends ColumnMeta) for
 * compatibility with italix/forms library.
 */
class Column implements RelationalColumnMeta
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

    /**
     * Whether the column refuses negative values.
     *
     * MySQL's `UNSIGNED`, which doubles the positive range and is the ordinary
     * way to say "this is an identifier, not a quantity that can go below
     * zero". SQLite takes the word and keeps INTEGER affinity. **PostgreSQL has
     * no unsigned integer type at all** — see {@see get_type_sql()}.
     */
    protected bool $is_unsigned = false;
    
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
     * Raw SQL boolean expressions this column's value must satisfy.
     *
     * Several may be added; each becomes its own `CHECK (...)` clause. Schema
     * text, not a bound value — trusted the same way a view's `->as_query()`
     * is (see `View`'s docblock): written by the developer beside the column
     * it constrains, not by a request.
     *
     * @var string[]
     */
    protected array $check_expressions = [];

    /**
     * The allowed values, for an `enum()` column. `null` for every other type.
     *
     * @var string[]|null
     */
    protected ?array $enum_values = null;

    /**
     * The `BackedEnum` class this column's raw value hydrates into, when
     * `enum()` was given a class name instead of a plain array of values.
     * `null` for a plain enum (or any other type). See `enum_class()`.
     */
    protected ?string $enum_class = null;

    /**
     * The built-in cast applied on read (raw value → PHP value) and on
     * write (PHP value → raw value): `'array'`, `'datetime'`, `'bool'`,
     * `'int'` or `'float'`. `null` for a column read and written exactly as
     * the driver hands it back — the default, and unaffected for every
     * column that never calls `cast_as()`. See `Italix\Orm\Casts\Cast`,
     * which is what actually applies it.
     */
    protected ?string $cast = null;

    /** @var RelationMeta|null Relation metadata for forms integration */
    protected ?RelationMeta $relation_meta = null;

    /** @var string Default label column for FK relations */
    protected string $foreign_label = 'name';

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
    /**
     * Refuse negative values: `INT UNSIGNED`.
     *
     * **Not portable, and this is the one thing to know about it.** MySQL and
     * SQLite take the word; PostgreSQL has no unsigned integer, so there it is
     * dropped rather than approximated. A `CHECK (col >= 0)` would enforce the
     * same rule, and this package does not add one on your behalf: a constraint
     * nobody wrote is a constraint nobody expects to find.
     */
    public function unsigned(): self
    {
        $this->is_unsigned = true;

        return $this;
    }

    public function is_unsigned(): bool
    {
        return $this->is_unsigned;
    }

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
     * Add a `CHECK` constraint on this column: `$expression` is rendered
     * verbatim inside `CHECK (...)`. Calling it more than once adds more
     * clauses rather than replacing the first — `CHECK (a) CHECK (b)` says
     * the same thing as `CHECK (a AND b)` and reads as two separate rules
     * instead of one that has to be parsed to see what it actually refuses.
     *
     * `unsigned()` is still the right call for "not negative" — see its own
     * docblock. Reach for `check()` for a rule `unsigned()` cannot express:
     * a fixed set of values with no native ENUM (use `enum()` instead), a
     * range, or a relationship between two columns of the same row.
     */
    public function check(string $expression): self
    {
        $this->check_expressions[] = $expression;
        return $this;
    }

    /**
     * The `CHECK` expressions added with `check()`, in the order they were
     * added. Does **not** include the constraint an `enum()` column adds on
     * PostgreSQL/SQLite for its own values — that one has no expression to
     * show, only the list `get_enum_values()` already returns.
     *
     * @return string[]
     */
    public function get_checks(): array
    {
        return $this->check_expressions;
    }

    /**
     * Set the allowed values. Called by the `enum()` factory — not meant to
     * be called directly on a column of any other type.
     */
    public function enum_values(array $values): self
    {
        $this->enum_values = $values;
        return $this;
    }

    /** The allowed values, or `null` for a column that is not an `enum()`. */
    public function get_enum_values(): ?array
    {
        return $this->enum_values;
    }

    /**
     * Set the `BackedEnum` class this column hydrates into. Called by the
     * `enum()` factory when given a class name — not meant to be called
     * directly.
     */
    public function enum_class(string $class): self
    {
        $this->enum_class = $class;
        return $this;
    }

    /** The `BackedEnum` class this column hydrates into, or `null`. */
    public function get_enum_class(): ?string
    {
        return $this->enum_class;
    }

    /**
     * Cast this column's value on read and on write:
     *
     * ```php
     * 'metadata'   => json()->cast_as('array'),      // JSON string <-> PHP array
     * 'expires_dt' => datetime()->cast_as('datetime'), // string <-> DateTimeImmutable
     * 'is_active'  => boolean()->cast_as('bool'),      // 0/1/"0"/"1" <-> real bool
     * ```
     *
     * One of `'array'`, `'datetime'`, `'bool'`, `'int'`, `'float'`. Applied
     * by `Italix\Orm\Casts\Cast` — see there for exactly what each direction
     * does and why raw driver output (a numeric string from PDO, `0`/`1`
     * for a boolean on SQLite) needs it at all.
     *
     * Not applied on your behalf for `enum()`: a `BackedEnum`-backed enum
     * column casts automatically because the class it hydrates into is
     * already unambiguous; a plain `enum(['a', 'b'])` has no PHP type to
     * cast into beyond the string it already is.
     */
    public function cast_as(string $cast): self
    {
        $this->cast = $cast;
        return $this;
    }

    /** The cast applied on read/write, or `null` for none. */
    public function get_cast(): ?string
    {
        return $this->cast;
    }

    // =========================================
    // RelationalColumnMeta Interface (Forms Integration)
    // =========================================

    /**
     * Get foreign key relation metadata, if this column is a foreign key.
     *
     * Implements RelationalColumnMeta interface for italix/forms compatibility.
     * Returns null if this column is not a foreign key.
     *
     * @return RelationMeta|null
     */
    public function get_relation(): ?RelationMeta
    {
        // Return cached relation meta if already set
        if ($this->relation_meta !== null) {
            return $this->relation_meta;
        }

        // Auto-create from references if available
        if (!empty($this->references)) {
            $this->relation_meta = new RelationMetaAdapter(
                $this->references['table'],
                $this->references['column'],
                $this->foreign_label
            );
            return $this->relation_meta;
        }

        return null;
    }

    /**
     * Set custom relation metadata.
     *
     * Use this to provide a custom RelationMeta implementation
     * or to configure the relation with a database connection.
     *
     * @param RelationMeta $relation
     * @return self
     */
    public function set_relation(RelationMeta $relation): self
    {
        $this->relation_meta = $relation;
        return $this;
    }

    /**
     * Set the label column to use for FK relation display.
     *
     * This is used when auto-creating RelationMetaAdapter from references.
     *
     * @param string $label Column name to use as label (default: 'name')
     * @return self
     */
    public function label_column(string $label): self
    {
        $this->foreign_label = $label;

        // Update existing relation_meta if it's a RelationMetaAdapter
        if ($this->relation_meta instanceof RelationMetaAdapter) {
            $this->relation_meta->set_foreign_label($label);
        }

        return $this;
    }

    /**
     * Check if this column has a foreign key relation.
     *
     * @return bool
     */
    public function has_relation(): bool
    {
        return $this->get_relation() !== null;
    }

    /**
     * Generate SQL for column definition.
     *
     * `$inline_primary_key = false` renders this column's line without its
     * own `PRIMARY KEY` keyword — for a column that is one part of a
     * composite key, where `Table::to_create_sql()` emits one table-level
     * `PRIMARY KEY (col1, col2)` clause instead of letting every column
     * claim to be a (single-column) primary key on its own, which no server
     * here accepts twice on one table. `NOT NULL` is still emitted in that
     * case — a primary key column is not nullable either way, and the inline
     * `PRIMARY KEY` keyword this is skipping is the only other thing that
     * would have said so.
     */
    public function to_sql(string $dialect = 'mysql', bool $inline_primary_key = true): string
    {
        $parts = [];

        // Column name
        $quoted_name = $this->quote_identifier($this->get_db_name(), $dialect);
        $parts[] = $quoted_name;

        // Type
        $type_sql = $this->get_type_sql($dialect);
        $parts[] = $type_sql;

        // Primary key
        if ($this->is_primary_key && $inline_primary_key) {
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
        if (!$this->is_nullable && !($this->is_primary_key && $inline_primary_key)) {
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

        // Explicit CHECK constraints
        foreach ($this->check_expressions as $expression) {
            $parts[] = "CHECK ({$expression})";
        }

        // enum() has no native type here — the values are enforced the same
        // way an explicit check() would, because that is what they are.
        if ($this->enum_values !== null && $dialect !== 'mysql') {
            $parts[] = 'CHECK (' . $quoted_name . ' IN (' . $this->quoted_enum_values($dialect) . '))';
        }

        return implode(' ', $parts);
    }

    /** `'a', 'b', 'c'` — each value quoted and comma-joined, for an inline `IN (...)`. */
    protected function quoted_enum_values(string $dialect): string
    {
        $quoted = array_map(
            fn($value) => "'" . addslashes((string) $value) . "'",
            $this->enum_values ?? []
        );

        return implode(', ', $quoted);
    }

    /**
     * Get SQL type for dialect
     */
    /**
     * The type this column would be created as on a given server.
     *
     * Public because comparing a declaration against a live database means
     * asking exactly this: not "what did the developer type" but "what would
     * that have become here". `datetime()` is `DATETIME` on MySQL and
     * `TIMESTAMP` on PostgreSQL, and a differ that does not know it reports a
     * change on every timestamp column, forever.
     */
    public function sql_type(string $dialect = 'mysql'): string
    {
        return $this->get_type_sql($dialect);
    }

    protected function get_type_sql(string $dialect): string
    {
        $type = strtoupper($this->type);

        // enum(): native only on MySQL. PostgreSQL and SQLite have nothing
        // that stores "one of these strings" as a type — VARCHAR(255) plus
        // the CHECK added in to_sql() enforces the identical rule.
        if ($type === 'ENUM') {
            if ($dialect === 'mysql') {
                return 'ENUM(' . $this->quoted_enum_values($dialect) . ')';
            }

            return 'VARCHAR(255)';
        }

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
                // The factory is called double_precision(); the SQL is two
                // words. Without this the underscore reached the server, which
                // answered with a syntax error pointing at the *next* line.
                'DOUBLE_PRECISION' => 'DOUBLE PRECISION',
                // MySQL's REAL is a *synonym for DOUBLE*, not the four-byte
                // float it is everywhere else, so real() would silently widen to
                // eight bytes and come back as double_precision(). FLOAT is the
                // type that means here what REAL means on PostgreSQL.
                'REAL' => 'FLOAT',
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
            // SQLite stores by *affinity*, not by declared type, and accepts any
            // type name at all — so the name it is given is the name it keeps and
            // hands back. Collapsing BIGINT, DATETIME, JSON and UUID to INTEGER
            // and TEXT, as this used to, changed nothing about how a value is
            // stored and lost every one of those declarations: a schema pulled
            // back out described columns nobody had written. The one entry left
            // is the factory name that is not SQL.
            'sqlite' => [
                'DOUBLE_PRECISION' => 'DOUBLE PRECISION',
            ],
        ];
        
        $sql = $type_map[$dialect][$type] ?? $type;

        // PostgreSQL has no unsigned integer type. Dropped there rather than
        // approximated with a CHECK constraint nobody asked for.
        if ($this->is_unsigned && $dialect !== 'postgresql' && $this->is_integer_type($type)) {
            $sql .= ' UNSIGNED';
        }

        return $sql;
    }

    /** Is this a type `UNSIGNED` means anything for? */
    protected function is_integer_type(string $type): bool
    {
        return in_array(strtoupper($type), [
            'INTEGER', 'INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'SERIAL', 'BIGSERIAL',
            'DECIMAL', 'NUMERIC', 'REAL', 'DOUBLE_PRECISION', 'FLOAT',
        ], true);
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
