<?php
/**
 * Italix ORM - Console Application
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console;

/**
 * CLI Application for Italix ORM (ix command)
 */
class Application
{
    protected string $name = 'Italix ORM';
    protected string $version = '1.0.0';
    protected array $commands = [];
    protected ?string $config_file = null;
    protected array $config = [];

    public function __construct()
    {
        $this->register_default_commands();
    }

    /**
     * Register default commands
     */
    protected function register_default_commands(): void
    {
        $this->commands = [
            'migrate' => Commands\MigrateCommand::class,
            'migrate:rollback' => Commands\RollbackCommand::class,
            'migrate:reset' => Commands\ResetCommand::class,
            'migrate:refresh' => Commands\RefreshCommand::class,
            'migrate:status' => Commands\StatusCommand::class,
            'make:migration' => Commands\MakeMigrationCommand::class,
            'db:push' => Commands\PushCommand::class,
            'db:pull' => Commands\PullCommand::class,
            'db:diff' => Commands\DiffCommand::class,
            'db:squash' => Commands\SquashCommand::class,
            'help' => Commands\HelpCommand::class,
        ];
    }

    /**
     * Run the application
     */
    public function run(array $argv): int
    {
        // Remove script name
        array_shift($argv);
        
        // Parse global options
        $argv = $this->parse_global_options($argv);
        
        // Get command name
        $command_name = $argv[0] ?? 'help';
        array_shift($argv);
        
        // Find and run command
        if (!isset($this->commands[$command_name])) {
            $this->error("Unknown command: {$command_name}");
            $this->line("Run 'ix help' to see available commands.");
            return 1;
        }
        
        try {
            $this->load_config();
            
            $command_class = $this->commands[$command_name];
            $command = new $command_class($this);
            
            return $command->run($argv);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if ($this->is_verbose()) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Parse global options
     */
    protected function parse_global_options(array $argv): array
    {
        $filtered = [];
        
        foreach ($argv as $arg) {
            if (strpos($arg, '--config=') === 0) {
                $this->config_file = substr($arg, 9);
            } elseif ($arg === '-v' || $arg === '--verbose') {
                $this->config['verbose'] = true;
            } elseif ($arg === '-q' || $arg === '--quiet') {
                $this->config['quiet'] = true;
            } else {
                $filtered[] = $arg;
            }
        }
        
        return $filtered;
    }

    /**
     * Load configuration file
     */
    protected function load_config(): void
    {
        $config_file = $this->config_file ?? $this->find_config_file();
        
        if ($config_file && file_exists($config_file)) {
            $config = require $config_file;
            if (is_array($config)) {
                $this->config = array_merge($this->config, $config);
            }
        }
    }

    /**
     * Find config file in current directory
     */
    protected function find_config_file(): ?string
    {
        $files = [
            'ix.config.php',
            'italix.config.php',
            'config/database.php',
        ];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                return $file;
            }
        }
        
        return null;
    }

    /**
     * Get configuration value
     */
    public function get_config(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    public function set_config(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Get full config array
     */
    public function get_all_config(): array
    {
        return $this->config;
    }

    /**
     * Check if verbose mode
     */
    public function is_verbose(): bool
    {
        return $this->config['verbose'] ?? false;
    }

    /**
     * Check if quiet mode
     */
    public function is_quiet(): bool
    {
        return $this->config['quiet'] ?? false;
    }

    /**
     * Output a line
     */
    public function line(string $message): void
    {
        if (!$this->is_quiet()) {
            echo $message . "\n";
        }
    }

    /**
     * Output info message (green)
     */
    public function info(string $message): void
    {
        $this->line("\033[32m{$message}\033[0m");
    }

    /**
     * Output warning (yellow)
     */
    public function warn(string $message): void
    {
        $this->line("\033[33m{$message}\033[0m");
    }

    /**
     * Output error (red)
     */
    public function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m\n";
    }

    /**
     * Output success message
     */
    public function success(string $message): void
    {
        $this->line("\033[32m✓ {$message}\033[0m");
    }

    /**
     * Ask for confirmation
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $options = $default ? '[Y/n]' : '[y/N]';
        echo "{$question} {$options} ";
        
        $handle = fopen('php://stdin', 'r');
        $answer = trim(fgets($handle));
        fclose($handle);
        
        if ($answer === '') {
            return $default;
        }
        
        return strtolower($answer[0]) === 'y';
    }

    /**
     * Display a table
     */
    public function table(array $headers, array $rows): void
    {
        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string)$cell));
            }
        }
        
        // Print header
        $line = '+';
        foreach ($widths as $w) {
            $line .= str_repeat('-', $w + 2) . '+';
        }
        $this->line($line);
        
        $header_line = '|';
        foreach ($headers as $i => $header) {
            $header_line .= ' ' . str_pad($header, $widths[$i]) . ' |';
        }
        $this->line($header_line);
        $this->line($line);
        
        // Print rows
        foreach ($rows as $row) {
            $row_line = '|';
            foreach ($row as $i => $cell) {
                $row_line .= ' ' . str_pad((string)$cell, $widths[$i]) . ' |';
            }
            $this->line($row_line);
        }
        
        $this->line($line);
    }

    /**
     * Get application name
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Get application version
     */
    public function get_version(): string
    {
        return $this->version;
    }

    /**
     * Get registered commands
     */
    public function get_commands(): array
    {
        return $this->commands;
    }
}
