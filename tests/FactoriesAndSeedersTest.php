<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Factories\Factory / Seeding\Seeder
 *
 * Every ORM this package was compared against has some form of "generate
 * plausible rows for a table" (Eloquent's model factories, Rails'
 * FactoryBot) and "declare what the seed data is" (Laravel's DatabaseSeeder,
 * Django's loaddata). This package had neither — filling a table with test
 * or demo data meant hand-writing every `->insert()->values([...])` call.
 *
 * No bundled fake-data generation (no Faker-equivalent) — see `Factory`'s
 * own docblock for why that is deliberately out of scope. What is tested
 * here is the mechanism: `definition()`/`state()`/`count()` composing
 * correctly, `Closure` values resolved per row rather than once, `create()`
 * actually reaching the database inside one transaction, and `Seeder::call()`
 * chaining. `ix db:seed` (the CLI entry point) is exercised manually, not
 * here — see the CHANGELOG entry for how; this suite only tests what the
 * command itself does after that: run a `Seeder` subclass's `run()`.
 *
 * Run: php src/Libs/Italix/Orm/tests/FactoriesAndSeedersTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\Factories\Factory;
use Italix\Orm\Seeding\Seeder;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Factories\\Factory / Seeding\\Seeder');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$members = sqlite_table('members', [
    'id'    => integer()->primary_key()->auto_increment(),
    'name'  => varchar(100)->not_null(),
    'email' => varchar(150)->not_null(),
    'role'  => varchar(20)->not_null(),
]);

class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'  => fn () => 'Member ' . $this->sequence(),
            'email' => fn () => 'member' . $this->sequence() . '@example.test',
            'role'  => 'member',
        ];
    }
}

// A second factory subclass, to prove sequence() is isolated per class.
class OtherFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fn () => 'Other ' . $this->sequence(), 'email' => 'x@x', 'role' => 'x'];
    }
}

// -----------------------------------------------------------------------------
section('make() builds rows without touching the database');

$dm = sqlite_memory();
$dm->create_tables($members);

/** @return Table the `members` table, rebuilt — usable from a class method with no enclosing-scope capture. */
function members_table(): \Italix\Orm\Schema\Table
{
    return sqlite_table('members', [
        'id'    => integer()->primary_key()->auto_increment(),
        'name'  => varchar(100)->not_null(),
        'email' => varchar(150)->not_null(),
        'role'  => varchar(20)->not_null(),
    ]);
}

$made = MemberFactory::new($dm, $members)->count(3)->make();
test('make() returns the requested count', count($made) === 3);
test('…with every closure resolved to a plain value', is_string($made[0]['name']) && strpos($made[0]['name'], 'Member ') === 0);
test('…nothing was written to the database', $dm->query('SELECT COUNT(*) AS n FROM members')[0]['n'] == 0);

// -----------------------------------------------------------------------------
section('sequence() gives each row of a batch its own value, and is isolated per factory subclass');

$dm->execute('DELETE FROM members');
$batch = MemberFactory::new($dm, $members)->count(3)->make();
$names = array_column($batch, 'name');
test('three rows got three distinct sequence-derived names', count(array_unique($names)) === 3);

$other_batch = OtherFactory::new($dm, $members)->count(2)->make();
test(
    'a different Factory subclass has its own sequence, starting fresh — not continuing MemberFactory\'s count',
    $other_batch[0]['name'] === 'Other 1' && $other_batch[1]['name'] === 'Other 2'
);

// -----------------------------------------------------------------------------
section('count() defaults to 1');

$single = MemberFactory::new($dm, $members)->make();
test('make() with no count() call builds exactly one row', count($single) === 1);

// -----------------------------------------------------------------------------
section('state() — array form overrides definition(), callable form derives from the resolved row');

$admin_state = MemberFactory::new($dm, $members)->state(['role' => 'admin'])->make()[0];
test('an array state overrides the base definition', $admin_state['role'] === 'admin');
test('…without disturbing fields the state did not mention', strpos($admin_state['name'], 'Member ') === 0);

$derived = MemberFactory::new($dm, $members)
    ->state(fn (array $row) => ['email' => strtolower(str_replace(' ', '.', $row['name'])) . '@derived.test'])
    ->make()[0];
test(
    'a callable state receives the already-resolved row and can derive a field from it',
    strpos($derived['email'], '@derived.test') !== false && strpos($derived['email'], 'member.') === 0
);

// -----------------------------------------------------------------------------
section('multiple state() calls accumulate and apply in registration order');

$layered = MemberFactory::new($dm, $members)
    ->state(['role' => 'admin'])
    ->state(['role' => 'superadmin']) // applied second — should win
    ->make()[0];
test('the later state() call wins when both touch the same field', $layered['role'] === 'superadmin');

// -----------------------------------------------------------------------------
section('make()\'s $overrides parameter applies after every state, and per call — not baked into the factory');

$overridden = MemberFactory::new($dm, $members)->state(['role' => 'admin'])->make(['role' => 'owner']);
test('$overrides beats even a state()', $overridden[0]['role'] === 'owner');

$unaffected = MemberFactory::new($dm, $members)->state(['role' => 'admin'])->make();
test('…and does not leak into a call that did not pass it', $unaffected[0]['role'] === 'admin');

// -----------------------------------------------------------------------------
section('create() actually persists, and merges the generated id back in');

$dm->execute('DELETE FROM members');
$created = MemberFactory::new($dm, $members)->count(4)->create();
test('four rows were actually written', $dm->query('SELECT COUNT(*) AS n FROM members')[0]['n'] == 4);
test('create() returned four rows too', count($created) === 4);
test('each returned row carries its real, distinct database id', array_column($created, 'id') === [1, 2, 3, 4]);

$db_names = array_column($dm->query('SELECT name FROM members ORDER BY id'), 'name');
test('the persisted rows match what create() returned', array_column($created, 'name') === $db_names);

// -----------------------------------------------------------------------------
section('create() honours state() and $overrides exactly like make() does');

$dm->execute('DELETE FROM members');
$admin_created = MemberFactory::new($dm, $members)->state(['role' => 'admin'])->create()[0];
test('the persisted row reflects the state', $dm->query('SELECT role FROM members WHERE id = ?', [$admin_created['id']])[0]['role'] === 'admin');

// -----------------------------------------------------------------------------
section('create() runs inside one transaction — a mid-batch failure leaves nothing behind');

$dm->execute('DELETE FROM members');

class FailingThirdRowFactory extends Factory
{
    // A dedicated counter, not sequence() — definition() below calls
    // sequence() once for 'name' too, so sequence()'s own value advances
    // twice per row and is not "which row is this" on its own.
    public static int $rows_built_n = 0;

    public function definition(): array
    {
        self::$rows_built_n++;
        $this_row_n = self::$rows_built_n;

        // The 3rd row this factory ever builds violates NOT NULL on `role`
        // — a real constraint failure, not a simulated one.
        return [
            'name'  => fn () => 'Row ' . $this->sequence(),
            'email' => 'x@x',
            'role'  => fn () => $this_row_n === 3 ? null : 'member',
        ];
    }
}
FailingThirdRowFactory::$rows_built_n = 0;

[$create_threw] = (static function () use ($dm, $members): array {
    try {
        FailingThirdRowFactory::new($dm, $members)->count(5)->create();

        return [false];
    } catch (\Throwable $e) {
        return [true];
    }
})();
test('the constraint violation on row 3 propagates as an exception', $create_threw);
test(
    'and the two rows that inserted fine before it were rolled back too — the whole batch, not a partial one',
    $dm->query('SELECT COUNT(*) AS n FROM members')[0]['n'] == 0
);

// -----------------------------------------------------------------------------
section('create() on a composite-key table leaves the key exactly as definition()/state() built it');

$dm2 = sqlite_memory();
$order_items = sqlite_table('order_items', [
    'tenant_id' => integer()->not_null(),
    'order_id'  => integer()->not_null(),
    'sku'       => varchar(30)->not_null(),
])->primary_key(['tenant_id', 'order_id']);
$dm2->create_tables($order_items);

class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'order_id'  => fn () => $this->sequence(),
            'sku'       => 'WIDGET',
        ];
    }
}

$items = OrderItemFactory::new($dm2, $order_items)->count(3)->create();
test('no synthetic id column was invented for a composite key', !array_key_exists('id', $items[0]));
test('each row kept the composite key definition() gave it', array_column($items, 'order_id') === [1, 2, 3]);
test('…and it actually landed in the database with that same key', $dm2->query('SELECT COUNT(*) AS n FROM order_items WHERE tenant_id = 1 AND order_id = 2')[0]['n'] == 1);

// -----------------------------------------------------------------------------
section('a single-column key that definition() already supplies is trusted, not overwritten');

$dm3 = sqlite_memory();
$dm3->create_tables($members);
$explicit = MemberFactory::new($dm3, $members)->state(['id' => 999])->create();
test('the caller-supplied id was kept, not replaced by lastInsertId()', $explicit[0]['id'] === 999);

// -----------------------------------------------------------------------------
section('Seeder::run() and call() — chaining to another seeder with the same DataManager');

$dm4 = sqlite_memory();
$dm4->create_tables($members);

class FirstSeeder extends Seeder
{
    public static array $ran = [];

    public function run(): void
    {
        self::$ran[] = 'first';
        MemberFactory::new($this->dm, members_table())->count(2)->create();
    }
}

class SecondSeeder extends Seeder
{
    public static array $ran = [];

    public function run(): void
    {
        self::$ran[] = 'second';
    }
}

class RootSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(FirstSeeder::class);
        $this->call(SecondSeeder::class);
    }
}

(new RootSeeder($dm4))->run();

test('call() ran the first seeder', FirstSeeder::$ran === ['first']);
test('…and the second one too, in order', SecondSeeder::$ran === ['second']);
test('the first seeder\'s factory call actually persisted, through the same $dm passed to the root seeder', $dm4->query('SELECT COUNT(*) AS n FROM members')[0]['n'] == 2);

// -----------------------------------------------------------------------------
section('create() composes correctly with hooks and optimistic_locking() — features built independently of this one, each reaching insert() the same way create() does');

$dm5 = sqlite_memory();
$widgets = sqlite_table('widgets', [
    'id'      => integer()->primary_key()->auto_increment(),
    'name'    => varchar(50)->not_null(),
    'version' => integer()->not_null(),
]);
$widgets->optimistic_locking('version');
$dm5->create_tables($widgets);

class WidgetFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fn () => 'Widget ' . $this->sequence()];
    }
}

$hook_calls_n = 0;
$dm5->on($widgets, 'before_insert', function (array $row) use (&$hook_calls_n): array {
    $hook_calls_n++;

    return $row;
});

$widget_rows = WidgetFactory::new($dm5, $widgets)->count(3)->create();
test('the before_insert hook fired once per row created through the factory', $hook_calls_n === 3);
test(
    'optimistic_locking() defaulted every row to version 1 in the database',
    array_column($dm5->query('SELECT version FROM widgets'), 'version') === ['1', '1', '1']
        || array_column($dm5->query('SELECT version FROM widgets'), 'version') === [1, 1, 1]
);
test(
    'create()\'s returned rows do NOT include the version default — documented: this never re-SELECTs, only the primary key is merged back',
    !array_key_exists('version', $widget_rows[0])
);

exit(summary());
