<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — materialized views
 *
 * A materialized view stores the *answer*, not the query, so nothing about it
 * can be checked by rendering strings: whether it holds rows, whether a refresh
 * changed them, whether a concurrent refresh was allowed at all — those are
 * facts about a server.
 *
 * So this suite needs a real PostgreSQL and **skips itself** without one. Point
 * it at a scratch database:
 *
 *     IX_PG_HOST=127.0.0.1 IX_PG_PORT=5432 IX_PG_DATABASE=postgres \
 *     IX_PG_USER=postgres IX_PG_PASSWORD=… php tests/MaterializedViewTest.php
 *
 * It creates and drops objects prefixed `ix_mv_test_`, and nothing else.
 *
 * Run: php src/Libs/Italix/Orm/tests/MaterializedViewTest.php
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
use Italix\Orm\Migration\Schema;
use Italix\Orm\QueryBuilder\QueryBuilder;

use function Italix\Orm\Schema\{integer, mysql_table, numeric, pg_materialized_view, pg_table, varchar};
use function Italix\Orm\Operators\{eq, sql_sum};
use function Italix\Orm\postgres;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - materialized views');

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
section('the dialect is part of the type');

// This much is true without a server, and worth asserting there too: a
// materialized view for MySQL or SQLite is refused where it is built, not where
// it is run.
test('MySQL has no materialized views, and saying so early is the point', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => new \Italix\Orm\Schema\MaterializedView('m', [], 'mysql'));

    return $threw && strpos($message, 'PostgreSQL-only') !== false;
})());

test('there is no CREATE OR REPLACE for one', (static function (): bool {
    $view = pg_materialized_view('m')->as_query('SELECT 1 AS n');

    // Measured on PostgreSQL 12: the OR REPLACE form is a syntax error, so
    // replacing is always DROP then CREATE.
    return count($view->to_replace_sql()) === 2
        && strpos($view->to_replace_sql()[0], 'DROP MATERIALIZED VIEW IF EXISTS') === 0
        && strpos($view->to_replace_sql()[1], 'CREATE MATERIALIZED VIEW') === 0;
})());

test('DROP is its own statement, not DROP VIEW', (static function (): bool {
    return pg_materialized_view('m')->to_drop_sql() === 'DROP MATERIALIZED VIEW IF EXISTS "m"';
})());

test('a deferred one says WITH NO DATA', (static function (): bool {
    $sql = pg_materialized_view('m')->as_query('SELECT 1 AS n')->with_no_data()->to_create_sql();

    return substr($sql, -12) === 'WITH NO DATA';
})());

test('with_no_data() copies rather than mutates', (static function (): bool {
    $view = pg_materialized_view('m')->as_query('SELECT 1 AS n');
    $view->with_no_data();

    return !$view->is_deferred();
})());

test('a unique index needs a column', (static function () use ($throws): bool {
    [$threw] = $throws(static fn() => pg_materialized_view('m')->add_unique_index('i', []));

    return $threw;
})());

// -----------------------------------------------------------------------------

$config = [
    'host'     => getenv('IX_PG_HOST') ?: '',
    'port'     => (int) (getenv('IX_PG_PORT') ?: 5432),
    'database' => getenv('IX_PG_DATABASE') ?: '',
    'username' => getenv('IX_PG_USER') ?: '',
    'password' => getenv('IX_PG_PASSWORD') ?: '',
];

if (!extension_loaded('pdo_pgsql') || $config['host'] === '' || $config['database'] === '') {
    echo "  SKIPPED the rest - no PostgreSQL configured (set IX_PG_HOST, IX_PG_DATABASE, IX_PG_USER).\n";
    exit(summary());
}

try {
    $dm = postgres($config);
    $dm->query('SELECT 1');
} catch (\Throwable $e) {
    echo '  SKIPPED the rest - cannot reach PostgreSQL: ' . $e->getMessage() . "\n";
    exit(summary());
}

Schema::set_connection($dm);

$dm->execute('DROP MATERIALIZED VIEW IF EXISTS ix_mv_test_totals');
$dm->execute('DROP TABLE IF EXISTS ix_mv_test_orders');
$dm->execute('CREATE TABLE ix_mv_test_orders (id serial primary key, customer text, total numeric(12,2))');

foreach ([['ada', 100], ['ada', 300], ['grace', 500]] as $row) {
    $dm->execute('INSERT INTO ix_mv_test_orders (customer, total) VALUES (?, ?)', $row);
}

$orders = pg_table('ix_mv_test_orders', [
    'id'       => integer()->primary_key(),
    'customer' => varchar(50),
    'total'    => numeric(12, 2),
]);

$totals = pg_materialized_view('ix_mv_test_totals', [
    'customer' => varchar(50),
    'total'    => numeric(12, 2),
])->as_query(
    (new QueryBuilder('postgresql'))->select([$orders->customer, sql_sum($orders->total)->as('total')])
        ->from($orders)
        ->group_by($orders->customer)
)->add_unique_index('ix_mv_test_totals_customer', ['customer']);

/** What the view currently holds. */
$held = static function (DataManager $dm): string {
    $rows = $dm->query('SELECT customer, total FROM ix_mv_test_totals ORDER BY customer');

    return implode(',', array_map(static fn(array $row): string => $row['customer'] . '=' . (int) $row['total'], $rows));
};

// -----------------------------------------------------------------------------
section('created, populated, and read like a table');

Schema::create_materialized_view($totals);

test('it exists', Schema::has_materialized_view($totals));
test('…and has_view() answers about the right catalogue', Schema::has_view($totals));
test('…while pg_views does not know it', !in_array('ix_mv_test_totals', Schema::get_views(), true));
test('…and get_materialized_views() does', in_array('ix_mv_test_totals', Schema::get_materialized_views(), true));
test('it holds the answer already', $held($dm) === 'ada=400,grace=500');
test('it reports itself populated', Schema::is_materialized_view_populated($totals));

test('the query builder reads it like any table', count(
    $dm->select()->from($totals)->where(eq($totals->customer, 'ada'))->execute()
) === 1);

test('writing to it is refused before the server sees it', (static function () use ($throws, $dm, $totals): bool {
    [$threw, $message] = $throws(static fn() => $dm->select()->delete($totals));

    return $threw && strpos($message, 'read-only') !== false;
})());

// -----------------------------------------------------------------------------
section('THE ROWS ARE AS OLD AS THE LAST REFRESH');

$dm->execute('INSERT INTO ix_mv_test_orders (customer, total) VALUES (?, ?)', ['ada', 1000]);

// This is the whole difference from a plain view, and the whole risk of one.
test('a new row in the table does not show up', $held($dm) === 'ada=400,grace=500');

Schema::refresh_materialized_view($totals);

test('a refresh brings it up to date', $held($dm) === 'ada=1400,grace=500');

Schema::refresh_materialized_view($totals, true);

test('a concurrent refresh works, given the unique index', $held($dm) === 'ada=1400,grace=500');

test('…and the index is really there', (static function () use ($dm): bool {
    $row = $dm->query_one(
        "SELECT indexdef FROM pg_indexes WHERE indexname = 'ix_mv_test_totals_customer'"
    );

    return $row !== null && strpos($row['indexdef'], 'CREATE UNIQUE INDEX') === 0;
})());

// -----------------------------------------------------------------------------
section('deferred creation, and what reading one costs');

$dm->execute('DROP MATERIALIZED VIEW IF EXISTS ix_mv_test_deferred');

$deferred = pg_materialized_view('ix_mv_test_deferred', ['customer' => varchar(50)])
    ->as_query((new QueryBuilder('postgresql'))->select([$orders->customer])->from($orders))
    ->with_no_data();

Schema::create_materialized_view($deferred);

test('it exists but holds nothing', Schema::has_materialized_view($deferred));
test('…and says so', !Schema::is_materialized_view_populated($deferred));

// Not an empty result — an error. Worth knowing before a job assumes otherwise.
test('READING IT BEFORE THE FIRST REFRESH IS AN ERROR', (static function () use ($throws, $dm): bool {
    [$threw, $message] = $throws(static fn() => $dm->query('SELECT * FROM ix_mv_test_deferred'));

    return $threw && strpos($message, 'has not been populated') !== false;
})());

Schema::refresh_materialized_view($deferred);

test('after a refresh it reads', count($dm->query('SELECT * FROM ix_mv_test_deferred')) === 4);
test('…and reports itself populated', Schema::is_materialized_view_populated($deferred));

// -----------------------------------------------------------------------------
section('replacing and dropping');

$narrowed = pg_materialized_view('ix_mv_test_totals', ['customer' => varchar(50), 'total' => numeric(12, 2)])
    ->as_query(
        (new QueryBuilder('postgresql'))->select([$orders->customer, sql_sum($orders->total)->as('total')])
            ->from($orders)
            ->where(eq($orders->customer, 'grace'))
            ->group_by($orders->customer)
    )->add_unique_index('ix_mv_test_totals_customer', ['customer']);

Schema::create_or_replace_materialized_view($narrowed);

test('replacing changes what it holds', $held($dm) === 'grace=500');
test('…and the index came back with it', (static function () use ($dm): bool {
    return $dm->query_one("SELECT 1 AS ok FROM pg_indexes WHERE indexname = 'ix_mv_test_totals_customer'") !== null;
})());

test('create_view() refuses a materialized one', (static function () use ($throws, $narrowed): bool {
    [$threw, $message] = $throws(static fn() => Schema::create_view($narrowed));

    return $threw && strpos($message, 'create_materialized_view()') !== false;
})());

test('drop_view() would not have worked anyway', (static function () use ($throws, $dm): bool {
    [$threw, $message] = $throws(static fn() => $dm->execute('DROP VIEW ix_mv_test_totals'));

    return $threw && strpos($message, 'is not a view') !== false;
})());

Schema::drop_materialized_view($totals);
Schema::drop_materialized_view('ix_mv_test_deferred');

test('dropping removes it', !Schema::has_materialized_view($totals));
test('…and dropping again is not an error', (static function (): bool {
    Schema::drop_materialized_view('ix_mv_test_totals');

    return true;
})());

test('asking whether a view that is gone holds rows says so plainly', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => Schema::is_materialized_view_populated('ix_mv_test_totals'));

    return $threw && strpos($message, 'no materialized view') !== false;
})());

$dm->execute('DROP TABLE IF EXISTS ix_mv_test_orders');

exit(summary());
