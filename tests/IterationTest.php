<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — reading a large result without holding all of it
 *
 * `execute()` calls `fetchAll()`. That is right until the result is large
 * enough that the array itself is the problem, and the failure is not subtle:
 * the export dies at the memory limit, having produced nothing.
 *
 * Three ways out, and they are not interchangeable:
 *
 *  - `cursor()` / `each()` — one statement, rows yielded as they arrive.
 *  - `chunk()` — `LIMIT`/`OFFSET` paging. Correct **only with an `ORDER BY`**,
 *    and this refuses to run without one, because pages that are not ordered
 *    can repeat rows and skip others without any error.
 *  - `chunk_by()` — keyset paging on a unique key. The one to reach for: pages
 *    cost the same at the end as at the start, and rows inserted or deleted
 *    mid-run cannot shift the window.
 *
 * The assertions below are mostly about **which rows come back**, because every
 * failure mode here produces a working query with a wrong answer.
 *
 * Run: php src/Libs/Italix/Orm/tests/IterationTest.php
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
use function Italix\Orm\Operators\{eq, gt};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - cursors and chunking');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$items = sqlite_table('items', [
    'id'   => integer()->primary_key(),
    'name' => varchar(50),
    'kind' => varchar(20),
]);

/** 25 rows: ids 1..25, every third one 'odd_kind'. */
$fresh = static function () use ($items): DataManager {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, kind TEXT)');

    for ($i = 1; $i <= 25; $i++) {
        $dm->execute('INSERT INTO items (id, name, kind) VALUES (?, ?, ?)', [
            $i,
            'item-' . $i,
            $i % 3 === 0 ? 'odd_kind' : 'plain',
        ]);
    }

    return $dm;
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

$dm = $fresh();

// -----------------------------------------------------------------------------
section('cursor() yields every row, once');

$ids = [];

foreach ($dm->select()->from($items)->order_by($items->id)->cursor() as $row) {
    $ids[] = (int) $row['id'];
}

test('every row arrives', count($ids) === 25);
test('…in order, with none repeated', $ids === range(1, 25));

test('a WHERE still applies', (static function () use ($dm, $items): bool {
    $seen = [];

    foreach ($dm->select()->from($items)->where(eq($items->kind, 'odd_kind'))->order_by($items->id)->cursor() as $row) {
        $seen[] = (int) $row['id'];
    }

    return $seen === [3, 6, 9, 12, 15, 18, 21, 24];
})());

test('breaking out of the loop closes the statement', (static function () use ($dm, $items): bool {
    foreach ($dm->select()->from($items)->order_by($items->id)->cursor() as $row) {
        break;
    }

    // The connection is usable straight away — if the cursor were left open,
    // a driver with one active statement per connection would refuse this.
    return count($dm->select()->from($items)->execute()) === 25;
})());

test('cursor() is for SELECT', (static function () use ($throws, $dm, $items): bool {
    [$threw, $message] = $throws(static function () use ($dm, $items): void {
        foreach ($dm->select()->delete($items)->cursor() as $row) {
            // The generator body does not run until it is iterated.
        }
    });

    return $threw && strpos($message, 'DELETE') !== false;
})());

// The point of a cursor is that the array is never built. Nothing above would
// notice a cursor() that called fetchAll() and yielded from the result — it
// would pass every assertion and save nothing — so this one measures.
test('CURSOR() DOES NOT BUILD THE ARRAY EXECUTE() BUILDS', (static function () use ($items): bool {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, kind TEXT)');
    $dm->transaction(static function (DataManager $dm): void {
        for ($i = 1; $i <= 20000; $i++) {
            $dm->execute('INSERT INTO items (id, name, kind) VALUES (?, ?, ?)', [$i, 'item-' . $i, 'plain']);
        }
    });

    $before      = memory_get_usage();
    $rows        = $dm->select()->from($items)->execute();
    $as_an_array = memory_get_usage() - $before;
    unset($rows);

    $before      = memory_get_usage();
    $seen        = 0;
    $as_a_cursor = 0;

    foreach ($dm->select()->from($items)->cursor() as $row) {
        $seen++;

        // Measured at the first row, not after the loop: a cursor() that called
        // fetchAll() would have allocated everything by the time the first row
        // arrives, and freed it again by the time the loop ends — so measuring
        // afterwards would see nothing and prove nothing.
        if ($seen === 1) {
            $as_a_cursor = memory_get_usage() - $before;
        }
    }

    // Measured: about 9.5 MB against about 0.5 KB. The ratio is what is
    // asserted, generously, so this does not become a test about the allocator.
    return $seen === 20000
        && $as_an_array > 1_000_000
        && $as_a_cursor < $as_an_array / 100;
})());

test('the raw-SQL cursor works the same way', (static function () use ($dm): bool {
    $n = 0;

    foreach ($dm->cursor('SELECT id FROM items WHERE id > ?', [20]) as $row) {
        $n++;
    }

    return $n === 5;
})());

// -----------------------------------------------------------------------------
section('each() hands rows over one at a time');

$sum = 0;
$seen = $dm->select()->from($items)->order_by($items->id)->each(static function (array $row) use (&$sum): void {
    $sum += (int) $row['id'];
});

test('every row is handed over', $seen === 25);
test('…and the callback saw them all', $sum === 325);

test('returning false stops the loop', (static function () use ($dm, $items): bool {
    $positions = [];

    $seen = $dm->select()->from($items)->order_by($items->id)->each(
        static function (array $row, int $position) use (&$positions) {
            $positions[] = $position;

            return $position < 4;
        }
    );

    return $seen === 5 && $positions === [0, 1, 2, 3, 4];
})());

// -----------------------------------------------------------------------------
section('CHUNK() REFUSES TO PAGE WITHOUT AN ORDER BY');

// This is the assertion that matters most. `LIMIT 10 OFFSET 10` against an
// unordered query is not an error — the server may order the pages differently
// each time, so rows get repeated and others never appear. Nothing reports it.
test('no ORDER BY is refused', (static function () use ($throws, $dm, $items): bool {
    [$threw, $message] = $throws(static fn() => $dm->select()->from($items)->chunk(10, static fn() => null));

    return $threw && strpos($message, 'ORDER BY') !== false;
})());

test('…and the message says what goes wrong', (static function () use ($throws, $dm, $items): bool {
    [, $message] = $throws(static fn() => $dm->select()->from($items)->chunk(10, static fn() => null));

    return strpos($message, 'skip') !== false;
})());

test('a query that already pages itself is refused', (static function () use ($throws, $dm, $items): bool {
    [$threw] = $throws(
        static fn() => $dm->select()->from($items)->order_by($items->id)->limit(5)->chunk(10, static fn() => null)
    );

    return $threw;
})());

test('a chunk of zero rows is refused', (static function () use ($throws, $dm, $items): bool {
    [$threw] = $throws(static fn() => $dm->select()->from($items)->order_by($items->id)->chunk(0, static fn() => null));

    return $threw;
})());

// -----------------------------------------------------------------------------
section('chunk() pages through everything');

$pages = [];
$total = $dm->select()->from($items)->order_by($items->id)->chunk(10, static function (array $rows, int $number) use (&$pages): void {
    $pages[$number] = array_map(static fn(array $row): int => (int) $row['id'], $rows);
});

test('every row, once', $total === 25);
test('…in three pages', array_keys($pages) === [1, 2, 3]);
test('…of 10, 10 and 5', [count($pages[1]), count($pages[2]), count($pages[3])] === [10, 10, 5]);
test('…covering 1..25 with no overlap', array_merge($pages[1], $pages[2], $pages[3]) === range(1, 25));

test('a result that divides exactly does not loop forever', (static function () use ($dm, $items): bool {
    $pages = 0;
    $total = $dm->select()->from($items)->where(gt($items->id, 20))->order_by($items->id)
        ->chunk(5, static function () use (&$pages): void {
            $pages++;
        });

    // 5 rows in a chunk of 5: the next request comes back empty and ends it.
    return $total === 5 && $pages === 1;
})());

test('returning false stops paging', (static function () use ($dm, $items): bool {
    $pages = 0;
    $total = $dm->select()->from($items)->order_by($items->id)->chunk(10, static function () use (&$pages) {
        $pages++;

        return false;
    });

    return $pages === 1 && $total === 10;
})());

// -----------------------------------------------------------------------------
section('CHUNK_BY() PAGES ON THE KEY, NOT ON A POSITION');

$by_key = [];
$total  = $dm->select()->from($items)->chunk_by($items->id, 10, static function (array $rows, int $number) use (&$by_key): void {
    $by_key[$number] = array_map(static fn(array $row): int => (int) $row['id'], $rows);
});

test('every row, once', $total === 25);
test('…covering 1..25 in order', array_merge(...array_values($by_key)) === range(1, 25));

test('an existing WHERE is kept, not replaced', (static function () use ($dm, $items): bool {
    $seen = [];

    $dm->select()->from($items)->where(eq($items->kind, 'odd_kind'))
        ->chunk_by($items->id, 3, static function (array $rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = (int) $row['id'];
            }
        });

    return $seen === [3, 6, 9, 12, 15, 18, 21, 24];
})());

// Rows deleted while paging shift an OFFSET window and leave a keyset one
// alone. That difference is the whole reason this method exists.
test('DELETING ROWS MID-RUN DOES NOT MAKE IT SKIP', (static function () use ($fresh, $items): bool {
    $dm   = $fresh();
    $seen = [];

    $dm->select()->from($items)->chunk_by($items->id, 5, static function (array $rows) use (&$seen, $dm): void {
        foreach ($rows as $row) {
            $seen[] = (int) $row['id'];
        }

        // Remove the page just handled, the way a "process and archive" job does.
        $dm->execute('DELETE FROM items WHERE id <= ?', [$rows[count($rows) - 1]['id']]);
    });

    return $seen === range(1, 25);
})());

test('the same job with chunk() loses rows', (static function () use ($fresh, $items): bool {
    $dm   = $fresh();
    $seen = [];

    $dm->select()->from($items)->order_by($items->id)->chunk(5, static function (array $rows) use (&$seen, $dm): void {
        foreach ($rows as $row) {
            $seen[] = (int) $row['id'];
        }

        $dm->execute('DELETE FROM items WHERE id <= ?', [$rows[count($rows) - 1]['id']]);
    });

    // Offset 5 into a table whose first five rows are gone starts at the tenth
    // original row. Nothing errors; five rows simply never arrive.
    return $seen !== range(1, 25) && count($seen) < 25;
})());

test('chunk_by() will not run on a query that orders itself', (static function () use ($throws, $dm, $items): bool {
    [$threw, $message] = $throws(
        static fn() => $dm->select()->from($items)->order_by($items->name)->chunk_by($items->id, 5, static fn() => null)
    );

    return $threw && strpos($message, 'ORDER BY') !== false;
})());

test('…or on a key it cannot see in the rows', (static function () use ($throws, $dm, $items): bool {
    [$threw, $message] = $throws(
        static fn() => $dm->select([$items->name])->from($items)->chunk_by($items->id, 5, static fn() => null)
    );

    return $threw && strpos($message, 'do not contain it') !== false;
})());

test('returning false stops paging', (static function () use ($dm, $items): bool {
    $pages = 0;
    $total = $dm->select()->from($items)->chunk_by($items->id, 10, static function () use (&$pages) {
        $pages++;

        return false;
    });

    return $pages === 1 && $total === 10;
})());

test('an empty table is one page of nothing', (static function () use ($items): bool {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, kind TEXT)');

    $pages = 0;
    $total = $dm->select()->from($items)->chunk_by($items->id, 10, static function () use (&$pages): void {
        $pages++;
    });

    return $total === 0 && $pages === 0;
})());

exit(summary());
