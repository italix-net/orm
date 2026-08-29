<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — reads from a replica, writes to the primary
 *
 * A replica is always a little behind, and how far is not something the
 * application can see. Every assertion here is about **which database answered**,
 * because that is the only thing that can be wrong: the query itself is fine
 * either way, and so is the row it returns — it is simply the wrong row.
 *
 * The two databases are given deliberately different contents, which a real
 * pair would not have. That is the point: identical copies make routing
 * invisible, and a routing bug invisible with it.
 *
 * Run: php src/Libs/Italix/Orm/tests/ReplicaTest.php
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
use Italix\Orm\Dialects\Driver;

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\Operators\eq;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - read replicas');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$items = sqlite_table('items', [
    'id'   => integer()->primary_key(),
    'name' => varchar(30),
]);

$files = [];

/** A SQLite file holding the given names, one row each. */
$database = static function (array $names) use (&$files): string {
    $path    = tempnam(sys_get_temp_dir(), 'ix_replica_');
    $files[] = $path;

    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

    foreach ($names as $index => $name) {
        $pdo->exec("INSERT INTO items (id, name) VALUES (" . ($index + 1) . ", '{$name}')");
    }

    return $path;
};

/**
 * A manager whose primary holds three rows and whose replica holds two — as if
 * it had not caught up with the third.
 *
 * @return array{0: DataManager, 1: string, 2: string}
 */
$pair = static function () use ($database): array {
    $primary_path = $database(['one', 'two', 'three']);
    $replica_path = $database(['one', 'two']);

    $dm = new DataManager(Driver::sqlite(['database' => $primary_path]));
    $dm->use_replicas(Driver::sqlite(['database' => $replica_path]));

    return [$dm, $primary_path, $replica_path];
};

/** How many rows the manager's next read sees. */
$seen = static fn(DataManager $dm): int => count($dm->select()->from($items)->execute());

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
section('reads go to the replica');

[$dm] = $pair();

test('a select reads the replica', $seen($dm) === 2);
test('…and says so', $dm->reads_replica());
test('a raw query() reads the replica', count($dm->query('SELECT id FROM items')) === 2);
test('query_one() too', ($dm->query_one('SELECT name FROM items ORDER BY id DESC')['name'] ?? '') === 'two');

test('a cursor reads the replica', (static function () use ($dm): bool {
    $n = 0;

    foreach ($dm->cursor('SELECT id FROM items') as $row) {
        $n++;
    }

    return $n === 2;
})());

test('with no replicas nothing changes', (static function () use ($database, $items): bool {
    $dm = new DataManager(Driver::sqlite(['database' => $database(['one', 'two', 'three'])]));

    return !$dm->has_replicas()
        && !$dm->reads_replica()
        && count($dm->select()->from($items)->execute()) === 3;
})());

// -----------------------------------------------------------------------------
section('A READ AFTER A WRITE GOES TO THE PRIMARY');

// Saving a form and rendering the page that shows it is the most common thing an
// application does. A replica that has not caught up shows the value before the
// edit, and the user concludes the save did not work.
[$dm] = $pair();

test('before writing, the replica answers', $seen($dm) === 2);

$dm->insert($items)->values(['id' => 4, 'name' => 'four'])->execute();

test('AFTER WRITING, THE PRIMARY DOES', $seen($dm) === 4);
test('…and it is not just this one read', $seen($dm) === 4);
test('…which reads_replica() reports', !$dm->reads_replica());
test('the write itself went to the primary', (static function () use ($dm, $items): bool {
    return count($dm->select()->from($items)->where(eq($items->name, 'four'))->execute()) === 1;
})());

test('resume_replica_reads() sends them back', (static function () use ($dm, $seen): bool {
    $dm->resume_replica_reads();

    return $dm->reads_replica() && $seen($dm) === 2;
})());

test('an UPDATE marks it too', (static function () use ($pair, $seen, $items): bool {
    [$dm] = $pair();
    $dm->update($items)->set(['name' => 'ONE'])->where(eq($items->id, 1))->execute();

    return $seen($dm) === 3;
})());

test('a DELETE as well', (static function () use ($pair, $seen, $items): bool {
    [$dm] = $pair();
    $dm->delete($items)->where(eq($items->id, 1))->execute();

    return $seen($dm) === 2 && !$dm->reads_replica();
})());

test('raw execute() is assumed to have written', (static function () use ($pair, $seen): bool {
    [$dm] = $pair();
    $dm->execute('SELECT 1');

    // It cannot tell a write from a read in raw SQL, and guessing from the text
    // is how a `WITH … INSERT` ends up on a replica. Assuming it wrote costs a
    // read on the primary, which is the safe direction.
    return !$dm->reads_replica() && $seen($dm) === 3;
})());

// -----------------------------------------------------------------------------
section('a transaction reads what it has written');

[$dm] = $pair();

test('inside a transaction, reads are on the primary', $dm->transaction(static function (DataManager $dm) use ($items): bool {
    $dm->insert($items)->values(['id' => 5, 'name' => 'five'])->execute();

    // The row exists nowhere else yet. A replica would answer 2.
    return count($dm->select()->from($items)->execute()) === 4;
}));

test('a transaction that only reads is still on the primary', (static function () use ($pair): bool {
    [$dm] = $pair();

    return $dm->transaction(static function (DataManager $dm): bool {
        return $dm->reads_replica() === false;
    });
})());

test('…and after it commits, the write is still remembered', !$dm->reads_replica());

// -----------------------------------------------------------------------------
section('pinning a read to the primary on purpose');

[$dm] = $pair();

test('on_primary() reads the primary', $dm->on_primary(static fn(): int => $seen($dm)) === 3);
test('…and lifts the pin afterwards', $dm->reads_replica() && $seen($dm) === 2);

test('…even when the callback throws', (static function () use ($dm, $throws): bool {
    $throws(static function () use ($dm): void {
        $dm->on_primary(static function (): void {
            throw new RuntimeException('no');
        });
    });

    return $dm->reads_replica();
})());

test('…and it does not un-pin a manager that had already written', (static function () use ($pair, $items): bool {
    [$dm] = $pair();
    $dm->insert($items)->values(['id' => 6, 'name' => 'six'])->execute();
    $dm->on_primary(static fn(): int => 1);

    return !$dm->reads_replica();
})());

// -----------------------------------------------------------------------------
section('more than one replica');

test('reads are spread across them', (static function () use ($database, $items): bool {
    // Two replicas with different row counts, so which one answered is visible.
    $dm = new DataManager(Driver::sqlite(['database' => $database(['one', 'two', 'three'])]));
    $dm->use_replicas(
        Driver::sqlite(['database' => $database(['one'])]),
        Driver::sqlite(['database' => $database(['one', 'two'])])
    );

    $counts = [];

    for ($i = 0; $i < 40; $i++) {
        $counts[count($dm->select()->from($items)->execute())] = true;
    }

    // Random choice, so this could in principle pick the same one 40 times —
    // once in 5·10^11 runs. Everything else here is deterministic.
    return array_keys($counts) === [1, 2] || array_keys($counts) === [2, 1];
})());

// -----------------------------------------------------------------------------
foreach ($files as $file) {
    @unlink($file);
}

exit(summary());
