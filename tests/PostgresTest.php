<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — the PostgreSQL path, against a real server
 *
 * Every other suite asserts about PostgreSQL by reading the SQL this package
 * *renders*. That caught nothing when the rendering itself was wrong:
 *
 *   The builder emitted libpq's numbered placeholders — `WHERE name = $1` —
 *   which **PDO does not parse**. It binds nothing to them, PostgreSQL receives
 *   a parameter that was never set, the comparison yields NULL, and the query
 *   comes back with **no rows and no error**. Every parameterised query on
 *   PostgreSQL silently answered "nothing found". The tests that existed all
 *   passed, because they asserted the presence of `$1` — the bug was the
 *   convention they were checking.
 *
 * So this suite executes. It needs a PostgreSQL and skips itself without one:
 *
 *     IX_PG_HOST=127.0.0.1 IX_PG_DATABASE=postgres IX_PG_USER=postgres \
 *     IX_PG_PASSWORD=… php tests/PostgresTest.php
 *
 * It creates and drops objects prefixed `ix_pg_test_`, and nothing else.
 *
 * Run: php src/Libs/Italix/Orm/tests/PostgresTest.php
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

use function Italix\Orm\Schema\{integer, numeric, pg_table, varchar};
use function Italix\Orm\Operators\{and_, between, desc, eq, gt, ilike, in_, like, lte, raw, row_number, sql_sum, sub};
use function Italix\Orm\postgres;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - PostgreSQL, executed');

$config = [
    'host'     => getenv('IX_PG_HOST') ?: '',
    'port'     => (int) (getenv('IX_PG_PORT') ?: 5432),
    'database' => getenv('IX_PG_DATABASE') ?: '',
    'username' => getenv('IX_PG_USER') ?: '',
    'password' => getenv('IX_PG_PASSWORD') ?: '',
];

if (!extension_loaded('pdo_pgsql') || $config['host'] === '' || $config['database'] === '') {
    echo "  SKIPPED - no PostgreSQL configured (set IX_PG_HOST, IX_PG_DATABASE, IX_PG_USER).\n";
    exit(summary());
}

try {
    $dm = postgres($config);
    $dm->query('SELECT 1');
} catch (\Throwable $e) {
    echo '  SKIPPED - cannot reach PostgreSQL: ' . $e->getMessage() . "\n";
    exit(summary());
}

$dm->execute('DROP TABLE IF EXISTS ix_pg_test_orders');
$dm->execute('DROP TABLE IF EXISTS ix_pg_test_customers');
$dm->execute('CREATE TABLE ix_pg_test_customers (id serial primary key, name text, status text)');
$dm->execute('CREATE TABLE ix_pg_test_orders (id serial primary key, customer_id int, total numeric(12,2))');

foreach ([['Ada', 'active'], ['Grace', 'active'], ['Alan', 'archived']] as $row) {
    $dm->execute('INSERT INTO ix_pg_test_customers (name, status) VALUES (?, ?)', $row);
}

foreach ([[1, 100], [1, 300], [2, 500], [3, 900]] as $row) {
    $dm->execute('INSERT INTO ix_pg_test_orders (customer_id, total) VALUES (?, ?)', $row);
}

$customers = pg_table('ix_pg_test_customers', [
    'id'     => integer()->primary_key(),
    'name'   => varchar(50),
    'status' => varchar(20),
]);

$orders = pg_table('ix_pg_test_orders', [
    'id'          => integer()->primary_key(),
    'customer_id' => integer(),
    'total'       => numeric(12, 2),
]);

$names = static function (array $rows): string {
    return implode(',', array_column($rows, 'name'));
};

// -----------------------------------------------------------------------------
section('A BOUND VALUE REACHES THE SERVER');

// The assertion the package did not have. Any of these coming back empty is the
// old bug: SQL that runs, binds nothing, and finds nothing.
test('WHERE with one value', $names(
    $dm->select()->from($customers)->where(eq($customers->name, 'Ada'))->execute()
) === 'Ada');

test('WHERE with several', $names(
    $dm->select()->from($customers)
        ->where(and_(eq($customers->status, 'active'), eq($customers->name, 'Grace')))
        ->execute()
) === 'Grace');

test('IN', $names(
    $dm->select()->from($customers)->where(in_($customers->name, ['Ada', 'Alan']))
        ->order_by($customers->name)->execute()
) === 'Ada,Alan');

test('BETWEEN', count(
    $dm->select()->from($orders)->where(between($orders->total, 200, 600))->execute()
) === 2);

test('LIKE', $names(
    $dm->select()->from($customers)->where(like($customers->name, 'A%'))
        ->order_by($customers->name)->execute()
) === 'Ada,Alan');

test('ILIKE, which is PostgreSQL-only and takes the same path', $names(
    $dm->select()->from($customers)->where(ilike($customers->name, 'ada'))->execute()
) === 'Ada');

test('a value that matches nothing really matches nothing', count(
    $dm->select()->from($customers)->where(eq($customers->name, 'Nobody'))->execute()
) === 0);

test('the placeholders in the SQL are ?, not $1', (static function () use ($customers): bool {
    $params = [];
    $sql    = (new QueryBuilder('postgresql'))->select()->from($customers)
        ->where(eq($customers->name, 'Ada'))->to_sql($params);

    return strpos($sql, '?') !== false && strpos($sql, '$1') === false;
})());

// -----------------------------------------------------------------------------
section('writing, and RETURNING');

$inserted = $dm->select()->insert($customers)
    ->values(['name' => 'Katherine', 'status' => 'active'])
    ->returning($customers->id, $customers->name)
    ->execute();

test('INSERT … RETURNING gives the row back', ($inserted[0]['name'] ?? '') === 'Katherine');
test('…with the generated id', (int) ($inserted[0]['id'] ?? 0) > 0);

$updated = $dm->select()->update($customers)
    ->set(['status' => 'archived'])
    ->where(eq($customers->name, 'Katherine'))
    ->returning($customers->status)
    ->execute();

test('UPDATE … WHERE finds the row it was told to', ($updated[0]['status'] ?? '') === 'archived');

$deleted = $dm->select()->delete($customers)->where(eq($customers->name, 'Katherine'))->execute();

test('DELETE … WHERE removes exactly one', $deleted === 1);
test('…and the others are untouched', count($dm->select()->from($customers)->execute()) === 3);

// -----------------------------------------------------------------------------
section('the things built on top of that path');

$spenders = $dm->select([$customers->name, sql_sum($orders->total)->as('spend')])
    ->from($customers)
    ->inner_join($orders, eq($orders->customer_id, $customers->id))
    ->where(eq($customers->status, 'active'))
    ->group_by($customers->name)
    ->having(gt(sql_sum($orders->total), 300))
    ->order_by($customers->name)
    ->execute();

test('an expression on the left of HAVING, with a bound value', $names($spenders) === 'Ada,Grace');

$big = sub(
    (new QueryBuilder('postgresql'))->select([$orders->customer_id])->from($orders)
        ->where(gt($orders->total, 400))
);

test('a subquery binds in its own position', $names(
    $dm->select()->from($customers)->where(in_($customers->id, $big))
        ->order_by($customers->name)->execute()
) === 'Alan,Grace');

$ranked = sub(
    (new QueryBuilder('postgresql'))->select([
        $orders->id,
        $orders->customer_id,
        $orders->total,
        row_number()->partition_by($orders->customer_id)->order_by(desc($orders->total))->as('n'),
    ])->from($orders),
    'ranked'
);

test('a window function, on the server that has always had them', count(
    $dm->select()->from($ranked)->where(lte(raw('n'), 1))->execute()
) === 3);

test('DISTINCT ON, which exists only here', count(
    $dm->select([$orders->customer_id, $orders->total])
        ->from($orders)
        ->distinct_on([$orders->customer_id])
        ->order_by($orders->customer_id, desc($orders->total))
        ->execute()
) === 3);

test('a cursor over a bound query', (static function () use ($dm, $customers): bool {
    $seen = [];

    foreach ($dm->select()->from($customers)->where(eq($customers->status, 'active'))
        ->order_by($customers->name)->cursor() as $row) {
        $seen[] = $row['name'];
    }

    return $seen === ['Ada', 'Grace'];
})());

test('keyset paging over a bound query', (static function () use ($dm, $customers): bool {
    $seen = [];

    $dm->select()->from($customers)->where(eq($customers->status, 'active'))
        ->chunk_by($customers->id, 1, static function (array $rows) use (&$seen): void {
            $seen[] = $rows[0]['name'];
        });

    return $seen === ['Ada', 'Grace'];
})());

test('the raw sql() builder binds too', (static function () use ($dm): bool {
    $rows = $dm->sql('SELECT name FROM ix_pg_test_customers WHERE status = ?', ['archived'])->all();

    return count($rows) === 1 && $rows[0]['name'] === 'Alan';
})());

// -----------------------------------------------------------------------------
$dm->execute('DROP TABLE IF EXISTS ix_pg_test_orders');
$dm->execute('DROP TABLE IF EXISTS ix_pg_test_customers');

exit(summary());
