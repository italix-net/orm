<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — window functions
 *
 * The three questions that have no answer in plain `GROUP BY`, run against a
 * real (in-memory) database:
 *
 *   1. the two largest orders **of every** customer
 *   2. every order beside its customer's running total
 *   3. every order beside the one before it
 *
 * Run: php src/Libs/Italix/Orm/examples/window_functions_example.php
 */

declare(strict_types=1);

foreach ([
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

use Italix\Orm\QueryBuilder\QueryBuilder;

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\Operators\{desc, lag, lte, raw, row_number, sql_sum, sub};
use function Italix\Orm\sqlite_memory;

$dm = sqlite_memory();
$dm->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer TEXT, total INTEGER, placed_d TEXT)');

foreach ([
    [1, 'ada',   100, '2026-01-01'],
    [2, 'ada',   300, '2026-01-05'],
    [3, 'ada',   200, '2026-01-09'],
    [4, 'grace', 500, '2026-01-02'],
    [5, 'grace',  50, '2026-01-07'],
] as $row) {
    $dm->execute('INSERT INTO orders (id, customer, total, placed_d) VALUES (?, ?, ?, ?)', $row);
}

$orders = sqlite_table('orders', [
    'id'       => integer()->primary_key(),
    'customer' => varchar(20),
    'total'    => integer(),
    'placed_d' => varchar(10),
]);

// -----------------------------------------------------------------------------
// 1. Top N per group.
//
// A window function cannot go in WHERE — WHERE is evaluated before the window
// is, in every engine. So the shape is: rank inside a subquery, filter outside.

$ranked = sub(
    (new QueryBuilder('sqlite'))->select([
        $orders->id,
        $orders->customer,
        $orders->total,
        row_number()->partition_by($orders->customer)->order_by(desc($orders->total))->as('n'),
    ])->from($orders),
    'ranked'
);

echo "Two largest orders per customer\n-------------------------------\n";

foreach ($dm->select()->from($ranked)->where(lte(raw('n'), 2))->order_by(raw('customer'), raw('n'))->execute() as $row) {
    echo "  {$row['customer']} #{$row['n']}: {$row['total']}\n";
}

// -----------------------------------------------------------------------------
// 2. A running total, without losing the rows the way GROUP BY would.

echo "\nRunning total per customer\n--------------------------\n";

$running = $dm->select([
    $orders->customer,
    $orders->placed_d,
    $orders->total,
    sql_sum($orders->total)->over()
        ->partition_by($orders->customer)
        ->order_by($orders->placed_d)
        ->rows_between('unbounded preceding', 'current row')
        ->as('running'),
])->from($orders)->order_by($orders->customer, $orders->placed_d)->execute();

foreach ($running as $row) {
    echo "  {$row['customer']} {$row['placed_d']}: {$row['total']} → {$row['running']}\n";
}

// -----------------------------------------------------------------------------
// 3. The previous row, and the gap to it.

echo "\nChange since the previous order\n-------------------------------\n";

$gaps = $dm->select([
    $orders->customer,
    $orders->placed_d,
    $orders->total,
    lag($orders->total, 1, 0)->partition_by($orders->customer)->order_by($orders->placed_d)->as('previous'),
])->from($orders)->order_by($orders->customer, $orders->placed_d)->execute();

foreach ($gaps as $row) {
    $delta = $row['total'] - $row['previous'];
    echo "  {$row['customer']} {$row['placed_d']}: {$row['total']} (", ($delta >= 0 ? '+' : ''), "{$delta})\n";
}

// -----------------------------------------------------------------------------
// The SQL, for the record.

$params = [];
echo "\nThe SQL behind the first one\n----------------------------\n",
    $dm->select()->from($ranked)->where(lte(raw('n'), 2))->to_sql($params), "\n";
