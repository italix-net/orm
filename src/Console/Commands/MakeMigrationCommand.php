<?php
/**
 * Italix ORM - Make Migration Command
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Create a new migration file
 */
class MakeMigrationCommand extends Command
{
    public function get_name(): string
    {
        return 'make:migration';
    }

    public function get_description(): string
    {
        return 'Create a new migration file';
    }

    public function handle(): int
    {
        $name = $this->argument(0);
        
        if (empty($name)) {
            $this->error('Please provide a migration name.');
            $this->line('Usage: ix make:migration <name> [--table=<table>] [--create=<table>]');
            return 1;
        }
        
        // Detect if this is a create table migration
        $table = $this->option('table');
        $create_table = $this->option('create');
        $is_create = !empty($create_table);
        
        if ($is_create) {
            $table = $create_table;
        }
        
        // Auto-detect from name
        if ($table === null) {
            if (preg_match('/^create_(\w+)_table$/', $name, $m)) {
                $table = $m[1];
                $is_create = true;
            } elseif (preg_match('/^add_\w+_to_(\w+)$/', $name, $m)) {
                $table = $m[1];
            } elseif (preg_match('/^remove_\w+_from_(\w+)$/', $name, $m)) {
                $table = $m[1];
            }
        }
        
        $migrator = $this->get_migrator();
        $filepath = $migrator->create($name, $table, $is_create);
        
        $this->success("Created migration: " . basename($filepath));
        $this->line("Path: {$filepath}");
        
        return 0;
    }
}
