<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Table::primary_key() for composite keys, and db:pull round trip
 *
 * Before this, a composite primary key could exist in the object model
 * (`Table::get_primary_keys()` already aggregated every column with its own
 * `is_primary_key` flag) but `to_create_sql()` could never actually build
 * one: each of those columns rendered its own inline `PRIMARY KEY`, and
 * every server here refuses a table with two of them. `Table::primary_key()`
 * mirrors `Migration\Blueprint::primary($columns)`, which already declared
 * a composite key correctly — as one fact about the table, not several
 * individually-flagged columns for the DDL layer to infer intent from.
 *
 * The same gap existed the other direction: `SchemaIntrospector` (`db:pull`)
 * generated `->primary_key()` per column too, so pulling a real database's
 * composite key produced code with the identical defect. A second, unrelated
 * SQLite bug surfaced while proving the round trip: `PRAGMA table_info`'s
 * `pk` column is a 1-based ordinal set on *every* column of a composite key,
 * not a single-column boolean, and the introspector was reading it as
 * "this column is THE primary key, and if it's an INTEGER it must be the
 * autoincrementing rowid alias" — true only when there is exactly one
 * primary key column, never for a composite one. Both fixed together, since
 * the second was found trying to verify the first.
 *
 * Enforcement proven by creating real tables and inserting into them on
 * SQLite, not by comparing rendered SQL strings — a dropped NOT NULL or a
 * spurious ->auto_increment() both produce syntactically valid SQL.
 *
 * Run: php src/Libs/Italix/Orm/tests/CompositePrimaryKeyTest.php
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

use Italix\Orm\Migration\SchemaIntrospector;

use function Italix\Orm\Schema\{integer, varchar, mysql_table, pg_table, sqlite_table};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - composite primary keys: DDL and db:pull round trip');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

/** @return array{0: bool, 1: string} */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (\Throwable $e) {
        return [true, $e->getMessage()];
    }
};

// -----------------------------------------------------------------------------
section('Table::primary_key() renders one table-level clause, on every dialect');

$columns = static fn () => [
    'tenant_id' => integer()->not_null(),
    'order_id'  => integer()->not_null(),
    'product'   => varchar(50)->not_null(),
];

$mysql_sql = mysql_table('order_items', $columns())->primary_key(['tenant_id', 'order_id'])->to_create_sql();
test('MySQL: no column claims its own PRIMARY KEY', substr_count($mysql_sql, 'PRIMARY KEY') === 1);
test('…and the table-level clause names both columns in order', str_contains($mysql_sql, 'PRIMARY KEY (`tenant_id`, `order_id`)'));

$pg_sql = pg_table('order_items', $columns())->primary_key(['tenant_id', 'order_id'])->to_create_sql();
test('PostgreSQL: same — one clause, not two', substr_count($pg_sql, 'PRIMARY KEY') === 1);

$sqlite_sql = sqlite_table('order_items', $columns())->primary_key(['tenant_id', 'order_id'])->to_create_sql();
test('SQLite: same', substr_count($sqlite_sql, 'PRIMARY KEY') === 1);
test('…and NOT NULL survived on both key columns despite the inline PRIMARY KEY being suppressed', substr_count($sqlite_sql, 'NOT NULL') === 3);

// -----------------------------------------------------------------------------
section('…and it actually creates a working table — not only valid-looking SQL');

$dm = sqlite_memory();
$order_items = sqlite_table('order_items', $columns())->primary_key(['tenant_id', 'order_id']);

[$threw] = $throws(fn () => $dm->create_tables($order_items));
test('create_tables() does not throw — this is exactly the call that used to fail', !$threw);

$dm->insert($order_items)->values(['tenant_id' => 1, 'order_id' => 1, 'product' => 'Widget'])->execute();
$dm->insert($order_items)->values(['tenant_id' => 1, 'order_id' => 2, 'product' => 'Gadget'])->execute();

test('both rows were written', count($dm->query('SELECT * FROM order_items')) === 2);

[$threw] = $throws(fn () => $dm->insert($order_items)->values(['tenant_id' => 1, 'order_id' => 1, 'product' => 'Duplicate'])->execute());
test('a duplicate (tenant_id, order_id) pair is refused by the server — the composite key really is enforced', $threw);

// -----------------------------------------------------------------------------
section('a single-column key is unaffected — the ordinary, unchanged path');

$users = sqlite_table('users', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);
$users_sql = $users->to_create_sql();
test('a single-column key still renders inline, exactly as before', str_contains($users_sql, '"id" INTEGER PRIMARY KEY AUTOINCREMENT'));

// -----------------------------------------------------------------------------
section('primary_key() naming a column that does not exist is refused');

[$threw, $message] = $throws(fn () => sqlite_table('x', ['id' => integer()])->primary_key(['id', 'nonexistent']));
test('an unknown column name throws', $threw);
test('…and the message names it', str_contains($message, 'nonexistent'));

// -----------------------------------------------------------------------------
section('db:pull round trip: a real composite-key table, introspected and regenerated');

$dm2 = sqlite_memory();
$dm2->execute('CREATE TABLE order_items (
    tenant_id INTEGER NOT NULL,
    order_id  INTEGER NOT NULL,
    product   TEXT NOT NULL,
    PRIMARY KEY (tenant_id, order_id)
)');

$introspector = new SchemaIntrospector($dm2, 'sqlite');
$code = $introspector->generate_table_code('order_items');

test('the generated code declares the composite key at the table level', str_contains($code, "->primary_key(['tenant_id', 'order_id'])"));
test('…and does not put ->primary_key() on either individual column', !str_contains($code, "'tenant_id' => integer()->primary_key()"));
test(
    '…and neither column is wrongly marked auto_increment — the SQLite-specific bug found while writing this test',
    !str_contains($code, '->auto_increment()')
);
test('…and NOT NULL is still there on both key columns', substr_count($code, '->not_null()') === 3);

$tmp_file = tempnam(sys_get_temp_dir(), 'italix_orm_test_gen') . '.php';
file_put_contents($tmp_file, "<?php\nnamespace Italix\\Orm\\Schema;\n" . $code . "\nreturn \$order_items;\n");
$regenerated_table = require $tmp_file;
unlink($tmp_file);

$dm3 = sqlite_memory();
[$threw] = $throws(fn () => $dm3->create_tables($regenerated_table));
test('the regenerated code creates a working table', !$threw);
test('…with the same composite key', $regenerated_table->get_primary_keys() === ['tenant_id', 'order_id']);

$dm3->insert($regenerated_table)->values(['tenant_id' => 5, 'order_id' => 1, 'product' => 'Sprocket'])->execute();
test(
    '…and querying it by composite key works, end to end',
    $dm3->query_table($regenerated_table)->find(['tenant_id' => 5, 'order_id' => 1])['product'] === 'Sprocket'
);

exit(summary());
