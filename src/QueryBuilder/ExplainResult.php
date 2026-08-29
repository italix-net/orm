<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - What the server says it will do
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\QueryBuilder;

/**
 * A query plan, as the server gave it.
 *
 *     $plan = $dm->select()->from($orders)->where(eq($orders->customer_id, 7))->explain();
 *
 *     $plan->has_full_scan();   // false, once there is an index on customer_id
 *     echo $plan;               // the plan, as text
 *
 * ## The rows are not normalised, and one question is
 *
 * Three servers describe their work in three vocabularies — a `detail` string, a
 * table of columns, a tree of indented text — and flattening them into one shape
 * would mean inventing a fourth that none of them speaks, then explaining the
 * translation to anybody who wanted to read the real thing. So {@see rows()}
 * hands back what came out of the server.
 *
 * The one question worth asking automatically is **whether it said it would read
 * the whole table**, because that is the answer that turns into a missing index.
 * Measured, that sentence is spelled:
 *
 * | | |
 * |---|---|
 * | SQLite | `SCAN TABLE orders` — and `SEARCH TABLE orders USING INDEX …` when it will not |
 * | MySQL / MariaDB | the `type` column reads `ALL` |
 * | PostgreSQL | a line containing `Seq Scan` |
 *
 * A `SCAN … USING COVERING INDEX` on SQLite is not counted: it reads an index
 * from end to end rather than the table, which is a different — usually much
 * cheaper — thing, and calling it a table scan would send somebody looking for
 * an index that is already there.
 */
final class ExplainResult
{
    private string $dialect;

    /** @var array<int, array<string, mixed>> */
    private array $rows;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(string $dialect, array $rows)
    {
        $this->dialect = $dialect;
        $this->rows    = $rows;
    }

    /**
     * What to put in front of the statement, for this server.
     *
     * SQLite wants `EXPLAIN QUERY PLAN`; plain `EXPLAIN` there dumps the virtual
     * machine's opcodes, which is a fascinating thing to read once and never the
     * answer to "why is this slow".
     */
    public static function prefix_for(string $dialect, bool $analyze = false): string
    {
        if ($dialect === 'sqlite') {
            return 'EXPLAIN QUERY PLAN';
        }

        return $analyze ? 'EXPLAIN ANALYZE' : 'EXPLAIN';
    }

    /**
     * The server's answer, unedited.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /** Did the server say it would read a whole table? */
    public function has_full_scan(): bool
    {
        foreach ($this->rows as $row) {
            if ($this->row_is_full_scan($row)) {
                return true;
            }
        }

        return false;
    }

    /** The plan as text, one line per row. */
    public function __toString(): string
    {
        $lines = [];

        foreach ($this->rows as $row) {
            $lines[] = $this->dialect === 'mysql'
                ? implode(' | ', array_map(static fn($value): string => (string) ($value ?? 'NULL'), $row))
                : trim(implode(' ', array_map(static fn($value): string => (string) ($value ?? ''), $row)));
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function row_is_full_scan(array $row): bool
    {
        if ($this->dialect === 'mysql') {
            // `ALL` is the whole table. `index` is the whole *index*, which is
            // also everything but is a different fix, so it is left alone.
            return strtoupper((string) ($row['type'] ?? '')) === 'ALL';
        }

        if ($this->dialect === 'sqlite') {
            $detail = strtoupper((string) ($row['detail'] ?? ''));

            return strpos($detail, 'SCAN') === 0 && strpos($detail, 'USING') === false;
        }

        foreach ($row as $value) {
            if (stripos((string) $value, 'Seq Scan') !== false) {
                return true;
            }
        }

        return false;
    }
}
