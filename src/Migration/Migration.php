<?php
/**
 * Italix ORM - Migration Base Class
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;

/**
 * Base class for database migrations.
 * Extend this class to create migrations with up() and down() methods.
 */
abstract class Migration
{
    /** @var IxOrm Database connection */
    protected IxOrm $db;
    
    /** @var bool Whether to wrap migration in transaction */
    protected bool $transactional = true;
    
    /** @var string Migration name (set by migrator) */
    protected string $name = '';

    /**
     * Run the migration (apply changes)
     */
    abstract public function up(): void;

    /**
     * Reverse the migration (rollback changes)
     */
    abstract public function down(): void;

    /**
     * Set the database connection
     */
    public function set_connection(IxOrm $db): void
    {
        $this->db = $db;
        Schema::set_connection($db);
    }

    /**
     * Get the database connection
     */
    public function get_connection(): IxOrm
    {
        return $this->db;
    }

    /**
     * Set migration name
     */
    public function set_name(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Get migration name
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Check if migration should be wrapped in transaction
     */
    public function is_transactional(): bool
    {
        return $this->transactional;
    }

    /**
     * Execute raw SQL
     * 
     * @param string $query SQL query
     * @param array $params Parameters for prepared statement
     */
    protected function sql(string $query, array $params = []): void
    {
        $this->db->execute($query, $params);
    }

    /**
     * Execute raw SQL and return results
     * 
     * @param string $query SQL query
     * @param array $params Parameters for prepared statement
     * @return array
     */
    protected function query(string $query, array $params = []): array
    {
        return $this->db->query($query, $params);
    }

    /**
     * Run different SQL based on dialect
     * 
     * @param array $queries ['mysql' => '...', 'postgresql' => '...', 'default' => '...']
     */
    protected function dialect(array $queries): void
    {
        $dialect = $this->db->get_driver()->get_dialect_name();
        
        if (isset($queries[$dialect])) {
            $this->sql($queries[$dialect]);
        } elseif (isset($queries['default'])) {
            $this->sql($queries['default']);
        }
    }

    /**
     * Output a message during migration
     */
    protected function info(string $message): void
    {
        echo "  → {$message}\n";
    }

    /**
     * Output a warning during migration
     */
    protected function warn(string $message): void
    {
        echo "  ⚠ {$message}\n";
    }

    /**
     * Seed data into a table
     * 
     * @param string $table Table name
     * @param array $data Array of rows to insert
     */
    protected function seed(string $table, array $data): void
    {
        if (empty($data)) {
            return;
        }
        
        $dialect = $this->db->get_driver()->get_dialect_name();
        $table_quoted = $this->quote_identifier($table, $dialect);
        
        // Get columns from first row
        $columns = array_keys($data[0]);
        $col_names = array_map(fn($c) => $this->quote_identifier($c, $dialect), $columns);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO {$table_quoted} (" . implode(', ', $col_names) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        foreach ($data as $row) {
            $values = array_map(fn($c) => $row[$c] ?? null, $columns);
            $this->db->execute($sql, $values);
        }
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
