<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — DelegatedTypes reads its configuration from the Table
 *
 * Before this, delegated-types configuration existed twice for the same
 * table: once on `Table` (`type_column()`, `type_path_column()`,
 * `delegate_foreign_key()`, `delegates([...])` — read by the Data Mapper
 * side, `$dm->query_table($table)->with(...)`), and a second time as
 * protected methods overridden on the `ActiveRow` subclass
 * (`get_type_column()` and friends), with nothing checking the two agreed.
 *
 * `DelegatedTypes`'s four config methods now default to reading the bound
 * `Table` — see `schema_table()` — so a class that does not override them
 * gets the schema's own answer instead of a hardcoded guess. Overriding is
 * still there and still wins; this only changes what the *default* does.
 *
 * Two behaviours are asserted end to end, not just at the getter level: that
 * `get_delegated_types()` derives the right class from `ActiveRowRegistry`
 * (built for exactly this), and that `->delegate()` on a real row actually
 * returns an instance of that class — a getter returning the right class
 * name would pass a narrower test while `delegate()` still failed to find
 * anything, if the two were wired together wrong.
 *
 * Run: php src/Libs/Italix/Orm/tests/DelegatedTypesFromSchemaTest.php
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
use Italix\Orm\ActiveRow\Traits\DelegatedTypes;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - DelegatedTypes reads Table');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

class ThingRow extends ActiveRow
{
    use Persistable, DelegatedTypes;
}

class DelegateBookRow extends ActiveRow
{
    use Persistable;
}

// -----------------------------------------------------------------------------
section('a subclass that overrides nothing derives its config from the Table');

$things = sqlite_table('things', [
    'id'    => integer()->primary_key()->auto_increment(),
    'kind'  => varchar(50)->not_null(),
    'path'  => varchar(255),
    'title' => varchar(255)->not_null(),
]);
$books = sqlite_table('books', [
    'id'       => integer()->primary_key()->auto_increment(),
    'thing_ref' => integer()->not_null(),
    'isbn'     => varchar(20),
]);

$things
    ->type_column('kind')
    ->type_path_column('path')
    ->delegate_foreign_key('thing_ref')
    ->delegates(['Book' => $books]);

$dm = sqlite_memory();
$dm->create_tables($things, $books);

ThingRow::set_persistence($dm, $things);
DelegateBookRow::set_persistence($dm, $books);

$reflect = new ReflectionClass(ThingRow::class);
$call    = static function (string $method) use ($reflect) {
    $m = $reflect->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke(ThingRow::wrap(['kind' => 'Book']));
};

test('get_type_column() reads Table::type_column()', $call('get_type_column') === 'kind');
test('get_type_path_column() reads Table::type_path_column()', $call('get_type_path_column') === 'path');
test('get_delegate_foreign_key() reads Table::delegate_foreign_key()', $call('get_delegate_foreign_key') === 'thing_ref');
test(
    'get_delegated_types() derives {Book: DelegateBookRow} from ActiveRowRegistry, not a hardcoded override',
    $call('get_delegated_types') === ['Book' => DelegateBookRow::class]
);

// -----------------------------------------------------------------------------
section('…and delegate() actually resolves to a real DelegateBookRow, not just the class name');

$thing = ThingRow::create(['kind' => 'Book', 'title' => 'Dune']);
DelegateBookRow::create(['thing_ref' => $thing['id'], 'isbn' => '9780441013593']);

$delegate = $thing->delegate();
test('delegate() found a row', $delegate !== null);
test('…and it is a DelegateBookRow', $delegate instanceof DelegateBookRow);
test('…with the right data', ($delegate['isbn'] ?? null) === '9780441013593');

// -----------------------------------------------------------------------------
section('a Table not configured for delegation leaves the old hardcoded defaults untouched');

$plain = sqlite_table('plain_things', [
    'id' => integer()->primary_key()->auto_increment(),
]);

class PlainRow extends ActiveRow
{
    use Persistable, DelegatedTypes;
}

$dm->create_tables($plain);
PlainRow::set_persistence($dm, $plain);

$plain_reflect = new ReflectionClass(PlainRow::class);
$plain_call    = static function (string $method) use ($plain_reflect) {
    $m = $plain_reflect->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke(PlainRow::wrap([]));
};

test('get_type_column() falls back to "type"', $plain_call('get_type_column') === 'type');
test('get_type_path_column() falls back to "type_path" — no delegation configured to trust instead', $plain_call('get_type_path_column') === 'type_path');
test('get_delegate_foreign_key() falls back to "thing_id"', $plain_call('get_delegate_foreign_key') === 'thing_id');
test('get_delegated_types() is empty', $plain_call('get_delegated_types') === []);

// -----------------------------------------------------------------------------
section('an explicit override on the subclass still wins over the Table');

class OverriddenThingRow extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_type_column(): string
    {
        return 'explicit_override';
    }
}

OverriddenThingRow::set_persistence($dm, $things);

$override_reflect = new ReflectionClass(OverriddenThingRow::class);
$override_method  = $override_reflect->getMethod('get_type_column');
$override_method->setAccessible(true);

test(
    'the override wins even though this Table has a real type_column() configured',
    $override_method->invoke(OverriddenThingRow::wrap([])) === 'explicit_override'
);

exit(summary());
