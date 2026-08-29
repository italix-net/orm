<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Reset Command
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Rollback all migrations
 */
class ResetCommand extends Command
{
    public function get_name(): string
    {
        return 'migrate:reset';
    }

    public function get_description(): string
    {
        return 'Rollback all database migrations';
    }

    public function handle(): int
    {
        if (!$this->has_option('force')) {
            if (!$this->confirm('This will rollback ALL migrations. Are you sure?', false)) {
                $this->line('Cancelled.');
                return 0;
            }
        }
        
        $this->info('Rolling back all migrations...');
        
        $migrator = $this->get_migrator();
        $rolled_back = $migrator->reset();
        
        if (empty($rolled_back)) {
            $this->line('Nothing to rollback.');
        } else {
            $this->success(count($rolled_back) . ' migration(s) rolled back.');
        }
        
        return 0;
    }
}
