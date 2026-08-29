<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — writing many rows at once
 *
 * `insert_many()` does two things a loop does not: it puts several rows in one
 * statement, and it puts every statement in one transaction. Measured on this
 * project's MariaDB with 5,000 rows — 273 s one at a time, 1.71 s one at a time
 * inside a transaction, 1.18 s batched — the transaction is where almost all of
 * the difference lives, and both come free here.
 *
 * The assertions are about **what ends up in the table**: the right rows, all of
 * them, in order, and nothing at all when something fails part way.
 *
 * Run: php src/Libs/Italix/Orm/tests/BatchTest.php
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

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - insert_many');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$readings = sqlite_table('readings', [
    'id'    => integer()->primary_key(),
    'label' => varchar(30),
    'value' => integer(),
]);

$fresh = static function (): DataManager {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE readings (id INTEGER PRIMARY KEY, label TEXT, value INTEGER)');

    return $dm;
};

/** @param int $n @return array<int, array<string, mixed>> */
$rows_of = static function (int $n, int $from = 1): array {
    $rows = [];

    for ($i = $from; $i < $from + $n; $i++) {
        $rows[] = ['id' => $i, 'label' => 'r' . $i, 'value' => $i * 3];
    }

    return $rows;
};

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
section('every row arrives, once');

$dm = $fresh();
$written_n = $dm->insert_many($readings, $rows_of(1200), 500);

test('it reports what it wrote', $written_n === 1200);
test('…and the table agrees', (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 1200);
test('…across the chunk boundary', (int) $dm->query('SELECT COUNT(*) AS n FROM readings WHERE id IN (500, 501, 1000, 1001)')[0]['n'] === 4);
test('the values went with them', (int) $dm->query('SELECT value FROM readings WHERE id = 777')[0]['value'] === 2331);
test('nothing was written twice', (int) $dm->query('SELECT COUNT(DISTINCT id) AS n FROM readings')[0]['n'] === 1200);

test('a chunk size larger than the batch is fine', (static function () use ($fresh, $readings, $rows_of): bool {
    $dm = $fresh();

    return $dm->insert_many($readings, $rows_of(10), 500) === 10
        && (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 10;
})());

test('a chunk size of one still works', (static function () use ($fresh, $readings, $rows_of): bool {
    $dm = $fresh();

    return $dm->insert_many($readings, $rows_of(5), 1) === 5
        && (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 5;
})());

test('an exact multiple of the chunk size does not write a short one', (static function () use ($fresh, $readings, $rows_of): bool {
    $dm = $fresh();

    return $dm->insert_many($readings, $rows_of(1000), 500) === 1000
        && (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 1000;
})());

test('no rows is not an error', $fresh()->insert_many($readings, []) === 0);

// -----------------------------------------------------------------------------
section('IT IS ONE TRANSACTION, SO A FAILURE LEAVES NOTHING BEHIND');

// The half-import is the thing to be afraid of here: the first chunks land, one
// fails, and the table is left in a state nobody designed. A retry then hits
// the rows that did land.
test('A FAILING CHUNK UNDOES THE ONES BEFORE IT', (static function () use ($fresh, $throws, $readings, $rows_of): bool {
    $dm   = $fresh();
    $rows = $rows_of(1200);

    // Row 1100 collides with row 3: the primary key is already taken by then.
    $rows[1099]['id'] = 3;

    [$threw] = $throws(static fn() => $dm->insert_many($readings, $rows, 500));

    return $threw && (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 0;
})());

test('…and the error is the database\'s own', (static function () use ($fresh, $throws, $readings, $rows_of): bool {
    $dm   = $fresh();
    $rows = $rows_of(4);
    $rows[3]['id'] = 1;

    [, $message] = $throws(static fn() => $dm->insert_many($readings, $rows, 2));

    return stripos($message, 'unique') !== false || stripos($message, 'constraint') !== false;
})());

test('inside a caller\'s transaction it nests instead of opening a second', (static function () use ($fresh, $readings, $rows_of): bool {
    $dm = $fresh();

    $depth = $dm->transaction(static function (DataManager $dm) use ($readings, $rows_of): int {
        $dm->insert_many($readings, $rows_of(30), 10);

        return $dm->transaction_depth();
    });

    return $depth === 1 && (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 30;
})());

test('…and the caller\'s rollback still takes it all with it', (static function () use ($fresh, $throws, $readings, $rows_of): bool {
    $dm = $fresh();

    $throws(static function () use ($dm, $readings, $rows_of): void {
        $dm->transaction(static function (DataManager $dm) use ($readings, $rows_of): void {
            $dm->insert_many($readings, $rows_of(30), 10);

            throw new RuntimeException('changed my mind');
        });
    });

    return (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 0;
})());

// -----------------------------------------------------------------------------
section('ROWS THAT DISAGREE ABOUT THEIR COLUMNS ARE REFUSED');

// A multi-row INSERT has one column list. Filling the gaps with NULL would write
// something nobody asked for; dropping the extras would lose data silently.
test('a row with a missing column', (static function () use ($fresh, $throws, $readings): bool {
    $dm = $fresh();

    [$threw, $message] = $throws(static fn() => $dm->insert_many($readings, [
        ['id' => 1, 'label' => 'a', 'value' => 1],
        ['id' => 2, 'label' => 'b'],
    ]));

    return $threw && strpos($message, 'Row 1') !== false;
})());

test('a row with an extra one', (static function () use ($fresh, $throws, $readings): bool {
    $dm = $fresh();

    [$threw] = $throws(static fn() => $dm->insert_many($readings, [
        ['id' => 1, 'label' => 'a'],
        ['id' => 2, 'label' => 'b', 'value' => 2],
    ]));

    return $threw;
})());

test('the same columns in a different order', (static function () use ($fresh, $throws, $readings): bool {
    $dm = $fresh();

    // Also refused: the column list is taken from the first row, and a second
    // row written in another order would put its label in the value column.
    [$threw] = $throws(static fn() => $dm->insert_many($readings, [
        ['id' => 1, 'label' => 'a', 'value' => 1],
        ['value' => 2, 'label' => 'b', 'id' => 2],
    ]));

    return $threw;
})());

test('…and nothing was written before the refusal', (static function () use ($fresh, $throws, $readings, $rows_of): bool {
    $dm   = $fresh();
    $rows = $rows_of(600);
    unset($rows[599]['value']);

    $throws(static fn() => $dm->insert_many($readings, $rows, 100));

    // The check runs over every row before the first statement, so a bad row at
    // the end does not leave the good ones at the start behind.
    return (int) $dm->query('SELECT COUNT(*) AS n FROM readings')[0]['n'] === 0;
})());

test('a chunk size of zero', (static function () use ($fresh, $throws, $readings, $rows_of): bool {
    [$threw] = $throws(static fn() => $fresh()->insert_many($readings, $rows_of(3), 0));

    return $threw;
})());

// -----------------------------------------------------------------------------
section('it plays with the rest');

test('the query cache lets go afterwards', (static function () use ($fresh, $readings, $rows_of): bool {
    $dm = $fresh();
    $dm->insert_many($readings, $rows_of(3));

    $cache = new class implements \Italix\Contracts\Cache {
        /** @var array<string, mixed> */
        private array $entries = [];

        public function get(string $key, $default = null)
        {
            return $this->entries[$key] ?? $default;
        }

        public function set(string $key, $value, int $ttl_n = 0): bool
        {
            $this->entries[$key] = $value;

            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->entries[$key]);
        }

        public function delete(string $key): bool
        {
            unset($this->entries[$key]);

            return true;
        }

        public function remember(string $key, int $ttl_n, callable $producer)
        {
            return $this->entries[$key] ?? ($this->entries[$key] = $producer());
        }
    };

    $dm->use_query_cache(new \Italix\Orm\Cache\QueryCache($cache, 300));

    $before = count($dm->select()->from($readings)->cached()->execute());
    $dm->insert_many($readings, $rows_of(3, 100));
    $after = count($dm->select()->from($readings)->cached()->execute());

    return $before === 3 && $after === 6;
})());

exit(summary());
