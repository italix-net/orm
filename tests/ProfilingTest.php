<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — what the queries cost, and what the server means to do
 *
 * Two questions with the same subject. `QueryLog` answers "what did this request
 * spend on the database", which is far more often *34 queries* than any one of
 * them being slow — and a log that keeps only the slow ones cannot say that.
 * `explain()` answers "and what will the server do with this one".
 *
 * The plan is per dialect and so are the assertions: three servers describe
 * their work in three vocabularies, and the one sentence worth reading
 * automatically — "I will read the whole table" — is spelled `SCAN TABLE`,
 * `type: ALL` and `Seq Scan`. Each is checked against the server that says it.
 *
 * MySQL and PostgreSQL run when configured (`IX_MY_*`, `IX_PG_*`).
 *
 * Run: php src/Libs/Italix/Orm/tests/ProfilingTest.php
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
use Italix\Orm\Profiling\QueryLog;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\{integer, mysql_table, pg_table, sqlite_table, varchar};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\{mysql, postgres, sqlite_memory};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - query log and explain');

/**
 * A manager for a configured server, or null when it is not answering.
 *
 * Configured and unreachable is the ordinary case on somebody else's machine —
 * and a suite that fatals there tells them their change broke something.
 */
$connect = static function (callable $make, string $label): ?DataManager {
    try {
        $dm = $make();
        $dm->query('SELECT 1');

        return $dm;
    } catch (\Throwable $e) {
        echo "  SKIPPED {$label} - configured but not answering: "
            . strtok($e->getMessage(), "\n") . "\n";

        return null;
    }
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
section('THE QUERY LOG COUNTS WHAT A REQUEST SPENDS');

$items = sqlite_table('ix_log_items', [
    'id'   => integer()->primary_key(),
    'kind' => varchar(10),
    'name' => varchar(20),
]);

$filled = static function (): DataManager {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE ix_log_items (id INTEGER PRIMARY KEY, kind TEXT, name TEXT)');
    $dm->execute('CREATE INDEX ix_log_items_kind ON ix_log_items (kind)');

    for ($i = 1; $i <= 200; $i++) {
        $dm->execute('INSERT INTO ix_log_items VALUES (?, ?, ?)', [$i, 'k' . ($i % 5), 'n' . $i]);
    }

    return $dm;
};

$dm  = $filled();
$log = new QueryLog(0.5);
$dm->use_query_log($log);

$dm->select()->from($items)->where(eq($items->kind, 'k1'))->execute();
$dm->select()->from($items)->where(eq($items->kind, 'k2'))->execute();
$dm->query('SELECT COUNT(*) AS n FROM ix_log_items');

test('every statement is counted, builder and raw alike', $log->queries_n() === 3);
test('…and timed', $log->total_seconds() > 0);
test('none of these was slow', $log->slow() === []);
test('a record keeps the statement', strpos((string) $log->all()[0]['sql'], 'SELECT') === 0);

// A query log is written to a file, shipped to a log service, pasted into a
// ticket. The bound values are the password being hashed and the tax code.
test('THE BOUND VALUES ARE NOT KEPT', !isset($log->all()[0]['params']));
test('…but their number is', $log->all()[0]['params_n'] === 1);

test('…and remember_values() says what it does', (static function () use ($filled, $items): bool {
    $dm  = $filled();
    $log = (new QueryLog(0.5))->remember_values();
    $dm->use_query_log($log);
    $dm->select()->from($items)->where(eq($items->kind, 'k1'))->execute();

    return ($log->all()[0]['params'] ?? []) === ['k1'];
})());

test('a slow query is separated out', (static function () use ($filled, $items): bool {
    $dm  = $filled();
    $log = new QueryLog(0.000001);          // everything counts as slow
    $dm->use_query_log($log);
    $dm->select()->from($items)->execute();

    return count($log->slow()) === 1 && $log->slow()[0]['slow'] === true;
})());

test('…and the handler is called as it happens', (static function () use ($filled, $items): bool {
    $seen = [];
    $dm   = $filled();
    $log  = new QueryLog(0.000001, static function (array $record) use (&$seen): void {
        $seen[] = $record['sql'];
    });

    $dm->use_query_log($log);
    $dm->select()->from($items)->execute();

    return count($seen) === 1;
})());

// The shape of an N+1: one query in a loop, none of them slow on its own. No
// slow-query threshold ever sees it.
test('REPEATED STATEMENTS ARE FOUND, THOUGH NONE IS SLOW', (static function () use ($filled, $items): bool {
    $dm  = $filled();
    $log = new QueryLog(0.5);
    $dm->use_query_log($log);

    for ($i = 1; $i <= 20; $i++) {
        $dm->select()->from($items)->where(eq($items->id, $i))->execute();
    }

    $repeated = $log->repeated();

    return $log->slow() === []
        && count($repeated) === 1
        && reset($repeated) === 20;
})());

test('keep_all(false) still counts and times without holding a record', (static function () use ($filled, $items): bool {
    $dm  = $filled();
    $log = (new QueryLog(0.5))->keep_all(false);
    $dm->use_query_log($log);

    $dm->select()->from($items)->execute();
    $dm->select()->from($items)->execute();

    return $log->queries_n() === 2 && $log->all() === [] && $log->total_seconds() > 0;
})());

test('reset() starts again', (static function () use ($filled, $items): bool {
    $dm  = $filled();
    $log = new QueryLog(0.5);
    $dm->use_query_log($log);
    $dm->select()->from($items)->execute();
    $log->reset();

    return $log->queries_n() === 0 && $log->all() === [];
})());

test('a threshold of zero is refused', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => new QueryLog(0));

    return $threw && strpos($message, 'no threshold') !== false;
})());

// -----------------------------------------------------------------------------
section('a cached answer is not a query');

test('a cache hit is not counted', (static function () use ($filled, $items): bool {
    $dm    = $filled();
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
    $log = new QueryLog(0.5);
    $dm->use_query_log($log);

    $dm->select()->from($items)->cached()->execute();
    $dm->select()->from($items)->cached()->execute();

    // Two calls, one query: the second never reached the server, and counting
    // it would hide exactly what the cache is there to show.
    return $log->queries_n() === 1;
})());

// -----------------------------------------------------------------------------
/** Everything explain() must say, per dialect. */
$explains = static function (DataManager $dm, Table $items, string $dialect) use ($throws): void {
    section($dialect . ': the plan the server gives');

    $indexed   = $dm->select()->from($items)->where(eq($items->kind, 'k1'))->explain();
    $unindexed = $dm->select()->from($items)->where(eq($items->name, 'n1'))->explain();

    test($dialect . ': the plan has rows in it', $indexed->rows() !== []);
    test($dialect . ': …and reads as text', (string) $indexed !== '');

    // The one question worth asking automatically, and the answer that turns
    // into a missing index.
    test($dialect . ': A QUERY WITH NO INDEX IS A FULL SCAN', $unindexed->has_full_scan(), (string) $unindexed);
    test($dialect . ': …and one with an index is not', !$indexed->has_full_scan(), (string) $indexed);

    test($dialect . ': EXPLAIN does not run the statement', (static function () use ($dm, $items): bool {
        $before = count($dm->select()->from($items)->execute());
        $dm->select()->delete($items)->where(eq($items->id, 1))->explain();

        return count($dm->select()->from($items)->execute()) === $before;
    })());

    // EXPLAIN ANALYZE on PostgreSQL performs the statement it is given. Finding
    // that out from a production database is not a way to learn it.
    test($dialect . ': ANALYZE IS REFUSED ON ANYTHING BUT A SELECT', (static function () use ($throws, $dm, $items): bool {
        [$threw, $message] = $throws(static fn() => $dm->select()->delete($items)->explain(true));

        return $threw && strpos($message, 'runs the statement') !== false;
    })());
};

$dm = $filled();
$explains($dm, $items, 'sqlite');

// -----------------------------------------------------------------------------
$my_config = [
    'host'     => getenv('IX_MY_HOST') ?: '',
    'database' => getenv('IX_MY_DATABASE') ?: '',
    'username' => getenv('IX_MY_USER') ?: '',
    'password' => getenv('IX_MY_PASSWORD') ?: '',
];

if ($my_config['host'] === '' || $my_config['database'] === '') {
    echo "  SKIPPED mysql - not configured (IX_MY_HOST, IX_MY_DATABASE, IX_MY_USER, IX_MY_PASSWORD).\n";
} else {
    $dm = $connect(static fn() => mysql($my_config), 'mysql');
}

if (isset($dm) && $dm !== null && $my_config['host'] !== '') {
    $dm->execute('DROP TABLE IF EXISTS ix_log_items');
    $dm->execute('CREATE TABLE ix_log_items (id INT PRIMARY KEY, kind VARCHAR(10), name VARCHAR(20), INDEX (kind))');

    for ($i = 1; $i <= 200; $i++) {
        $dm->execute('INSERT INTO ix_log_items VALUES (?, ?, ?)', [$i, 'k' . ($i % 5), 'n' . $i]);
    }

    $dm->query('ANALYZE TABLE ix_log_items');

    $explains($dm, mysql_table('ix_log_items', [
        'id'   => integer()->primary_key(),
        'kind' => varchar(10),
        'name' => varchar(20),
    ]), 'mysql');

    $dm->execute('DROP TABLE IF EXISTS ix_log_items');
}

// -----------------------------------------------------------------------------
$pg_config = [
    'host'     => getenv('IX_PG_HOST') ?: '',
    'port'     => (int) (getenv('IX_PG_PORT') ?: 5432),
    'database' => getenv('IX_PG_DATABASE') ?: '',
    'username' => getenv('IX_PG_USER') ?: '',
    'password' => getenv('IX_PG_PASSWORD') ?: '',
];

if (!extension_loaded('pdo_pgsql') || $pg_config['host'] === '' || $pg_config['database'] === '') {
    echo "  SKIPPED postgresql - not configured (IX_PG_HOST, IX_PG_DATABASE, IX_PG_USER).\n";
    exit(summary());
}

$dm = $connect(static fn() => postgres($pg_config), 'postgresql');

if ($dm === null) {
    exit(summary());
}

$dm->execute('DROP TABLE IF EXISTS ix_log_items');
$dm->execute('CREATE TABLE ix_log_items (id INT PRIMARY KEY, kind TEXT, name TEXT)');
$dm->execute('CREATE INDEX ix_log_items_kind ON ix_log_items (kind)');

for ($i = 1; $i <= 2000; $i++) {
    $dm->execute('INSERT INTO ix_log_items VALUES (?, ?, ?)', [$i, 'k' . ($i % 50), 'n' . $i]);
}

$dm->execute('ANALYZE ix_log_items');

$pg_items = pg_table('ix_log_items', [
    'id'   => integer()->primary_key(),
    'kind' => varchar(10),
    'name' => varchar(20),
]);

$explains($dm, $pg_items, 'postgresql');

section('postgresql: analyze');

test('EXPLAIN ANALYZE on a SELECT reports what really happened', (static function () use ($dm, $pg_items): bool {
    $plan = $dm->select()->from($pg_items)->where(eq($pg_items->name, 'n1'))->explain(true);

    return strpos((string) $plan, 'actual time') !== false;
})());

$dm->execute('DROP TABLE IF EXISTS ix_log_items');

exit(summary());
