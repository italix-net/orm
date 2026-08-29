<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Query result cache
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Cache;

use Italix\Contracts\Cache;

/**
 * Answers a query already answered, and forgets the answer when the table changes.
 *
 *     $dm->use_query_cache(new QueryCache($cache, 300));
 *
 *     $rows = $dm->select()->from($products)->where(eq($products->kind, 'tool'))
 *                ->cached()->execute();          // asks the server once
 *
 *     $dm->insert($products)->values([…])->execute();   // and now it will ask again
 *
 * ## The hard part is not caching. It is knowing when to stop.
 *
 * A TTL alone means every cached answer is wrong for up to that long, including
 * the one the user just changed themselves. So each table carries a
 * **generation**: a random token kept in the cache, mixed into every key for a
 * query that reads that table. A write through this package replaces the token,
 * which does not delete anything — it moves every key for that table at once,
 * and the old entries expire unread.
 *
 * A token rather than a counter, deliberately. If a counter's key expired it
 * would restart at 1, and entries written under the *previous* generation 1
 * would come back to life — stale, and by then trusted. A fresh random token
 * can never collide with a namespace that has already been used, which is also
 * why losing one is only a cost: every key for that table changes at once and
 * the answers are computed again.
 *
 * ## What it cannot see
 *
 * | | |
 * |---|---|
 * | a write through this package | invalidated, automatically |
 * | `$dm->execute('DELETE FROM …')` — raw SQL | **not seen.** Call {@see invalidate()} yourself. |
 * | another process, or a database trigger | **not seen.** The TTL is the only bound. |
 * | a subquery or CTE reading a table the outer query does not name | **not tracked** unless you pass it to `cached()` |
 *
 * The last one is the sharp edge. `from()` and the joins are tracked; a table
 * that appears only inside a subquery is not, so a cached answer can outlive a
 * change to it. `cached($ttl, ['other_table'])` names it, and the TTL is the
 * backstop for everything nobody thought of — which is why there is one.
 *
 * ## Where the answers are shared
 *
 * With `ArrayCache` the cache lives and dies with the request; with `FileCache`
 * it is per server, so **two servers can hold different generations** and a
 * write on one does not invalidate the other. For more than one machine the
 * store has to be shared — `RedisCache`, `StoreCache` — and that is a property
 * of the cache handed in here, not something this class can arrange.
 */
final class QueryCache
{
    private Cache $cache;

    private int $default_ttl_n;

    private string $prefix;

    /**
     * @param int $default_ttl_n seconds a cached result lives when `cached()` names no other
     */
    public function __construct(Cache $cache, int $default_ttl_n = 60, string $prefix = 'ix_orm:')
    {
        if ($default_ttl_n < 1) {
            throw new \InvalidArgumentException(
                'A cached query needs a lifetime; ' . $default_ttl_n . ' seconds is not one. '
                . 'The generation token invalidates on writes this package makes, and the TTL is '
                . 'the bound on everything else.'
            );
        }

        $this->cache         = $cache;
        $this->default_ttl_n = $default_ttl_n;
        $this->prefix        = $prefix;
    }

    /**
     * The rows for this query, from the cache or from `$producer`.
     *
     * @param array<int, mixed>  $params
     * @param array<int, string> $tables the tables the answer depends on
     * @param callable():mixed   $producer
     * @return mixed
     */
    public function remember(string $sql, array $params, array $tables, ?int $ttl_n, callable $producer)
    {
        return $this->cache->remember(
            $this->key($sql, $params, $tables),
            $ttl_n ?? $this->default_ttl_n,
            $producer
        );
    }

    /**
     * Forget every cached answer that reads these tables.
     *
     * Instant, and no more expensive for a million entries than for one: it
     * writes a new generation token, and every old key becomes unreachable.
     */
    public function invalidate(string ...$tables): void
    {
        foreach ($tables as $table) {
            // Stored without an expiry. Not for correctness — a token that
            // vanished would be replaced by a *new* random one, so no old entry
            // could be reached again — but for cost: every key for the table
            // changes at once, so an expiring generation is a wave of misses at
            // an hour nobody chose.
            $this->cache->set($this->generation_key($table), $this->new_token(), 0);
        }
    }

    /**
     * The token that namespaces every key for this table.
     *
     * Created on first use, so a cache that lost it starts a fresh namespace
     * rather than reusing one whose entries may still be around.
     */
    public function generation(string $table): string
    {
        $key   = $this->generation_key($table);
        $token = $this->cache->get($key);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = $this->new_token();
        $this->cache->set($key, $token, 0);

        return $token;
    }

    public function default_ttl_n(): int
    {
        return $this->default_ttl_n;
    }

    /**
     * The key one query is stored under.
     *
     * The statement, its values, the dialect it was rendered for, and the
     * generation of every table it reads. Two queries differing in any of those
     * are different questions and must not share an answer.
     *
     * @param array<int, mixed>  $params
     * @param array<int, string> $tables
     */
    public function key(string $sql, array $params, array $tables): string
    {
        $generations = [];

        foreach (array_unique($tables) as $table) {
            $generations[$table] = $this->generation($table);
        }

        ksort($generations);

        return $this->prefix . sha1(serialize([$sql, $params, $generations]));
    }

    private function generation_key(string $table): string
    {
        return $this->prefix . 'gen:' . $table;
    }

    private function new_token(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            // No entropy source is not a reason to fail a request. uniqid() is
            // weak for secrets and perfectly sufficient for a namespace nobody
            // is trying to guess.
            return uniqid('', true);
        }
    }
}
