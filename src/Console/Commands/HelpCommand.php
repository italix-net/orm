<?php
/**
 * Italix ORM - Help Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Display help information
 */
class HelpCommand extends Command
{
    public function get_name(): string
    {
        return 'help';
    }

    public function get_description(): string
    {
        return 'Display help information';
    }

    public function handle(): int
    {
        $this->line('');
        $this->line("\033[32m  ___  _        ___  ____  __  __ \033[0m");
        $this->line("\033[32m |_ _|( )_ __  / _ \|  _ \|  \/  |\033[0m");
        $this->line("\033[32m  | | |/| '__|/ | | | |_) | |\/| |\033[0m");
        $this->line("\033[32m  | |   | |  | |_| |  _ <| |  | |\033[0m");
        $this->line("\033[32m |___|  |_|   \___/|_| \_\_|  |_|\033[0m");
        $this->line('');
        $this->line("  \033[1mItalix ORM\033[0m - " . $this->app->get_version());
        $this->line('  Database migration and schema management tool');
        $this->line('');
        
        $this->info('Usage:');
        $this->line('  ix <command> [options] [arguments]');
        $this->line('');
        
        $this->info('Available Commands:');
        $this->line('');
        
        $this->line("  \033[33mMigrations:\033[0m");
        $this->line('    migrate              Run all pending migrations');
        $this->line('    migrate:rollback     Rollback the last migration batch');
        $this->line('    migrate:reset        Rollback all migrations');
        $this->line('    migrate:refresh      Reset and re-run all migrations');
        $this->line('    migrate:status       Show status of each migration');
        $this->line('    make:migration       Create a new migration file');
        $this->line('');
        
        $this->line("  \033[33mSchema Management:\033[0m");
        $this->line('    db:push              Push schema directly to database (no migration files)');
        $this->line('    db:pull              Generate code from existing database schema');
        $this->line('    db:diff              Compare schema with database, suggest migration');
        $this->line('    db:squash            Squash migrations into a single file');
        $this->line('');
        
        $this->info('Global Options:');
        $this->line('    --config=<file>      Use specific config file');
        $this->line('    --path=<dir>         Migrations directory');
        $this->line('    -v, --verbose        Verbose output');
        $this->line('    -q, --quiet          Quiet mode');
        $this->line('    --force              Skip confirmations');
        $this->line('');
        
        $this->info('Examples:');
        $this->line('');
        $this->line('  # Run migrations');
        $this->line('  ix migrate');
        $this->line('');
        $this->line('  # Create a migration');
        $this->line('  ix make:migration create_users_table');
        $this->line('  ix make:migration add_email_to_users --table=users');
        $this->line('');
        $this->line('  # Rollback');
        $this->line('  ix migrate:rollback');
        $this->line('  ix migrate:rollback --steps=3');
        $this->line('');
        $this->line('  # Pull existing database schema');
        $this->line('  ix db:pull --output=schema.php');
        $this->line('  ix db:pull --format=migration --init');
        $this->line('');
        $this->line('  # Push schema changes (rapid prototyping)');
        $this->line('  ix db:push --schema=schema.php');
        $this->line('  ix db:push --dry-run');
        $this->line('');
        $this->line('  # Auto-generate migration from diff');
        $this->line('  ix db:diff --schema=schema.php --generate');
        $this->line('');
        $this->line('  # Squash old migrations');
        $this->line('  ix db:squash');
        $this->line('');
        
        $this->info('Configuration:');
        $this->line('');
        $this->line('  Create ix.config.php in your project root:');
        $this->line('');
        $this->line("  \033[90m<?php\033[0m");
        $this->line("  \033[90mreturn [\033[0m");
        $this->line("  \033[90m    'database' => [\033[0m");
        $this->line("  \033[90m        'dialect' => 'mysql',\033[0m");
        $this->line("  \033[90m        'host' => 'localhost',\033[0m");
        $this->line("  \033[90m        'database' => 'myapp',\033[0m");
        $this->line("  \033[90m        'username' => 'root',\033[0m");
        $this->line("  \033[90m        'password' => '',\033[0m");
        $this->line("  \033[90m    ],\033[0m");
        $this->line("  \033[90m    'migrations_path' => 'migrations',\033[0m");
        $this->line("  \033[90m    'schema_file' => 'schema.php',\033[0m");
        $this->line("  \033[90m];\033[0m");
        $this->line('');
        
        return 0;
    }
}
