<?php
/**
 * Italix ORM - SQL Operators and Expressions
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Operators;

use Italix\Orm\Schema\Column;

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
     * Get parameter placeholder based on dialect.
     */
    protected function get_placeholder(int $index, string $dialect): string
    {
        // PostgreSQL and Supabase use numbered placeholders ($1, $2, etc.)
        return $this->is_postgres_compatible($dialect) ? '$' . $index : '?';
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
    
    protected Column $column;
    protected string $operator;
    /** @var mixed */
    protected $value;

    /**
     * @param Column $column
     * @param string $operator
     * @param mixed $value
     */
    public function __construct(Column $column, string $operator, $value)
    {
        $this->column = $column;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->get_column_ref($this->column, $dialect);
        
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
    
    protected Column $column;
    protected array $values;
    protected bool $negated;

    public function __construct(Column $column, array $values, bool $negated = false)
    {
        $this->column = $column;
        $this->values = $values;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->get_column_ref($this->column, $dialect);
        
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
    
    protected Column $column;
    /** @var mixed */
    protected $min;
    /** @var mixed */
    protected $max;
    protected bool $negated;

    /**
     * @param Column $column
     * @param mixed $min
     * @param mixed $max
     * @param bool $negated
     */
    public function __construct(Column $column, $min, $max, bool $negated = false)
    {
        $this->column = $column;
        $this->min = $min;
        $this->max = $max;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->get_column_ref($this->column, $dialect);
        
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
    
    protected Column $column;
    protected string $pattern;
    protected bool $case_insensitive;
    protected bool $negated;

    public function __construct(Column $column, string $pattern, bool $case_insensitive = false, bool $negated = false)
    {
        $this->column = $column;
        $this->pattern = $pattern;
        $this->case_insensitive = $case_insensitive;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->get_column_ref($this->column, $dialect);
        
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
    
    protected Column $column;
    protected bool $negated;

    public function __construct(Column $column, bool $negated = false)
    {
        $this->column = $column;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $col_ref = $this->get_column_ref($this->column, $dialect);
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

    public function to_sql(string $dialect, array &$params): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        
        if ($this->column === null) {
            $col_ref = '*';
        } elseif ($this->column instanceof Column) {
            $col_ref = $this->get_column_ref($this->column, $dialect);
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

// ============================================
// Operator Factory Functions
// ============================================

/**
 * Equal (=)
 * 
 * @param Column $column
 * @param mixed $value
 */
function eq(Column $column, $value): Comparison
{
    return new Comparison($column, '=', $value);
}

/**
 * Not equal (<>)
 * 
 * @param Column $column
 * @param mixed $value
 */
function ne(Column $column, $value): Comparison
{
    return new Comparison($column, '<>', $value);
}

/**
 * Greater than (>)
 * 
 * @param Column $column
 * @param mixed $value
 */
function gt(Column $column, $value): Comparison
{
    return new Comparison($column, '>', $value);
}

/**
 * Greater than or equal (>=)
 * 
 * @param Column $column
 * @param mixed $value
 */
function gte(Column $column, $value): Comparison
{
    return new Comparison($column, '>=', $value);
}

/**
 * Less than (<)
 * 
 * @param Column $column
 * @param mixed $value
 */
function lt(Column $column, $value): Comparison
{
    return new Comparison($column, '<', $value);
}

/**
 * Less than or equal (<=)
 * 
 * @param Column $column
 * @param mixed $value
 */
function lte(Column $column, $value): Comparison
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
function in_array(Column $column, array $values): InExpression
{
    return new InExpression($column, $values, false);
}

/**
 * NOT IN operator
 */
function not_in_array(Column $column, array $values): InExpression
{
    return new InExpression($column, $values, true);
}

/**
 * BETWEEN operator
 * 
 * @param Column $column
 * @param mixed $min
 * @param mixed $max
 */
function between(Column $column, $min, $max): BetweenExpression
{
    return new BetweenExpression($column, $min, $max, false);
}

/**
 * NOT BETWEEN operator
 * 
 * @param Column $column
 * @param mixed $min
 * @param mixed $max
 */
function not_between(Column $column, $min, $max): BetweenExpression
{
    return new BetweenExpression($column, $min, $max, true);
}

/**
 * LIKE operator
 */
function like(Column $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, false, false);
}

/**
 * NOT LIKE operator
 */
function not_like(Column $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, false, true);
}

/**
 * ILIKE operator (case-insensitive LIKE)
 */
function ilike(Column $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, true, false);
}

/**
 * NOT ILIKE operator (case-insensitive NOT LIKE)
 */
function not_ilike(Column $column, string $pattern): LikeExpression
{
    return new LikeExpression($column, $pattern, true, true);
}

/**
 * IS NULL
 */
function is_null(Column $column): NullExpression
{
    return new NullExpression($column, false);
}

/**
 * IS NOT NULL
 */
function is_not_null(Column $column): NullExpression
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
function sql_sum(Column $column): AggregateExpression
{
    return new AggregateExpression('SUM', $column);
}

/**
 * AVG aggregate
 */
function sql_avg(Column $column): AggregateExpression
{
    return new AggregateExpression('AVG', $column);
}

/**
 * MIN aggregate
 */
function sql_min(Column $column): AggregateExpression
{
    return new AggregateExpression('MIN', $column);
}

/**
 * MAX aggregate
 */
function sql_max(Column $column): AggregateExpression
{
    return new AggregateExpression('MAX', $column);
}

/**
 * COUNT DISTINCT shortcut
 */
function sql_count_distinct(Column $column): AggregateExpression
{
    return new AggregateExpression('COUNT', $column, true);
}
