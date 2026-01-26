<?php
/**
 * Italix ORM - Migrator
 * 
 * Core migration engine that handles running, rolling back, and tracking migrations.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;

/**
 * Manages database migrations: run, rollback, status, and tracking.
 */
class Migrator
{
    protected IxOrm $db;
    protected string $migrations_path;
    protected string $migrations_table = 'ix_migrations';
    protected string $dialect;
    protected bool $output_enabled = true;

    public function __construct(IxOrm $db, string $migrations_path)
    {
        $this->db = $db;
        $this->migrations_path = rtrim($migrations_path, '/');
        $this->dialect = $db->get_driver()->get_dialect_name();
        
        Schema::set_connection($db);
        $this->ensure_migrations_table();
    }

    /**
     * Run all pending migrations
     * 
     * @return array List of applied migration names
     */
    public function migrate(): array
    {
        $pending = $this->pending();
        
        if (empty($pending)) {
            $this->output("Nothing to migrate.");
            return [];
        }

        $batch = $this->get_next_batch_number();
        $applied = [];

        foreach ($pending as $name => $file) {
            $this->output("Migrating: {$name}");
            
            $start = microtime(true);
            $this->run_migration($name, $file, 'up', $batch);
            $time = round((microtime(true) - $start) * 1000, 2);
            
            $this->output("Migrated:  {$name} ({$time}ms)");
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Rollback the last batch of migrations
     * 
     * @param int $steps Number of batches to rollback (0 = all)
     * @return array List of rolled back migration names
     */
    public function rollback(int $steps = 1): array
    {
        $migrations = $this->get_migrations_to_rollback($steps);
        
        if (empty($migrations)) {
            $this->output("Nothing to rollback.");
            return [];
        }

        $rolled_back = [];

        foreach ($migrations as $migration) {
            $name = $migration['migration'];
            $file = $this->migrations_path . '/' . $name . '.php';
            
            if (!file_exists($file)) {
                $this->output("Migration file not found: {$name}");
                continue;
            }

            $this->output("Rolling back: {$name}");
            
            $start = microtime(true);
            $this->run_migration($name, $file, 'down');
            $this->remove_migration_record($name);
            $time = round((microtime(true) - $start) * 1000, 2);
            
            $this->output("Rolled back:  {$name} ({$time}ms)");
            $rolled_back[] = $name;
        }

        return $rolled_back;
    }

    /**
     * Rollback all migrations
     */
    public function reset(): array
    {
        return $this->rollback(0);
    }

    /**
     * Rollback all and re-run migrations
     */
    public function refresh(): array
    {
        $this->reset();
        return $this->migrate();
    }

    /**
     * Get migration status
     * 
     * @return array [{name, status, batch, ran_at}]
     */
    public function status(): array
    {
        $all_files = $this->get_migration_files();
        $applied = $this->get_applied_migrations();
        $applied_map = [];
        
        foreach ($applied as $m) {
            $applied_map[$m['migration']] = $m;
        }

        $status = [];
        foreach ($all_files as $name => $file) {
            if (isset($applied_map[$name])) {
                $status[] = [
                    'name' => $name,
                    'status' => 'Ran',
                    'batch' => $applied_map[$name]['batch'],
                    'ran_at' => $applied_map[$name]['applied_at'] ?? null,
                ];
            } else {
                $status[] = [
                    'name' => $name,
                    'status' => 'Pending',
                    'batch' => null,
                    'ran_at' => null,
                ];
            }
        }

        return $status;
    }

    /**
     * Get pending migrations
     * 
     * @return array [name => filepath]
     */
    public function pending(): array
    {
        $all = $this->get_migration_files();
        $applied = array_column($this->get_applied_migrations(), 'migration');
        
        return array_filter($all, fn($name) => !in_array($name, $applied), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get applied migrations
     */
    public function get_applied_migrations(): array
    {
        $table = $this->quote_identifier($this->migrations_table);
        return $this->db->query("SELECT * FROM {$table} ORDER BY batch ASC, migration ASC");
    }

    /**
     * Create a new migration file
     * 
     * @param string $name Migration name (e.g., "create_users_table")
     * @param string|null $table Table name for pre-filled template
     * @param bool $create Whether this is a create table migration
     * @return string Path to created file
     */
    public function create(string $name, ?string $table = null, bool $create = false): string
    {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->migrations_path . '/' . $filename;
        
        // Generate class name from migration name
        $class_name = $this->name_to_class($name);
        
        // Generate migration content
        $content = $this->generate_migration_content($class_name, $table, $create);
        
        // Ensure directory exists
        if (!is_dir($this->migrations_path)) {
            mkdir($this->migrations_path, 0755, true);
        }
        
        file_put_contents($filepath, $content);
        
        return $filepath;
    }

    /**
     * Run a specific migration
     */
    protected function run_migration(string $name, string $file, string $direction, ?int $batch = null): void
    {
        require_once $file;
        
        $class_name = $this->file_to_class($file);
        
        if (!class_exists($class_name)) {
            throw new \RuntimeException("Migration class {$class_name} not found in {$file}");
        }

        /** @var Migration $migration */
        $migration = new $class_name();
        $migration->set_connection($this->db);
        $migration->set_name($name);

        $use_transaction = $migration->is_transactional() && $this->dialect !== 'mysql';

        try {
            if ($use_transaction) {
                $this->db->begin_transaction();
            }

            if ($direction === 'up') {
                $migration->up();
                $this->record_migration($name, $batch);
            } else {
                $migration->down();
            }

            if ($use_transaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($use_transaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Get migration files from directory
     * 
     * @return array [name => filepath]
     */
    protected function get_migration_files(): array
    {
        if (!is_dir($this->migrations_path)) {
            return [];
        }

        $files = glob($this->migrations_path . '/*.php');
        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $migrations[$name] = $file;
        }

        ksort($migrations);
        return $migrations;
    }

    /**
     * Get migrations to rollback
     */
    protected function get_migrations_to_rollback(int $steps): array
    {
        $table = $this->quote_identifier($this->migrations_table);
        
        if ($steps === 0) {
            // Rollback all
            return $this->db->query("SELECT * FROM {$table} ORDER BY batch DESC, migration DESC");
        }

        // Get the last N batches
        $batches = $this->db->query(
            "SELECT DISTINCT batch FROM {$table} ORDER BY batch DESC LIMIT {$steps}"
        );
        
        if (empty($batches)) {
            return [];
        }

        $batch_numbers = array_column($batches, 'batch');
        $placeholders = implode(',', array_fill(0, count($batch_numbers), '?'));
        
        return $this->db->query(
            "SELECT * FROM {$table} WHERE batch IN ({$placeholders}) ORDER BY batch DESC, migration DESC",
            $batch_numbers
        );
    }

    /**
     * Record a migration as applied
     */
    protected function record_migration(string $name, int $batch): void
    {
        $table = $this->quote_identifier($this->migrations_table);
        $this->db->execute(
            "INSERT INTO {$table} (migration, batch) VALUES (?, ?)",
            [$name, $batch]
        );
    }

    /**
     * Remove migration record
     */
    protected function remove_migration_record(string $name): void
    {
        $table = $this->quote_identifier($this->migrations_table);
        $this->db->execute("DELETE FROM {$table} WHERE migration = ?", [$name]);
    }

    /**
     * Get the next batch number
     */
    protected function get_next_batch_number(): int
    {
        $table = $this->quote_identifier($this->migrations_table);
        $result = $this->db->query("SELECT MAX(batch) as max_batch FROM {$table}");
        return ($result[0]['max_batch'] ?? 0) + 1;
    }

    /**
     * Ensure migrations table exists
     */
    protected function ensure_migrations_table(): void
    {
        $table = $this->quote_identifier($this->migrations_table);
        
        $sql = match ($this->dialect) {
            'mysql' => "CREATE TABLE IF NOT EXISTS {$table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                applied_at TEXT DEFAULT CURRENT_TIMESTAMP
            )",
            
            default => "CREATE TABLE IF NOT EXISTS {$table} (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                applied_at TIMESTAMP DEFAULT NOW()
            )",
        };

        $this->db->execute($sql);
    }

    /**
     * Convert filename to class name
     */
    protected function file_to_class(string $file): string
    {
        $name = basename($file, '.php');
        // Remove timestamp prefix (YYYY_MM_DD_HHMMSS_)
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
        return $this->name_to_class($name);
    }

    /**
     * Convert migration name to class name
     */
    protected function name_to_class(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    /**
     * Generate migration file content
     */
    protected function generate_migration_content(string $class_name, ?string $table, bool $create): string
    {
        $up_body = '';
        $down_body = '';

        if ($table !== null) {
            if ($create) {
                $up_body = <<<PHP
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            
            \$table->timestamps();
        });
PHP;
                $down_body = "        Schema::drop_if_exists('{$table}');";
            } else {
                $up_body = <<<PHP
        Schema::table('{$table}', function (Blueprint \$table) {
            //
        });
PHP;
                $down_body = <<<PHP
        Schema::table('{$table}', function (Blueprint \$table) {
            //
        });
PHP;
            }
        } else {
            $up_body = '        //';
            $down_body = '        //';
        }

        return <<<PHP
<?php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class {$class_name} extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
{$up_body}
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
{$down_body}
    }
}

PHP;
    }

    /**
     * Quote identifier for current dialect
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Enable/disable output
     */
    public function set_output(bool $enabled): void
    {
        $this->output_enabled = $enabled;
    }

    /**
     * Output a message
     */
    protected function output(string $message): void
    {
        if ($this->output_enabled) {
            echo $message . "\n";
        }
    }

    /**
     * Get migrations path
     */
    public function get_migrations_path(): string
    {
        return $this->migrations_path;
    }

    /**
     * Get migrations table name
     */
    public function get_migrations_table(): string
    {
        return $this->migrations_table;
    }

    /**
     * Set migrations table name
     */
    public function set_migrations_table(string $table): void
    {
        $this->migrations_table = $table;
    }
}
