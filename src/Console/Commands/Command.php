<?php
/**
 * Italix ORM - Base Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

use Italix\Orm\Console\Application;
use Italix\Orm\IxOrm;
use Italix\Orm\Migration\Migrator;
use Italix\Orm\Migration\Schema;

/**
 * Base class for CLI commands
 */
abstract class Command
{
    protected Application $app;
    protected ?IxOrm $db = null;
    protected ?Migrator $migrator = null;
    protected array $options = [];
    protected array $arguments = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get command name
     */
    abstract public function get_name(): string;

    /**
     * Get command description
     */
    abstract public function get_description(): string;

    /**
     * Execute the command
     */
    abstract public function handle(): int;

    /**
     * Run the command with arguments
     */
    public function run(array $argv): int
    {
        $this->parse_arguments($argv);
        return $this->handle();
    }

    /**
     * Parse command line arguments
     */
    protected function parse_arguments(array $argv): void
    {
        $this->arguments = [];
        $this->options = [];
        
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0) {
                // Long option
                $arg = substr($arg, 2);
                if (strpos($arg, '=') !== false) {
                    [$key, $value] = explode('=', $arg, 2);
                    $this->options[$key] = $value;
                } else {
                    $this->options[$arg] = true;
                }
            } elseif (strpos($arg, '-') === 0) {
                // Short option
                $this->options[substr($arg, 1)] = true;
            } else {
                // Argument
                $this->arguments[] = $arg;
            }
        }
    }

    /**
     * Get option value
     */
    protected function option(string $name, $default = null)
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Check if option exists
     */
    protected function has_option(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * Get argument by index
     */
    protected function argument(int $index, $default = null)
    {
        return $this->arguments[$index] ?? $default;
    }

    /**
     * Get database connection
     */
    protected function get_database(): IxOrm
    {
        if ($this->db !== null) {
            return $this->db;
        }
        
        $config = $this->app->get_all_config();
        
        // Check for connection string
        if (isset($config['database_url'])) {
            $this->db = $this->connect_from_url($config['database_url']);
            return $this->db;
        }
        
        // Check for connection config
        if (!isset($config['database'])) {
            throw new \RuntimeException(
                "No database configuration found.\n" .
                "Create ix.config.php with database settings or use --config option."
            );
        }
        
        $db_config = $config['database'];
        $dialect = $db_config['dialect'] ?? $db_config['driver'] ?? 'mysql';
        
        $this->db = match ($dialect) {
            'mysql' => \Italix\Orm\mysql($db_config),
            'postgresql', 'postgres', 'pgsql' => \Italix\Orm\postgresql($db_config),
            'sqlite' => \Italix\Orm\sqlite($db_config),
            'supabase' => \Italix\Orm\supabase($db_config),
            default => throw new \RuntimeException("Unknown dialect: {$dialect}"),
        };
        
        return $this->db;
    }

    /**
     * Connect from database URL
     */
    protected function connect_from_url(string $url): IxOrm
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'mysql';
        
        $config = [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? null,
            'database' => ltrim($parsed['path'] ?? '', '/'),
            'username' => $parsed['user'] ?? null,
            'password' => $parsed['pass'] ?? null,
        ];
        
        return match ($scheme) {
            'mysql' => \Italix\Orm\mysql($config),
            'postgresql', 'postgres', 'pgsql' => \Italix\Orm\postgresql($config),
            'sqlite' => \Italix\Orm\sqlite(['database' => $config['database']]),
            default => throw new \RuntimeException("Unknown scheme: {$scheme}"),
        };
    }

    /**
     * Get migrator instance
     */
    protected function get_migrator(): Migrator
    {
        if ($this->migrator !== null) {
            return $this->migrator;
        }
        
        $db = $this->get_database();
        $path = $this->get_migrations_path();
        
        $this->migrator = new Migrator($db, $path);
        
        // Configure migrations table if specified
        $table = $this->app->get_config('migrations_table');
        if ($table) {
            $this->migrator->set_migrations_table($table);
        }
        
        return $this->migrator;
    }

    /**
     * Get migrations path
     */
    protected function get_migrations_path(): string
    {
        $path = $this->option('path') 
             ?? $this->app->get_config('migrations_path') 
             ?? 'migrations';
        
        // Make absolute if relative
        if ($path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }
        
        return $path;
    }

    /**
     * Output helpers
     */
    protected function line(string $message): void
    {
        $this->app->line($message);
    }

    protected function info(string $message): void
    {
        $this->app->info($message);
    }

    protected function warn(string $message): void
    {
        $this->app->warn($message);
    }

    protected function error(string $message): void
    {
        $this->app->error($message);
    }

    protected function success(string $message): void
    {
        $this->app->success($message);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->app->confirm($question, $default);
    }

    protected function table(array $headers, array $rows): void
    {
        $this->app->table($headers, $rows);
    }
}
