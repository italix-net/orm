<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — what a page spends on the database
 *
 * Two questions. "Where did the time go" is answered far more often by *how
 * many* queries than by any one of them being slow — so the log counts first and
 * flags second. "And what will the server do with this one" is `explain()`.
 *
 * Run: php src/Libs/Italix/Orm/examples/profiling_example.php
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

use Italix\Orm\DataManager;
use Italix\Orm\Profiling\QueryLog;

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;

$customers = sqlite_table('customers', [
    'id'   => integer()->primary_key(),
    'city' => varchar(30),
    'name' => varchar(30),
]);

$orders = sqlite_table('orders', [
    'id'          => integer()->primary_key(),
    'customer_id' => integer(),
    'total'       => integer(),
]);

$dm = sqlite_memory();
$dm->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY, city TEXT, name TEXT)');
$dm->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, total INTEGER)');
$dm->execute('CREATE INDEX customers_city ON customers (city)');

$dm->transaction(static function (DataManager $dm): void {
    for ($i = 1; $i <= 300; $i++) {
        $dm->execute('INSERT INTO customers VALUES (?, ?, ?)', [$i, 'city-' . ($i % 10), 'name-' . $i]);
        $dm->execute('INSERT INTO orders VALUES (?, ?, ?)', [$i, $i, $i * 10]);
    }
});

// -----------------------------------------------------------------------------
// A page that looks fine: nothing slow, and thirty-one queries.

$log = new QueryLog(0.05);
$dm->use_query_log($log);

$page = $dm->select()->from($customers)->where(eq($customers->city, 'city-3'))->limit(30)->execute();

foreach ($page as $row) {
    // One query per customer — the shape everyone writes once.
    $dm->select()->from($orders)->where(eq($orders->customer_id, $row['id']))->execute();
}

printf("A page of %d customers\n----------------------\n", count($page));
printf("  queries:      %d\n", $log->queries_n());
printf("  total:        %.1f ms\n", $log->total_seconds() * 1000);
printf("  slow (>50ms): %d\n", count($log->slow()));

echo "\nThe same statement, over and over\n---------------------------------\n";

foreach ($log->repeated() as $sql => $times_n) {
    printf("  %d× %s\n", $times_n, strlen($sql) > 66 ? substr($sql, 0, 66) . '…' : $sql);
}

echo "\n  None of them was slow. A slow-query threshold would have said nothing at all.\n";

// -----------------------------------------------------------------------------
// And what the server means to do.

echo "\nWhat the server will do\n-----------------------\n";

$with_index = $dm->select()->from($customers)->where(eq($customers->city, 'city-3'))->explain();
$without    = $dm->select()->from($customers)->where(eq($customers->name, 'name-3'))->explain();

printf("  on an indexed column:    full scan? %s\n    %s\n",
    $with_index->has_full_scan() ? 'yes' : 'no', (string) $with_index);

printf("  on an unindexed column:  full scan? %s\n    %s\n",
    $without->has_full_scan() ? 'yes' : 'no', (string) $without);

echo "\n  The plan is the server's own words — three dialects say this three ways.\n";
echo "  What is normalised is the one question worth asking automatically.\n";

// -----------------------------------------------------------------------------
// The values are not in the log unless you ask.

echo "\nWhat a record holds\n-------------------\n";
print_r($log->all()[0]);
echo "  No bound values: a query log gets written to a file and pasted into tickets.\n";
