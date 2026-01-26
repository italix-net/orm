<?php
/**
 * Italix ORM - Refresh Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Reset and re-run all migrations
 */
class RefreshCommand extends Command
{
    public function get_name(): string
    {
        return 'migrate:refresh';
    }

    public function get_description(): string
    {
        return 'Reset and re-run all migrations';
    }

    public function handle(): int
    {
        if (!$this->has_option('force')) {
            if (!$this->confirm('This will reset and re-run ALL migrations. Are you sure?', false)) {
                $this->line('Cancelled.');
                return 0;
            }
        }
        
        $this->info('Refreshing migrations...');
        
        $migrator = $this->get_migrator();
        $applied = $migrator->refresh();
        
        $this->success(count($applied) . ' migration(s) applied.');
        
        return 0;
    }
}
