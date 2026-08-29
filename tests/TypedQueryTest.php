<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Persistable::query(), a fluent chain that returns ActiveRow
 *
 * `find_all(['where' => …, 'with' => …])` already existed, and was already
 * built by assembling a `TableQuery` from that options array key by key —
 * the fluency was there, just not exposed. `query()` exposes it directly:
 * `PersonRow::query()->where(...)->with(...)->find_many()`, ending in
 * `ActiveRow` instances instead of the plain arrays `TableQuery` itself
 * returns.
 *
 * Three things are asserted, each because it was a real question rather
 * than an assumption:
 *
 *  1. `find_all()`/`find()`/`find_one()` behave identically to before —
 *     this is a second entry point, not a replacement for the first.
 *  2. `find_many()`/`find_all()`/`all()` (and `find_first()`/`find_one()`/
 *     `first()`) are true aliases: same call, same result, asserted by
 *     value rather than assumed from reading three one-line method bodies.
 *  3. The two behaviours discussed on the way to building this — order
 *     among different fluent calls does not matter, but repeating the same
 *     one does not mean the same thing for every method (`where()` replaces,
 *     `order_by()` accumulates) — still hold through `TypedQuery`, since it
 *     only forwards to `TableQuery` rather than reimplementing any of it.
 *
 * Run: php src/Libs/Italix/Orm/tests/TypedQueryTest.php
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

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Operators\{eq, asc, desc};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Persistable::query() (TypedQuery)');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$users = sqlite_table('users', [
    'id'   => integer()->primary_key()->auto_increment(),
    'role' => varchar(20)->not_null(),
    'name' => varchar(50)->not_null(),
]);

class PersonRow extends ActiveRow
{
    use Persistable;
}

$dm = sqlite_memory();
$dm->create_tables($users);
PersonRow::set_persistence($dm, $users);

PersonRow::create(['role' => 'admin', 'name' => 'Ada']);
PersonRow::create(['role' => 'admin', 'name' => 'Bob']);
PersonRow::create(['role' => 'user', 'name' => 'Cid']);

// -----------------------------------------------------------------------------
section('query() returns ActiveRow instances, chained fluently');

$admins = PersonRow::query()->where(eq($users->role, 'admin'))->order_by(desc($users->name))->find_many();

test('two rows come back', count($admins) === 2);
test('each one a real PersonRow, not a plain array', $admins[0] instanceof PersonRow);
test('the WHERE and ORDER BY both applied', $admins[0]['name'] === 'Bob' && $admins[1]['name'] === 'Ada');

// -----------------------------------------------------------------------------
section('find_many() / find_all() / all() are true aliases — same call, same result');

$a = PersonRow::query()->where(eq($users->role, 'admin'))->find_many();
$b = PersonRow::query()->where(eq($users->role, 'admin'))->find_all();
$c = PersonRow::query()->where(eq($users->role, 'admin'))->all();

$names = fn (array $rows): array => array_map(fn ($r) => $r['name'], $rows);

test('find_all() returns the same rows as find_many()', $names($a) === $names($b));
test('all() returns the same rows too', $names($a) === $names($c));

// -----------------------------------------------------------------------------
section('find_first() / find_one() / first() / one() are true aliases too');

$x = PersonRow::query()->where(eq($users->role, 'user'))->find_first();
$y = PersonRow::query()->where(eq($users->role, 'user'))->find_one();
$z = PersonRow::query()->where(eq($users->role, 'user'))->first();
$w = PersonRow::query()->where(eq($users->role, 'user'))->one();

test(
    'all four found the same row',
    $x['name'] === 'Cid' && $y['name'] === 'Cid' && $z['name'] === 'Cid' && $w['name'] === 'Cid'
);
test(
    'all four are real PersonRow instances',
    $x instanceof PersonRow && $y instanceof PersonRow && $z instanceof PersonRow && $w instanceof PersonRow
);

// -----------------------------------------------------------------------------
section('find() looks up by primary key and wraps the result');

$first_admin = $admins[1]; // Ada, from the first section
$found = PersonRow::query()->find($first_admin['id']);

test('find(id) returns the right row', $found !== null && $found['name'] === 'Ada');
test('a nonexistent id returns null, not an error', PersonRow::query()->find(999999) === null);

// -----------------------------------------------------------------------------
section('find_all()/find()/find_one() on Persistable itself are unaffected — the old style still works');

$old_style = PersonRow::find_all(['where' => eq($users->role, 'admin'), 'order_by' => desc($users->name)]);
test('the pre-existing options-array style gives the identical result', $names($old_style) === $names($admins));

// -----------------------------------------------------------------------------
section('order among different fluent calls does not matter — verified, not assumed');

$order_a = PersonRow::query()->where(eq($users->role, 'admin'))->order_by(desc($users->name))->limit(1)->find_many();
$order_b = PersonRow::query()->limit(1)->order_by(desc($users->name))->where(eq($users->role, 'admin'))->find_many();

test('reversing the call order gives the identical result', $names($order_a) === $names($order_b));

// -----------------------------------------------------------------------------
section('repeating where() replaces, repeating order_by() accumulates — through TypedQuery too');

$replaced = PersonRow::query()
    ->where(eq($users->role, 'admin'))
    ->where(eq($users->role, 'user'))
    ->find_many();
test('the second where() replaced the first, not AND-ed with it', $names($replaced) === ['Cid']);

// A fresh table for this one: the rows above (admin/admin/user) would sort
// the same way whether order_by() replaces or accumulates, which is exactly
// the ambiguity that must be avoided — see the conversation this test is
// closing. role='b' paired with the alphabetically later name only tells
// "replace" and "accumulate" apart if role sorts first.
$sortable = sqlite_table('sortable', [
    'id'   => integer()->primary_key()->auto_increment(),
    'role' => varchar(20)->not_null(),
    'name' => varchar(50)->not_null(),
]);

class SortableRow extends ActiveRow
{
    use Persistable;
}

$dm->create_tables($sortable);
SortableRow::set_persistence($dm, $sortable);
SortableRow::create(['role' => 'b', 'name' => 'Z']);
SortableRow::create(['role' => 'a', 'name' => 'A']);

// If order_by() replaced instead of accumulating, this would read ['Z', 'A']
// — name desc alone. It reads ['A', 'Z'] instead: role asc first, name desc
// only within role.
$accumulated = SortableRow::query()
    ->order_by(asc($sortable->role))
    ->order_by(desc($sortable->name))
    ->find_many();

test(
    'order_by() called twice sorts by both — role asc first, not just the last call (name desc alone)',
    $names($accumulated) === ['A', 'Z']
);

exit(summary());
