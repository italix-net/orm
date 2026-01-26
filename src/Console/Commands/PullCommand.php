<?php
/**
 * Italix ORM - Pull Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

use Italix\Orm\Migration\SchemaIntrospector;

/**
 * Pull database schema and generate code
 */
class PullCommand extends Command
{
    public function get_name(): string
    {
        return 'db:pull';
    }

    public function get_description(): string
    {
        return 'Pull database schema and generate PHP code';
    }

    public function handle(): int
    {
        $db = $this->get_database();
        $introspector = new SchemaIntrospector($db);
        
        $tables = $introspector->get_tables();
        
        if (empty($tables)) {
            $this->warn('No tables found in database.');
            return 0;
        }
        
        $this->info("Found " . count($tables) . " table(s):");
        foreach ($tables as $t) {
            $this->line("  - {$t}");
        }
        $this->line('');
        
        // Determine output format
        $format = $this->option('format', 'schema');
        $output = $this->option('output');
        
        if ($format === 'migration') {
            // Generate migration code
            $code = $this->generate_migration_code($introspector, $tables);
        } else {
            // Generate schema code
            $code = $introspector->generate_schema_code($tables);
        }
        
        if ($output) {
            // Write to file
            file_put_contents($output, $code);
            $this->success("Written to: {$output}");
        } else {
            // Output to console
            $this->line($code);
        }
        
        // Optionally mark as initial migration
        if ($this->has_option('init')) {
            $this->init_migration($tables);
        }
        
        return 0;
    }

    /**
     * Generate migration code from introspected schema
     */
    protected function generate_migration_code(SchemaIntrospector $introspector, array $tables): string
    {
        $up_code = [];
        $down_code = [];
        
        foreach ($tables as $table) {
            $up_code[] = $introspector->generate_migration_code($table);
            $up_code[] = '';
            $down_code[] = "Schema::drop_if_exists('{$table}');";
        }
        
        $up_body = implode("\n        ", $up_code);
        $down_body = implode("\n        ", array_reverse($down_code));
        
        $timestamp = date('Y_m_d_His');
        
        return <<<PHP
<?php
/**
 * Migration generated from existing database schema
 * Generated: {$this->now()}
 */

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class InitialSchema extends Migration
{
    public function up(): void
    {
        {$up_body}
    }

    public function down(): void
    {
        {$down_body}
    }
}

PHP;
    }

    /**
     * Create initial migration and mark it as applied
     */
    protected function init_migration(array $tables): void
    {
        $migrator = $this->get_migrator();
        $db = $this->get_database();
        
        // Generate and save migration file
        $introspector = new SchemaIntrospector($db);
        $code = $this->generate_migration_code($introspector, $tables);
        
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_initial_schema.php";
        $filepath = $migrator->get_migrations_path() . '/' . $filename;
        
        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }
        
        file_put_contents($filepath, $code);
        
        // Mark as applied in migrations table
        $migration_name = basename($filename, '.php');
        $table = $migrator->get_migrations_table();
        
        $db->execute(
            "INSERT INTO {$table} (migration, batch) VALUES (?, 0)",
            [$migration_name]
        );
        
        $this->success("Created and marked as applied: {$filename}");
        $this->line("You can now continue adding new migrations.");
    }

    /**
     * Get current timestamp
     */
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
