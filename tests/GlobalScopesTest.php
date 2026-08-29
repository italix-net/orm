<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — DataManager::add_global_scope()
 *
 * Eloquent, Django and Doctrine all let a model declare a filter that every
 * read carries automatically — multi-tenant isolation, "only published",
 * a visibility rule — without repeating the same WHERE at every call site.
 * This package already had exactly one such filter, `Table::soft_deletes()`,
 * but it was hard-coded into `effective_where()` rather than something a
 * caller could add one of their own. `add_global_scope()` generalises it:
 * `effective_where()` on both query engines now ANDs in every active
 * registered scope, the same way it already ANDs in the soft-delete filter.
 *
 * Both engines are exercised independently — QueryBuilder (select()) and
 * TableQuery (query_table()) do not share an implementation, the same
 * reason AttributeCastingTest.php and TimestampsAndSoftDeleteTest.php test
 * both separately rather than trusting one to say anything about the other.
 *
 * Run: php src/Libs/Italix/Orm/tests/GlobalScopesTest.php
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
use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - DataManager::add_global_scope()');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

// -----------------------------------------------------------------------------
section('a global scope narrows select() — QueryBuilder');

$orders = sqlite_table('orders', [
    'id'        => integer()->primary_key()->auto_increment(),
    'tenant_id' => integer()->not_null(),
    'total'     => integer()->not_null(),
]);
$dm = sqlite_memory();
$dm->create_tables($orders);

$dm->insert($orders)->values(['tenant_id' => 1, 'total' => 10])->execute();
$dm->insert($orders)->values(['tenant_id' => 1, 'total' => 20])->execute();
$dm->insert($orders)->values(['tenant_id' => 2, 'total' => 99])->execute();

$dm->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));

$rows = $dm->select()->from($orders)->execute();
test('only tenant 1\'s rows come back', array_column($rows, 'total') === [10, 20]);

$dm->insert($orders)->values(['tenant_id' => 1, 'total' => 30])->execute();
test('…the scope applies to a row inserted after it was registered too', count($dm->select()->from($orders)->execute()) === 3);

$raw_count = $dm->query('SELECT COUNT(*) AS n FROM orders')[0]['n'];
test('…and it never touched the actual rows — raw SQL still sees tenant 2\'s', (int) $raw_count === 4);

// -----------------------------------------------------------------------------
section('without_scopes() undoes it — QueryBuilder');

test('without_scopes() with no arguments sees every tenant again', count($dm->select()->from($orders)->without_scopes()->execute()) === 4);
test('a plain select() (no without_scopes) is still scoped — the escape hatch is per-query, opt-in', count($dm->select()->from($orders)->execute()) === 3);

// -----------------------------------------------------------------------------
section('a scope combines with an explicit where() — AND, not replace');

$tenant1_high = $dm->select()->from($orders)->where(eq($orders->total, 20))->execute();
test('the explicit where() and the scope both apply', count($tenant1_high) === 1 && $tenant1_high[0]['total'] === 20);

$cross_tenant_miss = $dm->select()->from($orders)->where(eq($orders->tenant_id, 2))->execute();
test('a where() that only tenant 2 could satisfy finds nothing — the scope still applies underneath it', $cross_tenant_miss === []);

// -----------------------------------------------------------------------------
section('two scopes on the same table both apply, ANDed together');

$dm->execute('DELETE FROM orders');
$dm2 = sqlite_memory();
$dm2->create_tables($orders);
$dm2->insert($orders)->values(['tenant_id' => 1, 'total' => 5])->execute();
$dm2->insert($orders)->values(['tenant_id' => 1, 'total' => 500])->execute();
$dm2->insert($orders)->values(['tenant_id' => 2, 'total' => 5])->execute();

$dm2->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));
$dm2->add_global_scope($orders, 'small', fn (Table $t) => \Italix\Orm\Operators\lt($t->total, 100));

$both = $dm2->select()->from($orders)->execute();
test('only the row satisfying both scopes comes back', count($both) === 1 && $both[0]['total'] === 5);

// -----------------------------------------------------------------------------
section('without_scopes([names]) skips only the named ones');

$without_tenant = $dm2->select()->from($orders)->without_scopes(['tenant'])->execute();
test('skipping just "tenant" still applies "small" — tenant 2\'s cheap row shows, tenant 1\'s expensive one does not', array_column($without_tenant, 'total') === [5, 5]);

$without_both = $dm2->select()->from($orders)->without_scopes(['tenant'])->without_scopes(['small'])->execute();
test('two calls naming different scopes accumulate — skipping both leaves every row', count($without_both) === 3);

// -----------------------------------------------------------------------------
section('registering the same scope name again replaces it, not adds a second one');

$dm3 = sqlite_memory();
$dm3->create_tables($orders);
$dm3->insert($orders)->values(['tenant_id' => 1, 'total' => 1])->execute();
$dm3->insert($orders)->values(['tenant_id' => 9, 'total' => 1])->execute();

$dm3->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));
test('the first registration filters to tenant 1', count($dm3->select()->from($orders)->execute()) === 1);

$dm3->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 9));
test('re-registering "tenant" replaces it — now tenant 9', $dm3->select()->from($orders)->execute()[0]['tenant_id'] === 9);

// -----------------------------------------------------------------------------
section('a scope on one table never applies to another');

$dm4 = sqlite_memory();
$members = sqlite_table('members', [
    'id'      => integer()->primary_key()->auto_increment(),
    'active'  => integer()->not_null(),
]);
$dm4->create_tables($orders);
$dm4->create_tables($members);
$dm4->insert($orders)->values(['tenant_id' => 7, 'total' => 1])->execute();
$dm4->insert($members)->values(['active' => 1])->execute();

$dm4->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));
test('orders is scoped and excludes tenant 7', $dm4->select()->from($orders)->execute() === []);
test('members has no scope registered — unaffected', count($dm4->select()->from($members)->execute()) === 1);

// -----------------------------------------------------------------------------
section('a global scope narrows query_table() too — TableQuery, a separate engine');

$dm5 = sqlite_memory();
$dm5->create_tables($orders);
$dm5->insert($orders)->values(['tenant_id' => 1, 'total' => 1])->execute();
$dm5->insert($orders)->values(['tenant_id' => 2, 'total' => 2])->execute();
$dm5->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));

$via_table_query = $dm5->query_table($orders)->find_many();
test('query_table()->find_many() is scoped too', count($via_table_query) === 1 && $via_table_query[0]['tenant_id'] === 1);

test('…and without_scopes() undoes it there as well', count($dm5->query_table($orders)->without_scopes()->find_many()) === 2);

$tenant2_id = (int) $dm5->query('SELECT id FROM orders WHERE tenant_id = 2')[0]['id'];
test('find($id) on a scoped-out row returns null, same as it does for a soft-deleted one', $dm5->query_table($orders)->find($tenant2_id) === null);
test('…but with_trashed()-style escape hatch (without_scopes) finds it', $dm5->query_table($orders)->without_scopes()->find($tenant2_id) !== null);

// -----------------------------------------------------------------------------
section('a scope combines with Table::soft_deletes() — both narrow the same read, independently');

$dm6 = sqlite_memory();
$tickets = sqlite_table('tickets', [
    'id'         => integer()->primary_key()->auto_increment(),
    'tenant_id'  => integer()->not_null(),
    'deleted_dt' => varchar(19),
]);
$tickets->soft_deletes('deleted_dt');
$dm6->create_tables($tickets);
$dm6->insert($tickets)->values(['tenant_id' => 1])->execute();
$dm6->insert($tickets)->values(['tenant_id' => 2])->execute();
$dm6->add_global_scope($tickets, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));

$tenant2_ticket_id = (int) $dm6->query('SELECT id FROM tickets WHERE tenant_id = 2')[0]['id'];
$dm6->delete($tickets)->where(eq($tickets->id, (int) $dm6->query('SELECT id FROM tickets WHERE tenant_id = 1')[0]['id']))->execute();

test('the tenant-1 row is gone from a scoped read — soft-deleted', $dm6->query_table($tickets)->find_many() === []);
test(
    'with_trashed() lifts the soft-delete filter but the tenant scope still applies — the tenant-1 row reappears, tenant-2\'s stays excluded',
    array_column($dm6->query_table($tickets)->with_trashed()->find_many(), 'tenant_id') === [1]
);
test('without_scopes() alone still respects soft_deletes() — the tenant-1 row stays hidden, it is actually deleted', array_column($dm6->query_table($tickets)->without_scopes()->find_many(), 'tenant_id') === [2]);
test('both escape hatches together see everything', count($dm6->query_table($tickets)->without_scopes()->with_trashed()->find_many()) === 2);

// -----------------------------------------------------------------------------
section('writes (insert/update/delete) are never scoped — a scoped-out row must still be reachable to correct or purge it');

$dm7 = sqlite_memory();
$dm7->create_tables($orders);
$dm7->insert($orders)->values(['tenant_id' => 5, 'total' => 1])->execute();
$order_id = (int) $dm7->query('SELECT id FROM orders')[0]['id'];
$dm7->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));

$dm7->update($orders)->set(['total' => 999])->where(eq($orders->id, $order_id))->execute();
test('update() reached the scoped-out row — UPDATE does not go through effective_where()', $dm7->query('SELECT total FROM orders')[0]['total'] === 999);

// -----------------------------------------------------------------------------
section('TypedQuery / Persistable reach the same scopes — ActiveRow, not only the two lower-level engines');

class ScopedOrderRow extends ActiveRow
{
    use Persistable;
}

$dm8 = sqlite_memory();
$dm8->create_tables($orders);
ScopedOrderRow::set_persistence($dm8, $orders);
$dm8->insert($orders)->values(['tenant_id' => 1, 'total' => 1])->execute();
$dm8->insert($orders)->values(['tenant_id' => 2, 'total' => 1])->execute();
$dm8->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, 1));

test('find_all() is scoped', count(ScopedOrderRow::find_all()) === 1);
test(
    "find_all(['without_scopes' => true]) undoes it",
    count(ScopedOrderRow::find_all(['without_scopes' => true])) === 2
);
test('query()->without_scopes()->find_many() undoes it through TypedQuery too', count(ScopedOrderRow::query()->without_scopes()->find_many()) === 2);

exit(summary());
