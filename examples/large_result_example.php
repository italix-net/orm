<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — reading a result too large to hold
 *
 * Prints what each way of reading 50,000 rows actually costs, and shows the
 * difference between paging by offset and paging by key when the job deletes
 * what it has processed.
 *
 * Run: php src/Libs/Italix/Orm/examples/large_result_example.php
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

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\sqlite_memory;

$rows_n = 50000;

$items = sqlite_table('items', [
    'id'   => integer()->primary_key(),
    'name' => varchar(50),
]);

$filled = static function () use ($rows_n): DataManager {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    $dm->transaction(static function (DataManager $dm) use ($rows_n): void {
        for ($i = 1; $i <= $rows_n; $i++) {
            $dm->execute('INSERT INTO items (id, name) VALUES (?, ?)', [$i, 'item-' . $i]);
        }
    });

    return $dm;
};

$mb = static fn(int $bytes): string => number_format($bytes / 1048576, 2) . ' MB';

$dm = $filled();

// -----------------------------------------------------------------------------
echo "Reading {$rows_n} rows\n---------------------\n";

$before = memory_get_usage();
$rows   = $dm->select()->from($items)->execute();
echo '  execute()   ', $mb(memory_get_usage() - $before), " held at once\n";
unset($rows);

$before = memory_get_usage();
$peak   = 0;
$seen   = 0;

foreach ($dm->select()->from($items)->cursor() as $row) {
    $seen++;

    if ($seen === 1) {
        $peak = memory_get_usage() - $before;
    }
}

echo '  cursor()    ', $mb($peak), " held at once, {$seen} rows seen\n";

$before = memory_get_usage();
$peak   = 0;

$dm->select()->from($items)->chunk_by($items->id, 1000, static function (array $rows) use (&$peak, $before): void {
    $peak = max($peak, memory_get_usage() - $before);
});

echo '  chunk_by()  ', $mb($peak), " held at once (one page of 1000)\n";

// -----------------------------------------------------------------------------
// A job that processes rows and deletes them: the two paging strategies do not
// agree, and only one of them is right.

echo "\nProcess-then-delete, 500 rows at a time\n---------------------------------------\n";

$dm   = $filled();
$seen = 0;

$dm->select()->from($items)->chunk_by($items->id, 500, static function (array $rows) use (&$seen, $dm): void {
    $seen += count($rows);
    $dm->execute('DELETE FROM items WHERE id <= ?', [$rows[count($rows) - 1]['id']]);
});

echo "  chunk_by(): {$seen} of {$rows_n} rows processed\n";

$dm   = $filled();
$seen = 0;

$dm->select()->from($items)->order_by($items->id)->chunk(500, static function (array $rows) use (&$seen, $dm): void {
    $seen += count($rows);
    $dm->execute('DELETE FROM items WHERE id <= ?', [$rows[count($rows) - 1]['id']]);
});

echo "  chunk():    {$seen} of {$rows_n} rows processed";
echo $seen < $rows_n ? " — OFFSET moved under the deletions\n" : "\n";

// -----------------------------------------------------------------------------
// And the refusal that prevents the quietest version of the same bug.

try {
    $dm->select()->from($items)->chunk(500, static fn() => null);
} catch (\RuntimeException $e) {
    echo "\nPaging with no ORDER BY\n-----------------------\n  ", $e->getMessage(), "\n";
}
