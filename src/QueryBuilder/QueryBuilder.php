<?php
/**
 * Italix ORM - Query Builder
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\QueryBuilder;

use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\Column;
use Italix\Orm\Operators\SQLExpression;
use Italix\Orm\Operators\OrderDirection;
use PDO;

/**
 * Query Builder - builds and executes SQL queries.
 */
class QueryBuilder
{
    /** @var string Query type (SELECT, INSERT, UPDATE, DELETE) */
    protected string $type = '';
    
    /** @var Table|null Target table */
    protected ?Table $table = null;
    
    /** @var array Columns to select */
    protected array $select_columns = [];
    
    /** @var SQLExpression|null WHERE condition */
    protected ?SQLExpression $where_condition = null;
    
    /** @var array ORDER BY clauses */
    protected array $order_by = [];
    
    /** @var int|null LIMIT value */
    protected ?int $limit_value = null;
    
    /** @var int|null OFFSET value */
    protected ?int $offset_value = null;
    
    /** @var array Values for INSERT */
    protected array $insert_values = [];
    
    /** @var array Values for UPDATE */
    protected array $update_values = [];
    
    /** @var array JOIN clauses */
    protected array $join_clauses = [];
    
    /** @var array GROUP BY columns */
    protected array $group_by_columns = [];
    
    /** @var SQLExpression|null HAVING condition */
    protected ?SQLExpression $having_condition = null;
    
    /** @var bool Use RETURNING clause */
    protected bool $return_values = false;
    
    /** @var array Columns to return */
    protected array $returning_columns = [];
    
    /** @var array|null ON CONFLICT target columns */
    protected ?array $conflict_target = null;
    
    /** @var array|null ON CONFLICT DO UPDATE SET values */
    protected ?array $conflict_update = null;
    
    /** @var bool ON CONFLICT DO NOTHING */
    protected bool $conflict_do_nothing = false;
    
    /** @var string Database dialect */
    protected string $dialect;
    
    /** @var PDO|null Database connection */
    protected ?PDO $connection = null;

    /**
     * Create a new QueryBuilder
     */
    public function __construct(string $dialect = 'mysql')
    {
        $this->dialect = $dialect;
    }

    /**
     * Set the database connection
     */
    public function set_connection(PDO $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Start a SELECT query
     * 
     * @param array|null $columns Columns to select, null for all (*)
     */
    public function select(?array $columns = null): self
    {
        $builder = clone $this;
        $builder->type = 'SELECT';
        $builder->select_columns = $columns ?? [];
        return $builder;
    }

    /**
     * Set the table to query from
     */
    public function from(Table $table): self
    {
        $builder = clone $this;
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * Add WHERE condition
     */
    public function where(SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->where_condition = $condition;
        return $builder;
    }

    /**
     * Add ORDER BY clause
     * 
     * @param mixed ...$columns Column objects or OrderDirection objects
     */
    public function order_by(...$columns): self
    {
        $builder = clone $this;
        $builder->order_by = array_merge($builder->order_by, $columns);
        return $builder;
    }

    /**
     * Set LIMIT
     */
    public function limit(int $limit): self
    {
        $builder = clone $this;
        $builder->limit_value = $limit;
        return $builder;
    }

    /**
     * Set OFFSET
     */
    public function offset(int $offset): self
    {
        $builder = clone $this;
        $builder->offset_value = $offset;
        return $builder;
    }

    /**
     * Start an INSERT query
     */
    public function insert(Table $table): self
    {
        $builder = clone $this;
        $builder->type = 'INSERT';
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * Set values for INSERT
     * 
     * @param array|array[] $values Single row or multiple rows
     */
    public function values($values): self
    {
        $builder = clone $this;
        
        // Check if it's a single row or multiple rows
        if (isset($values[0]) && is_array($values[0])) {
            $builder->insert_values = $values;
        } else {
            $builder->insert_values = [$values];
        }
        
        return $builder;
    }

    /**
     * ON CONFLICT DO UPDATE (upsert)
     * 
     * @param array $target Conflict target columns (e.g., ['email'] or ['user_id', 'date'])
     * @param array $update Values to update on conflict
     */
    public function on_conflict_do_update(array $target, array $update): self
    {
        $builder = clone $this;
        $builder->conflict_target = $target;
        $builder->conflict_update = $update;
        $builder->conflict_do_nothing = false;
        return $builder;
    }

    /**
     * ON CONFLICT DO NOTHING
     * 
     * @param array|null $target Optional conflict target columns
     */
    public function on_conflict_do_nothing(?array $target = null): self
    {
        $builder = clone $this;
        $builder->conflict_target = $target;
        $builder->conflict_update = null;
        $builder->conflict_do_nothing = true;
        return $builder;
    }

    /**
     * Start an UPDATE query
     */
    public function update(Table $table): self
    {
        $builder = clone $this;
        $builder->type = 'UPDATE';
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * Set values for UPDATE
     */
    public function set(array $values): self
    {
        $builder = clone $this;
        $builder->update_values = $values;
        return $builder;
    }

    /**
     * Start a DELETE query
     */
    public function delete(Table $table): self
    {
        $builder = clone $this;
        $builder->type = 'DELETE';
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * Add RETURNING clause (PostgreSQL, SQLite)
     * 
     * @param mixed ...$columns
     */
    public function returning(...$columns): self
    {
        $builder = clone $this;
        $builder->return_values = true;
        $builder->returning_columns = $columns;
        return $builder;
    }

    /**
     * Add LEFT JOIN
     */
    public function left_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'LEFT JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add INNER JOIN
     */
    public function inner_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'INNER JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add RIGHT JOIN
     */
    public function right_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'RIGHT JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add FULL OUTER JOIN
     */
    public function full_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'FULL OUTER JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add CROSS JOIN
     */
    public function cross_join(Table $table): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'CROSS JOIN',
            'table' => $table,
            'condition' => null
        ];
        return $builder;
    }

    /**
     * Add GROUP BY
     * 
     * @param mixed ...$columns
     */
    public function group_by(...$columns): self
    {
        $builder = clone $this;
        $builder->group_by_columns = array_merge($builder->group_by_columns, $columns);
        return $builder;
    }

    /**
     * Add HAVING condition
     */
    public function having(SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->having_condition = $condition;
        return $builder;
    }

    /**
     * Build the SQL query
     * 
     * @param array &$params Parameter bindings
     * @return string SQL query
     */
    public function to_sql(array &$params = []): string
    {
        switch ($this->type) {
            case 'SELECT':
                return $this->build_select($params);
            case 'INSERT':
                return $this->build_insert($params);
            case 'UPDATE':
                return $this->build_update($params);
            case 'DELETE':
                return $this->build_delete($params);
            default:
                throw new \RuntimeException("No query type specified");
        }
    }

    /**
     * Execute the query
     * 
     * @return array|int Query results or affected rows
     */
    public function execute()
    {
        if ($this->connection === null) {
            throw new \RuntimeException("No database connection set");
        }
        
        $params = [];
        $sql = $this->to_sql($params);
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        
        if ($this->type === 'SELECT') {
            return $stmt->fetchAll();
        }
        
        if ($this->return_values && $this->supports_returning()) {
            return $stmt->fetchAll();
        }
        
        if ($this->type === 'INSERT') {
            return (int)$this->connection->lastInsertId();
        }
        
        return $stmt->rowCount();
    }

    // ============================================
    // Protected Build Methods
    // ============================================

    /**
     * Build SELECT query
     */
    protected function build_select(array &$params): string
    {
        if ($this->table === null) {
            throw new \RuntimeException("No table specified for SELECT");
        }
        
        $parts = ['SELECT'];
        
        // Columns
        if (empty($this->select_columns)) {
            $parts[] = '*';
        } else {
            $cols = [];
            foreach ($this->select_columns as $col) {
                if ($col instanceof Column) {
                    $cols[] = $this->get_column_ref($col);
                } elseif ($col instanceof SQLExpression) {
                    $cols[] = $col->to_sql($this->dialect, $params);
                } else {
                    $cols[] = (string)$col;
                }
            }
            $parts[] = implode(', ', $cols);
        }
        
        // FROM
        $parts[] = 'FROM ' . $this->quote_identifier($this->table->get_full_name());
        
        // JOINs
        foreach ($this->join_clauses as $join) {
            $join_table = $this->quote_identifier($join['table']->get_full_name());
            $parts[] = $join['type'] . ' ' . $join_table;
            
            // CROSS JOIN doesn't have ON condition
            if ($join['condition'] !== null) {
                $parts[] = 'ON ' . $join['condition']->to_sql($this->dialect, $params);
            }
        }
        
        // WHERE
        if ($this->where_condition !== null) {
            $parts[] = 'WHERE ' . $this->where_condition->to_sql($this->dialect, $params);
        }
        
        // GROUP BY
        if (!empty($this->group_by_columns)) {
            $group_parts = [];
            foreach ($this->group_by_columns as $column) {
                if ($column instanceof Column) {
                    $group_parts[] = $this->get_column_ref($column);
                } else {
                    $group_parts[] = (string)$column;
                }
            }
            $parts[] = 'GROUP BY ' . implode(', ', $group_parts);
        }
        
        // HAVING
        if ($this->having_condition !== null) {
            $parts[] = 'HAVING ' . $this->having_condition->to_sql($this->dialect, $params);
        }
        
        // ORDER BY
        if (!empty($this->order_by)) {
            $order_parts = [];
            foreach ($this->order_by as $order) {
                if ($order instanceof OrderDirection) {
                    if ($order->column instanceof Column) {
                        $order_parts[] = $this->get_column_ref($order->column) . ' ' . $order->direction;
                    } elseif ($order->column instanceof SQLExpression) {
                        $order_parts[] = $order->column->to_sql($this->dialect, $params) . ' ' . $order->direction;
                    }
                } elseif ($order instanceof Column) {
                    $order_parts[] = $this->get_column_ref($order);
                } elseif ($order instanceof SQLExpression) {
                    $order_parts[] = $order->to_sql($this->dialect, $params);
                } elseif (is_array($order) && isset($order['column'])) {
                    $col = $order['column'];
                    $dir = $order['direction'] ?? 'ASC';
                    if ($col instanceof Column) {
                        $order_parts[] = $this->get_column_ref($col) . ' ' . $dir;
                    }
                }
            }
            if (!empty($order_parts)) {
                $parts[] = 'ORDER BY ' . implode(', ', $order_parts);
            }
        }
        
        // LIMIT
        if ($this->limit_value !== null) {
            $parts[] = 'LIMIT ' . $this->limit_value;
        }
        
        // OFFSET
        if ($this->offset_value !== null) {
            $parts[] = 'OFFSET ' . $this->offset_value;
        }
        
        return implode(' ', $parts);
    }

    /**
     * Build INSERT query
     */
    protected function build_insert(array &$params): string
    {
        if (empty($this->insert_values)) {
            throw new \RuntimeException("No values provided for INSERT");
        }
        
        $table_name = $this->quote_identifier($this->table->get_full_name());
        
        // Get column names from first row
        $columns = array_keys($this->insert_values[0]);
        $column_names = array_map(function($col) {
            $column = $this->table->get_column($col);
            if ($column !== null) {
                return $this->quote_identifier($column->get_db_name());
            }
            return $this->quote_identifier($col);
        }, $columns);
        
        // Build value placeholders
        $value_rows = [];
        foreach ($this->insert_values as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                if ($value instanceof SQLExpression) {
                    $placeholders[] = $value->to_sql($this->dialect, $params);
                } else {
                    $params[] = $value;
                    $placeholders[] = $this->get_placeholder(count($params));
                }
            }
            $value_rows[] = '(' . implode(', ', $placeholders) . ')';
        }
        
        // MySQL uses INSERT IGNORE for DO NOTHING behavior
        $insert_keyword = 'INSERT';
        if ($this->conflict_do_nothing && $this->dialect === 'mysql') {
            $insert_keyword = 'INSERT IGNORE';
        }
        
        $sql = "{$insert_keyword} INTO {$table_name} (" . implode(', ', $column_names) . ") VALUES " . implode(', ', $value_rows);
        
        // ON CONFLICT clause (not needed for MySQL INSERT IGNORE)
        if ($this->conflict_do_nothing && $this->dialect !== 'mysql') {
            $sql .= $this->build_on_conflict($params);
        } elseif ($this->conflict_update !== null) {
            $sql .= $this->build_on_conflict($params);
        }
        
        // RETURNING
        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }
        
        return $sql;
    }

    /**
     * Build ON CONFLICT clause
     */
    protected function build_on_conflict(array &$params): string
    {
        // MySQL uses different syntax: INSERT ... ON DUPLICATE KEY UPDATE
        if ($this->dialect === 'mysql') {
            return $this->build_on_duplicate_key($params);
        }
        
        // PostgreSQL / SQLite syntax
        $sql = ' ON CONFLICT';
        
        // Target columns
        if (!empty($this->conflict_target)) {
            $target_cols = array_map(
                fn($col) => $this->quote_identifier($col),
                $this->conflict_target
            );
            $sql .= ' (' . implode(', ', $target_cols) . ')';
        }
        
        // DO NOTHING or DO UPDATE
        if ($this->conflict_do_nothing) {
            $sql .= ' DO NOTHING';
        } else if ($this->conflict_update !== null) {
            $sql .= ' DO UPDATE SET ';
            $set_parts = [];
            
            foreach ($this->conflict_update as $col => $value) {
                $col_name = $this->quote_identifier($col);
                
                if ($value instanceof SQLExpression) {
                    $set_parts[] = "{$col_name} = " . $value->to_sql($this->dialect, $params);
                } else {
                    $params[] = $value;
                    $set_parts[] = "{$col_name} = " . $this->get_placeholder(count($params));
                }
            }
            
            $sql .= implode(', ', $set_parts);
        }
        
        return $sql;
    }

    /**
     * Build MySQL ON DUPLICATE KEY UPDATE clause
     */
    protected function build_on_duplicate_key(array &$params): string
    {
        if ($this->conflict_do_nothing) {
            // MySQL doesn't have DO NOTHING, use INSERT IGNORE instead
            // We need to modify the INSERT at the beginning
            return ''; // Handled separately
        }
        
        if ($this->conflict_update === null) {
            return '';
        }
        
        $sql = ' ON DUPLICATE KEY UPDATE ';
        $set_parts = [];
        
        foreach ($this->conflict_update as $col => $value) {
            $col_name = $this->quote_identifier($col);
            
            if ($value instanceof SQLExpression) {
                $set_parts[] = "{$col_name} = " . $value->to_sql($this->dialect, $params);
            } else {
                $params[] = $value;
                $set_parts[] = "{$col_name} = " . $this->get_placeholder(count($params));
            }
        }
        
        return $sql . implode(', ', $set_parts);
    }

    /**
     * Build UPDATE query
     */
    protected function build_update(array &$params): string
    {
        if (empty($this->update_values)) {
            throw new \RuntimeException("No values provided for UPDATE");
        }
        
        $table_name = $this->quote_identifier($this->table->get_full_name());
        
        // Build SET clause
        $set_parts = [];
        foreach ($this->update_values as $col => $value) {
            $column = $this->table->get_column($col);
            $col_name = $column !== null ? $column->get_db_name() : $col;
            
            if ($value instanceof SQLExpression) {
                $set_parts[] = $this->quote_identifier($col_name) . ' = ' . $value->to_sql($this->dialect, $params);
            } else {
                $params[] = $value;
                $set_parts[] = $this->quote_identifier($col_name) . ' = ' . $this->get_placeholder(count($params));
            }
        }
        
        $sql = "UPDATE {$table_name} SET " . implode(', ', $set_parts);
        
        // WHERE
        if ($this->where_condition !== null) {
            $sql .= ' WHERE ' . $this->where_condition->to_sql($this->dialect, $params);
        }
        
        // RETURNING
        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }
        
        return $sql;
    }

    /**
     * Build DELETE query
     */
    protected function build_delete(array &$params): string
    {
        $table_name = $this->quote_identifier($this->table->get_full_name());
        
        $sql = "DELETE FROM {$table_name}";
        
        // WHERE
        if ($this->where_condition !== null) {
            $sql .= ' WHERE ' . $this->where_condition->to_sql($this->dialect, $params);
        }
        
        // LIMIT (MySQL)
        if ($this->limit_value !== null && $this->dialect === 'mysql') {
            $sql .= ' LIMIT ' . $this->limit_value;
        }
        
        // RETURNING
        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }
        
        return $sql;
    }

    /**
     * Build RETURNING clause
     */
    protected function build_returning(): string
    {
        if (empty($this->returning_columns)) {
            return ' RETURNING *';
        }
        
        $cols = [];
        foreach ($this->returning_columns as $col) {
            if ($col instanceof Column) {
                $cols[] = $this->quote_identifier($col->get_db_name());
            } else {
                $cols[] = $this->quote_identifier((string)$col);
            }
        }
        
        return ' RETURNING ' . implode(', ', $cols);
    }

    /**
     * Quote identifier based on dialect
     * Properly escapes the quote character to prevent SQL injection
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            // Escape backticks by doubling them
            return '`' . str_replace('`', '``', $name) . '`';
        }
        // PostgreSQL/SQLite: escape double quotes by doubling them
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Get column reference for SQL
     */
    protected function get_column_ref(Column $column): string
    {
        $table = $column->get_table();
        if ($table !== null) {
            return $this->quote_identifier($table->get_name()) . '.' . $this->quote_identifier($column->get_db_name());
        }
        return $this->quote_identifier($column->get_db_name());
    }

    /**
     * Get placeholder for parameter
     */
    protected function get_placeholder(int $index): string
    {
        // PostgreSQL and Supabase use numbered placeholders ($1, $2, etc.)
        return $this->is_postgres_compatible() ? '$' . $index : '?';
    }

    /**
     * Check if current dialect is PostgreSQL-compatible (PostgreSQL or Supabase)
     */
    protected function is_postgres_compatible(): bool
    {
        return in_array($this->dialect, ['postgresql', 'supabase']);
    }

    /**
     * Check if current dialect supports RETURNING clause
     */
    protected function supports_returning(): bool
    {
        return in_array($this->dialect, ['postgresql', 'supabase', 'sqlite']);
    }
}
