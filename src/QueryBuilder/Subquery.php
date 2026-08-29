<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Subquery
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\QueryBuilder;

use Italix\Orm\Operators\SQLExpression;

/**
 * A SELECT used inside another statement.
 *
 *     use function Italix\Orm\Operators\{in_, eq, sub};
 *
 *     $recent = sub(
 *         QueryBuilder::select($orders, [$orders->customer_id])
 *             ->where(gte($orders->placed_dt, '2026-01-01'))
 *     );
 *
 *     QueryBuilder::select($customers)->where(in_($customers->id, $recent));
 *
 * ## Why this is a type and not a string
 *
 * Without it the only way to write `WHERE id IN (SELECT …)` was `raw()`, which
 * takes a string and interpolates nothing — so every value inside the subquery
 * had to be pasted into SQL by hand. That is the exact moment a query builder
 * stops protecting anybody, and it is reached by the most ordinary question a
 * schema can be asked.
 *
 * A `Subquery` is an {@see SQLExpression}, so it composes with what already
 * exists rather than needing new operators: `Comparison` already wraps an
 * expression in parentheses, which makes `eq($col, sub(…))` a scalar subquery
 * with no change to that class.
 *
 * ## Parameters
 *
 * The inner query's bindings are appended to the outer array **at the position
 * the subquery appears in the SQL**, because the two are built in one pass and
 * placeholders are positional. Getting this wrong does not produce an error: it
 * produces a query that runs and answers about the wrong rows.
 *
 * ## Dialect
 *
 * The inner builder is told which dialect it is being rendered for, rather than
 * keeping whichever one it was constructed with. A subquery built from a MySQL
 * table and embedded in a PostgreSQL statement would otherwise emit backticks
 * and `?` inside a statement using double quotes and `$1`.
 */
final class Subquery implements SQLExpression
{
    /** @var QueryBuilder */
    private $query;

    /** @var string|null */
    private $alias;

    public function __construct(QueryBuilder $query, ?string $alias = null)
    {
        $this->query = $query;
        $this->alias = $alias;
    }

    /**
     * Name this subquery, for use in a FROM or a JOIN.
     *
     * Ignored where SQL has no place for it — inside `IN (…)` or `EXISTS (…)`
     * an alias is a syntax error, so it is not emitted there.
     */
    public function alias(string $name): self
    {
        $clone = clone $this;
        $clone->alias = $name;

        return $clone;
    }

    public function get_alias(): ?string
    {
        return $this->alias;
    }

    /** The dialect the wrapped query was built for. */
    public function dialect(): string
    {
        return $this->query->dialect();
    }

    /** The wrapped builder, for callers that need to inspect or extend it. */
    public function query(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * The inner SELECT, without surrounding parentheses.
     *
     * Callers add them, because where they go differs: `IN (…)` needs one pair,
     * a derived table needs `(…) AS name`, and a scalar comparison gets its
     * parentheses from {@see \Italix\Orm\Operators\Comparison}.
     */
    public function to_sql(string $dialect, array &$params): string
    {
        return $this->query->for_dialect($dialect)->to_sql($params);
    }

    /** The inner SELECT wrapped in parentheses, with the alias when there is one. */
    public function to_derived_table(string $dialect, array &$params): string
    {
        $sql = '(' . $this->to_sql($dialect, $params) . ')';

        if ($this->alias === null) {
            return $sql;
        }

        return $sql . ' AS ' . ($dialect === 'mysql'
            ? '`' . str_replace('`', '``', $this->alias) . '`'
            : '"' . str_replace('"', '""', $this->alias) . '"');
    }
}
