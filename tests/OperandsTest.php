<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — expressions on the left of a condition
 *
 * The comparison operators used to be typed to take a `Column` and nothing
 * else. That is not a missing convenience: it left `HAVING SUM(total) > 1000`
 * — the most ordinary question a `GROUP BY` is asked — with no form at all, and
 * `gte(raw('total'), 1000)` a `TypeError` rather than SQL. (The README carried
 * exactly that as an example, which is how it was found.)
 *
 * Now every operator takes a column *or* an expression on the left: an
 * aggregate, a raw fragment, a scalar subquery.
 *
 * The assertions run the query and read the rows back. A bug in this area does
 * not usually produce invalid SQL — it produces valid SQL asking about
 * something else, most often because a binding landed in the wrong position
 * once the left side started carrying parameters of its own.
 *
 * Run: php src/Libs/Italix/Orm/tests/OperandsTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',               // checked out on its own
        __DIR__ . '/../../../../../vendor/autoload.php',   // vendored in a project
        __DIR__ . '/../../../../vendor/autoload.php',      // installed as a package
        __DIR__ . '/../../../autoload.php',                // sibling autoloader
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\DataManager;
use Italix\Orm\QueryBuilder\QueryBuilder;

use function Italix\Orm\Schema\{integer, numeric, pg_table, sqlite_table, varchar};
use function Italix\Orm\Operators\{between, eq, gt, gte, in_, is_null, is_not_null, like, lt, not_in_, raw, sql_count, sql_max, sql_sum, sub};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - expression operands');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$customers = sqlite_table('customers', [
    'id'     => integer()->primary_key(),
    'name'   => varchar(120),
    'status' => varchar(20),
]);

$orders = sqlite_table('orders', [
    'id'          => integer()->primary_key(),
    'customer_id' => integer(),
    'total'       => numeric(10, 2),
    'note'        => varchar(50),
]);

$dm = sqlite_memory();
$dm->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
$dm->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, total NUMERIC, note TEXT)');

foreach ([[1, 'Ada', 'active'], [2, 'Grace', 'active'], [3, 'Alan', 'archived']] as $row) {
    $dm->execute('INSERT INTO customers (id, name, status) VALUES (?, ?, ?)', $row);
}

foreach ([
    [1, 1, 4000, 'first'],
    [2, 1, 3000, null],
    [3, 2, 500,  'small'],
    [4, 3, 9000, null],
] as $row) {
    $dm->execute('INSERT INTO orders (id, customer_id, total, note) VALUES (?, ?, ?, ?)', $row);
}

/** The names a query returns, joined. */
$names = static function (array $rows): string {
    return implode(',', array_column($rows, 'name'));
};

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
section('AN AGGREGATE CAN BE COMPARED — WHICH IS WHAT HAVING IS FOR');

$spenders = $dm->select([$customers->name, sql_sum($orders->total)->as('spend')])
    ->from($customers)
    ->inner_join($orders, eq($orders->customer_id, $customers->id))
    ->group_by($customers->name)
    ->having(gt(sql_sum($orders->total), 1000))
    ->order_by($customers->name)
    ->execute();

test('HAVING SUM(total) > 1000 now has a form', $names($spenders) === 'Ada,Alan');
test('…and it really is the sum being compared', (int) $spenders[0]['spend'] === 7000);

$one_order = $dm->select([$customers->name])
    ->from($customers)
    ->inner_join($orders, eq($orders->customer_id, $customers->id))
    ->group_by($customers->name)
    ->having(eq(sql_count(), 1))
    ->order_by($customers->name)
    ->execute();

test('COUNT(*) = 1 too', $names($one_order) === 'Alan,Grace');

$ranged = $dm->select([$customers->name])
    ->from($customers)
    ->inner_join($orders, eq($orders->customer_id, $customers->id))
    ->group_by($customers->name)
    ->having(between(sql_max($orders->total), 1000, 5000))
    ->execute();

test('BETWEEN takes an aggregate as well', $names($ranged) === 'Ada');

test('an aggregate over an expression works', (static function () use ($dm, $customers, $orders): bool {
    $rows = $dm->select([$customers->name])
        ->from($customers)
        ->inner_join($orders, eq($orders->customer_id, $customers->id))
        ->group_by($customers->name)
        ->having(gt(sql_sum(raw('total * 2')), 10000))
        ->execute();

    return implode(',', array_column($rows, 'name')) === 'Ada,Alan';
})());

// -----------------------------------------------------------------------------
section('a raw fragment on the left');

test('WHERE LOWER(name) = ?', $names(
    $dm->select([$customers->name])->from($customers)->where(eq(raw('LOWER(name)'), 'ada'))->execute()
) === 'Ada');

test('IN over an expression', $names(
    $dm->select([$customers->name])->from($customers)
        ->where(in_(raw('LOWER(status)'), ['archived']))
        ->execute()
) === 'Alan');

test('NOT IN over an expression', $names(
    $dm->select([$customers->name])->from($customers)
        ->where(not_in_(raw('LOWER(status)'), ['archived']))
        ->order_by($customers->name)
        ->execute()
) === 'Ada,Grace');

test('LIKE over an expression', $names(
    $dm->select([$customers->name])->from($customers)
        ->where(like(raw('UPPER(name)'), 'A%'))
        ->order_by($customers->name)
        ->execute()
) === 'Ada,Alan');

test('IS NULL over an expression', count(
    $dm->select([$orders->id])->from($orders)
        ->where(is_null(raw("NULLIF(note, 'first')")))
        ->execute()
) === 3);

test('IS NOT NULL over an expression', count(
    $dm->select([$orders->id])->from($orders)
        ->where(is_not_null(raw("NULLIF(note, 'first')")))
        ->execute()
) === 1);

// -----------------------------------------------------------------------------
section('A LEFT SIDE THAT BINDS VALUES KEEPS THE ORDER STRAIGHT');

// This is the failure mode that does not announce itself. Placeholders are
// positional, so a left operand carrying its own binding must append it before
// the right-hand value does — otherwise the query runs and answers about
// something else entirely.
$prefix = $dm->select([$customers->name])->from($customers)
    ->where(eq(raw('SUBSTR(name, 1, ?)', [3]), 'Ada'))
    ->execute();

test('a bound value on the left is bound before the one on the right', $names($prefix) === 'Ada');

test('…and the SQL carries them in that order', (static function () use ($customers): bool {
    $params = [];
    (new QueryBuilder('sqlite'))->select([$customers->name])->from($customers)
        ->where(eq(raw('SUBSTR(name, 1, ?)', [3]), 'Ada'))
        ->to_sql($params);

    return $params === [3, 'Ada'];
})());

test('a WHERE before it does not shift them', (static function () use ($dm, $customers): bool {
    $rows = $dm->select([$customers->name])->from($customers)
        ->where(\Italix\Orm\Operators\and_(
            eq($customers->status, 'active'),
            eq(raw('SUBSTR(name, 1, ?)', [3]), 'Ada')
        ))
        ->execute();

    return implode(',', array_column($rows, 'name')) === 'Ada';
})());

test('PostgreSQL binds them in the same order', (static function (): bool {
    $pg_customers = pg_table('customers', ['id' => integer()->primary_key(), 'name' => varchar(120)]);
    $params       = [];
    $sql          = (new QueryBuilder('postgresql'))->select([$pg_customers->name])
        ->from($pg_customers)
        ->where(eq(raw('SUBSTR(name, 1, ?)', [3]), 'Ada'))
        ->to_sql($params);

    // `?` here too: PDO parses no other form, whatever the dialect.
    return $params === [3, 'Ada'] && substr_count($sql, '?') === 2;
})());

// -----------------------------------------------------------------------------
section('a scalar subquery on the left');

// `(SELECT …) > 5` — the parentheses are not decoration, they are the only way
// SQL takes a subquery in this position.
$average = sub(
    (new QueryBuilder('sqlite'))->select([sql_sum($orders->total)->as('t')])->from($orders)
);

test('a subquery on the left is parenthesised', (static function () use ($average, $customers): bool {
    $params = [];
    $sql    = (new QueryBuilder('sqlite'))->select([$customers->name])->from($customers)
        ->where(gt($average, 100))
        ->to_sql($params);

    return strpos($sql, 'WHERE (SELECT') !== false;
})());

// The orders add up to 16500, so the boundary is where the assertion bites: a
// subquery that rendered as something else would not sit between these two.
test('…and the comparison actually holds', count(
    $dm->select([$customers->name])->from($customers)->where(gt($average, 16000))->execute()
) === 3);

test('…and fails when it should', count(
    $dm->select([$customers->name])->from($customers)->where(gt($average, 17000))->execute()
) === 0);

test('…the other way round too', count(
    $dm->select([$customers->name])->from($customers)->where(lt($average, 16000))->execute()
) === 0);

// -----------------------------------------------------------------------------
section('nothing about columns changed');

test('a column on the left still works', $names(
    $dm->select([$customers->name])->from($customers)->where(eq($customers->status, 'archived'))->execute()
) === 'Alan');

test('column to column still compares references, not values', (static function () use ($customers, $orders): bool {
    $params = [];
    $sql    = (new QueryBuilder('sqlite'))->select([$customers->name])->from($customers)
        ->inner_join($orders, eq($orders->customer_id, $customers->id))
        ->to_sql($params);

    return $params === [] && strpos($sql, '"orders"."customer_id" = "customers"."id"') !== false;
})());

test('gte still binds a plain value', (static function () use ($orders): bool {
    $params = [];
    (new QueryBuilder('sqlite'))->select([$orders->id])->from($orders)->where(gte($orders->total, 1000))->to_sql($params);

    return $params === [1000];
})());

test('a string on the left is still refused', (static function () use ($throws, $orders): bool {
    [$threw] = $throws(static fn() => eq('total', 1000));

    return $threw;
})());

exit(summary());
