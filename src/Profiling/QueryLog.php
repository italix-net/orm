<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - What the queries cost
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Profiling;

/**
 * How many queries ran, how long they took, and which ones were slow.
 *
 *     $log = new QueryLog(0.1, static function (array $query) use ($logger): void {
 *         $logger->warning('slow query', $query);
 *     });
 *
 *     $dm->use_query_log($log);
 *     …
 *     $log->queries_n();        // 34
 *     $log->total_seconds();    // 0.412
 *     $log->slow();             // the two that took longer than 100 ms
 *
 * The number nobody has when they need it is the first one. "The page is slow"
 * is answered by *34 queries* far more often than by any single query being
 * slow, and a log that only keeps the slow ones cannot say that.
 *
 * ## Parameter values are not kept
 *
 * By default a record holds the statement, how many values were bound, and how
 * long it took — **not the values**. A query log is written to a file, shipped
 * to a log service, pasted into a ticket; the bound values are the password
 * being hashed, the tax code, the e-mail address. Keeping them by default means
 * leaking them by default.
 *
 * `remember_values()` turns that off, for a development session where the values
 * are the point. It says what it does in its name, which is the least a method
 * that starts collecting personal data can do.
 *
 * ## What this is not
 *
 * It is not a sampler and it is not free: every statement gets a `microtime()`
 * on each side and an array push. That is nothing against a query and something
 * against ten thousand, so `keep_all(false)` stops it holding a record per
 * statement while still counting and timing them.
 */
final class QueryLog
{
    private float $slow_seconds;

    /** @var callable|null */
    private $handler;

    private bool $keep_all_flag = true;

    private bool $remember_values_flag = false;

    /** @var array<int, array<string, mixed>> */
    private array $records = [];

    /** @var array<int, array<string, mixed>> */
    private array $slow = [];

    private int $queries_n = 0;

    private float $total_seconds = 0.0;

    /**
     * @param float         $slow_seconds anything at or over this is slow
     * @param callable|null $handler      called with each slow query as it happens
     */
    public function __construct(float $slow_seconds = 0.1, ?callable $handler = null)
    {
        if ($slow_seconds <= 0) {
            throw new \InvalidArgumentException(
                'A threshold of ' . $slow_seconds . ' seconds makes every query slow, which is the '
                . 'same as having no threshold. Pass the number of seconds you actually mind.'
            );
        }

        $this->slow_seconds = $slow_seconds;
        $this->handler      = $handler;
    }

    /** Keep a record per statement, or only count and time them. */
    public function keep_all(bool $enabled = true): self
    {
        $this->keep_all_flag = $enabled;

        return $this;
    }

    /**
     * Also keep the bound values.
     *
     * Off by default, and worth leaving off outside a development session: the
     * values are what a query log leaks.
     */
    public function remember_values(bool $enabled = true): self
    {
        $this->remember_values_flag = $enabled;

        return $this;
    }

    /**
     * One statement, and what it cost.
     *
     * @param array<int, mixed> $params
     */
    public function record(string $sql, array $params, float $seconds): void
    {
        $this->queries_n++;
        $this->total_seconds += $seconds;

        $slow_flag = $seconds >= $this->slow_seconds;

        if (!$slow_flag && !$this->keep_all_flag) {
            return;
        }

        $record = [
            'sql'      => $sql,
            'seconds'  => round($seconds, 6),
            'params_n' => count($params),
            'slow'     => $slow_flag,
        ];

        if ($this->remember_values_flag) {
            $record['params'] = $params;
        }

        if ($this->keep_all_flag) {
            $this->records[] = $record;
        }

        if (!$slow_flag) {
            return;
        }

        $this->slow[] = $record;

        if ($this->handler !== null) {
            ($this->handler)($record);
        }
    }

    public function queries_n(): int
    {
        return $this->queries_n;
    }

    public function total_seconds(): float
    {
        return round($this->total_seconds, 6);
    }

    public function slow_seconds(): float
    {
        return $this->slow_seconds;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->records;
    }

    /** @return array<int, array<string, mixed>> */
    public function slow(): array
    {
        return $this->slow;
    }

    /**
     * The same statement, run over and over.
     *
     * The shape of an N+1: one query in a loop, a hundred times, none of them
     * slow on its own. A slow-query threshold never sees it — this is where it
     * shows up.
     *
     * @return array<string, int> statement => how many times it ran
     */
    public function repeated(int $at_least = 2): array
    {
        $counts = [];

        foreach ($this->records as $record) {
            $sql          = (string) $record['sql'];
            $counts[$sql] = ($counts[$sql] ?? 0) + 1;
        }

        $repeated = array_filter($counts, static fn(int $n): bool => $n >= $at_least);

        arsort($repeated);

        return $repeated;
    }

    /** Start again. */
    public function reset(): self
    {
        $this->records       = [];
        $this->slow          = [];
        $this->queries_n     = 0;
        $this->total_seconds = 0.0;

        return $this;
    }
}
