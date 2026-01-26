<?php
/**
 * Italix ORM - Push Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

use Italix\Orm\Migration\SchemaPusher;

/**
 * Push schema changes directly to database
 */
class PushCommand extends Command
{
    public function get_name(): string
    {
        return 'db:push';
    }

    public function get_description(): string
    {
        return 'Push schema changes directly to database (no migration files)';
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
        $pusher = new SchemaPusher($db);
        
        // Preview mode
        if ($this->has_option('dry-run')) {
            $this->info('Preview mode (no changes will be made):');
            $preview = $pusher->preview($tables);
            $this->show_preview($preview);
            return 0;
        }
        
        // Confirm if not forced
        $force = $this->has_option('force');
        
        if (!$force) {
            $preview = $pusher->preview($tables);
            $this->show_preview($preview);
            
            if (!$this->confirm('Apply these changes?', false)) {
                $this->line('Cancelled.');
                return 0;
            }
        }
        
        $this->info('Pushing schema to database...');
        
        $result = $pusher->push($tables, $force);
        
        // Show results
        if (!empty($result['created_tables'])) {
            foreach ($result['created_tables'] as $t) {
                $this->success("Created: {$t}");
            }
        }
        
        if (!empty($result['altered_tables'])) {
            foreach ($result['altered_tables'] as $t => $changes) {
                $this->success("Altered: {$t}");
            }
        }
        
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                $this->error($err);
            }
            return 1;
        }
        
        $this->success('Schema pushed successfully!');
        
        return 0;
    }

    /**
     * Load schema from file
     */
    protected function load_schema(string $file): array
    {
        // The schema file should return an array of Table objects
        $result = require $file;
        
        if (is_array($result)) {
            return $result;
        }
        
        // Check for global $tables variable
        global $tables;
        if (isset($tables) && is_array($tables)) {
            return $tables;
        }
        
        return [];
    }

    /**
     * Show preview of changes
     */
    protected function show_preview(array $preview): void
    {
        $this->line('');
        
        if (!empty($preview['create_tables'])) {
            $this->info('Tables to create:');
            foreach ($preview['create_tables'] as $t) {
                $this->line("  + {$t}");
            }
        }
        
        if (!empty($preview['drop_tables'])) {
            $this->warn('Tables to drop (requires --force):');
            foreach ($preview['drop_tables'] as $t) {
                $this->line("  - {$t}");
            }
        }
        
        if (!empty($preview['alter_tables'])) {
            $this->info('Tables to alter:');
            foreach ($preview['alter_tables'] as $t => $changes) {
                $this->line("  ~ {$t}");
                foreach ($changes as $change) {
                    $this->line("      {$change}");
                }
            }
        }
        
        if (empty($preview['create_tables']) && 
            empty($preview['drop_tables']) && 
            empty($preview['alter_tables'])) {
            $this->line('No changes detected.');
        }
        
        $this->line('');
    }
}
