<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - SQL Operators and Expressions
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Operators;

use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;
use Italix\Orm\QueryBuilder\ExistsExpression;
use Italix\Orm\QueryBuilder\QueryBuilder;
use Italix\Orm\QueryBuilder\Subquery;

/**
 * Trait providing common SQL helper methods for expression classes.
 * Centralizes identifier quoting and placeholder generation to ensure
 * consistent and secure SQL generation.
 */
trait SqlHelper
{
    /**
     * Quote an identifier (table name, column name) based on dialect.
     * Properly escapes quote characters to prevent SQL injection.
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

    /**
     * Get a fully qualified column reference with proper quoting.
     */
    protected function get_column_ref(Column $column, string $dialect): string
    {
        $col_name = $this->quote_identifier($column->get_db_name(), $dialect);
        $table = $column->get_table();
        
        if ($table !== null) {
            $tbl_name = $this->quote_identifier($table->get_name(), $dialect);
            return "{$tbl_name}.{$col_name}";
        }
        
        return $col_name;
    }

    /**
     * One side of a condition, as SQL.
     *
     * A column, or any expression: an aggregate (`SUM(total)`), a raw fragment,
     * a scalar subquery. Comparison operators used to take a `Column` and
     * nothing else, which left `HAVING SUM(total) > 1000` — the most ordinary
     * question a `GROUP BY` is asked — with no form at all.
     *
     * A subquery is parenthesised because `(SELECT …) > 5` is the only way SQL
     * accepts one here. Everything else is emitted as written: an aggregate is
     * already a function call, and `raw()` means raw — parenthesise it yourself
     * if precedence matters.
     *
     * Bindings land in the order the operand appears, because placeholders are
     * positional and both sides are rendered in one pass.
     */
    protected function render_operand(Column|SQLExpression $operand, string $dialect, array &$params): string
    {
        if ($operand instanceof Column) {
            return $this->get_column_ref($operand, $dialect);
        }

        if ($operand instanceof Subquery) {
            return '(' . $operand->to_sql($dialect, $params) . ')';
        }

        return $operand->to_sql($dialect, $params);
    }

    /**
     * Get parameter placeholder based on dialect.
     */
    protected function get_placeholder(int $index, string $dialect): string
    {
        // `?` everywhere: PDO understands `?` and named placeholders and nothing
        // else, so libpq's `$1` would reach PostgreSQL unbound — no rows, and no
        // error either. See QueryBuilder::get_placeholder().
        return '?';
    }

    /**
     * Check if dialect is PostgreSQL-compatible
     */
    protected function is_postgres_compatible(string $dialect): bool
    {
        return $dialect === 'postgresql' || $dialect === 'supabase';
    }
}

/**
 * Interface for SQL expressions
 */
interface SQLExpression
{
    /**
     * Convert expression to SQL string
     * 
     * @param string $dialect Database dialect
     * @param array &$params Parameter bindings array
     * @return string SQL string
     */
    public function to_sql(string $dialect, array &$params): string;
}

/**
 * Comparison expression (=, <>, <, >, <=, >=)
 */
class Comparison implements SQLExpression
{
    use SqlHelper;
    
    protected Column|SQLExpression $operand;
    protected string $operator;
    /** @var mixed */
    protected $value;

    /**
     * @param Column|SQLExpression $column a column, or any expression
     * @param string $operator
     * @param mixed $value
     */
    public function __construct(Column|SQLExpression $column, string $operator, $value)
    {
        $this->operand = $column;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->render_operand($this->operand, $dialect, $params);
        
        if ($this->value instanceof Column) {
            $value_ref = $this->get_column_ref($this->value, $dialect);
            return "{$col_ref} {$this->operator} {$value_ref}";
        }
        
        if ($this->value instanceof SQLExpression) {
            return "{$col_ref} {$this->operator} (" . $this->value->to_sql($dialect, $params) . ")";
        }
        
        $params[] = $this->value;
        $placeholder = $this->get_placeholder(count($params), $dialect);
        return "{$col_ref} {$this->operator} {$placeholder}";
    }
}

/**
 * Logical AND expression
 */
class AndExpression implements SQLExpression
{
    /** @var SQLExpression[] */
    protected array $conditions;

    public function __construct(SQLExpression ...$conditions)
    {
        $this->conditions = $conditions;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        if (empty($this->conditions)) {
            return '1=1';
        }
        
        // Use foreach instead of array_map to properly pass $params by reference
        $parts = [];
        foreach ($this->conditions as $cond) {
            $parts[] = '(' . $cond->to_sql($dialect, $params) . ')';
        }

        return implode(' AND ', $parts);
    }
}

/**
 * Logical OR expression
 */
class OrExpression implements SQLExpression
{
    /** @var SQLExpression[] */
    protected array $conditions;

    public function __construct(SQLExpression ...$conditions)
    {
        $this->conditions = $conditions;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        if (empty($this->conditions)) {
            return '1=0';
        }
        
        // Use foreach instead of array_map to properly pass $params by reference
        $parts = [];
        foreach ($this->conditions as $cond) {
            $parts[] = '(' . $cond->to_sql($dialect, $params) . ')';
        }
        
        return implode(' OR ', $parts);
    }
}

/**
 * Logical NOT expression
 */
class NotExpression implements SQLExpression
{
    protected SQLExpression $condition;

    public function __construct(SQLExpression $condition)
    {
        $this->condition = $condition;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        return 'NOT (' . $this->condition->to_sql($dialect, $params) . ')';
    }
}

/**
 * IN expression
 */
class InExpression implements SQLExpression
{
    use SqlHelper;
    
    protected Column|SQLExpression $operand;
    /** @var array|SQLExpression a list of values, or a subquery producing them */
    protected $values;
    protected bool $negated;

    /**
     * @param Column|SQLExpression $column a column, or any expression
     * @param array|SQLExpression $values a list, or a Subquery selecting one column
     */
    public function __construct(Column|SQLExpression $column, $values, bool $negated = false)
    {
        if (!is_array($values) && !$values instanceof SQLExpression) {
            throw new \InvalidArgumentException(
                'IN takes an array of values or a subquery; ' . gettype($values) . ' given.'
            );
        }

        $this->operand = $column;
        $this->values = $values;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->render_operand($this->operand, $dialect, $params);
        
        // `IN (SELECT …)`. The subquery renders itself and appends its own
        // bindings, in the position it occupies in the statement.
        if ($this->values instanceof SQLExpression) {
            $op = $this->negated ? 'NOT IN' : 'IN';

            return "{$col_ref} {$op} (" . $this->values->to_sql($dialect, $params) . ')';
        }

        // An empty list is not a syntax error waiting to happen: `IN ()` is
        // invalid SQL in every dialect here, and the truthful answer is that
        // nothing matches.
        if (empty($this->values)) {
            return $this->negated ? '1=1' : '1=0';
        }
        
        $placeholders = [];
        foreach ($this->values as $value) {
            $params[] = $value;
            $placeholders[] = $this->get_placeholder(count($params), $dialect);
        }
        
        $op = $this->negated ? 'NOT IN' : 'IN';
        return "{$col_ref} {$op} (" . implode(', ', $placeholders) . ')';
    }
}

/**
 * BETWEEN expression
 */
class BetweenExpression implements SQLExpression
{
    use SqlHelper;
    
    protected Column|SQLExpression $operand;
    /** @var mixed */
    protected $min;
    /** @var mixed */
    protected $max;
    protected bool $negated;

    /**
     * @param Column|SQLExpression $column a column, or any expression
     * @param mixed $min
     * @param mixed $max
     * @param bool $negated
     */
    public function __construct(Column|SQLExpression $column, $min, $max, bool $negated = false)
    {
        $this->operand = $column;
        $this->min = $min;
        $this->max = $max;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->render_operand($this->operand, $dialect, $params);
        
        $params[] = $this->min;
        $min_ph = $this->get_placeholder(count($params), $dialect);
        
        $params[] = $this->max;
        $max_ph = $this->get_placeholder(count($params), $dialect);
        
        $not = $this->negated ? 'NOT ' : '';
        return "{$col_ref} {$not}BETWEEN {$min_ph} AND {$max_ph}";
    }
}

/**
 * LIKE expression
 */
class LikeExpression implements SQLExpression
{
    use SqlHelper;
    
    protected Column|SQLExpression $operand;
    protected string $pattern;
    protected bool $case_insensitive;
    protected bool $negated;

    /** @param Column|SQLExpression $column a column, or any expression */
    public function __construct(Column|SQLExpression $column, string $pattern, bool $case_insensitive = false, bool $negated = false)
    {
        $this->operand = $column;
        $this->pattern = $pattern;
        $this->case_insensitive = $case_insensitive;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->render_operand($this->operand, $dialect, $params);
        
        $params[] = $this->pattern;
        $placeholder = $this->get_placeholder(count($params), $dialect);
        
        $not = $this->negated ? 'NOT ' : '';
        
        // PostgreSQL and Supabase support native ILIKE
        if ($this->case_insensitive && $this->is_postgres_compatible($dialect)) {
            $op = $this->negated ? 'NOT ILIKE' : 'ILIKE';
            return "{$col_ref} {$op} {$placeholder}";
        }
        
        if ($this->case_insensitive) {
            return "LOWER({$col_ref}) {$not}LIKE LOWER({$placeholder})";
        }
        
        return "{$col_ref} {$not}LIKE {$placeholder}";
    }
}

/**
 * IS NULL / IS NOT NULL expression
 */
class NullExpression implements SQLExpression
{
    use SqlHelper;
    
    protected Column|SQLExpression $operand;
    protected bool $negated;

    /** @param Column|SQLExpression $column a column, or any expression */
    public function __construct(Column|SQLExpression $column, bool $negated = false)
    {
        $this->operand = $column;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->render_operand($this->operand, $dialect, $params);
        $op = $this->negated ? 'IS NOT NULL' : 'IS NULL';
        return "{$col_ref} {$op}";
    }
}

/**
 * ORDER BY direction holder
 */
class OrderDirection
{
    /** @var Column|SQLExpression */
    public $column;
    public string $direction;

    /**
     * @param Column|SQLExpression $column
     * @param string $direction
     */
    public function __construct($column, string $direction)
    {
        $this->column = $column;
        $this->direction = $direction;
    }
}

/**
 * Aggregate expression (COUNT, SUM, AVG, MIN, MAX)
 */
class AggregateExpression implements SQLExpression
{
    use SqlHelper;
    
    protected string $function;
    /** @var Column|string|null */
    protected $column;
    protected bool $distinct;
    protected ?string $alias;

    /**
     * @param string $function Aggregate function name
     * @param Column|string|null $column Column to aggregate
     * @param bool $distinct Use DISTINCT
     */
    public function __construct(string $function, $column = null, bool $distinct = false)
    {
        $this->function = strtoupper($function);
        $this->column = $column;
        $this->distinct = $distinct;
        $this->alias = null;
    }

    /**
     * Set an alias for this expression
     */
    public function as(string $alias): self
    {
        $clone = clone $this;
        $clone->alias = $alias;
        return $clone;
    }

    /**
     * Compute this over a window instead of collapsing the rows.
     *
     *     sql_sum($orders->total)->over()->partition_by($orders->customer_id)
     *
     * Any alias moves to the window, since `SUM(x) AS t OVER (…)` is not a
     * statement any engine accepts — the alias belongs to the whole expression.
     */
    public function over(): WindowExpression
    {
        $bare        = clone $this;
        $bare->alias = null;

        $window = new WindowExpression($bare);

        return $this->alias === null ? $window : $window->as($this->alias);
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        
        if ($this->column === null) {
            $col_ref = '*';
        } elseif ($this->column instanceof Column || $this->column instanceof SQLExpression) {
            $col_ref = $this->render_operand($this->column, $dialect, $params);
        } else {
            $col_ref = (string)$this->column;
        }
        
        $sql = "{$this->function}({$distinct}{$col_ref})";
        
        if ($this->alias !== null) {
            $alias_quoted = $this->quote_identifier($this->alias, $dialect);
            $sql .= " AS {$alias_quoted}";
        }
        
        return $sql;
    }

    /**
     * Convert to string for use in select columns
     */
    public function __toString(): string
    {
        $params = [];
        return $this->to_sql('mysql', $params);
    }
}

/**
 * Raw SQL expression
 */
class RawExpression implements SQLExpression
{
    protected string $sql;
    protected array $bindings;

    public function __construct(string $sql, array $bindings = [])
    {
        $this->sql = $sql;
        $this->bindings = $bindings;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        foreach ($this->bindings as $binding) {
            $params[] = $binding;
        }
        return $this->sql;
    }
}

/**
 * `Operators\fulltext_match()`'s condition — one WHERE fragment, rendered
 * three different ways, matching whichever full-text mechanism
 * `Table::fulltext()` built for the dialect at hand:
 *
 * - **MySQL**: `MATCH(col1, col2) AGAINST (? IN NATURAL|BOOLEAN LANGUAGE MODE)`
 * - **PostgreSQL/Supabase**: `to_tsvector('english', col1 || ' ' || col2)
 *   @@ plainto_tsquery('english', ?)` (natural) or `@@ to_tsquery('english', ?)`
 *   (boolean — `$query` must already be `to_tsquery` syntax, e.g. `'fox & quick'`)
 * - **SQLite**: `pk_col IN (SELECT rowid FROM table_fts WHERE table_fts MATCH
 *   ?)` — a subquery against the FTS5 virtual table `fulltext()` created,
 *   composable with the rest of a normal WHERE the same as any other
 *   condition (AND'd, OR'd, negated) since it is just another SQLExpression.
 *
 * Assumes `Table::fulltext($columns)` was declared for this exact `$columns`
 * list — MySQL raises its own error without a matching FULLTEXT index, and
 * SQLite's subquery references a virtual table that only exists because of
 * it; nothing here re-checks that PHP-side.
 */
class FulltextMatch implements SQLExpression
{
    use SqlHelper;

    protected Table $table;

    /** @var array<int, string> */
    protected array $columns;

    protected string $query;
    protected string $mode;

    /** @param array<int, string> $columns */
    public function __construct(Table $table, array $columns, string $query, string $mode = 'natural')
    {
        $this->table = $table;
        $this->columns = $columns;
        $this->query = $query;
        $this->mode = $mode;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $cols = array_map(fn ($c) => $this->quote_identifier($c, $dialect), $this->columns);

        if ($dialect === 'mysql') {
            $params[] = $this->query;
            $placeholder = $this->get_placeholder(count($params), $dialect);
            $mode_sql = $this->mode === 'boolean' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

            return 'MATCH(' . implode(', ', $cols) . ") AGAINST ({$placeholder} {$mode_sql})";
        }

        if ($dialect === 'postgresql' || $dialect === 'supabase') {
            $params[] = $this->query;
            $placeholder = $this->get_placeholder(count($params), $dialect);
            $concat = implode(" || ' ' || ", $cols);
            $fn = $this->mode === 'boolean' ? 'to_tsquery' : 'plainto_tsquery';

            return "to_tsvector('english', {$concat}) @@ {$fn}('english', {$placeholder})";
        }

        if ($dialect === 'sqlite') {
            $pk_columns = $this->table->get_primary_keys();

            if (count($pk_columns) !== 1) {
                throw new \RuntimeException(
                    "fulltext_match() on SQLite needs the table to have exactly one, single-column "
                    . "primary key — '{$this->table->get_name()}' has " . count($pk_columns) . '.'
                );
            }

            $pk_col = $this->quote_identifier($pk_columns[0], $dialect);
            $fts_table = $this->quote_identifier($this->table->fulltext_table_name(), $dialect);

            $params[] = $this->query;
            $placeholder = $this->get_placeholder(count($params), $dialect);

            return "{$pk_col} IN (SELECT rowid FROM {$fts_table} WHERE {$fts_table} MATCH {$placeholder})";
        }

        throw new \RuntimeException("fulltext_match() has no rendering for dialect '{$dialect}'.");
    }
}

// ============================================
// Operator Factory Functions
// ============================================

/**
 * Equal (=)
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $value
 */
function eq(Column|SQLExpression $column, $value): Comparison
{
    return new Comparison($column, '=', $value);
}

/**
 * Not equal (<>)
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $value
 */
function ne(Column|SQLExpression $column, $value): Comparison
{
    return new Comparison($column, '<>', $value);
}

/**
 * Greater than (>)
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $value
 */
function gt(Column|SQLExpression $column, $value): Comparison
{
    return new Comparison($column, '>', $value);
}

/**
 * Greater than or equal (>=)
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $value
 */
function gte(Column|SQLExpression $column, $value): Comparison
{
    return new Comparison($column, '>=', $value);
}

/**
 * Less than (<)
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $value
 */
function lt(Column|SQLExpression $column, $value): Comparison
{
    return new Comparison($column, '<', $value);
}

/**
 * Less than or equal (<=)
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $value
 */
function lte(Column|SQLExpression $column, $value): Comparison
{
    return new Comparison($column, '<=', $value);
}

/**
 * Logical AND
 */
function and_(SQLExpression ...$conditions): AndExpression
{
    return new AndExpression(...$conditions);
}

/**
 * Logical OR
 */
function or_(SQLExpression ...$conditions): OrExpression
{
    return new OrExpression(...$conditions);
}

/**
 * Logical NOT
 */
function not_(SQLExpression $condition): NotExpression
{
    return new NotExpression($condition);
}

/**
 * IN operator
 */
/**
 * @param array|SQLExpression $values a list, or a subquery selecting one column
 */
function in_array(Column|SQLExpression $column, $values): InExpression
{
    return new InExpression($column, $values, false);
}

/**
 * IN operator (alias for in_array)
 */
/**
 * @param array|SQLExpression $values a list, or a subquery selecting one column
 */
function in_(Column|SQLExpression $column, $values): InExpression
{
    return new InExpression($column, $values, false);
}

/**
 * NOT IN operator
 */
/**
 * @param array|SQLExpression $values a list, or a subquery selecting one column
 *
 * Prefer `not_exists()` when the subquery's column can be NULL: `NOT IN` with a
 * NULL among the results is true for no row at all, which is correct SQL and
 * almost never the question that was asked.
 */
function not_in_array(Column|SQLExpression $column, $values): InExpression
{
    return new InExpression($column, $values, true);
}

/**
 * NOT IN operator (alias for not_in_array)
 */
/** @param array|SQLExpression $values a list, or a subquery selecting one column */
function not_in_(Column|SQLExpression $column, $values): InExpression
{
    return new InExpression($column, $values, true);
}

/**
 * Wrap a SELECT so it can be used inside another statement.
 *
 *     where(in_($customers->id, sub(QueryBuilder::select($orders, [$orders->customer_id]))))
 *     where(eq($p->price, sub($max_price_query)))          // scalar subquery
 *     from(sub($grouped)->alias('totals'))                  // derived table
 *
 * @see \Italix\Orm\QueryBuilder\Subquery
 */
function sub(QueryBuilder $query, ?string $alias = null): Subquery
{
    return new Subquery($query, $alias);
}

/**
 * `EXISTS (SELECT …)`.
 *
 * Stops at the first matching row, and behaves the way people expect when the
 * subquery can produce a NULL — which `IN` does not.
 */
function exists(Subquery $subquery): ExistsExpression
{
    return new ExistsExpression($subquery, false);
}

/** `NOT EXISTS (SELECT …)`. The safe counterpart of `not_in_()`. */
function not_exists(Subquery $subquery): ExistsExpression
{
    return new ExistsExpression($subquery, true);
}

/**
 * BETWEEN operator
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $min
 * @param mixed $max
 */
function between(Column|SQLExpression $column, $min, $max): BetweenExpression
{
    return new BetweenExpression($column, $min, $max, false);
}

/**
 * NOT BETWEEN operator
 * 
 * @param Column|SQLExpression $column a column, or any expression
 * @param mixed $min
 * @param mixed $max
 */
function not_between(Column|SQLExpression $column, $min, $max): BetweenExpression
{
    return new BetweenExpression($column, $min, $max, true);
}

/**
 * LIKE operator
 */
function like(Column|SQLExpression $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, false, false);
}

/**
 * NOT LIKE operator
 */
function not_like(Column|SQLExpression $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, false, true);
}

/**
 * ILIKE operator (case-insensitive LIKE)
 */
function ilike(Column|SQLExpression $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, true, false);
}

/**
 * NOT ILIKE operator (case-insensitive NOT LIKE)
 */
function not_ilike(Column|SQLExpression $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, true, true);
}

/**
 * IS NULL
 */
function is_null(Column|SQLExpression $column): NullExpression
{
    return new NullExpression($column, false);
}

/**
 * IS NOT NULL
 */
function is_not_null(Column|SQLExpression $column): NullExpression
{
    return new NullExpression($column, true);
}

/**
 * ORDER BY ASC
 * 
 * @param Column|SQLExpression $column
 */
function asc($column): OrderDirection
{
    return new OrderDirection($column, 'ASC');
}

/**
 * ORDER BY DESC
 * 
 * @param Column|SQLExpression $column
 */
function desc($column): OrderDirection
{
    return new OrderDirection($column, 'DESC');
}

/**
 * Raw SQL expression
 */
function raw(string $sql, array $bindings = []): RawExpression
{
    return new RawExpression($sql, $bindings);
}

// ============================================
// JSON
// ============================================
//
// One path syntax in — `$.meta.age`, `$.tags[0]` — three renderings out. See
// JsonExpression for what each dialect actually accepts, all of it measured.

/**
 * The text at a path: `json_text($orders->doc, '$.customer.name')`.
 *
 * Text, not JSON — on MySQL that means `JSON_UNQUOTE(JSON_EXTRACT(…))`, because
 * `JSON_EXTRACT` alone returns `"Ada"` with the quotes and comparing that to
 * `'Ada'` is comparing a longer string.
 *
 * @param Column|SQLExpression $document
 */
function json_text($document, string $path = '$'): JsonExpression
{
    return new JsonExpression($document, $path, JsonExpression::AS_TEXT);
}

/**
 * The JSON at a path, still JSON: `json_get($orders->doc, '$.items')`.
 *
 * For an object or an array, or to compare against another JSON value.
 *
 * @param Column|SQLExpression $document
 */
function json_get($document, string $path = '$'): JsonExpression
{
    return new JsonExpression($document, $path, JsonExpression::AS_JSON);
}

/**
 * How many entries the array at this path has.
 *
 * @param Column|SQLExpression $document
 */
function json_length($document, string $path = '$'): JsonExpression
{
    return new JsonExpression($document, $path, JsonExpression::AS_LENGTH);
}

/**
 * Is there anything at this path?
 *
 *     ->where(json_has($orders->doc, '$.shipping.tracking'))
 *
 * @param Column|SQLExpression $document
 */
function json_has($document, string $path): JsonCondition
{
    return new JsonCondition($document, JsonCondition::HAS, $path);
}

/**
 * Is there nothing at this path?
 *
 * @param Column|SQLExpression $document
 */
function json_missing($document, string $path): JsonCondition
{
    return new JsonCondition($document, JsonCondition::HAS, $path, true);
}

/**
 * Does the document contain this one?
 *
 *     ->where(json_contains($orders->doc, ['status' => 'paid']))
 *
 * PostgreSQL's `@>` and MySQL's `JSON_CONTAINS()`. **Refused on SQLite**, which
 * has neither and no rewrite that means the same thing — see JsonCondition.
 *
 * @param Column|SQLExpression $document
 * @param mixed $value an array or object to encode, or JSON already written
 */
function json_contains($document, $value): JsonCondition
{
    return new JsonCondition($document, JsonCondition::CONTAINS, $value);
}

/**
 * Does it not contain it?
 *
 * @param Column|SQLExpression $document
 * @param mixed $value
 */
function json_not_contains($document, $value): JsonCondition
{
    return new JsonCondition($document, JsonCondition::CONTAINS, $value, true);
}

// ============================================
// Window Functions
// ============================================
//
// `<function>(…) OVER (PARTITION BY … ORDER BY …)`. See WindowExpression for
// where these may appear — SELECT and ORDER BY, never WHERE — and for the
// server versions they need.

/** `ROW_NUMBER()` — 1, 2, 3 … within each partition, ties broken arbitrarily. */
function row_number(): WindowExpression
{
    return new WindowExpression(new SqlFunction('ROW_NUMBER'));
}

/** `RANK()` — ties share a number, and the next one skips ahead. */
function rank(): WindowExpression
{
    return new WindowExpression(new SqlFunction('RANK'));
}

/** `DENSE_RANK()` — ties share a number, and the next one does not skip. */
function dense_rank(): WindowExpression
{
    return new WindowExpression(new SqlFunction('DENSE_RANK'));
}

/** `PERCENT_RANK()` — the rank as a fraction between 0 and 1. */
function percent_rank(): WindowExpression
{
    return new WindowExpression(new SqlFunction('PERCENT_RANK'));
}

/** `CUME_DIST()` — the share of rows at or before this one. */
function cume_dist(): WindowExpression
{
    return new WindowExpression(new SqlFunction('CUME_DIST'));
}

/** `NTILE(n)` — the partition cut into n buckets of near-equal size. */
function ntile(int $buckets): WindowExpression
{
    if ($buckets < 1) {
        throw new \InvalidArgumentException('NTILE takes a positive number of buckets; ' . $buckets . ' given.');
    }

    return new WindowExpression(new SqlFunction('NTILE', [raw((string) $buckets)]));
}

/**
 * `LAG(column, offset, default)` — the value this many rows earlier.
 *
 * The offset is written as a literal because MySQL requires one there; the
 * default is bound like any other value.
 *
 * @param Column|SQLExpression $column
 * @param mixed $default what to use where there is no earlier row
 */
function lag(Column|SQLExpression $column, int $offset = 1, $default = null): WindowExpression
{
    return new WindowExpression(new SqlFunction('LAG', window_offset_args($column, $offset, $default, 'LAG')));
}

/**
 * `LEAD(column, offset, default)` — the value this many rows later.
 *
 * @param Column|SQLExpression $column
 * @param mixed $default what to use where there is no later row
 */
function lead(Column|SQLExpression $column, int $offset = 1, $default = null): WindowExpression
{
    return new WindowExpression(new SqlFunction('LEAD', window_offset_args($column, $offset, $default, 'LEAD')));
}

/**
 * The arguments of `LAG`/`LEAD`.
 *
 * A `null` default is left out rather than bound: `LAG(x, 1, NULL)` and
 * `LAG(x, 1)` mean the same thing, and the shorter one is what an engine
 * without three-argument support still accepts.
 *
 * @param Column|SQLExpression $column
 * @param mixed $default
 * @return array<int, mixed>
 */
function window_offset_args(Column|SQLExpression $column, int $offset, $default, string $function): array
{
    if ($offset < 0) {
        throw new \InvalidArgumentException($function . ' takes a non-negative offset; ' . $offset . ' given.');
    }

    $arguments = [$column, raw((string) $offset)];

    if ($default !== null) {
        $arguments[] = $default;
    }

    return $arguments;
}

/** `FIRST_VALUE(column)` — the first value in the frame. */
function first_value(Column|SQLExpression $column): WindowExpression
{
    return new WindowExpression(new SqlFunction('FIRST_VALUE', [$column]));
}

/**
 * `LAST_VALUE(column)` — the last value **in the frame**, which by default ends
 * at the current row. Pair it with
 * `rows_between('unbounded preceding', 'unbounded following')` if you meant the
 * last value of the partition; this catches people out in every SQL dialect.
 */
function last_value(Column|SQLExpression $column): WindowExpression
{
    return new WindowExpression(new SqlFunction('LAST_VALUE', [$column]));
}

/** `NTH_VALUE(column, n)` — the nth value in the frame, counting from 1. */
function nth_value(Column|SQLExpression $column, int $position): WindowExpression
{
    if ($position < 1) {
        throw new \InvalidArgumentException('NTH_VALUE counts from 1; ' . $position . ' given.');
    }

    return new WindowExpression(new SqlFunction('NTH_VALUE', [$column, raw((string) $position)]));
}

/**
 * Any other function, windowed: `window_call('MEDIAN', $col)->over(…)`.
 *
 * The name must be an identifier — this is not a second `raw()`.
 *
 * @param Column|SQLExpression|mixed ...$arguments
 */
function window_call(string $name, ...$arguments): WindowExpression
{
    return new WindowExpression(new SqlFunction($name, $arguments));
}

// ============================================
// Aggregate Functions
// ============================================

/**
 * COUNT aggregate
 * Note: Named sql_count to avoid conflict with PHP's built-in count()
 * 
 * @param Column|string|null $column Column to count, null for COUNT(*)
 * @param bool $distinct Use DISTINCT
 */
function sql_count($column = null, bool $distinct = false): AggregateExpression
{
    return new AggregateExpression('COUNT', $column, $distinct);
}

/**
 * SUM aggregate
 */
function sql_sum(Column|SQLExpression $column): AggregateExpression
{
    return new AggregateExpression('SUM', $column);
}

/**
 * AVG aggregate
 */
function sql_avg(Column|SQLExpression $column): AggregateExpression
{
    return new AggregateExpression('AVG', $column);
}

/**
 * MIN aggregate
 */
function sql_min(Column|SQLExpression $column): AggregateExpression
{
    return new AggregateExpression('MIN', $column);
}

/**
 * MAX aggregate
 */
function sql_max(Column|SQLExpression $column): AggregateExpression
{
    return new AggregateExpression('MAX', $column);
}

/**
 * COUNT DISTINCT shortcut
 */
function sql_count_distinct(Column|SQLExpression $column): AggregateExpression
{
    return new AggregateExpression('COUNT', $column, true);
}

/**
 * A WHERE condition matching `$query` against `$columns` on `$table` —
 * `Table::fulltext($columns)` must already be declared for the exact same
 * `$columns`, the way MySQL's own `MATCH() AGAINST()` always needs a
 * `FULLTEXT` index to exist first.
 *
 *     $articles = mysql_table('articles', [...])->fulltext(['title', 'body']);
 *     $dm->select()->from($articles)
 *         ->where(fulltext_match($articles, ['title', 'body'], 'quick fox'))
 *         ->execute();
 *
 * `$mode` is `'natural'` (free text — the default) or `'boolean'` (operator
 * syntax the underlying engine parses itself: MySQL's `+word -word "phrase"`,
 * PostgreSQL's `to_tsquery` syntax like `'fox & quick'`). SQLite's FTS5 has
 * its own query grammar regardless — `$mode` does not change how `$query`
 * is sent there, only how MySQL/PostgreSQL wrap it.
 *
 * @param array<int, string> $columns
 */
function fulltext_match(Table $table, array $columns, string $query, string $mode = 'natural'): SQLExpression
{
    return new FulltextMatch($table, $columns, $query, $mode);
}
