<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — writing many rows, and reading from a replica
 *
 * Two SQLite files stand in for a primary and its replica, with deliberately
 * different contents so that **which one answered** is visible. A real pair
 * would look identical, which is exactly what makes a routing bug invisible.
 *
 * Run: php src/Libs/Italix/Orm/examples/batch_and_replica_example.php
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
use Italix\Orm\Dialects\Driver;

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\sqlite_memory;

$readings = sqlite_table('readings', [
    'id'    => integer()->primary_key(),
    'label' => varchar(30),
    'value' => integer(),
]);

// -----------------------------------------------------------------------------
// 1. Writing 20,000 rows.

$rows = [];

for ($i = 1; $i <= 20000; $i++) {
    $rows[] = ['id' => $i, 'label' => 'reading-' . $i, 'value' => $i * 3];
}

$loop = sqlite_memory();
$loop->execute('CREATE TABLE readings (id INTEGER PRIMARY KEY, label TEXT, value INTEGER)');

$t0 = microtime(true);
$loop->transaction(static function (DataManager $dm) use ($readings, $rows): void {
    foreach ($rows as $row) {
        $dm->insert($readings)->values($row)->execute();
    }
});
$one_at_a_time = microtime(true) - $t0;

$batch = sqlite_memory();
$batch->execute('CREATE TABLE readings (id INTEGER PRIMARY KEY, label TEXT, value INTEGER)');

$t0        = microtime(true);
$written_n = $batch->insert_many($readings, $rows, 500);
$batched   = microtime(true) - $t0;

echo "Writing 20,000 rows (SQLite, in memory)\n---------------------------------------\n";
printf("  one at a time, in one transaction  %6.3fs\n", $one_at_a_time);
printf("  insert_many, chunks of 500         %6.3fs   (%d rows)\n", $batched, $written_n);
printf("  batching is %.1fx here\n", $one_at_a_time / $batched);

// On a network the gap is wider, because each statement is also a round trip.
// Measured against this project's MariaDB with 5,000 rows: 273s one at a time
// with no transaction, 1.71s one at a time inside one, 1.18s batched — nearly
// all of the difference is the transaction, and insert_many gives you both.

// -----------------------------------------------------------------------------
// 2. Reads from a replica.

$file = static function (array $labels): string {
    $path = tempnam(sys_get_temp_dir(), 'ix_example_');
    $pdo  = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE readings (id INTEGER PRIMARY KEY, label TEXT, value INTEGER)');

    foreach ($labels as $index => $label) {
        $pdo->exec("INSERT INTO readings (id, label, value) VALUES (" . ($index + 1) . ", '{$label}', 0)");
    }

    return $path;
};

$primary_path = $file(['one', 'two', 'three']);
$replica_path = $file(['one', 'two']);           // as if it had not caught up

$dm = new DataManager(Driver::sqlite(['database' => $primary_path]));
$dm->use_replicas(Driver::sqlite(['database' => $replica_path]));

$seen = static fn(): string => count($dm->select()->from($readings)->execute()) . ' rows';

echo "\nA primary with 3 rows and a replica with 2\n------------------------------------------\n";
echo '  a read:                       ', $seen(), " (the replica)\n";
echo '  pinned with on_primary():     ', $dm->on_primary($seen), "\n";
echo '  and back:                     ', $seen(), "\n";

$dm->insert($readings)->values(['id' => 4, 'label' => 'four', 'value' => 0])->execute();

echo "\nAfter a write\n-------------\n";
echo '  the next read:                ', $seen(), " (the primary — it must see its own write)\n";
echo '  and the one after that:       ', $seen(), " (still the primary)\n";

$dm->resume_replica_reads();

echo '  after resume_replica_reads(): ', $seen(), " (the replica again)\n";

echo "\nInside a transaction\n--------------------\n";
echo '  ', $dm->transaction(static function (DataManager $dm) use ($seen): string {
    return $seen() . ' (the primary, always: the rows a transaction writes exist nowhere else yet)';
}), "\n";

@unlink($primary_path);
@unlink($replica_path);
