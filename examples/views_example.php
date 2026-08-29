<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — views, end to end
 *
 * The same view the README shows, built, created, read and replaced against a
 * real (in-memory) database, so the documented snippet is executed rather than
 * only proofread.
 *
 * Run: php src/Libs/Italix/Orm/examples/views_example.php
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

use Italix\Orm\Migration\Schema;

use function Italix\Orm\Schema\{integer, numeric, sqlite_table, sqlite_view, varchar};
use function Italix\Orm\Operators\{desc, eq, gt, sql_sum};
use function Italix\Orm\sqlite_memory;

$dm = sqlite_memory();
Schema::set_connection($dm);

$dm->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
$dm->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, total NUMERIC)');

foreach ([[1, 'Ada', 'active'], [2, 'Grace', 'active'], [3, 'Alan', 'archived']] as $row) {
    $dm->execute('INSERT INTO customers (id, name, status) VALUES (?, ?, ?)', $row);
}

foreach ([[1, 1, 4000], [2, 1, 3000], [3, 2, 500], [4, 3, 9000]] as $row) {
    $dm->execute('INSERT INTO orders (id, customer_id, total) VALUES (?, ?, ?)', $row);
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
]);

// -----------------------------------------------------------------------------
// Declared like a table, defined by a query.

$top_customers = sqlite_view('top_customers', [
    'id'    => integer(),
    'name'  => varchar(120),
    'spend' => numeric(10, 2),
])->as_query(
    $dm->select([$customers->id, $customers->name, sql_sum($orders->total)->as('spend')])
       ->from($customers)
       ->inner_join($orders, eq($orders->customer_id, $customers->id))
       ->where(eq($customers->status, 'active'))
       ->group_by($customers->id, $customers->name)
       ->having(gt(sql_sum($orders->total), 1000))
);

echo "CREATE statement\n----------------\n", $top_customers->to_create_sql(), "\n\n";

// Note the 'active' from the definition sitting in the text: CREATE VIEW is DDL
// and binds nothing, so the value is rendered — escaped — rather than bound.

Schema::create_or_replace_view($top_customers);

// -----------------------------------------------------------------------------
// From here on it is a table.

$rows = $dm->select()->from($top_customers)
           ->where(gt($top_customers->spend, 5000))
           ->order_by(desc($top_customers->spend))
           ->execute();

echo "Customers over 5000\n-------------------\n";

foreach ($rows as $row) {
    echo '  ', $row['name'], ': ', $row['spend'], "\n";
}

// Alan spent 9000 but is archived, so the view's own WHERE keeps him out.

// -----------------------------------------------------------------------------
// Writing is refused before it reaches the server.

try {
    $dm->select()->delete($top_customers)->execute();
} catch (\RuntimeException $e) {
    echo "\nWriting to it\n-------------\n  ", $e->getMessage(), "\n";
}

// -----------------------------------------------------------------------------
// Redefining it takes two statements on SQLite and one everywhere else.

$all_customers = sqlite_view('top_customers', [
    'id'    => integer(),
    'name'  => varchar(120),
    'spend' => numeric(10, 2),
])->as_query(
    $dm->select([$customers->id, $customers->name, sql_sum($orders->total)->as('spend')])
       ->from($customers)
       ->inner_join($orders, eq($orders->customer_id, $customers->id))
       ->group_by($customers->id, $customers->name)
);

echo "\nReplacing it\n------------\n  ", count($all_customers->to_replace_sql()),
    " statement(s) on sqlite\n";

Schema::create_or_replace_view($all_customers);

echo '  now returns ', count($dm->select()->from($all_customers)->execute()), " rows\n";
echo '  views in this database: ', implode(', ', Schema::get_views()), "\n";
