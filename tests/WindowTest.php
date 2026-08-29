<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — window functions
 *
 * A window function answers the questions `GROUP BY` cannot, because grouping
 * collapses the rows you still want to see: the three most recent orders *of
 * every* customer, each row beside its group's total, the gap since the previous
 * payment.
 *
 * Everything here is executed and read back. Window SQL is easy to render into
 * something that parses and means something else — a frame that ends at the
 * current row when you meant the whole partition, an `ORDER BY` that lands on
 * the statement instead of inside the `OVER`, a binding that shifts by one
 * because the function's own arguments now carry parameters. None of those is
 * an error; all of them are wrong answers.
 *
 * Run: php src/Libs/Italix/Orm/tests/WindowTest.php
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

use Italix\Orm\QueryBuilder\QueryBuilder;

use function Italix\Orm\Schema\{integer, pg_table, sqlite_table, varchar};
use function Italix\Orm\Operators\{asc, dense_rank, desc, eq, first_value, lag, last_value, lead, lte, ntile, rank, raw, row_number, sql_sum, sub, window_call};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - window functions');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$dm = sqlite_memory();

// Window functions arrived in SQLite 3.25. Below that this suite would be
// asserting about the server, not about us.
$sqlite_version = $dm->query('SELECT sqlite_version() AS v')[0]['v'];

if (version_compare($sqlite_version, '3.25', '<')) {
    echo "  SKIPPED - SQLite {$sqlite_version} has no window functions (3.25 needed).\n";
    exit(summary());
}

$orders = sqlite_table('orders', [
    'id'          => integer()->primary_key(),
    'customer'    => varchar(20),
    'total'       => integer(),
    'placed_d'    => varchar(10),
]);

$dm->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer TEXT, total INTEGER, placed_d TEXT)');

foreach ([
    [1, 'ada',   100, '2026-01-01'],
    [2, 'ada',   300, '2026-01-05'],
    [3, 'ada',   200, '2026-01-09'],
    [4, 'grace', 500, '2026-01-02'],
    [5, 'grace', 500, '2026-01-06'],
    [6, 'grace',  50, '2026-01-07'],
] as $row) {
    $dm->execute('INSERT INTO orders (id, customer, total, placed_d) VALUES (?, ?, ?, ?)', $row);
}

/** One column of a result, joined. */
$column_of = static function (array $rows, string $key): string {
    return implode(',', array_map(static fn(array $row): string => (string) $row[$key], $rows));
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
section('numbering within a partition');

$numbered = $dm->select([
    $orders->id,
    row_number()->partition_by($orders->customer)->order_by(desc($orders->total))->as('n'),
])->from($orders)->order_by($orders->id)->execute();

test('ROW_NUMBER restarts in every partition', $column_of($numbered, 'n') === '3,1,2,1,2,3');

$ranked = $dm->select([
    $orders->id,
    rank()->partition_by($orders->customer)->order_by(desc($orders->total))->as('r'),
    dense_rank()->partition_by($orders->customer)->order_by(desc($orders->total))->as('d'),
])->from($orders)->order_by($orders->id)->execute();

// grace has two orders of 500: RANK gives 1,1 then skips to 3; DENSE_RANK gives
// 1,1 then 2. That difference is the entire reason both functions exist.
test('RANK skips after a tie', $column_of($ranked, 'r') === '3,1,2,1,1,3');
test('DENSE_RANK does not', $column_of($ranked, 'd') === '3,1,2,1,1,2');

$buckets = $dm->select([$orders->id, ntile(2)->order_by($orders->id)->as('b')])
    ->from($orders)->order_by($orders->id)->execute();

test('NTILE cuts the rows into buckets', $column_of($buckets, 'b') === '1,1,1,2,2,2');

// -----------------------------------------------------------------------------
section('an aggregate over a window keeps the rows');

$running = $dm->select([
    $orders->id,
    sql_sum($orders->total)->over()
        ->partition_by($orders->customer)
        ->order_by($orders->placed_d)
        ->rows_between('unbounded preceding', 'current row')
        ->as('running'),
])->from($orders)->order_by($orders->id)->execute();

test('a running total accumulates inside each partition', $column_of($running, 'running') === '100,400,600,500,1000,1050');

$share = $dm->select([
    $orders->id,
    sql_sum($orders->total)->over()->partition_by($orders->customer)->as('customer_total'),
])->from($orders)->order_by($orders->id)->execute();

test('no ORDER BY in the window means the whole partition', $column_of($share, 'customer_total') === '600,600,600,1050,1050,1050');

test('the alias moves from the aggregate to the window', (static function () use ($orders): bool {
    $params = [];
    $sql    = sql_sum($orders->total)->as('t')->over()->to_sql('sqlite', $params);

    // `SUM(x) AS t OVER (…)` is not a statement any engine accepts.
    return $sql === 'SUM("orders"."total") OVER () AS "t"';
})());

// -----------------------------------------------------------------------------
section('looking at the neighbouring row');

$gaps = $dm->select([
    $orders->id,
    lag($orders->total, 1, 0)->partition_by($orders->customer)->order_by($orders->placed_d)->as('previous'),
    lead($orders->total, 1, 0)->partition_by($orders->customer)->order_by($orders->placed_d)->as('next'),
])->from($orders)->order_by($orders->id)->execute();

test('LAG sees the row before, and its default where there is none', $column_of($gaps, 'previous') === '0,100,300,0,500,500');
test('LEAD sees the row after', $column_of($gaps, 'next') === '300,200,0,500,50,0');

$edges = $dm->select([
    $orders->id,
    first_value($orders->total)->partition_by($orders->customer)->order_by($orders->placed_d)->as('first_total'),
    last_value($orders->total)->partition_by($orders->customer)->order_by($orders->placed_d)->as('last_seen'),
    last_value($orders->total)->partition_by($orders->customer)->order_by($orders->placed_d)
        ->rows_between('unbounded preceding', 'unbounded following')->as('last_total'),
])->from($orders)->order_by($orders->id)->execute();

test('FIRST_VALUE is the first of the partition', $column_of($edges, 'first_total') === '100,100,100,500,500,500');

// The frame ends at the current row by default, so LAST_VALUE without a frame
// is the current row — the classic surprise, in every dialect.
test('LAST_VALUE without a frame is the current row', $column_of($edges, 'last_seen') === '100,300,200,500,500,50');
test('…and with the full frame it is the last of the partition', $column_of($edges, 'last_total') === '200,200,200,50,50,50');

// -----------------------------------------------------------------------------
section('TOP N PER GROUP — THE QUERY THIS EXISTS FOR');

// A window function cannot go in WHERE: WHERE runs before the window does. The
// shape is compute-then-filter, and the ORM has to make that expressible rather
// than hide it.
$ranked_orders = sub(
    (new QueryBuilder('sqlite'))->select([
        $orders->id,
        $orders->customer,
        $orders->total,
        row_number()->partition_by($orders->customer)->order_by(desc($orders->total))->as('n'),
    ])->from($orders),
    'ranked'
);

$top_two = $dm->select()
    ->from($ranked_orders)
    ->where(lte(raw('n'), 2))
    ->order_by(raw('customer'), raw('n'))
    ->execute();

test('two rows per customer, not two rows in total', count($top_two) === 4);
test('…and they are the right ones', $column_of($top_two, 'id') === '2,3,4,5');

// -----------------------------------------------------------------------------
section('A WINDOW THAT BINDS VALUES KEEPS THE ORDER STRAIGHT');

// The function's arguments bind before the window's, and both bind before a
// WHERE that follows them in the statement. Placeholders are positional: get
// this wrong and the query runs, against the wrong values.
$defaulted = $dm->select([
    $orders->id,
    lag($orders->total, 1, -1)->partition_by($orders->customer)->order_by($orders->placed_d)->as('previous'),
])->from($orders)->where(eq($orders->customer, 'ada'))->order_by($orders->id)->execute();

test('a default in the window and a value in the WHERE do not swap', $column_of($defaulted, 'previous') === '-1,100,300');

test('…and the parameters come out in that order', (static function () use ($orders): bool {
    $params = [];
    (new QueryBuilder('sqlite'))->select([
        $orders->id,
        lag($orders->total, 1, -1)->order_by($orders->placed_d)->as('previous'),
    ])->from($orders)->where(eq($orders->customer, 'ada'))->to_sql($params);

    return $params === [-1, 'ada'];
})());

// Both sides binding at once is the case that tells the two orders apart: the
// function's arguments come first because they appear first, and a window whose
// PARTITION BY also binds must not overtake them.
$partitioned = $dm->select([
    $orders->id,
    lag($orders->total, 1, -1)
        ->partition_by(raw('CASE WHEN customer = ? THEN 1 ELSE 0 END', ['ada']))
        ->order_by($orders->id)
        ->as('previous'),
])->from($orders)->order_by($orders->id)->execute();

test('a binding in the window does not overtake one in the function', $column_of($partitioned, 'previous') === '-1,100,300,-1,500,500');

test('…and the array says so too', (static function () use ($orders): bool {
    $params = [];
    (new QueryBuilder('sqlite'))->select([
        lag($orders->total, 1, -1)
            ->partition_by(raw('CASE WHEN customer = ? THEN 1 ELSE 0 END', ['ada']))
            ->order_by($orders->id)
            ->as('previous'),
    ])->from($orders)->to_sql($params);

    return $params === [-1, 'ada'];
})());

test('PostgreSQL binds them the same way', (static function (): bool {
    $pg_orders = pg_table('orders', [
        'id'       => integer()->primary_key(),
        'customer' => varchar(20),
        'total'    => integer(),
    ]);

    $params = [];
    $sql    = (new QueryBuilder('postgresql'))->select([
        $pg_orders->id,
        lag($pg_orders->total, 1, -1)->order_by($pg_orders->id)->as('previous'),
    ])->from($pg_orders)->where(eq($pg_orders->customer, 'ada'))->to_sql($params);

    return $params === [-1, 'ada'] && strpos($sql, 'LAG("orders"."total", 1, ?)') !== false;
})());

// -----------------------------------------------------------------------------
section('a window in ORDER BY');

$by_rank = $dm->select([$orders->id])
    ->from($orders)
    ->order_by(desc(row_number()->partition_by($orders->customer)->order_by(asc($orders->id))), $orders->customer)
    ->execute();

test('ordering by a window function works', count($by_rank) === 6);

// -----------------------------------------------------------------------------
section('what a window refuses');

test('a frame bound that is not one of the five shapes', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => row_number()->rows_between('somewhere', 'current row'));

    return $threw && strpos($message, 'none of them') !== false;
})());

test('…including one that smuggles SQL', (static function () use ($throws): bool {
    [$threw] = $throws(static fn() => row_number()->rows_between('current row) --', 'current row'));

    return $threw;
})());

test('a frame that runs backwards', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => row_number()->rows_between('unbounded following', 'current row'));

    return $threw && strpos($message, 'empty by construction') !== false;
})());

test('N preceding is accepted', (static function () use ($orders): bool {
    $params = [];
    $sql    = sql_sum($orders->total)->over()->order_by($orders->id)->rows_between('2 preceding', '1 following')
        ->to_sql('sqlite', $params);

    return strpos($sql, 'ROWS BETWEEN 2 PRECEDING AND 1 FOLLOWING') !== false;
})());

test('a function name that is not an identifier', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => window_call('COUNT(*) FROM x; --'));

    return $threw && strpos($message, 'identifier') !== false;
})());

test('NTILE with no buckets', (static function () use ($throws): bool {
    [$threw] = $throws(static fn() => ntile(0));

    return $threw;
})());

test('a window ORDER BY that is given a string', (static function () use ($throws): bool {
    [$threw] = $throws(static function (): void {
        $params = [];
        row_number()->order_by('id')->to_sql('sqlite', $params);
    });

    return $threw;
})());

exit(summary());
