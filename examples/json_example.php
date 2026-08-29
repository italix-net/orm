<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — asking questions of a JSON column
 *
 * One path syntax, three renderings. The SQL each dialect gets is printed at the
 * end, because the differences are the whole story.
 *
 * Run: php src/Libs/Italix/Orm/examples/json_example.php
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

use function Italix\Orm\Schema\{integer, jsonb, json, mysql_table, pg_table, sqlite_table, text};
use function Italix\Orm\Operators\{desc, eq, gt, json_has, json_length, json_text};
use function Italix\Orm\sqlite_memory;

$orders = sqlite_table('orders', [
    'id'  => integer()->primary_key(),
    'doc' => text(),
]);

$dm = sqlite_memory();
$dm->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, doc TEXT)');

foreach ([
    [1, '{"customer":{"name":"Ada"},"status":"paid","items":["hammer","saw"],"total":4000}'],
    [2, '{"customer":{"name":"Grace"},"status":"draft","items":["apple"],"total":500}'],
    [3, '{"customer":{"name":"Alan"},"status":"paid","items":[],"total":9000,"note":"rush"}'],
] as $row) {
    $dm->execute('INSERT INTO orders (id, doc) VALUES (?, ?)', $row);
}

// -----------------------------------------------------------------------------

echo "Paid orders, by customer\n------------------------\n";

$paid = $dm->select([
    $orders->id,
    json_text($orders->doc, '$.customer.name')->as('customer'),
    json_text($orders->doc, '$.total')->as('total'),
    json_length($orders->doc, '$.items')->as('items_n'),
])
    ->from($orders)
    ->where(eq(json_text($orders->doc, '$.status'), 'paid'))
    ->order_by(desc(json_text($orders->doc, '$.total')))
    ->execute();

foreach ($paid as $row) {
    echo "  {$row['customer']}: {$row['total']} ({$row['items_n']} item(s))\n";
}

echo "\nOrders with more than one item\n------------------------------\n";

foreach ($dm->select([json_text($orders->doc, '$.customer.name')->as('customer')])
    ->from($orders)
    ->where(gt(json_length($orders->doc, '$.items'), 1))
    ->execute() as $row) {
    echo '  ', $row['customer'], "\n";
}

echo "\nOrders carrying a note\n----------------------\n";

foreach ($dm->select([json_text($orders->doc, '$.note')->as('note')])
    ->from($orders)
    ->where(json_has($orders->doc, '$.note'))
    ->execute() as $row) {
    echo '  ', $row['note'], "\n";
}

// -----------------------------------------------------------------------------
// The same expression, for three servers.

echo "\nThe same question, three dialects\n---------------------------------\n";

$tables = [
    'sqlite'     => sqlite_table('orders', ['doc' => text()]),
    'mysql'      => mysql_table('orders', ['doc' => json()]),
    'postgresql' => pg_table('orders', ['doc' => jsonb()]),
];

foreach ($tables as $dialect => $table) {
    $params = [];
    $sql    = json_text($table->doc, '$.customer.name')->to_sql($dialect, $params);

    printf("  %-11s %-52s %s\n", $dialect, $sql, json_encode($params));
}

echo "\n  The path is bound, never written into the statement — and on PostgreSQL\n";
echo "  every segment is quoted, or a key containing a comma would address\n";
echo "  somewhere else entirely.\n";
