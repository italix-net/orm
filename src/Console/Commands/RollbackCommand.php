<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Rollback Command
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Rollback the last batch of migrations
 */
class RollbackCommand extends Command
{
    public function get_name(): string
    {
        return 'migrate:rollback';
    }

    public function get_description(): string
    {
        return 'Rollback the last database migration batch';
    }

    public function handle(): int
    {
        $steps = (int)$this->option('steps', 1);
        
        $this->info("Rolling back {$steps} batch(es)...");
        
        $migrator = $this->get_migrator();
        $rolled_back = $migrator->rollback($steps);
        
        if (empty($rolled_back)) {
            $this->line('Nothing to rollback.');
        } else {
            $this->success(count($rolled_back) . ' migration(s) rolled back.');
        }
        
        return 0;
    }
}
