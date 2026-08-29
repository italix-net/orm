<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Migrate Command
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

/**
 * Run all pending migrations
 */
class MigrateCommand extends Command
{
    public function get_name(): string
    {
        return 'migrate';
    }

    public function get_description(): string
    {
        return 'Run all pending database migrations';
    }

    public function handle(): int
    {
        $this->info('Running migrations...');
        
        $migrator = $this->get_migrator();
        
        $applied = $migrator->migrate();
        
        if (empty($applied)) {
            $this->line('Nothing to migrate.');
        } else {
            $this->success(count($applied) . ' migration(s) applied.');
        }
        
        return 0;
    }
}
