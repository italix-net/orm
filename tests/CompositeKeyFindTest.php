<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — find() against a composite primary key
 *
 * `TableQuery::find($id)` used to read only `get_primary_keys()[0]` — the
 * first column of a composite key — and silently ignore the rest. That does
 * not fail: it returns *a* row, matched on one column of a key that needs
 * two, which on a table sharing the first column's value across many rows
 * (a tenant id, say) can be any one of them. Wrong, and quiet about it.
 *
 * `find()` now takes an array keyed by column name for a composite key, and
 * refuses — loudly — a bare scalar or an incomplete array, rather than
 * guessing. The regression proven here is the exact failure mode: two rows
 * sharing the first key column but differing on the second, where the old
 * code would return whichever one `find_first()` happened to see first.
 *
 * Run: php src/Libs/Italix/Orm/tests/CompositeKeyFindTest.php
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

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - find() against a composite primary key');

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
section('a single-column key is unaffected — the ordinary, unchanged path');

$users = sqlite_table('users', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);

$dm = sqlite_memory();
$dm->create_tables($users);
$dm->insert($users)->values(['name' => 'Ada'])->execute();
$ada = $dm->query('SELECT * FROM users')[0];

test('find(scalar) still works exactly as before', $dm->query_table($users)->find($ada['id'])['name'] === 'Ada');

// -----------------------------------------------------------------------------
section('a composite key: find() with the right array finds the right row');

$order_items = sqlite_table('order_items', [
    'tenant_id' => integer()->primary_key(),
    'order_id'  => integer()->primary_key(),
    'product'   => varchar(50)->not_null(),
]);

// Table::to_create_sql() cannot build this table itself: each column
// individually flagged primary_key() renders its own inline PRIMARY KEY, and
// SQLite refuses two of them on one table ("has more than one primary key")
// — a separate, real limitation found while writing this test, not fixed
// here (out of scope for the find() bug this file is about; flagged to the
// user for a decision). Built with raw SQL instead — exactly the shape
// `db:pull` would hand back from a real database that already has a
// composite key, which is the scenario this fix actually has to handle.
$dm->execute('CREATE TABLE order_items (
    tenant_id INTEGER NOT NULL,
    order_id  INTEGER NOT NULL,
    product   TEXT NOT NULL,
    PRIMARY KEY (tenant_id, order_id)
)');
$dm->insert($order_items)->values(['tenant_id' => 1, 'order_id' => 1, 'product' => 'Widget'])->execute();
// Same tenant_id (the first key column) as the row above, different order_id —
// this is the exact pair that exposes the old bug: matching on tenant_id
// alone cannot tell these two rows apart.
$dm->insert($order_items)->values(['tenant_id' => 1, 'order_id' => 2, 'product' => 'Gadget'])->execute();
// Different tenant_id, same order_id as the first row — the other half of
// the same ambiguity, from the other column.
$dm->insert($order_items)->values(['tenant_id' => 2, 'order_id' => 1, 'product' => 'Sprocket'])->execute();

$found = $dm->query_table($order_items)->find(['tenant_id' => 1, 'order_id' => 2]);
test('the exact row is found', $found !== null && $found['product'] === 'Gadget');

$found_other = $dm->query_table($order_items)->find(['tenant_id' => 2, 'order_id' => 1]);
test('…and picking the other tenant with the same order_id finds the right one, not the first row', $found_other['product'] === 'Sprocket');

$missing = $dm->query_table($order_items)->find(['tenant_id' => 9, 'order_id' => 9]);
test('a key that matches nothing returns null, not an error', $missing === null);

// -----------------------------------------------------------------------------
section('a bare scalar against a composite key is refused, not silently narrowed to the first column');

[$threw, $message] = $throws(fn () => $dm->query_table($order_items)->find(1));
test('find(1) throws instead of matching tenant_id=1 alone', $threw);
test('…and the message names the composite key and how to call it correctly', str_contains($message, 'tenant_id') && str_contains($message, 'order_id'));

// -----------------------------------------------------------------------------
section('an incomplete array is refused too, rather than treated as a partial match');

[$threw, $message] = $throws(fn () => $dm->query_table($order_items)->find(['tenant_id' => 1]));
test('find() with only one of the two columns throws', $threw);
test('…and says which column is missing', str_contains($message, 'order_id'));

exit(summary());
