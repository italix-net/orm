<?php
/**
 * Italix ORM - Database Driver
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

use PDO;
use PDOException;

/**
 * Database driver for managing connections.
 */
class Driver
{
    /** @var DialectInterface Database dialect */
    protected DialectInterface $dialect;
    
    /** @var array Connection configuration */
    protected array $config;
    
    /** @var PDO|null Database connection */
    protected ?PDO $connection = null;

    /**
     * Create a new Driver instance
     */
    public function __construct(DialectInterface $dialect, array $config)
    {
        $this->dialect = $dialect;
        $this->config = $config;
    }

    /**
     * Get the dialect
     */
    public function get_dialect(): DialectInterface
    {
        return $this->dialect;
    }

    /**
     * Get the dialect name
     */
    public function get_dialect_name(): string
    {
        return $this->dialect->get_name();
    }

    /**
     * Get or create the PDO connection
     */
    public function get_connection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        
        return $this->connection;
    }

    /**
     * Establish database connection
     */
    public function connect(): self
    {
        $dsn = $this->dialect->build_dsn($this->config);
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;
        $options = $this->dialect->get_connection_options();
        
        try {
            $this->connection = new PDO($dsn, $username, $password, $options);
            
            // Enable foreign keys for SQLite
            if ($this->dialect->get_name() === 'sqlite') {
                $this->connection->exec('PRAGMA foreign_keys = ON');
            }
        } catch (PDOException $e) {
            throw new \RuntimeException(
                "Failed to connect to database: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
        
        return $this;
    }

    /**
     * Close the database connection
     */
    public function disconnect(): self
    {
        $this->connection = null;
        return $this;
    }

    /**
     * Check if connected
     */
    public function is_connected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Execute a raw SQL query
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $conn = $this->get_connection();
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Execute a query and fetch all results
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    /**
     * Execute a query and fetch one result
     */
    public function query_one(string $sql, array $params = []): ?array
    {
        $result = $this->execute($sql, $params)->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Get the last inserted ID
     */
    public function last_insert_id(?string $name = null): string
    {
        return $this->get_connection()->lastInsertId($name);
    }

    /**
     * Begin a transaction
     */
    public function begin_transaction(): bool
    {
        return $this->get_connection()->beginTransaction();
    }

    /**
     * Commit the current transaction
     */
    public function commit(): bool
    {
        return $this->get_connection()->commit();
    }

    /**
     * Rollback the current transaction
     */
    public function rollback(): bool
    {
        return $this->get_connection()->rollBack();
    }

    /**
     * Check if inside a transaction
     */
    public function in_transaction(): bool
    {
        return $this->get_connection()->inTransaction();
    }

    // ============================================
    // Static Factory Methods
    // ============================================

    /**
     * Create a MySQL driver
     */
    public static function mysql(array $config): self
    {
        return new self(new MysqlDialect(), $config);
    }

    /**
     * Create a PostgreSQL driver
     */
    public static function postgres(array $config): self
    {
        return new self(new PostgresqlDialect(), $config);
    }

    /**
     * Create a SQLite driver
     */
    public static function sqlite(array $config): self
    {
        return new self(new SqliteDialect(), $config);
    }

    /**
     * Create a SQLite in-memory driver
     */
    public static function sqlite_memory(): self
    {
        return new self(new SqliteDialect(), ['database' => ':memory:']);
    }

    /**
     * Create a Supabase driver
     */
    public static function supabase(array $config): self
    {
        return new self(new SupabaseDialect(), $config);
    }

    /**
     * Create a Supabase driver from credentials
     */
    public static function supabase_from_credentials(
        string $project_ref,
        string $password,
        string $database = 'postgres',
        string $region = 'us-east-1',
        bool $pooling = true
    ): self {
        $config = SupabaseDialect::from_credentials(
            $project_ref,
            $password,
            $database,
            $region,
            $pooling
        );
        
        return new self(new SupabaseDialect(), $config);
    }

    /**
     * Create a driver from connection string
     */
    public static function from_connection_string(string $connection_string): self
    {
        // Parse connection string format: driver://user:pass@host:port/database
        $parsed = parse_url($connection_string);
        
        if ($parsed === false) {
            throw new \InvalidArgumentException("Invalid connection string format");
        }
        
        $scheme = $parsed['scheme'] ?? '';
        
        $config = [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? null,
            'database' => ltrim($parsed['path'] ?? '', '/'),
            'username' => $parsed['user'] ?? null,
            'password' => $parsed['pass'] ?? null,
        ];
        
        // Parse query string options
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $options);
            $config = array_merge($config, $options);
        }
        
        switch ($scheme) {
            case 'mysql':
                return self::mysql($config);
                
            case 'postgres':
            case 'postgresql':
            case 'pgsql':
                return self::postgres($config);
                
            case 'sqlite':
                return self::sqlite(['database' => $config['database']]);
                
            case 'supabase':
                return self::supabase($config);
                
            default:
                throw new \InvalidArgumentException("Unsupported database scheme: {$scheme}");
        }
    }
}
