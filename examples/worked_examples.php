<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - the worked examples from the README
 *
 * Ten ordinary questions, built with the real API and executed against a real
 * database. The README quotes the SQL these print, which is the only way that
 * documentation stays true: an example nobody runs is documentation that ages
 * in silence, and this package had two of those until they were run.
 *
 * Running them is also how the two defects fixed in 2.5.1 were found — a
 * derived table rendered with the wrong dialect, and a parameter bound as a
 * string against a column that has no type to coerce it toward. Neither raised;
 * both returned the wrong number of rows.
 *
 * Run: php examples/worked_examples.php
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

// The autoloader, wherever this package happens to sit.
(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../../../vendor/autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\QueryBuilder\QueryBuilder;

use function Italix\Orm\Operators\{and_, asc, desc, eq, exists, gt, gte, in_, lt,
                                   not_exists, sql_count, sql_sum, sub};
use function Italix\Orm\Schema\{decimal, integer, serial, sqlite_table, varchar};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, city TEXT, status TEXT)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, placed_on TEXT, total REAL)');
$pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, product_id INTEGER, qty INTEGER)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL, category_id INTEGER)');
$pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INTEGER, name TEXT)');

$pdo->exec("INSERT INTO customers VALUES
    (1,'Alice','Rome','active'),(2,'Bob','Milan','active'),
    (3,'Chen','Rome','archived'),(4,'Dara','Naples','active'),(5,'Eve','Milan','active')");
$pdo->exec("INSERT INTO orders VALUES
    (1,1,'2026-08-01',120.0),(2,1,'2026-06-01',80.0),(3,2,'2026-08-10',300.0),
    (4,3,'2026-01-05',45.0),(5,4,'2026-08-12',900.0)");
$pdo->exec('INSERT INTO order_items VALUES (1,1,1,2),(2,1,2,1),(3,3,1,5),(4,5,3,1),(5,4,2,3)');
$pdo->exec("INSERT INTO products VALUES
    (1,'Keyboard',49.0,10),(2,'Monitor',220.0,10),(3,'Desk',450.0,20),(4,'Lamp',35.0,20)");
$pdo->exec("INSERT INTO categories VALUES
    (10,NULL,'Electronics'),(20,NULL,'Furniture'),(11,10,'Peripherals'),(12,11,'Keyboards')");

$customers   = sqlite_table('customers', ['id' => serial(), 'name' => varchar(50), 'city' => varchar(50), 'status' => varchar(20)]);
$orders      = sqlite_table('orders', ['id' => serial(), 'customer_id' => integer(), 'placed_on' => varchar(10), 'total' => decimal(10, 2)]);
$order_items = sqlite_table('order_items', ['id' => serial(), 'order_id' => integer(), 'product_id' => integer(), 'qty' => integer()]);
$products    = sqlite_table('products', ['id' => serial(), 'name' => varchar(50), 'price' => decimal(10, 2), 'category_id' => integer()]);
$categories  = sqlite_table('categories', ['id' => serial(), 'parent_id' => integer(), 'name' => varchar(50)]);
$tree        = sqlite_table('subtree', ['id' => serial(), 'parent_id' => integer(), 'name' => varchar(50)]);

$sel = static function ($table, ?array $columns = null) use ($pdo): QueryBuilder {
    return (new QueryBuilder())->select($columns)->from($table)->set_connection($pdo);
};

$n = 0;

$show = static function (string $title, string $question, QueryBuilder $query, string $code) use (&$n): void {
    $params = [];
    $sql    = $query->to_sql($params);
    $rows   = $query->execute();

    $flat = array_map(static function (array $row): string {
        $named = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);

        return implode(' | ', array_map(static function ($v): string {
            return $v === null ? '—' : (string) $v;
        }, $named));
    }, $rows);

    printf("\n%d. %s\n   %s\n\n%s\n\n   SQL: %s\n   params: %s\n   → %s\n",
        ++$n, $title, $question, $code, $sql, json_encode($params),
        $flat === [] ? '(no rows)' : implode('   /   ', $flat));
};

// -----------------------------------------------------------------------------

$show(
    'Customers who have never ordered',
    'The classic one. NOT EXISTS rather than NOT IN, because customer_id may be null.',
    $sel($customers, [$customers->name])->where(not_exists(
        sub($sel($orders, [$orders->id])->where(eq($orders->customer_id, $customers->id)))
    )),
    <<<'PHP'
   $sel($customers, [$customers->name])->where(not_exists(
       sub($sel($orders, [$orders->id])->where(eq($orders->customer_id, $customers->id)))
   ));
PHP
);

$show(
    'Customers who ordered since a date',
    'A subquery feeding IN — one round trip instead of two.',
    $sel($customers, [$customers->name])->where(in_(
        $customers->id,
        sub($sel($orders, [$orders->customer_id])->where(gte($orders->placed_on, '2026-08-01')))
    )),
    <<<'PHP'
   $sel($customers, [$customers->name])->where(in_($customers->id,
       sub($sel($orders, [$orders->customer_id])->where(gte($orders->placed_on, '2026-08-01')))
   ));
PHP
);

$show(
    'Products priced above the average',
    'A scalar subquery in a comparison. Needed no new operator.',
    $sel($products, [$products->name, $products->price])->where(
        gt($products->price, sub($sel($products, ['AVG(price)'])))
    ),
    <<<'PHP'
   $sel($products, [$products->name, $products->price])
       ->where(gt($products->price, sub($sel($products, ['AVG(price)']))));
PHP
);

$show(
    'The cities we actually ship to',
    'DISTINCT over exactly what is selected.',
    $sel($customers, [$customers->city])->distinct()->order_by(asc($customers->city)),
    <<<'PHP'
   $sel($customers, [$customers->city])->distinct()->order_by(asc($customers->city));
PHP
);

$show(
    'Spend per customer, then filtered',
    'A derived table: aggregate first, then ask questions of the result.',
    (static function () use ($pdo, $sel, $orders): QueryBuilder {
        $totals = sqlite_table('per_customer', ['customer_id' => integer(), 'spent' => decimal(10, 2)]);

        $per_customer = sub(
            $sel($orders, [$orders->customer_id, 'SUM(total) AS spent'])->group_by($orders->customer_id)
        )->alias('per_customer');

        return (new QueryBuilder())->select()->from($per_customer)->set_connection($pdo)
            ->where(gt($totals->spent, 100));
    })(),
    <<<'PHP'
   $per_customer = sub(
       $sel($orders, [$orders->customer_id, sql_sum($orders->total)->as_alias('spent')])
           ->group_by($orders->customer_id)
   )->alias('per_customer');

   $sel(null)->from($per_customer)->where(gt($totals->spent, 100));
PHP
);

$show(
    'Active and archived, in one list',
    'UNION, with the ordering applied to the whole thing.',
    $sel($customers, [$customers->name, $customers->status])->where(eq($customers->status, 'active'))
        ->union($sel($customers, [$customers->name, $customers->status])->where(eq($customers->status, 'archived')))
        ->order_by(asc($customers->name)),
    <<<'PHP'
   $sel($customers, [$customers->name, $customers->status])->where(eq($customers->status, 'active'))
       ->union($sel($customers, [...])->where(eq($customers->status, 'archived')))
       ->order_by(asc($customers->name));      // applies to the compound
PHP
);

$show(
    'Customers in Rome who have not ordered recently',
    'EXCEPT — set difference, which reads better than a NOT IN with two conditions.',
    $sel($customers, [$customers->name])->where(eq($customers->city, 'Rome'))
        ->except($sel($customers, [$customers->name])->where(in_(
            $customers->id,
            sub($sel($orders, [$orders->customer_id])->where(gte($orders->placed_on, '2026-08-01')))
        ))),
    <<<'PHP'
   $in_rome->except($ordered_recently);
PHP
);

$show(
    'The whole category subtree',
    'A recursive CTE: one statement instead of a query per level.',
    (static function () use ($sel, $categories, $tree): QueryBuilder {
        $anchor = $sel($categories, [$categories->id, $categories->parent_id, $categories->name])
            ->where(eq($categories->id, 10));

        $step = $sel($categories, [$categories->id, $categories->parent_id, $categories->name])
            ->inner_join($tree, eq($categories->parent_id, $tree->id));

        return $sel($tree, [$tree->name])->with_recursive('subtree', sub($anchor->union_all($step)));
    })(),
    <<<'PHP'
   $anchor = $sel($categories, [...])->where(eq($categories->id, $root_id));
   $step   = $sel($categories, [...])->inner_join($subtree, eq($categories->parent_id, $subtree->id));

   $sel($subtree, [$subtree->name])->with_recursive('subtree', sub($anchor->union_all($step)));
PHP
);

$show(
    'A two-step report',
    'Named CTEs read top to bottom, in the order the work happens.',
    $sel($customers, [$customers->name])
        ->with_cte('big_orders', sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 200))))
        ->where(in_($customers->id, sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 200)))))
        ->order_by(asc($customers->name)),
    <<<'PHP'
   $sel($customers, [$customers->name])
       ->with_cte('big_orders', sub($orders_over_200))
       ->where(in_($customers->id, $big_orders))
       ->order_by(asc($customers->name));
PHP
);

$show(
    'Customers who bought a specific product',
    'A subquery two joins deep, still one statement.',
    $sel($customers, [$customers->name])->where(exists(
        sub($sel($orders, [$orders->id])
            ->inner_join($order_items, eq($order_items->order_id, $orders->id))
            ->where(and_(
                eq($orders->customer_id, $customers->id),
                eq($order_items->product_id, 1)
            )))
    )),
    <<<'PHP'
   $sel($customers, [$customers->name])->where(exists(
       sub($sel($orders, [$orders->id])
           ->inner_join($order_items, eq($order_items->order_id, $orders->id))
           ->where(and_(eq($orders->customer_id, $customers->id),
                        eq($order_items->product_id, $product_id))))
   ));
PHP
);
