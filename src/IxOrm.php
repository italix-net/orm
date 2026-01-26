<?php
/**
 * Italix ORM - Main ORM Class
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm;

use Italix\Orm\Dialects\Driver;
use Italix\Orm\Dialects\DialectInterface;
use Italix\Orm\QueryBuilder\QueryBuilder;
use Italix\Orm\Schema\Table;
use Italix\Orm\Sql;
use PDO;

/**
 * Main ORM class providing database operations.
 */
class IxOrm
{
    /** @var Driver Database driver */
    protected Driver $driver;
    
    /** @var QueryBuilder Query builder instance */
    protected QueryBuilder $query_builder;

    /**
     * Create a new IxOrm instance
     */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
        $this->query_builder = new QueryBuilder($driver->get_dialect_name());
        $this->query_builder->set_connection($driver->get_connection());
    }

    /**
     * Get the database driver
     */
    public function get_driver(): Driver
    {
        return $this->driver;
    }

    /**
     * Get the dialect
     */
    public function get_dialect(): DialectInterface
    {
        return $this->driver->get_dialect();
    }

    /**
     * Get the PDO connection
     */
    public function get_connection(): PDO
    {
        return $this->driver->get_connection();
    }

    /**
     * Start a SELECT query
     * 
     * @param array|null $columns Columns to select
     */
    public function select(?array $columns = null): QueryBuilder
    {
        return $this->query_builder->select($columns);
    }

    /**
     * Start an INSERT query
     */
    public function insert(Table $table): QueryBuilder
    {
        return $this->query_builder->insert($table);
    }

    /**
     * Start an UPDATE query
     */
    public function update(Table $table): QueryBuilder
    {
        return $this->query_builder->update($table);
    }

    /**
     * Start a DELETE query
     */
    public function delete(Table $table): QueryBuilder
    {
        return $this->query_builder->delete($table);
    }

    /**
     * Create tables from schemas
     * 
     * @param Table ...$tables
     */
    public function create_tables(Table ...$tables): void
    {
        foreach ($tables as $table) {
            $sql = $table->to_create_sql();
            $this->driver->execute($sql);
            
            // Create indexes
            foreach ($table->get_index_sql() as $index_sql) {
                try {
                    $this->driver->execute($index_sql);
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }
    }

    /**
     * Drop tables
     * 
     * @param Table ...$tables
     */
    public function drop_tables(Table ...$tables): void
    {
        foreach ($tables as $table) {
            $sql = $table->to_drop_sql();
            $this->driver->execute($sql);
        }
    }

    /**
     * Check if a table exists
     */
    public function table_exists(string $table_name): bool
    {
        $sql = $this->driver->get_dialect()->get_table_exists_sql($table_name);
        $result = $this->driver->query_one($sql, [$table_name]);
        return $result !== null;
    }

    /**
     * Execute a raw SQL query
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        return $this->driver->execute($sql, $params);
    }

    /**
     * Execute a query and fetch all results
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->driver->query($sql, $params);
    }

    /**
     * Execute a query and fetch one result
     */
    public function query_one(string $sql, array $params = []): ?array
    {
        return $this->driver->query_one($sql, $params);
    }

    /**
     * Begin a transaction
     */
    public function begin_transaction(): bool
    {
        return $this->driver->begin_transaction();
    }

    /**
     * Commit the current transaction
     */
    public function commit(): bool
    {
        return $this->driver->commit();
    }

    /**
     * Rollback the current transaction
     */
    public function rollback(): bool
    {
        return $this->driver->rollback();
    }

    /**
     * Execute a callback within a transaction
     * 
     * @param callable $callback
     * @return mixed Result of callback
     */
    public function transaction(callable $callback)
    {
        $this->begin_transaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get the last inserted ID
     */
    public function last_insert_id(?string $name = null): string
    {
        return $this->driver->last_insert_id($name);
    }

    /**
     * Create a custom SQL query with safe parameter binding
     * 
     * This method provides a way to write custom SQL while maintaining
     * protection against SQL injection through parameterized queries.
     * 
     * Usage:
     *   // Simple query with parameters
     *   $db->sql('SELECT * FROM users WHERE id = ?', [$userId])->all();
     *   
     *   // Multiple parameters
     *   $db->sql('SELECT * FROM users WHERE status = ? AND age > ?', ['active', 18])->all();
     *   
     *   // Fluent builder
     *   $db->sql()
     *      ->append('SELECT * FROM ')
     *      ->identifier('users')
     *      ->append(' WHERE id = ')
     *      ->value($userId)
     *      ->all();
     * 
     * @param string $query SQL query with ? placeholders (optional)
     * @param array $params Parameter bindings (optional)
     * @return Sql
     */
    public function sql(string $query = '', array $params = []): Sql
    {
        $sql = new Sql($query, $params);
        $sql->set_connection($this->driver->get_connection());
        $sql->set_dialect($this->driver->get_dialect_name());
        return $sql;
    }
}
