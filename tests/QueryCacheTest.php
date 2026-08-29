<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — caching a query's answer
 *
 * Caching is easy. Knowing when to stop is the whole problem, and getting it
 * wrong does not raise: it serves a row the user just changed themselves.
 *
 * So the probe throughout is a change made to the table **behind the cache's
 * back**, with raw SQL the package cannot see. If a later read still shows the
 * old rows, the answer came from the cache; if it shows the new ones, the cache
 * let go. Nothing else distinguishes a cache that is never consulted from one
 * that is never invalidated — both look perfectly healthy from outside.
 *
 * Run: php src/Libs/Italix/Orm/tests/QueryCacheTest.php
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

use Italix\Contracts\Cache;
use Italix\Orm\Cache\QueryCache;
use Italix\Orm\DataManager;

use function Italix\Orm\Schema\{integer, sqlite_table, varchar};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - query cache');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

/**
 * The smallest thing that satisfies the contract.
 *
 * Written here rather than imported: this package caches through
 * `Italix\Contracts\Cache` and does not depend on `italix/cache`, so the suite
 * should not either — and a test double that counts is what these assertions
 * need anyway.
 */
final class CountingCache implements Cache
{
    /** @var array<string, array{value: mixed, expires_t: int}> */
    private array $entries = [];

    public int $writes_n = 0;

    public function get(string $key, $default = null)
    {
        if (!isset($this->entries[$key])) {
            return $default;
        }

        $entry = $this->entries[$key];

        if ($entry['expires_t'] !== 0 && $entry['expires_t'] <= time()) {
            unset($this->entries[$key]);

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, $value, int $ttl_n = 0): bool
    {
        $this->writes_n++;
        $this->entries[$key] = [
            'value'     => $value,
            'expires_t' => $ttl_n === 0 ? 0 : time() + $ttl_n,
        ];

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, null) !== null;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function remember(string $key, int $ttl_n, callable $producer)
    {
        $found = $this->get($key, null);

        if ($found !== null) {
            return $found;
        }

        $value = $producer();
        $this->set($key, $value, $ttl_n);

        return $value;
    }

    /** When the entry stops being valid: 0 for never, or a timestamp. */
    public function expiry_of(string $key): ?int
    {
        return isset($this->entries[$key]) ? $this->entries[$key]['expires_t'] : null;
    }

    /** Pretend everything stored expired, without touching the generations. */
    public function expire_results(string $prefix): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (strpos($key, $prefix . 'gen:') !== 0) {
                unset($this->entries[$key]);
            }
        }
    }
}

/** @return array{0: bool, 1: string} */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (\Throwable $e) {
        return [true, $e->getMessage()];
    }
};

$products = sqlite_table('products', [
    'id'   => integer()->primary_key(),
    'name' => varchar(50),
    'kind' => varchar(20),
]);

$tags = sqlite_table('tags', [
    'id'         => integer()->primary_key(),
    'product_id' => integer(),
    'label'      => varchar(20),
]);

/**
 * A manager with three products and two tags, and a cache to keep answers in.
 *
 * @return array{0: DataManager, 1: CountingCache}
 */
$fresh = static function (int $ttl_n = 300): array {
    $dm = sqlite_memory();

    $dm->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, kind TEXT)');
    $dm->execute('CREATE TABLE tags (id INTEGER PRIMARY KEY, product_id INTEGER, label TEXT)');
    $dm->execute("INSERT INTO products VALUES (1, 'hammer', 'tool'), (2, 'saw', 'tool'), (3, 'apple', 'food')");
    $dm->execute("INSERT INTO tags VALUES (1, 1, 'heavy'), (2, 3, 'fresh')");

    $cache = new CountingCache();
    $dm->use_query_cache(new QueryCache($cache, $ttl_n));

    return [$dm, $cache];
};

// -----------------------------------------------------------------------------
section('A CACHED ANSWER IS NOT ASKED FOR TWICE');

[$dm, $cache] = $fresh();

$first = $dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute();

// Behind the cache's back: raw SQL, which this package cannot see.
$dm->execute("INSERT INTO products VALUES (4, 'drill', 'tool')");

$second = $dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute();

test('the rows are right', count($first) === 2);
test('THE SECOND READ CAME FROM THE CACHE', $second === $first);
test('…while the database really had changed', count($dm->query("SELECT id FROM products WHERE kind = 'tool'")) === 3);

test('an uncached query is never served from the cache', (static function () use ($dm, $products): bool {
    return count($dm->select()->from($products)->where(eq($products->kind, 'tool'))->execute()) === 3;
})());

test('a different value is a different question', (static function () use ($dm, $products): bool {
    // Never cached under this key, so it goes to the server and sees the truth.
    return count($dm->select()->from($products)->where(eq($products->kind, 'food'))->cached()->execute()) === 1;
})());

test('an answer is stored only once', (static function () use ($fresh, $products): bool {
    [$dm, $cache] = $fresh();

    $dm->select()->from($products)->cached()->execute();
    $writes_n = $cache->writes_n;
    $dm->select()->from($products)->cached()->execute();

    // A second store would mean the first read never landed in the cache.
    return $cache->writes_n === $writes_n;
})());

// -----------------------------------------------------------------------------
section('A WRITE THROUGH THIS PACKAGE RETIRES THE ANSWERS');

test('an INSERT does', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute();
    $dm->insert($products)->values(['id' => 4, 'name' => 'drill', 'kind' => 'tool'])->execute();

    return count($dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute()) === 3;
})());

test('an UPDATE does', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute();
    $dm->update($products)->set(['kind' => 'food'])->where(eq($products->id, 2))->execute();

    return count($dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute()) === 1;
})());

test('a DELETE does', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute();
    $dm->delete($products)->where(eq($products->id, 2))->execute();

    return count($dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute()) === 1;
})());

test('a write to another table leaves this answer alone', (static function () use ($fresh, $products, $tags): bool {
    [$dm] = $fresh();

    $dm->select()->from($products)->cached()->execute();
    $dm->execute("INSERT INTO products VALUES (4, 'drill', 'tool')");   // unseen
    $dm->insert($tags)->values(['id' => 3, 'product_id' => 2, 'label' => 'sharp'])->execute();

    // Nothing touched `products` through the package, so the answer stands —
    // the unseen row is still missing from it.
    return count($dm->select()->from($products)->cached()->execute()) === 3;
})());

test('A JOINED TABLE COUNTS AS READ', (static function () use ($fresh, $products, $tags): bool {
    [$dm] = $fresh();

    $joined = static fn(DataManager $dm) => $dm->select([$products->name, $tags->label])
        ->from($products)
        ->inner_join($tags, eq($tags->product_id, $products->id))
        ->cached()
        ->execute();

    $joined($dm);
    $dm->insert($tags)->values(['id' => 3, 'product_id' => 2, 'label' => 'sharp'])->execute();

    // Writing to the joined table has to retire the answer: the rows changed.
    return count($joined($dm)) === 3;
})());

test('a write returns what it always returned', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $id = $dm->insert($products)->values(['name' => 'drill', 'kind' => 'tool'])->execute();
    $n  = $dm->delete($products)->where(eq($products->id, $id))->execute();

    return $id === 4 && $n === 1;
})());

// -----------------------------------------------------------------------------
section('what it cannot see, and what bounds that');

test('RAW SQL IS NOT SEEN — the answer stands until told', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $dm->select()->from($products)->cached()->execute();
    $dm->execute("INSERT INTO products VALUES (9, 'wrench', 'tool')");

    $stale = $dm->select()->from($products)->cached()->execute();

    // Three rows, not four. This is the documented limit, asserted so that it
    // stays a decision rather than becoming a surprise.
    return count($stale) === 3;
})());

test('…and invalidate() is how you tell it', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $dm->select()->from($products)->cached()->execute();
    $dm->execute("INSERT INTO products VALUES (9, 'wrench', 'tool')");
    $dm->query_cache()->invalidate('products');

    return count($dm->select()->from($products)->cached()->execute()) === 4;
})());

test('A TABLE REACHED ONLY THROUGH A SUBQUERY CAN BE NAMED', (static function () use ($fresh, $products, $tags): bool {
    [$dm] = $fresh();

    // The statement mentions `products` and nothing else, so a write to `tags`
    // would leave this answer standing — unless the answer says it depends on
    // it. The unseen row appearing is the proof it was retired.
    $rows = static fn(DataManager $dm) => $dm->select()->from($products)->cached(300, ['tags'])->execute();

    $rows($dm);
    $dm->execute("INSERT INTO products VALUES (9, 'wrench', 'tool')");   // unseen
    $dm->insert($tags)->values(['id' => 3, 'product_id' => 2, 'label' => 'sharp'])->execute();

    return count($rows($dm)) === 4;
})());

test('…and without naming it, the answer stands', (static function () use ($fresh, $products, $tags): bool {
    [$dm] = $fresh();

    $rows = static fn(DataManager $dm) => $dm->select()->from($products)->cached()->execute();

    $rows($dm);
    $dm->execute("INSERT INTO products VALUES (9, 'wrench', 'tool')");
    $dm->insert($tags)->values(['id' => 3, 'product_id' => 2, 'label' => 'sharp'])->execute();

    return count($rows($dm)) === 3;
})());

test('THE LIFETIME BOUNDS EVERYTHING NOBODY THOUGHT OF', (static function () use ($fresh, $products): bool {
    [$dm, $cache] = $fresh();

    $dm->select()->from($products)->cached()->execute();
    $dm->execute("INSERT INTO products VALUES (9, 'wrench', 'tool')");   // unseen
    $cache->expire_results('ix_orm:');                                   // as if the TTL ran out

    // Nothing invalidated this answer; it simply stopped existing, and the
    // question had to be asked again. That is what the TTL is for.
    return count($dm->select()->from($products)->cached()->execute()) === 4;
})());

// -----------------------------------------------------------------------------
section('THE GENERATION IS A TOKEN, NOT A COUNTER');

// A counter that expired would restart at 1, and entries written under the
// *previous* generation 1 would come back — stale, and by then trusted.
test('a new generation never repeats an old one', (static function (): bool {
    $cache = new CountingCache();
    $qc    = new QueryCache($cache, 60);

    $seen = [$qc->generation('products')];

    for ($i = 0; $i < 5; $i++) {
        $qc->invalidate('products');
        $seen[] = $qc->generation('products');
    }

    return count(array_unique($seen)) === count($seen);
})());

test('a generation that vanished starts a fresh namespace', (static function (): bool {
    $cache = new CountingCache();
    $qc    = new QueryCache($cache, 60);

    $before = $qc->key('SELECT 1', [], ['products']);
    $cache->delete('ix_orm:gen:products');
    $after  = $qc->key('SELECT 1', [], ['products']);

    return $before !== $after;
})());

test('the same query in the same generation has the same key', (static function (): bool {
    $cache = new CountingCache();
    $qc    = new QueryCache($cache, 60);

    return $qc->key('SELECT 1', [1], ['products']) === $qc->key('SELECT 1', [1], ['products'])
        && $qc->key('SELECT 1', [1], ['products']) !== $qc->key('SELECT 1', [2], ['products']);
})());

// Not a correctness property — a lost token is replaced by a *new* one, so
// nothing stale can come back — but a cost one: every key for the table changes
// at once, and an expiring generation is a wave of misses at an hour nobody
// chose. So it is stored without an expiry, deliberately.
test('THE GENERATION IS STORED WITHOUT AN EXPIRY', (static function (): bool {
    $cache = new CountingCache();
    $qc    = new QueryCache($cache, 60);

    $qc->generation('products');
    $on_creation = $cache->expiry_of('ix_orm:gen:products');

    $qc->invalidate('products');
    $on_invalidation = $cache->expiry_of('ix_orm:gen:products');

    return $on_creation === 0 && $on_invalidation === 0;
})());

test('the generation entry outlives a sweep of the answers', (static function (): bool {
    $cache = new CountingCache();
    $qc    = new QueryCache($cache, 60);
    $qc->generation('products');
    $cache->expire_results('ix_orm:');

    // expire_results() cleared everything except generations; the generation is
    // still readable, which is the point — it must outlive what it namespaces.
    return $cache->get('ix_orm:gen:products') !== null;
})());

// -----------------------------------------------------------------------------
section('what it refuses');

test('cached() on a write', (static function () use ($throws, $fresh, $products): bool {
    [$dm] = $fresh();
    [$threw, $message] = $throws(static fn() => $dm->insert($products)->values(['name' => 'x'])->cached());

    return $threw && strpos($message, 'INSERT') !== false;
})());

test('cached() with no cache on the manager', (static function () use ($throws, $products): bool {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, kind TEXT)');

    [$threw, $message] = $throws(static fn() => $dm->select()->from($products)->cached()->execute());

    return $threw && strpos($message, 'use_query_cache') !== false;
})());

test('a lifetime of zero', (static function () use ($throws, $fresh, $products): bool {
    [$dm] = $fresh();
    [$threw] = $throws(static fn() => $dm->select()->from($products)->cached(0));

    return $threw;
})());

test('a cache with no default lifetime', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => new QueryCache(new CountingCache(), 0));

    return $threw && strpos($message, 'lifetime') !== false;
})());

test('cached() copies rather than mutates', (static function () use ($fresh, $products): bool {
    [$dm] = $fresh();

    $plain = $dm->select()->from($products);
    $plain->cached();                       // the copy is cached; $plain is not

    $plain->execute();
    $dm->execute("INSERT INTO products VALUES (9, 'wrench', 'tool')");

    return count($plain->execute()) === 4;
})());

exit(summary());
