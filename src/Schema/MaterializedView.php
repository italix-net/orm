<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Materialized view
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use InvalidArgumentException;

/**
 * A materialized view: a query whose **answer** is stored, not just its text.
 *
 *     $daily_totals = pg_materialized_view('daily_totals', [
 *         'day'   => date_type(),
 *         'total' => numeric(12, 2),
 *     ])->as_query($select)->add_unique_index('daily_totals_day', ['day']);
 *
 *     Schema::create_materialized_view($daily_totals);
 *     Schema::refresh_materialized_view($daily_totals, true);   // concurrently
 *
 * A plain {@see View} runs its query every time you read it. This one holds the
 * rows until you say otherwise, which is the whole point and also the whole
 * catch: **the data is as old as the last refresh.** Refreshing is your job, and
 * choosing when is a decision about the application, not about SQL.
 *
 * ## PostgreSQL only, and refused elsewhere
 *
 * MySQL, MariaDB and SQLite have no materialized views at all. The substitute
 * everyone writes is a real table plus a job that empties and refills it — which
 * is a fine thing to build, and not a thing this class can pretend to be. So a
 * materialized view for another dialect is refused at construction rather than
 * rendered into SQL that no server will take.
 *
 * ## What was measured, on PostgreSQL 12
 *
 * | | |
 * |---|---|
 * | `CREATE OR REPLACE MATERIALIZED VIEW` | **does not exist** — syntax error. Replacing is `DROP` then `CREATE`, always two statements. |
 * | `CREATE MATERIALIZED VIEW IF NOT EXISTS` | works. Safe to offer here, unlike on a plain view, because this is PostgreSQL by construction. |
 * | `WITH NO DATA` | works — and reading the view before its first refresh then **errors**: `materialized view "x" has not been populated`. |
 * | `REFRESH … CONCURRENTLY` | needs a **unique index** on the view *and* a populated view; without either, PostgreSQL refuses with a message that says which. |
 * | `DROP VIEW` on one | `"x" is not a view`. The two kinds do not share a `DROP`, or a catalogue — matviews are in `pg_matviews`, not `pg_views`. |
 *
 * ## `CONCURRENTLY`
 *
 * A plain refresh takes an exclusive lock: readers block until it finishes. With
 * `CONCURRENTLY` they do not, at the price of a slower refresh and the unique
 * index it requires — {@see add_unique_index()}. For a view that anything reads
 * during business hours, that trade is usually already made for you.
 */
class MaterializedView extends View
{
    /** @var array<string, array<int, string>> index name => columns */
    protected array $unique_indexes = [];

    /** Whether the view is created empty, to be filled by the first refresh. */
    protected bool $with_no_data = false;

    public function __construct(string $name, array $columns, string $dialect = 'postgresql')
    {
        if ($dialect !== 'postgresql' && $dialect !== 'supabase') {
            throw new InvalidArgumentException(
                'Materialized views are PostgreSQL-only; "' . $dialect . '" has none. '
                . 'On MySQL or SQLite the equivalent is a real table plus a job that refills it, '
                . 'which is a different thing and worth writing as one.'
            );
        }

        parent::__construct($name, $columns, $dialect);
    }

    /**
     * A unique index on the view — what `REFRESH … CONCURRENTLY` requires.
     *
     * It must cover columns that are unique across the whole view and carry no
     * `WHERE`; PostgreSQL checks this itself when you ask for a concurrent
     * refresh, and says so clearly.
     *
     * @param array<int, string> $columns
     */
    public function add_unique_index(string $name, array $columns): self
    {
        if ($columns === []) {
            throw new InvalidArgumentException('A unique index needs at least one column.');
        }

        $this->unique_indexes[$name] = $columns;

        return $this;
    }

    /**
     * Create the view empty, and let the first refresh fill it.
     *
     * Useful when the query is expensive and the migration should not wait for
     * it. The cost is that **reading the view before that refresh is an error**,
     * not an empty result — so schedule the refresh, do not hope for it.
     */
    public function with_no_data(bool $enabled = true): self
    {
        $view               = clone $this;
        $view->with_no_data = $enabled;

        return $view;
    }

    public function is_deferred(): bool
    {
        return $this->with_no_data;
    }

    public function to_create_sql(bool $if_not_exists = false): string
    {
        return 'CREATE MATERIALIZED VIEW ' . ($if_not_exists ? 'IF NOT EXISTS ' : '')
            . $this->quoted_name() . $this->column_list()
            . ' AS ' . $this->definition_sql()
            . ($this->with_no_data ? ' WITH NO DATA' : '');
    }

    /**
     * `DROP` then `CREATE` — there is no `CREATE OR REPLACE` for these.
     *
     * Measured, not assumed: PostgreSQL 12 answers the `OR REPLACE` form with a
     * syntax error at `MATERIALIZED`.
     *
     * @return string[]
     */
    public function to_replace_sql(): array
    {
        return [
            $this->to_drop_sql(),
            $this->to_create_sql(),
        ];
    }

    public function to_drop_sql(): string
    {
        return 'DROP MATERIALIZED VIEW IF EXISTS ' . $this->quoted_name();
    }

    /**
     * `REFRESH MATERIALIZED VIEW [CONCURRENTLY] name`
     *
     * Concurrent refreshes need a unique index and a populated view. This does
     * not check either: PostgreSQL's own refusal names which one is missing, and
     * a second check here could only repeat it less accurately.
     */
    public function to_refresh_sql(bool $concurrently = false): string
    {
        return 'REFRESH MATERIALIZED VIEW ' . ($concurrently ? 'CONCURRENTLY ' : '') . $this->quoted_name();
    }

    /**
     * The index statements, unique ones included.
     *
     * A materialized view takes no constraints — no `PRIMARY KEY`, no `UNIQUE`
     * in the definition — so uniqueness here is an index and nothing else.
     *
     * @return array<int, string>
     */
    public function get_index_sql(): array
    {
        $statements = parent::get_index_sql();

        foreach ($this->unique_indexes as $name => $columns) {
            $columns_sql = array_map(fn(string $column): string => $this->quote_identifier($column), $columns);

            $statements[] = 'CREATE UNIQUE INDEX ' . $this->quote_identifier($name)
                . ' ON ' . $this->quoted_name() . ' (' . implode(', ', $columns_sql) . ')';
        }

        return $statements;
    }
}
