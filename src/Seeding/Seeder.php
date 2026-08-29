<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Database seeders
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Seeding;

use Italix\Orm\DataManager;

/**
 * One place an application declares what its seed data is and in what
 * order it goes in — Laravel's `DatabaseSeeder`, adapted to a package with
 * no fixed bootstrapping of its own: a subclass gets the `DataManager` it
 * is to seed and nothing else assumed about how the application is wired.
 *
 *     class DatabaseSeeder extends Seeder
 *     {
 *         public function run(): void
 *         {
 *             $this->call(UserSeeder::class);
 *             $this->call(PostSeeder::class);
 *         }
 *     }
 *
 *     class UserSeeder extends Seeder
 *     {
 *         public function run(): void
 *         {
 *             UserFactory::new($this->dm, $this->dm->query_table(...)->... )->count(10)->create();
 *         }
 *     }
 *
 * `Console\Commands\SeedCommand` (`ix db:seed`) is the CLI entry point that
 * instantiates and runs one of these; nothing about this class depends on
 * that command existing, and calling `(new DatabaseSeeder($dm))->run()`
 * directly — from a test's own setup, say — needs no CLI at all.
 */
abstract class Seeder
{
    protected DataManager $dm;

    public function __construct(DataManager $dm)
    {
        $this->dm = $dm;
    }

    abstract public function run(): void;

    /** Run another seeder, passing along the same `DataManager`. */
    protected function call(string $seeder_class): void
    {
        $seeder = new $seeder_class($this->dm);
        $seeder->run();
    }
}
