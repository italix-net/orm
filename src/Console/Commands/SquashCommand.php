<?php
/**
 * Italix ORM - Squash Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

use Italix\Orm\Migration\MigrationSquasher;

/**
 * Squash migrations into a single file
 */
class SquashCommand extends Command
{
    public function get_name(): string
    {
        return 'db:squash';
    }

    public function get_description(): string
    {
        return 'Squash all migrations into a single migration file';
    }

    public function handle(): int
    {
        $db = $this->get_database();
        $path = $this->get_migrations_path();
        
        $squasher = new MigrationSquasher($db, $path);
        
        // Preview
        $up_to = $this->argument(0);
        $preview = $squasher->preview($up_to);
        
        if ($preview['count'] === 0) {
            $this->warn('No migrations found to squash.');
            return 0;
        }
        
        $this->info("Migrations to squash ({$preview['count']}):");
        foreach ($preview['migrations_to_squash'] as $m) {
            $this->line("  - {$m}");
        }
        $this->line('');
        
        $this->info("Current database tables ({$preview['tables_count']}):");
        foreach ($preview['tables_in_schema'] as $t) {
            $this->line("  - {$t}");
        }
        $this->line('');
        
        // Confirm
        if (!$this->has_option('force')) {
            $this->warn('This will:');
            $this->line('  1. Archive all existing migrations to a backup folder');
            $this->line('  2. Create a single squashed migration that recreates the current schema');
            $this->line('');
            
            if (!$this->confirm('Proceed with squashing?', false)) {
                $this->line('Cancelled.');
                return 0;
            }
        }
        
        $this->info('Squashing migrations...');
        
        $result = $squasher->squash($up_to);
        
        $this->success("Squashed {$result['archived_count']} migration(s)");
        $this->line("  Squashed file: " . basename($result['squashed_file']));
        $this->line("  Archive: {$result['archive_dir']}");
        
        $this->line('');
        $this->warn("Important: Update your migrations table if needed:");
        $this->line("  The squashed migration should be marked as 'applied' for existing databases.");
        
        return 0;
    }
}
