<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Table::timestamps() and Table::soft_deletes()
 *
 * Before this, "when was this row last touched" and "deleting without
 * really deleting" only existed as ActiveRow traits (HasTimestamps,
 * SoftDeletes) — invisible to the Data Mapper style this package's own
 * examples otherwise favour, where a write is `$dm->insert($table)->…` and
 * a row is a plain array. `Table::timestamps()`/`soft_deletes()` move the
 * declaration to the schema, and `QueryBuilder::build_insert()`,
 * `build_update()` and `build_delete()` read it — so both styles get the
 * same behaviour from the same three methods, not two independent
 * implementations that happen to agree today.
 *
 * Enforcement is proven by reading the row back after the write, not by
 * inspecting the generated SQL string — a column silently left out of the
 * SET clause and a column set to the wrong value both produce valid SQL.
 *
 * Run: php src/Libs/Italix/Orm/tests/TimestampsAndSoftDeleteTest.php
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
use Italix\Orm\ActiveRow\Traits\SoftDeletes;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Table::timestamps() and Table::soft_deletes()');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

// -----------------------------------------------------------------------------
section('Table::timestamps() — insert');

$orders = sqlite_table('orders', [
    'id'         => integer()->primary_key()->auto_increment(),
    'status'     => varchar(20)->not_null(),
    'insert_dt'  => varchar(19),
    'update_dt'  => varchar(19),
]);
$orders->timestamps('insert_dt', 'update_dt');

$dm = sqlite_memory();
$dm->create_tables($orders);

$dm->insert($orders)->values(['status' => 'draft'])->execute();
$row = $dm->query('SELECT * FROM orders')[0];

test('has_timestamps() reports true once declared', $orders->has_timestamps());
test('insert_dt was filled in automatically', $row['insert_dt'] !== null && $row['insert_dt'] !== '');
test('update_dt was filled in too, at creation', $row['update_dt'] !== null && $row['update_dt'] !== '');
test('…with the same instant for both, on one INSERT', $row['insert_dt'] === $row['update_dt']);

$dm->execute('DELETE FROM orders');
$dm->insert($orders)->values(['status' => 'draft', 'insert_dt' => '2020-01-01 00:00:00'])->execute();
$explicit_row = $dm->query('SELECT * FROM orders')[0];
test('an explicit value is trusted over the automatic one', $explicit_row['insert_dt'] === '2020-01-01 00:00:00');

// -----------------------------------------------------------------------------
section('Table::timestamps() — update');

$dm->execute('DELETE FROM orders');
$dm->insert($orders)->values(['status' => 'draft'])->execute();
$before = $dm->query('SELECT * FROM orders')[0];

sleep(1);
$dm->update($orders)->set(['status' => 'placed'])->where(eq($orders->id, $before['id']))->execute();
$after = $dm->query('SELECT * FROM orders')[0];

test('update_dt moved forward on UPDATE', $after['update_dt'] > $before['update_dt']);
test('insert_dt is untouched by an UPDATE', $after['insert_dt'] === $before['insert_dt']);

$dm->update($orders)->set(['status' => 'shipped', 'update_dt' => '2020-06-01 00:00:00'])
    ->where(eq($orders->id, $before['id']))->execute();
$explicit_update = $dm->query('SELECT * FROM orders')[0];
test('an explicit update_dt is trusted over the automatic one too', $explicit_update['update_dt'] === '2020-06-01 00:00:00');

// -----------------------------------------------------------------------------
section('Table::timestamps() applies to insert_many() as well, not only single-row insert()');

$dm->execute('DELETE FROM orders');
$dm->insert_many($orders, [['status' => 'a'], ['status' => 'b']]);
$many_rows = $dm->query('SELECT * FROM orders');
test('both rows got an insert_dt', $many_rows[0]['insert_dt'] !== null && $many_rows[1]['insert_dt'] !== null);

// -----------------------------------------------------------------------------
section('Table::soft_deletes() — delete() becomes an UPDATE');

$products = sqlite_table('products', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(50)->not_null(),
    'deleted_dt' => varchar(19),
]);
$products->soft_deletes('deleted_dt');

$dm->create_tables($products);
$dm->insert($products)->values(['name' => 'Widget'])->execute();
$product = $dm->query('SELECT * FROM products')[0];

test('has_soft_deletes() reports true once declared', $products->has_soft_deletes());
test('the row starts with no deleted_dt', $product['deleted_dt'] === null);

$dm->delete($products)->where(eq($products->id, $product['id']))->execute();
$after_delete = $dm->query('SELECT * FROM products');

test('the row still exists — delete() did not remove it', count($after_delete) === 1);
test('…but deleted_dt is now set', $after_delete[0]['deleted_dt'] !== null);

// -----------------------------------------------------------------------------
section('…and force() bypasses it for a real delete');

$dm->execute('DELETE FROM products');
$dm->insert($products)->values(['name' => 'Gadget'])->execute();
$gadget = $dm->query('SELECT * FROM products')[0];

$dm->delete($products)->force()->where(eq($products->id, $gadget['id']))->execute();
test('the row is genuinely gone', $dm->query('SELECT * FROM products') === []);

// -----------------------------------------------------------------------------
section('…and a soft-deleted row is filtered out of reads by default — through both query engines');

$dm->execute('DELETE FROM products');
$dm->insert($products)->values(['name' => 'Sprocket'])->execute();
$dm->insert($products)->values(['name' => 'Cog'])->execute();
$sprocket = $dm->query('SELECT * FROM products WHERE name = ?', ['Sprocket'])[0];

$dm->delete($products)->where(eq($products->id, $sprocket['id']))->execute();

test(
    'query_table()->find_many() (TableQuery) excludes the soft-deleted row',
    array_column($dm->query_table($products)->find_many(), 'name') === ['Cog']
);
test(
    '…but raw SQL still sees it — it was never actually removed',
    count($dm->query('SELECT * FROM products')) === 2
);
test(
    'query_table()->with_trashed()->find_many() includes it again',
    array_column($dm->query_table($products)->with_trashed()->find_many(), 'name') === ['Sprocket', 'Cog']
);
test(
    '$dm->select()->from($table) (the other query engine) filters it too',
    array_column($dm->select()->from($products)->execute(), 'name') === ['Cog']
);
test(
    '…and ->with_trashed() undoes it there as well',
    array_column($dm->select()->from($products)->with_trashed()->execute(), 'name') === ['Sprocket', 'Cog']
);
test(
    'find($id) — a single soft-deleted row — is not found by default either',
    $dm->query_table($products)->find($sprocket['id']) === null
);
test(
    '…but is, with with_trashed()',
    ($dm->query_table($products)->with_trashed()->find($sprocket['id']) ?? [])['name'] === 'Sprocket'
);

// -----------------------------------------------------------------------------
section('a table with neither declared behaves exactly as before');

$plain = sqlite_table('plain', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);
$dm->create_tables($plain);
$dm->insert($plain)->values(['name' => 'x'])->execute();
$plain_row = $dm->query('SELECT * FROM plain')[0];
test('no extra columns were invented', array_keys($plain_row) === ['id', 'name']);

$dm->delete($plain)->where(eq($plain->id, $plain_row['id']))->execute();
test('delete() is a real delete when soft_deletes() was never declared', $dm->query('SELECT * FROM plain') === []);

// -----------------------------------------------------------------------------
section('ActiveRow\'s own SoftDeletes::force_delete() still deletes for real, even now');

class WidgetRow extends ActiveRow
{
    use Persistable, SoftDeletes;
}

$widgets = sqlite_table('widgets', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(50)->not_null(),
    'deleted_at' => varchar(19),
]);
// Both mechanisms declared on the same table on purpose — this is exactly
// the combination SoftDeletes::perform_hard_delete() had to be fixed for.
$widgets->soft_deletes('deleted_at');

$dm->create_tables($widgets);
WidgetRow::set_persistence($dm, $widgets);

$widget = WidgetRow::create(['name' => 'Sprocket']);
$widget->force_delete();

test(
    'force_delete() removes the row for real, not a soft delete, despite Table::soft_deletes() being declared',
    $dm->query('SELECT * FROM widgets') === []
);

// -----------------------------------------------------------------------------
section('…and the read filter reaches ActiveRow too — Persistable and TypedQuery both');

$kept  = WidgetRow::create(['name' => 'Kept']);
$gone  = WidgetRow::create(['name' => 'Gone']);
$gone->delete(); // Persistable::delete() — not force_delete(), so this soft-deletes

test('find_all() excludes the soft-deleted row', array_map(fn ($r) => $r['name'], WidgetRow::find_all()) === ['Kept']);
test(
    "find_all(['with_trashed' => true]) includes it again",
    in_array('Gone', array_map(fn ($r) => $r['name'], WidgetRow::find_all(['with_trashed' => true])), true)
);
test('query()->find_many() excludes it the same way', array_map(fn ($r) => $r['name'], WidgetRow::query()->find_many()) === ['Kept']);
test(
    'query()->with_trashed()->find_many() includes it',
    in_array('Gone', array_map(fn ($r) => $r['name'], WidgetRow::query()->with_trashed()->find_many()), true)
);

exit(summary());
