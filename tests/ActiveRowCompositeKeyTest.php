<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — ActiveRow with a composite primary key
 *
 * `Table::primary_key([...])` (2.27.0) and `TableQuery::find($id)` (2.26.0)
 * already handled composite keys at the Data Mapper level. `ActiveRow` never
 * did: `static::$primary_key` was a bare string everywhere it was read —
 * `exists()`, `get_key()`, `save()`, `delete()`, `refresh()`,
 * `SoftDeletes::force_delete()` — so a composite-key table used through
 * `Persistable` silently built a WHERE against only the first column
 * (`eq($table->$pk, $this[$pk])` with `$pk` an array coerces to a warning
 * and a broken query, not a graceful failure).
 *
 * `static::$primary_key` now accepts either a string (unaffected — every
 * assertion in `ActiveRowTest.php` still passes unmodified) or an array of
 * column names. This suite exercises only the composite case; the single
 * -column path is `ActiveRowTest.php`'s job and is not re-proven here.
 *
 * Run: php src/Libs/Italix/Orm/tests/ActiveRowCompositeKeyTest.php
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
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - ActiveRow with a composite primary key');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$order_items = sqlite_table('order_items', [
    'tenant_id' => integer()->not_null(),
    'order_id'  => integer()->not_null(),
    'product'   => varchar(50)->not_null(),
    'qty'       => integer()->not_null(),
])->primary_key(['tenant_id', 'order_id']);

class OrderItemRow extends ActiveRow
{
    use Persistable;

    protected static $primary_key = ['tenant_id', 'order_id'];
}

$dm = sqlite_memory();
$dm->create_tables($order_items);
OrderItemRow::set_persistence($dm, $order_items);

// -----------------------------------------------------------------------------
section('get_key_names() / get_key_name()');

test('get_key_names() returns both columns, in declared order', OrderItemRow::get_key_names() === ['tenant_id', 'order_id']);

[$threw, $message] = (static function (): array {
    try {
        OrderItemRow::get_key_name();

        return [false, ''];
    } catch (\LogicException $e) {
        return [true, $e->getMessage()];
    }
})();
test('get_key_name() raises on a composite key — there is no single name to return', $threw);
test('…and the message points at get_key_names()', strpos($message, 'get_key_names()') !== false);

// -----------------------------------------------------------------------------
section('exists() / get_key() before and after a row has both key columns');

$blank = OrderItemRow::make([]);
test('a freshly make()\'d row with neither key column set does not exist', !$blank->exists());

$half = OrderItemRow::make(['tenant_id' => 1]);
test('…nor one with only one of two key columns set', !$half->exists());

$full = OrderItemRow::make(['tenant_id' => 1, 'order_id' => 5, 'product' => 'Widget', 'qty' => 2]);
test(
    '…nor one with both key columns set — make() always means "not yet persisted", however much of the key it already carries',
    !$full->exists()
);

$loaded = OrderItemRow::wrap(['tenant_id' => 1, 'order_id' => 5, 'product' => 'Widget', 'qty' => 2]);
test('wrap() (a row read back from the database) with both key columns set does exist', $loaded->exists());
test('get_key() returns the composite key as [column => value]', $loaded->get_key() === ['tenant_id' => 1, 'order_id' => 5]);

// -----------------------------------------------------------------------------
section('create() / save() (UPDATE) / find() round trip');

$item = OrderItemRow::create(['tenant_id' => 1, 'order_id' => 100, 'product' => 'Widget', 'qty' => 3]);
test('create() persisted the row', $dm->query('SELECT COUNT(*) AS n FROM order_items')[0]['n'] == 1);

$item['qty'] = 9;
$item->save();
$saved_row = $dm->query('SELECT * FROM order_items WHERE tenant_id = 1 AND order_id = 100')[0];
test('save() UPDATEd the right row — not the first row, not every row', (int) $saved_row['qty'] === 9);

// A second row sharing one column with the first, to prove the UPDATE's
// WHERE really is both columns ANDed, not just the first one.
$dm->insert($order_items)->values(['tenant_id' => 1, 'order_id' => 200, 'product' => 'Gadget', 'qty' => 1])->execute();
$item['qty'] = 99;
$item->save();
$other_row = $dm->query('SELECT * FROM order_items WHERE tenant_id = 1 AND order_id = 200')[0];
test('a same-tenant, different-order row was untouched by that save()', (int) $other_row['qty'] === 1);

$found = OrderItemRow::find(['tenant_id' => 1, 'order_id' => 100]);
test('find() with the composite id array locates the right row', $found !== null && $found['product'] === 'Widget');
test('…with the update reflected', $found['qty'] === 99);

// -----------------------------------------------------------------------------
section('delete()');

$item->delete();
test('delete() removed exactly the targeted row', $dm->query('SELECT COUNT(*) AS n FROM order_items WHERE tenant_id = 1 AND order_id = 100')[0]['n'] == 0);
test('…and left the other one alone', $dm->query('SELECT COUNT(*) AS n FROM order_items WHERE tenant_id = 1 AND order_id = 200')[0]['n'] == 1);
test('both key columns are cleared from the instance afterward', !$item->exists());
test('…tenant_id specifically', !array_key_exists('tenant_id', $item->to_array()));
test('…and order_id specifically, not only the first column', !array_key_exists('order_id', $item->to_array()));

// -----------------------------------------------------------------------------
section('refresh()');

$dm->execute('DELETE FROM order_items');
$item2 = OrderItemRow::create(['tenant_id' => 2, 'order_id' => 1, 'product' => 'Sprocket', 'qty' => 1]);
$dm->update($order_items)
    ->set(['qty' => 42])
    ->where(\Italix\Orm\Operators\and_(
        \Italix\Orm\Operators\eq($order_items->tenant_id, 2),
        \Italix\Orm\Operators\eq($order_items->order_id, 1)
    ))
    ->execute();

$item2->refresh();
test('refresh() reloaded the row by its composite key', $item2['qty'] === 42);

// -----------------------------------------------------------------------------
section('replicate()');

$original = OrderItemRow::create(['tenant_id' => 3, 'order_id' => 1, 'product' => 'Cog', 'qty' => 5]);
$copy = $original->replicate();
test('the copy has neither key column — it is treated as new', !$copy->exists());
test('…but kept the non-key data', $copy['product'] === 'Cog' && $copy['qty'] === 5);

// -----------------------------------------------------------------------------
section('SoftDeletes::force_delete() with a composite key');

$soft_items = sqlite_table('soft_order_items', [
    'tenant_id'  => integer()->not_null(),
    'order_id'   => integer()->not_null(),
    'product'    => varchar(50)->not_null(),
    'deleted_at' => varchar(19),
])->primary_key(['tenant_id', 'order_id']);

class SoftOrderItemRow extends ActiveRow
{
    use Persistable, SoftDeletes;

    protected static $primary_key = ['tenant_id', 'order_id'];
}

$dm2 = sqlite_memory();
$dm2->create_tables($soft_items);
SoftOrderItemRow::set_persistence($dm2, $soft_items);

$soft_item = SoftOrderItemRow::create(['tenant_id' => 1, 'order_id' => 1, 'product' => 'Bolt']);
$dm2->insert($soft_items)->values(['tenant_id' => 1, 'order_id' => 2, 'product' => 'Nut'])->execute();

$soft_item->force_delete();
test('force_delete() removed exactly the targeted composite-key row', $dm2->query('SELECT COUNT(*) AS n FROM soft_order_items WHERE tenant_id = 1 AND order_id = 1')[0]['n'] == 0);
test('…and left the other row untouched', $dm2->query('SELECT COUNT(*) AS n FROM soft_order_items WHERE tenant_id = 1 AND order_id = 2')[0]['n'] == 1);
test('both key columns are cleared from the instance, not only the first', !array_key_exists('tenant_id', $soft_item->to_array()) && !array_key_exists('order_id', $soft_item->to_array()));

// -----------------------------------------------------------------------------
section('composes with Table::optimistic_locking() — a composite key AND a version guard together');

$locked_items = sqlite_table('locked_items', [
    'tenant_id' => integer()->not_null(),
    'order_id'  => integer()->not_null(),
    'qty'       => integer()->not_null(),
    'version'   => integer()->not_null(),
])->primary_key(['tenant_id', 'order_id']);
$locked_items->optimistic_locking('version');

class LockedItemRow extends ActiveRow
{
    use Persistable;

    protected static $primary_key = ['tenant_id', 'order_id'];
}

$dm5 = sqlite_memory();
$dm5->create_tables($locked_items);
LockedItemRow::set_persistence($dm5, $locked_items);

$locked_item = LockedItemRow::create(['tenant_id' => 1, 'order_id' => 100, 'qty' => 5]);
test('create() starts at version 1 for a composite-key row too', $locked_item['version'] === 1);

// A decoy row sharing one column, to prove the UPDATE's WHERE really is
// both key columns ANDed, not just satisfied by matching tenant_id.
$dm5->insert($locked_items)->values(['tenant_id' => 1, 'order_id' => 200, 'qty' => 1, 'version' => 1])->execute();

$locked_item['qty'] = 9;
$locked_item->save();
test('save() bumped the in-memory version', $locked_item['version'] === 2);
test('…the right row in the database', $dm5->query('SELECT qty FROM locked_items WHERE tenant_id = 1 AND order_id = 100')[0]['qty'] == 9);
test('…and left the decoy row alone', $dm5->query('SELECT qty FROM locked_items WHERE tenant_id = 1 AND order_id = 200')[0]['qty'] == 1);

// Simulate a concurrent writer moving the version out from under this instance.
$dm5->update($locked_items)
    ->set(['qty' => 999])
    ->where(\Italix\Orm\Operators\and_(
        \Italix\Orm\Operators\eq($locked_items->tenant_id, 1),
        \Italix\Orm\Operators\eq($locked_items->order_id, 100)
    ))
    ->execute();

[$stale_composite_save_threw] = (static function () use ($locked_item): array {
    try {
        $locked_item['qty'] = 42;
        $locked_item->save();

        return [false];
    } catch (\Italix\Orm\Locking\OptimisticLockException $e) {
        return [true];
    }
})();
test('saving a stale composite-key instance raises OptimisticLockException, same as a single-column one', $stale_composite_save_threw);
test('…and the concurrent writer\'s change stands', $dm5->query('SELECT qty FROM locked_items WHERE tenant_id = 1 AND order_id = 100')[0]['qty'] == 999);

// -----------------------------------------------------------------------------
section('single-column keys are completely unaffected — the ordinary case still works exactly as before');

$people = sqlite_table('people', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);

class PersonRow extends ActiveRow
{
    use Persistable;
}

$dm3 = sqlite_memory();
$dm3->create_tables($people);
PersonRow::set_persistence($dm3, $people);

test('get_key_names() wraps the single string in an array', PersonRow::get_key_names() === ['id']);
test('get_key_name() still returns the plain string, no exception', PersonRow::get_key_name() === 'id');

$person = PersonRow::create(['name' => 'Ada']);
test('get_key() still returns a bare scalar, not an array, for a single-column key', is_int($person->get_key()));

$person['name'] = 'Ada Lovelace';
$person->save();
test('save() still works for a single-column key', $dm3->query('SELECT name FROM people')[0]['name'] === 'Ada Lovelace');

$person->delete();
test('delete() still works for a single-column key', $dm3->query('SELECT COUNT(*) AS n FROM people')[0]['n'] == 0);

exit(summary());
