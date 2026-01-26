<?php
/**
 * Italix ORM - SQL Builder
 * 
 * Provides a safe way to build and execute custom SQL queries
 * with proper parameter binding to prevent SQL injection.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm;

use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;
use PDO;

/**
 * SQL Builder class for creating safe parameterized queries.
 * 
 * Similar to Drizzle's sql template tag, this allows writing custom SQL
 * while maintaining protection against SQL injection.
 * 
 * Usage:
 *   $db->sql('SELECT * FROM users WHERE id = ?', [$userId])->execute();
 *   $db->sql('SELECT * FROM users WHERE status = ? AND age > ?', ['active', 18])->all();
 *   
 *   // Or using the fluent builder:
 *   $db->sql()
 *      ->append('SELECT * FROM ')
 *      ->identifier('users')
 *      ->append(' WHERE ')
 *      ->identifier('status')
 *      ->append(' = ')
 *      ->value('active')
 *      ->execute();
 */
class Sql
{
    /** @var string SQL query string */
    protected string $query = '';
    
    /** @var array Parameter bindings */
    protected array $params = [];
    
    /** @var PDO|null Database connection */
    protected ?PDO $connection = null;
    
    /** @var string Database dialect */
    protected string $dialect = 'mysql';

    /**
     * Create a new Sql instance
     * 
     * @param string $sql Initial SQL string (with ? placeholders)
     * @param array $params Parameter bindings
     */
    public function __construct(string $sql = '', array $params = [])
    {
        $this->query = $sql;
        $this->params = $params;
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
     * Set the database dialect
     */
    public function set_dialect(string $dialect): self
    {
        $this->dialect = $dialect;
        return $this;
    }

    /**
     * Append raw SQL to the query
     * 
     * @param string $sql SQL string to append
     */
    public function append(string $sql): self
    {
        $this->query .= $sql;
        return $this;
    }

    /**
     * Alias for append()
     */
    public function raw(string $sql): self
    {
        return $this->append($sql);
    }

    /**
     * Append a safely quoted identifier (table name, column name)
     * 
     * @param string|Column|Table $identifier
     */
    public function identifier($identifier): self
    {
        if ($identifier instanceof Column) {
            $table = $identifier->get_table();
            if ($table !== null) {
                $this->query .= $this->quote_identifier($table->get_name()) . '.';
            }
            $this->query .= $this->quote_identifier($identifier->get_db_name());
        } elseif ($identifier instanceof Table) {
            $this->query .= $this->quote_identifier($identifier->get_full_name());
        } else {
            $this->query .= $this->quote_identifier((string)$identifier);
        }
        return $this;
    }

    /**
     * Append a parameter placeholder and bind the value
     * 
     * @param mixed $value Value to bind
     */
    public function value($value): self
    {
        $this->params[] = $value;
        $this->query .= '?';
        return $this;
    }

    /**
     * Append multiple parameter placeholders and bind values
     * 
     * @param array $values Values to bind
     * @param string $separator Separator between placeholders (default: ', ')
     */
    public function values(array $values, string $separator = ', '): self
    {
        $placeholders = [];
        foreach ($values as $value) {
            $this->params[] = $value;
            $placeholders[] = '?';
        }
        $this->query .= implode($separator, $placeholders);
        return $this;
    }

    /**
     * Append an IN clause with values
     * 
     * @param array $values Values for IN clause
     */
    public function in(array $values): self
    {
        $this->query .= 'IN (';
        $this->values($values);
        $this->query .= ')';
        return $this;
    }

    /**
     * Append a column reference
     * 
     * @param Column $column
     */
    public function column(Column $column): self
    {
        return $this->identifier($column);
    }

    /**
     * Append a table reference
     * 
     * @param Table $table
     */
    public function table(Table $table): self
    {
        return $this->identifier($table);
    }

    /**
     * Append a conditional SQL fragment
     * 
     * @param bool $condition If true, appends the SQL
     * @param string $sql SQL to append if condition is true
     * @param array $params Parameters to bind if condition is true
     */
    public function when(bool $condition, string $sql, array $params = []): self
    {
        if ($condition) {
            $this->query .= $sql;
            $this->params = array_merge($this->params, $params);
        }
        return $this;
    }

    /**
     * Append a join of multiple SQL parts
     * 
     * @param array $parts Array of Sql objects or strings
     * @param string $separator Separator between parts
     */
    public function join(array $parts, string $separator = ' '): self
    {
        $sql_parts = [];
        foreach ($parts as $part) {
            if ($part instanceof Sql) {
                $sql_parts[] = $part->get_query();
                $this->params = array_merge($this->params, $part->get_params());
            } else {
                $sql_parts[] = (string)$part;
            }
        }
        $this->query .= implode($separator, $sql_parts);
        return $this;
    }

    /**
     * Merge another Sql object into this one
     * 
     * @param Sql $other
     */
    public function merge(Sql $other): self
    {
        $this->query .= $other->get_query();
        $this->params = array_merge($this->params, $other->get_params());
        return $this;
    }

    /**
     * Get the SQL query string
     */
    public function get_query(): string
    {
        return $this->query;
    }

    /**
     * Alias for get_query()
     */
    public function to_string(): string
    {
        return $this->query;
    }

    /**
     * Get the parameter bindings
     */
    public function get_params(): array
    {
        return $this->params;
    }

    /**
     * Convert to PostgreSQL numbered placeholders ($1, $2, etc.)
     */
    public function to_postgres(): array
    {
        $query = $this->query;
        $index = 1;
        while (($pos = strpos($query, '?')) !== false) {
            $query = substr_replace($query, '$' . $index, $pos, 1);
            $index++;
        }
        return [$query, $this->params];
    }

    /**
     * Execute the query and return the PDOStatement
     * 
     * @return \PDOStatement
     * @throws \RuntimeException if no connection is set
     */
    public function execute(): \PDOStatement
    {
        if ($this->connection === null) {
            throw new \RuntimeException("No database connection set for SQL execution");
        }
        
        $query = $this->query;
        $params = $this->params;
        
        // Convert to PostgreSQL placeholders if needed
        if ($this->dialect === 'postgresql') {
            [$query, $params] = $this->to_postgres();
        }
        
        $stmt = $this->connection->prepare($query);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Execute and fetch all results
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->execute()->fetchAll();
    }

    /**
     * Execute and fetch one result
     * 
     * @return array|null
     */
    public function one(): ?array
    {
        $result = $this->execute()->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Execute and fetch a single column value
     * 
     * @param int $column Column index (default: 0)
     * @return mixed
     */
    public function scalar(int $column = 0)
    {
        return $this->execute()->fetchColumn($column);
    }

    /**
     * Execute and return affected row count
     * 
     * @return int
     */
    public function row_count(): int
    {
        return $this->execute()->rowCount();
    }

    /**
     * Quote an identifier based on dialect
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Magic method for string conversion
     */
    public function __toString(): string
    {
        return $this->query;
    }

    // ============================================
    // Static Factory Methods
    // ============================================

    /**
     * Create a SELECT query
     * 
     * @param string|array $columns Columns to select (* for all)
     */
    public static function select($columns = '*'): self
    {
        $sql = new self();
        $sql->append('SELECT ');
        
        if (is_array($columns)) {
            $cols = [];
            foreach ($columns as $col) {
                if ($col instanceof Column) {
                    $table = $col->get_table();
                    if ($table !== null) {
                        $cols[] = $sql->quote_identifier($table->get_name()) . '.' . 
                                  $sql->quote_identifier($col->get_db_name());
                    } else {
                        $cols[] = $sql->quote_identifier($col->get_db_name());
                    }
                } else {
                    $cols[] = (string)$col;
                }
            }
            $sql->append(implode(', ', $cols));
        } else {
            $sql->append((string)$columns);
        }
        
        return $sql;
    }

    /**
     * Create an INSERT query
     * 
     * @param string|Table $table
     */
    public static function insert_into($table): self
    {
        $sql = new self();
        $sql->append('INSERT INTO ');
        
        if ($table instanceof Table) {
            $sql->identifier($table);
        } else {
            $sql->identifier((string)$table);
        }
        
        return $sql;
    }

    /**
     * Create an UPDATE query
     * 
     * @param string|Table $table
     */
    public static function update_table($table): self
    {
        $sql = new self();
        $sql->append('UPDATE ');
        
        if ($table instanceof Table) {
            $sql->identifier($table);
        } else {
            $sql->identifier((string)$table);
        }
        
        return $sql;
    }

    /**
     * Create a DELETE query
     * 
     * @param string|Table $table
     */
    public static function delete_from($table): self
    {
        $sql = new self();
        $sql->append('DELETE FROM ');
        
        if ($table instanceof Table) {
            $sql->identifier($table);
        } else {
            $sql->identifier((string)$table);
        }
        
        return $sql;
    }

    /**
     * Create a raw SQL fragment with values
     * 
     * @param string $sql SQL with ? placeholders
     * @param array $params Parameter values
     */
    public static function raw_sql(string $sql, array $params = []): self
    {
        return new self($sql, $params);
    }

    /**
     * Create a placeholder for a single value
     * 
     * @param mixed $value
     */
    public static function param($value): self
    {
        return new self('?', [$value]);
    }

    /**
     * Create an empty SQL builder
     */
    public static function empty(): self
    {
        return new self();
    }
}
