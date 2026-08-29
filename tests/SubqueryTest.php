<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — subqueries, CTEs and set operations
 *
 * Three constructs that are SQL rather than sugar. Without them the only way to
 * ask an ordinary question — "customers who have ordered", "the tree under this
 * node" — was `raw()`, which takes a string and interpolates nothing: every
 * value inside had to be pasted into the statement by hand. That is the precise
 * moment a query builder stops protecting anybody.
 *
 * The assertions come in two kinds and both are needed. **What SQL is emitted**
 * catches a clause built in the wrong place. **What rows come back** catches the
 * emitted SQL being valid and wrong — which is the failure that survives a
 * review, because the query runs.
 *
 * The parameter order is the quiet one. Placeholders are positional, so a CTE
 * whose bindings are appended after the outer WHERE produces a statement that
 * executes without complaint against the wrong values. It is asserted directly.
 *
 * Run: php src/Libs/Italix/Orm/tests/SubqueryTest.php
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
use Italix\Orm\QueryBuilder\Subquery;

use function Italix\Orm\Schema\{sqlite_table, mysql_table, pg_table, integer, varchar, serial};
use function Italix\Orm\Operators\{and_, eq, exists, gt, in_, not_exists, not_in_, sub};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - subqueries, CTEs and set operations');

/** @return array{0: bool, 1: string} */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (\Throwable $e) {
        return [true, $e->getMessage()];
    }
};

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, city TEXT)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, total INTEGER)');
$pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, parent_id INTEGER, name TEXT)');
$pdo->exec("INSERT INTO customers VALUES (1,'Anna','Roma'),(2,'Bruno','Milano'),(3,'Carla','Roma'),(4,'Dino','Napoli')");
$pdo->exec('INSERT INTO orders VALUES (1,1,150),(2,1,900),(3,2,50),(4,3,1200)');
$pdo->exec("INSERT INTO nodes VALUES (1,NULL,'root'),(2,1,'a'),(3,1,'b'),(4,2,'a1'),(5,4,'a1x'),(6,NULL,'other')");

$customers = sqlite_table('customers', ['id' => serial(), 'name' => varchar(100), 'city' => varchar(50)]);
$orders    = sqlite_table('orders', ['id' => serial(), 'customer_id' => integer(), 'total' => integer()]);
$nodes     = sqlite_table('nodes', ['id' => serial(), 'parent_id' => integer(), 'name' => varchar(50)]);
$tree      = sqlite_table('tree', ['id' => serial(), 'parent_id' => integer(), 'name' => varchar(50)]);

/** A connected SELECT builder. */
$sel = static function ($table, ?array $columns = null) use ($pdo): QueryBuilder {
    return (new QueryBuilder())->select($columns)->from($table)->set_connection($pdo);
};

/** The names a query returns, sorted, as one string. */
$names = static function (QueryBuilder $query): string {
    $out = array_column($query->execute(), 'name');
    sort($out);

    return implode(',', $out);
};

/** The SQL, and the bindings it collected, in order. */
$rendered = static function (QueryBuilder $query): array {
    $params = [];
    $sql    = $query->to_sql($params);

    return [$sql, $params];
};

// -----------------------------------------------------------------------------
section('a subquery in WHERE');

$expensive = static function () use ($sel, $orders): Subquery {
    return sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 100)));
};

[$sql, $params] = $rendered($sel($customers)->where(in_($customers->id, $expensive())));

test('IN takes a subquery as well as a list',
    strpos($sql, 'IN (SELECT "orders"."customer_id" FROM "orders"') !== false, $sql);

test('THE INNER VALUE IS BOUND, NOT PASTED INTO THE SQL',
    $params === [100] && strpos($sql, '100') === false,
    'the value reached the statement as text — which is what raw() forced before this existed');

test('…and the rows are the right ones',
    $names($sel($customers)->where(in_($customers->id, $expensive()))) === 'Anna,Carla');

test('NOT IN is the same construct negated',
    $names($sel($customers)->where(not_in_($customers->id, sub($sel($orders, [$orders->customer_id])))))
    === 'Dino');

// A list still works: widening the parameter must not have narrowed it.
test('a plain list of values still works',
    $names($sel($customers)->where(in_($customers->id, [1, 4]))) === 'Anna,Dino');

test('an empty list still matches nothing',
    $names($sel($customers)->where(in_($customers->id, []))) === '');

// -----------------------------------------------------------------------------
section('EXISTS, and why it is not just a second spelling of IN');

$has_orders = static function () use ($sel, $orders, $customers): Subquery {
    return sub($sel($orders, [$orders->id])->where(eq($orders->customer_id, $customers->id)));
};

test('EXISTS correlates against the outer table',
    $names($sel($customers)->where(exists($has_orders()))) === 'Anna,Bruno,Carla');

test('NOT EXISTS is the complement',
    $names($sel($customers)->where(not_exists($has_orders()))) === 'Dino');

[$sql] = $rendered($sel($customers)->where(exists($has_orders())));

test('the correlation is an ordinary column reference',
    strpos($sql, '"orders"."customer_id" = "customers"."id"') !== false, $sql);

// The difference that matters. `NOT IN` against a column that can be NULL is
// true for no row at all — correct three-valued logic, and almost never the
// question anybody was asking. `NOT EXISTS` answers it.
$pdo->exec('INSERT INTO orders VALUES (5, NULL, 10)');

test('NOT IN WITH A NULL IN THE SUBQUERY MATCHES NOTHING',
    $names($sel($customers)->where(not_in_($customers->id, sub($sel($orders, [$orders->customer_id])))))
    === '',
    'SQL says so, and this assertion is the documentation of why not_exists() exists');

test('…while NOT EXISTS still answers the question',
    $names($sel($customers)->where(not_exists($has_orders()))) === 'Dino');

$pdo->exec('DELETE FROM orders WHERE id = 5');

// -----------------------------------------------------------------------------
section('a subquery as a table, and as a scalar');

$roman = static function () use ($sel, $customers): Subquery {
    return sub($sel($customers)->where(eq($customers->city, 'Roma')))->alias('r');
};

test('FROM accepts a derived table', $names($sel($customers)->from($roman())) === 'Anna,Carla');

[$sql] = $rendered($sel($customers)->from($roman()));

test('…emitted with its alias', strpos($sql, ') AS "r"') !== false, $sql);

// A scalar subquery in a comparison needed no new code: Comparison already
// wrapped an SQLExpression in parentheses, and Subquery is one.
$biggest = sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 1000)));

test('a comparison accepts one too',
    $names($sel($customers)->where(eq($customers->id, $biggest))) === 'Carla');

// -----------------------------------------------------------------------------
section('set operations');

$in_city = static function (string $city) use ($sel, $customers): QueryBuilder {
    return $sel($customers)->where(eq($customers->city, $city));
};

test('UNION removes duplicates',
    $names($in_city('Roma')->union($in_city('Napoli'))) === 'Anna,Carla,Dino');

test('UNION ALL keeps them',
    $names($in_city('Roma')->union_all($sel($customers))) === 'Anna,Anna,Bruno,Carla,Carla,Dino');

test('INTERSECT keeps what is in both',
    $names($sel($customers)->intersect($in_city('Roma'))) === 'Anna,Carla');

test('EXCEPT removes what is in the other',
    $names($sel($customers)->except($in_city('Roma'))) === 'Bruno,Dino');

test('several branches chain',
    $names($in_city('Roma')->union($in_city('Napoli'))->union($in_city('Milano')))
    === 'Anna,Bruno,Carla,Dino');

[$sql, $params] = $rendered($in_city('Roma')->union($in_city('Milano')));

test('both branches bind their own value, in order', $params === ['Roma', 'Milano'], json_encode($params));

// -----------------------------------------------------------------------------
section('ORDER BY AND LIMIT BELONG TO THE COMPOUND, NOT TO A BRANCH');

// In standard SQL they follow the last branch and apply to the whole thing. A
// dialect that accepts them inside one either parenthesises (MySQL, Postgres)
// or rejects the statement (SQLite) — so emitting them and hoping is how a
// query returns different rows on a laptop and on the server.
[$sql] = $rendered($in_city('Roma')->union($in_city('Napoli'))->order_by($customers->name)->limit(2));

test('the compound ends with ORDER BY then LIMIT',
    preg_match('/UNION SELECT.*ORDER BY .* LIMIT 2$/s', $sql) === 1, $sql);

test('…and it orders across both branches, not within one',
    implode(',', array_column(
        $in_city('Roma')->union($in_city('Napoli'))->order_by($customers->name)->limit(2)->execute(),
        'name'
    )) === 'Anna,Carla');

foreach ([
    'ORDER BY on a branch' => static function () use ($in_city, $sel, $customers): void {
        $in_city('Roma')->union($sel($customers)->order_by($customers->id));
    },
    'LIMIT on a branch' => static function () use ($in_city, $sel, $customers): void {
        $in_city('Roma')->union($sel($customers)->limit(3));
    },
    'OFFSET on a branch' => static function () use ($in_city, $sel, $customers): void {
        $in_city('Roma')->union($sel($customers)->offset(3));
    },
    'a branch with set operations of its own' => static function () use ($in_city): void {
        $in_city('Roma')->union($in_city('Milano')->union($in_city('Napoli')));
    },
] as $label => $attempt) {
    [$threw, $message] = $throws($attempt);

    test("{$label} is refused", $threw, 'accepted, and the statement is not portable');
    test("…with a message naming what to do instead", $threw && strlen($message) > 40, $message);
}

// -----------------------------------------------------------------------------
section('common table expressions');

$big_orders = sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 800)));

[$sql, $params] = $rendered(
    $sel($customers)->with_cte('big', $big_orders)->where(gt($customers->id, 1))
);

test('WITH comes first in the statement', strpos($sql, 'WITH "big" AS (SELECT') === 0, $sql);

test('THE CTE BINDS BEFORE THE OUTER WHERE',
    $params === [800, 1],
    'placeholders are positional: bound in the other order the query runs, against the wrong values');

test('…and the rows are right',
    $names($sel($customers)->with_cte('big', $big_orders)->where(in_($customers->id, $big_orders)))
    === 'Anna,Carla');

$two = $sel($customers)
    ->with_cte('a', sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 100))))
    ->with_cte('b', sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 1000))))
    ->where(gt($customers->id, 0));

[$sql, $params] = $rendered($two);

test('several CTEs are emitted in the order declared',
    strpos($sql, 'WITH "a" AS') === 0 && strpos($sql, ', "b" AS (') !== false, $sql);
test('…and bind in that order too', $params === [100, 1000, 0], json_encode($params));

[$sql] = $rendered($sel($customers)->with_cte('c', $big_orders, ['customer_id'])->where(gt($customers->id, 0)));

test('an explicit column list is emitted', strpos($sql, '"c" ("customer_id") AS (') !== false, $sql);

// -----------------------------------------------------------------------------
section('a recursive CTE, which is the reason the tree query was hard');

$anchor = $sel($nodes, [$nodes->id, $nodes->parent_id, $nodes->name])->where(eq($nodes->id, 2));
$step   = $sel($nodes, [$nodes->id, $nodes->parent_id, $nodes->name])
    ->inner_join($tree, eq($nodes->parent_id, $tree->id));

$descendants = $sel($tree, [$tree->name])->with_recursive('tree', sub($anchor->union_all($step)));

[$sql] = $rendered($descendants);

test('RECURSIVE marks the whole WITH clause', strpos($sql, 'WITH RECURSIVE "tree" AS (') === 0, $sql);

test('THE WHOLE SUBTREE COMES BACK, at every depth',
    $names($descendants) === 'a,a1,a1x',
    'the recursion stopped early, or never started');

test('…and a sibling branch is not included', strpos($names($descendants), 'b') === false);

// -----------------------------------------------------------------------------
section('the dialects disagree about quoting and placeholders');

$pg_customers = pg_table('customers', ['id' => serial(), 'city' => varchar(50)]);
$pg_orders    = pg_table('orders', ['id' => serial(), 'customer_id' => integer(), 'total' => integer()]);

$pg_query = (new QueryBuilder())->select()->from($pg_customers)
    ->with_cte('big', sub((new QueryBuilder())->select([$pg_orders->customer_id])->from($pg_orders)
        ->where(gt($pg_orders->total, 1000))))
    ->where(and_(
        eq($pg_customers->city, 'Roma'),
        in_($pg_customers->id, sub((new QueryBuilder())->select([$pg_orders->customer_id])->from($pg_orders)
            ->where(gt($pg_orders->total, 500))))
    ));

[$sql, $params] = $rendered($pg_query);

// The placeholders are `?` on PostgreSQL too — PDO parses no other form — so
// what has to hold is the *order*: nested builders bind in the order they are
// rendered, or the statement asks about the wrong values without complaining.
test('PostgreSQL binds across nested builders in the order they appear',
    substr_count($sql, '?') === 3 && strpos($sql, '$1') === false,
    $sql);
test('…and the array lines up with them', $params === [1000, 'Roma', 500], json_encode($params));

$my_orders = mysql_table('orders', ['id' => serial(), 'customer_id' => integer(), 'total' => integer()]);
$my_inner  = (new QueryBuilder())->select([$my_orders->customer_id])->from($my_orders)
    ->where(gt($my_orders->total, 10));

[$sql] = $rendered((new QueryBuilder())->select()->from($pg_customers)
    ->where(in_($pg_customers->id, sub($my_inner))));

test('A SUBQUERY IS RENDERED FOR THE STATEMENT IT LANDS IN, not the table it came from',
    strpos($sql, '`') === false && strpos($sql, '"orders"') !== false,
    'backticks inside a PostgreSQL statement: ' . $sql);

[$sql] = $rendered($my_inner);

test('…and the original builder is not moved by that', strpos($sql, '`orders`') !== false, $sql);

// -----------------------------------------------------------------------------
section('a derived table brings two silent failures with it');

// Both were found by writing worked examples for the README, and neither
// raises: they return the wrong number of rows, which is the failure that gets
// past a review because the query runs.

// 1. The outer builder must take its dialect from the subquery. `new
//    QueryBuilder()` defaults to MySQL, and `from(Table)` corrects that — but
//    `from(Subquery)` did not, so a SQLite derived table was rendered with
//    backticks.
$derived = (new QueryBuilder())->select()
    ->from(sub($sel($customers)->where(eq($customers->city, 'Roma')))->alias('r'))
    ->set_connection($pdo);

[$sql] = $rendered($derived);

test('FROM A SUBQUERY TAKES THE SUBQUERY\'S DIALECT',
    strpos($sql, '`') === false,
    'MySQL backticks in a SQLite statement: ' . $sql);

test('…and the query runs', count($derived->execute()) === 2, $sql);

// 2. A column out of a derived table has no declared type, so SQLite cannot
//    coerce a bound value toward one — and in its ordering every TEXT is
//    greater than every number. Bound as a string, `spent > 100` matched
//    nothing at all, whatever the data.
$totals = sqlite_table('per_customer', ['customer_id' => integer(), 'spent' => integer()]);

$per_customer = (new QueryBuilder())->select()
    ->from(sub($sel($orders, [$orders->customer_id, 'SUM(total) AS spent'])
        ->group_by($orders->customer_id))->alias('per_customer'))
    ->set_connection($pdo)
    ->where(gt($totals->spent, 100));

// Sums are 1050, 50 and 1200, so two clear the threshold — and zero clear it
// when the 100 arrives as TEXT.
test('A VALUE IS BOUND WITH ITS OWN TYPE, not as a string',
    count($per_customer->execute()) === 2,
    'nothing matched: an integer bound as TEXT is greater than every number in SQLite, '
    . 'so the comparison is false for every row');

// The same value against a column that *does* have a declared type kept
// working, which is why this hid for as long as derived tables did not exist.
test('…and an ordinary column still behaves',
    count($sel($orders)->where(gt($orders->total, 100))->execute()) === 3);

test('a null binds as null, not as the string "null"',
    count($sel($orders)->where(eq($orders->customer_id, null))->execute()) === 0);

// -----------------------------------------------------------------------------
section('SELECT DISTINCT');

test('DISTINCT collapses repeated rows',
    count($sel($orders, [$orders->customer_id])->distinct()->execute()) === 3,
    'three customers have placed orders: 1, 2, 3');

test('…and without it every row is returned',
    count($sel($orders, [$orders->customer_id])->execute()) === 4);

// The part people mean differently from what SQL does: DISTINCT is over
// *everything selected*, so adding a column changes which rows are duplicates.
test('DISTINCT IS OVER THE WHOLE SELECT LIST, not the first column',
    count($sel($orders, [$orders->customer_id, $orders->total])->distinct()->execute()) === 4,
    'adding a column did not change the grouping — which is not what DISTINCT means');

test('it can be turned back off', (static function () use ($sel, $orders, $rendered): bool {
    [$sql] = $rendered($sel($orders, [$orders->customer_id])->distinct()->distinct(false));

    return strpos($sql, 'DISTINCT') === false;
})());

[$sql] = $rendered($sel($orders, [$orders->customer_id])->distinct());

test('the keyword sits between SELECT and the columns',
    strpos($sql, 'SELECT DISTINCT "orders"."customer_id"') === 0, $sql);

// DISTINCT ON is PostgreSQL's, and refused elsewhere rather than approximated:
// plain DISTINCT would return a different number of rows, which is the kind of
// difference that only shows up on the server.
$pg_orders = pg_table('orders', ['id' => serial(), 'customer_id' => integer(), 'total' => integer()]);

[$sql] = $rendered((new QueryBuilder())->select()->from($pg_orders)
    ->distinct_on([$pg_orders->customer_id]));

test('DISTINCT ON renders for PostgreSQL',
    strpos($sql, 'SELECT DISTINCT ON ("orders"."customer_id")') === 0, $sql);

foreach (['sqlite' => $orders, 'mysql' => mysql_table('orders', ['id' => serial()])] as $dialect_c => $table) {
    [$threw, $message] = $throws(static function () use ($table): void {
        (new QueryBuilder())->select()->from($table)->distinct_on([$table->id]);
    });

    test("DISTINCT ON IS REFUSED ON {$dialect_c}", $threw,
        'plain DISTINCT would have been emitted, returning a different number of rows');
    test("…and the message says what to do instead",
        $threw && strpos($message, 'window function') !== false, $message);
}

// -----------------------------------------------------------------------------
section('what is refused, and says why');

foreach ([
    'a subquery used as a table without an alias' => static function () use ($sel, $customers): void {
        $sel($customers)->from(sub($sel($customers)));
    },
    'from() given a string' => static function () use ($sel, $customers): void {
        $sel($customers)->from('customers');
    },
    'IN given something that is neither' => static function () use ($customers): void {
        in_($customers->id, 'a string');
    },
    'a branch that is not a SELECT' => static function () use ($sel, $customers, $pdo): void {
        $sel($customers)->union((new QueryBuilder())->from($customers)->delete());
    },
] as $label => $attempt) {
    [$threw, $message] = $throws($attempt);

    test("{$label} is refused", $threw, 'accepted silently');
}

[$threw, $message] = $throws(static function () use ($sel, $customers): void {
    $sel($customers)->from(sub($sel($customers)));
});

test('…and the alias message says how to add one',
    strpos($message, "alias('name')") !== false, $message);

exit(summary());
