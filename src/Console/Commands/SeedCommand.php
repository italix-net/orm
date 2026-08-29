<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Seed Command
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Console\Commands;

use Italix\Orm\Seeding\Seeder;

/**
 * Run a `Seeding\Seeder` class against the configured database.
 *
 *     ix db:seed                       # runs DatabaseSeeder
 *     ix db:seed --class=UserSeeder    # runs a specific one
 *
 * The class must already be autoloadable (or `require`d by whatever loads
 * `ix.config.php` before this runs) — this does not scan a directory for
 * seeder files, the same way `db:diff --migration` does not scan for
 * migration files beyond the configured path.
 */
class SeedCommand extends Command
{
    public function get_name(): string
    {
        return 'db:seed';
    }

    public function get_description(): string
    {
        return 'Run a database seeder class. --class= picks one other than DatabaseSeeder.';
    }

    public function handle(): int
    {
        $class = $this->option('class') ?? $this->app->get_config('seeder_class') ?? 'DatabaseSeeder';

        if (!class_exists($class)) {
            $this->error("Seeder class not found: {$class}");

            return 1;
        }

        if (!is_subclass_of($class, Seeder::class)) {
            $this->error("{$class} must extend " . Seeder::class);

            return 1;
        }

        $seeder = new $class($this->get_database());
        $seeder->run();

        $this->success("Seeded using {$class}.");

        return 0;
    }
}
