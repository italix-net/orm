<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — DataManager::on() lifecycle hooks
 *
 * Every ORM in wide use gives a model lifecycle hooks — Eloquent's
 * creating/created/saving/deleting events, Doctrine's lifecycle callbacks,
 * Django's pre_save/post_save signals. This package had exactly one place
 * to react to a write: an ActiveRow subclass overriding a method. The Data
 * Mapper style this package's own examples otherwise favour — a write is
 * `$dm->insert($table)->…`, a row a plain array — had nothing: no way to
 * stamp a value before an INSERT, or fire a side effect after one, without
 * hand-writing it at every call site.
 *
 * `DataManager::on()` closes that gap for `insert()`/`update()`/`delete()`
 * directly, and — since `Persistable::save()`/`delete()` compile to exactly
 * those same calls — for ActiveRow too, without ActiveRow needing to know
 * hooks exist.
 *
 * Verified by executing every branch, not by reading the wiring: a hook
 * that mutates is asserted against the row actually written, an after_*
 * hook against the fact it ran at all and with what arguments, and
 * soft_deletes() specifically to prove after_delete still fires for a
 * DELETE that compiles to an UPDATE.
 *
 * Run: php src/Libs/Italix/Orm/tests/HooksTest.php
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
use Italix\Orm\QueryBuilder\QueryBuilder;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - DataManager::on() lifecycle hooks');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

// -----------------------------------------------------------------------------
section('before_insert — mutates the row actually written, per row');

$orders = sqlite_table('orders', [
    'id'        => integer()->primary_key()->auto_increment(),
    'reference' => varchar(20),
    'status'    => varchar(20)->not_null(),
]);
$dm = sqlite_memory();
$dm->create_tables($orders);

$dm->on($orders, 'before_insert', function (array $row): array {
    $row['reference'] = 'ORD-' . $row['status'];

    return $row;
});

$dm->insert($orders)->values(['status' => 'draft'])->execute();
$row = $dm->query('SELECT * FROM orders')[0];
test('the mutated row, not the original, was written', $row['reference'] === 'ORD-draft');

$dm->execute('DELETE FROM orders');
$dm->insert($orders)->values(['status' => 'a']);
$dm->insert($orders)->values(['status' => 'placed'])->execute();
test('a second insert on the same table gets the hook too — it is registered once, not per call', $dm->query('SELECT * FROM orders')[0]['reference'] === 'ORD-placed');

// -----------------------------------------------------------------------------
section('a hook that returns nothing leaves the row untouched — only an array replaces it');

$dm->execute('DELETE FROM orders');
$notes = sqlite_table('notes', [
    'id'   => integer()->primary_key()->auto_increment(),
    'body' => varchar(50)->not_null(),
]);
$dm->create_tables($notes);

$seen = [];
$dm->on($notes, 'before_insert', function (array $row) use (&$seen): void {
    $seen[] = $row['body'];
    // no return — side effect only
});
$dm->insert($notes)->values(['body' => 'hello'])->execute();
$note = $dm->query('SELECT * FROM notes')[0];
test('the row is unchanged when the hook returns void', $note['body'] === 'hello');
test('…but the hook still ran, and saw the row', $seen === ['hello']);

// -----------------------------------------------------------------------------
section('before_insert runs multiple hooks, in registration order, on the same table');

$dm->execute('DELETE FROM orders');
$order_log = [];
$dm2 = sqlite_memory();
$counters = sqlite_table('counters', [
    'id'   => integer()->primary_key()->auto_increment(),
    'tag'  => varchar(20)->not_null(),
]);
$dm2->create_tables($counters);
$dm2->on($counters, 'before_insert', function (array $row) use (&$order_log): array {
    $order_log[] = 'first';
    $row['tag'] .= '-a';

    return $row;
});
$dm2->on($counters, 'before_insert', function (array $row) use (&$order_log): array {
    $order_log[] = 'second';
    $row['tag'] .= '-b';

    return $row;
});
$dm2->insert($counters)->values(['tag' => 'x'])->execute();
$counter = $dm2->query('SELECT * FROM counters')[0];
test('both hooks ran, first-registered first', $order_log === ['first', 'second']);
test('…and each saw the previous one\'s mutation, chained', $counter['tag'] === 'x-a-b');

// -----------------------------------------------------------------------------
section('before_update — mutates the SET clause, once, not per row');

$dm3 = sqlite_memory();
$products = sqlite_table('products', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(50)->not_null(),
    'updated_by' => varchar(20),
]);
$dm3->create_tables($products);
$dm3->insert($products)->values(['name' => 'Widget'])->execute();
$product_id = (int) $dm3->query('SELECT id FROM products')[0]['id'];

$dm3->on($products, 'before_update', function (array $values): array {
    $values['updated_by'] = 'hook';

    return $values;
});
$dm3->update($products)->set(['name' => 'Widget v2'])->where(eq($products->id, $product_id))->execute();
$updated = $dm3->query('SELECT * FROM products')[0];
test('before_update injected a column the caller never set', $updated['updated_by'] === 'hook');
test('…without disturbing the column the caller did set', $updated['name'] === 'Widget v2');

// -----------------------------------------------------------------------------
section('a before_insert/before_update hook that returns a full replacement (a field whitelist) never drops Table::timestamps() or Table::optimistic_locking() — a real bug found and fixed while reviewing this feature (2.29.0)');

$dm3b = sqlite_memory();
$stamped_accounts = sqlite_table('stamped_accounts', [
    'id'         => integer()->primary_key()->auto_increment(),
    'balance'    => integer()->not_null(),
    'insert_dt'  => varchar(19)->not_null(),
    'update_dt'  => varchar(19)->not_null(),
    'version'    => integer()->not_null(),
]);
$stamped_accounts->timestamps('insert_dt', 'update_dt');
$stamped_accounts->optimistic_locking('version');
$dm3b->create_tables($stamped_accounts);

// A whitelist-style hook: returns a brand new array containing only the
// field it cares about, discarding everything else it was handed —
// including whatever timestamps()/optimistic_locking() already injected,
// if they ran before this hook rather than after it.
$dm3b->on($stamped_accounts, 'before_insert', fn (array $row): array => ['balance' => $row['balance']]);
$dm3b->on($stamped_accounts, 'before_update', fn (array $values): array => ['balance' => $values['balance']]);

$dm3b->insert($stamped_accounts)->values(['balance' => 100])->execute();
$inserted = $dm3b->query('SELECT * FROM stamped_accounts')[0];
test('INSERT: insert_dt still got filled in despite the whitelist hook', $inserted['insert_dt'] !== null && $inserted['insert_dt'] !== '');
test('INSERT: version still defaulted to 1', (int) $inserted['version'] === 1);

$stamped_id = (int) $inserted['id'];
sleep(1);
$dm3b->update($stamped_accounts)->set(['balance' => 50])->where(eq($stamped_accounts->id, $stamped_id))->execute();
$after_stamped_update = $dm3b->query('SELECT * FROM stamped_accounts')[0];
test('UPDATE: update_dt still moved forward despite the whitelist hook', $after_stamped_update['update_dt'] > $inserted['update_dt']);
test('UPDATE: version still bumped to 2', (int) $after_stamped_update['version'] === 2);

// -----------------------------------------------------------------------------
section('before_delete — side effects only, fires once regardless of soft_deletes()');

$dm4 = sqlite_memory();
$widgets = sqlite_table('widgets', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(50)->not_null(),
    'deleted_dt' => varchar(19),
]);
$widgets->soft_deletes('deleted_dt');
$dm4->create_tables($widgets);
$dm4->insert($widgets)->values(['name' => 'Gadget'])->execute();
$widget_id = (int) $dm4->query('SELECT id FROM widgets')[0]['id'];

$before_delete_calls_n = 0;
$dm4->on($widgets, 'before_delete', function () use (&$before_delete_calls_n): void {
    $before_delete_calls_n++;
});
$dm4->delete($widgets)->where(eq($widgets->id, $widget_id))->execute();
test('before_delete fired exactly once for a soft delete', $before_delete_calls_n === 1);
test('…and the row is still there, only marked — soft_deletes() still applies', count($dm4->query('SELECT * FROM widgets')) === 1);

// -----------------------------------------------------------------------------
section('after_insert — fires with the written row and the new id');

$dm5 = sqlite_memory();
$people = sqlite_table('people', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);
$dm5->create_tables($people);

$captured = null;
$dm5->on($people, 'after_insert', function (array $rows, ?int $id) use (&$captured): void {
    $captured = [$rows, $id];
});
$dm5->insert($people)->values(['name' => 'Ada'])->execute();
test('after_insert received the row that was written', $captured[0][0]['name'] === 'Ada');
test('…and the id the database assigned', $captured[1] === 1);

// -----------------------------------------------------------------------------
section('after_update / after_delete — fire with the affected row count');

$dm6 = sqlite_memory();
$tasks = sqlite_table('tasks', [
    'id'     => integer()->primary_key()->auto_increment(),
    'status' => varchar(20)->not_null(),
]);
$dm6->create_tables($tasks);
$dm6->insert($tasks)->values(['status' => 'todo'])->execute();
$dm6->insert($tasks)->values(['status' => 'todo'])->execute();

$after_update_args = null;
$dm6->on($tasks, 'after_update', function (array $values, int $affected_n) use (&$after_update_args): void {
    $after_update_args = [$values, $affected_n];
});
$dm6->update($tasks)->set(['status' => 'done'])->where(eq($tasks->status, 'todo'))->execute();
test('after_update reports how many rows it actually touched', $after_update_args[1] === 2);
test('…and the values that were set', $after_update_args[0]['status'] === 'done');

$after_delete_n = null;
$dm6->on($tasks, 'after_delete', function (int $affected_n) use (&$after_delete_n): void {
    $after_delete_n = $affected_n;
});
$dm6->delete($tasks)->where(eq($tasks->status, 'done'))->execute();
test('after_delete reports the affected count too', $after_delete_n === 2);

// -----------------------------------------------------------------------------
section('after_delete fires for a soft-deleted row — DELETE compiled to an UPDATE, but the event matches intent, not SQL');

$dm7 = sqlite_memory();
$archives = sqlite_table('archives', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(50)->not_null(),
    'deleted_dt' => varchar(19),
]);
$archives->soft_deletes('deleted_dt');
$dm7->create_tables($archives);
$dm7->insert($archives)->values(['name' => 'Doc'])->execute();
$archive_id = (int) $dm7->query('SELECT id FROM archives')[0]['id'];

$fired_event = null;
$dm7->on($archives, 'after_delete', function (int $affected_n) use (&$fired_event): void {
    $fired_event = 'after_delete';
});
$dm7->on($archives, 'after_update', function (array $values, int $affected_n) use (&$fired_event): void {
    $fired_event = 'after_update';
});
$dm7->delete($archives)->where(eq($archives->id, $archive_id))->execute();
test('after_delete fired, not after_update, even though the executed SQL was an UPDATE', $fired_event === 'after_delete');

// -----------------------------------------------------------------------------
section('a hook on one table never fires for writes to another');

$dm8 = sqlite_memory();
$a_table = sqlite_table('table_a', ['id' => integer()->primary_key()->auto_increment(), 'n' => integer()]);
$b_table = sqlite_table('table_b', ['id' => integer()->primary_key()->auto_increment(), 'n' => integer()]);
$dm8->create_tables($a_table);
$dm8->create_tables($b_table);

$a_fired_n = 0;
$dm8->on($a_table, 'before_insert', function (array $row) use (&$a_fired_n): void {
    $a_fired_n++;
});
$dm8->insert($b_table)->values(['n' => 1])->execute();
test('inserting into table_b did not fire table_a\'s hook', $a_fired_n === 0);

$dm8->insert($a_table)->values(['n' => 1])->execute();
test('…but inserting into table_a did', $a_fired_n === 1);

// -----------------------------------------------------------------------------
section('ActiveRow\'s Persistable reaches the same hooks — it compiles to $dm->insert()/update()/delete()');

class HookedPerson extends ActiveRow
{
    use Persistable;
}

$dm9 = sqlite_memory();
$hooked_people = sqlite_table('hooked_people', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);
$dm9->create_tables($hooked_people);
HookedPerson::set_persistence($dm9, $hooked_people);

$active_row_before_insert_n = 0;
$dm9->on($hooked_people, 'before_insert', function (array $row) use (&$active_row_before_insert_n): array {
    $active_row_before_insert_n++;
    $row['name'] = strtoupper($row['name']);

    return $row;
});
HookedPerson::create(['name' => 'ada']);
test('creating through ActiveRow fired the DataManager-level hook', $active_row_before_insert_n === 1);
// The hook mutates the array that gets written, not the ActiveRow
// instance's own in-memory attributes — create() never re-syncs from it —
// so the mutation is checked against what actually landed in the row.
test('…and its mutation reached the actual write', $dm9->query('SELECT name FROM hooked_people')[0]['name'] === 'ADA');

// -----------------------------------------------------------------------------
section('a bare QueryBuilder built without a DataManager has no HookRegistry at all — writes still work');

$bare_pdo = new \PDO('sqlite::memory:');
$bare_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$standalone_table = sqlite_table('standalone', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);
$bare_pdo->exec($standalone_table->to_create_sql());

$bare_builder = (new QueryBuilder('sqlite'))->set_connection($bare_pdo);
$new_id = $bare_builder->insert($standalone_table)->values(['name' => 'x'])->execute();
test('insert() through a hookless QueryBuilder does not crash on a null HookRegistry', $new_id === 1);

$affected = (new QueryBuilder('sqlite'))->set_connection($bare_pdo)
    ->update($standalone_table)->set(['name' => 'y'])->where(eq($standalone_table->id, 1))->execute();
test('…neither does update()', $affected === 1);

$deleted = (new QueryBuilder('sqlite'))->set_connection($bare_pdo)
    ->delete($standalone_table)->where(eq($standalone_table->id, 1))->execute();
test('…nor delete()', $deleted === 1);

// -----------------------------------------------------------------------------
section('on() rejects an unrecognised event name — a typo should fail loudly, not silently register a dead hook');

[$threw] = (static function () use ($dm9, $hooked_people): array {
    try {
        $dm9->on($hooked_people, 'beforeInsert', function (): void {
        });

        return [false];
    } catch (\InvalidArgumentException $e) {
        return [true];
    }
})();
test('a camelCase (or otherwise unknown) event name throws', $threw);

exit(summary());
