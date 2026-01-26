<?php
/**
 * Italix ORM - Status Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Show migration status
 */
class StatusCommand extends Command
{
    public function get_name(): string
    {
        return 'migrate:status';
    }

    public function get_description(): string
    {
        return 'Show the status of each migration';
    }

    public function handle(): int
    {
        $migrator = $this->get_migrator();
        $status = $migrator->status();
        
        if (empty($status)) {
            $this->line('No migrations found.');
            return 0;
        }
        
        $rows = [];
        foreach ($status as $s) {
            $status_text = $s['status'] === 'Ran' 
                ? "\033[32mRan\033[0m" 
                : "\033[33mPending\033[0m";
            
            $rows[] = [
                $s['name'],
                $status_text,
                $s['batch'] ?? '-',
            ];
        }
        
        $this->table(['Migration', 'Status', 'Batch'], $rows);
        
        // Summary
        $ran = count(array_filter($status, fn($s) => $s['status'] === 'Ran'));
        $pending = count($status) - $ran;
        
        $this->line('');
        $this->line("Total: {$ran} ran, {$pending} pending");
        
        return 0;
    }
}
