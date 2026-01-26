<?php
/**
 * Italix ORM - Diff Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

use Italix\Orm\Migration\SchemaDiffer;

/**
 * Compare schema with database and suggest migrations
 */
class DiffCommand extends Command
{
    public function get_name(): string
    {
        return 'db:diff';
    }

    public function get_description(): string
    {
        return 'Compare schema with database and generate suggested migration';
    }

    public function handle(): int
    {
        $schema_file = $this->option('schema') ?? $this->app->get_config('schema_file') ?? 'schema.php';
        
        if (!file_exists($schema_file)) {
            $this->error("Schema file not found: {$schema_file}");
            $this->line('Create a schema file or specify with --schema=<file>');
            return 1;
        }
        
        // Load schema definitions
        $tables = $this->load_schema($schema_file);
        
        if (empty($tables)) {
            $this->error('No tables found in schema file.');
            return 1;
        }
        
        $db = $this->get_database();
        $differ = new SchemaDiffer($db);
        
        $diff = $differ->diff($tables);
        $summary = $differ->get_diff_summary($diff);
        
        if ($summary['total_changes'] === 0) {
            $this->success('Schema is in sync with database. No changes needed.');
            return 0;
        }
        
        // Show diff
        $this->info("Found {$summary['total_changes']} change(s):");
        $this->line('');
        
        if (!empty($diff['create_tables'])) {
            $this->info('Tables to create:');
            foreach ($diff['create_tables'] as $t) {
                $this->line("  \033[32m+ {$t}\033[0m");
            }
            $this->line('');
        }
        
        if (!empty($diff['drop_tables'])) {
            $this->warn('Tables in database but not in schema:');
            foreach ($diff['drop_tables'] as $t) {
                $this->line("  \033[31m- {$t}\033[0m");
            }
            $this->line('');
        }
        
        if (!empty($diff['alter_tables'])) {
            $this->info('Tables with changes:');
            foreach ($diff['alter_tables'] as $table => $changes) {
                $this->line("  \033[33m~ {$table}\033[0m");
                
                foreach ($changes['add_columns'] ?? [] as $col) {
                    $this->line("      \033[32m+ {$col['name']} ({$col['type']})\033[0m");
                }
                
                foreach ($changes['drop_columns'] ?? [] as $col) {
                    $this->line("      \033[31m- {$col}\033[0m");
                }
                
                foreach ($changes['modify_columns'] ?? [] as $col => $mods) {
                    $changes_str = [];
                    foreach ($mods as $prop => $change) {
                        $changes_str[] = "{$prop}: {$change['from']} → {$change['to']}";
                    }
                    $this->line("      \033[33m~ {$col} (" . implode(', ', $changes_str) . ")\033[0m");
                }
            }
            $this->line('');
        }
        
        // Generate migration
        if ($this->has_option('generate') || $this->confirm('Generate migration file?', true)) {
            $code = $differ->generate_migration_from_diff($diff);
            
            $output = $this->option('output');
            if ($output) {
                file_put_contents($output, $code);
                $this->success("Migration written to: {$output}");
            } else {
                // Create migration file
                $migrator = $this->get_migrator();
                $timestamp = date('Y_m_d_His');
                $filename = "{$timestamp}_auto_generated_migration.php";
                $filepath = $migrator->get_migrations_path() . '/' . $filename;
                
                if (!is_dir(dirname($filepath))) {
                    mkdir(dirname($filepath), 0755, true);
                }
                
                file_put_contents($filepath, $code);
                $this->success("Created: {$filename}");
                $this->warn("Review the migration before running it!");
            }
        }
        
        return 0;
    }

    /**
     * Load schema from file
     */
    protected function load_schema(string $file): array
    {
        $result = require $file;
        
        if (is_array($result)) {
            return $result;
        }
        
        global $tables;
        if (isset($tables) && is_array($tables)) {
            return $tables;
        }
        
        return [];
    }
}
