<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Window functions
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Operators;

use InvalidArgumentException;
use Italix\Orm\Schema\Column;

/**
 * A function call: `UPPER(name)`, `LAG(total, 1, 0)`, `ROW_NUMBER()`.
 *
 * The name is checked to be an identifier rather than accepted as text, because
 * the one thing this type must not become is another way to paste SQL into a
 * statement — that is what {@see raw()} is for, where the caller can see they
 * are doing it.
 *
 * Arguments are columns, expressions, or values; values are bound.
 */
final class SqlFunction implements SQLExpression
{
    use SqlHelper;

    protected string $name;

    /** @var array<int, mixed> */
    protected array $arguments;

    /** @param array<int, mixed> $arguments columns, expressions or values */
    public function __construct(string $name, array $arguments = [])
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                'A SQL function name is an identifier; "' . $name . '" is not one. '
                . 'Use raw() if you mean to write SQL by hand.'
            );
        }

        $this->name      = strtoupper($name);
        $this->arguments = $arguments;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $rendered = [];

        foreach ($this->arguments as $argument) {
            if ($argument instanceof Column || $argument instanceof SQLExpression) {
                $rendered[] = $this->render_operand($argument, $dialect, $params);
                continue;
            }

            $params[]   = $argument;
            $rendered[] = $this->get_placeholder(count($params), $dialect);
        }

        return $this->name . '(' . implode(', ', $rendered) . ')';
    }
}

/**
 * `<function>(…) OVER (PARTITION BY … ORDER BY … ROWS BETWEEN … AND …)`
 *
 *     row_number()->partition_by($orders->customer_id)->order_by(desc($orders->placed_dt))->as('n')
 *     sql_sum($orders->total)->over()->order_by($orders->placed_dt)->as('running_total')
 *
 * ## What this makes possible
 *
 * "The three most recent orders of every customer", "each row next to its
 * group's total", "the gap since the previous payment" — questions with no
 * answer in plain `GROUP BY`, because grouping collapses the rows you still
 * want to see.
 *
 * ## Where it may appear
 *
 * In `SELECT` and in `ORDER BY`. **Not in `WHERE` or `HAVING`**: those are
 * evaluated before the window is, in every engine, so filtering on one means
 * computing it in a subquery or CTE and filtering outside:
 *
 *     $ranked = sub($dm->select([$orders->id, row_number()->partition_by(…)->as('n')])->from($orders));
 *     $dm->select()->from($ranked->alias('ranked'))->where(lte($n_column, 3));
 *
 * That is not a limitation of this package — it is the order SQL evaluates in,
 * and an ORM that hid it would be hiding the reason the query is shaped that
 * way.
 *
 * ## Server versions
 *
 * Window functions need **SQLite 3.25**, **MySQL 8.0**, **MariaDB 10.2** or any
 * PostgreSQL. That is a floor this package does not otherwise impose, which is
 * why nothing here emits one on your behalf — relation loading still caps
 * children in PHP. Reach for these when you know your server.
 */
final class WindowExpression implements SQLExpression
{
    use SqlHelper;

    protected SQLExpression $function;

    /** @var array<int, Column|SQLExpression> */
    protected array $partition_by = [];

    /** @var array<int, mixed> Column, OrderDirection or SQLExpression */
    protected array $order_by = [];

    protected ?string $frame = null;

    protected ?string $alias = null;

    public function __construct(SQLExpression $function)
    {
        $this->function = $function;
    }

    /** `PARTITION BY a, b` — the groups the function restarts in. */
    public function partition_by(Column|SQLExpression ...$columns): self
    {
        $window               = clone $this;
        $window->partition_by = array_merge($window->partition_by, $columns);

        return $window;
    }

    /**
     * `ORDER BY …` **inside** the window.
     *
     * This is what decides "previous", "first" and "running" — it is not the
     * statement's own `ORDER BY`, and setting one does not set the other.
     *
     * @param Column|SQLExpression|OrderDirection ...$columns
     */
    public function order_by(...$columns): self
    {
        $window           = clone $this;
        $window->order_by = array_merge($window->order_by, $columns);

        return $window;
    }

    /** `ROWS BETWEEN <start> AND <end>` — a frame counted in rows. */
    public function rows_between(string $start, string $end): self
    {
        return $this->with_frame('ROWS', $start, $end);
    }

    /** `RANGE BETWEEN <start> AND <end>` — a frame counted in values of the `ORDER BY`. */
    public function range_between(string $start, string $end): self
    {
        return $this->with_frame('RANGE', $start, $end);
    }

    /** The name this expression takes in the result set. */
    public function as(string $alias): self
    {
        $window        = clone $this;
        $window->alias = $alias;

        return $window;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        // The function's own arguments bind before the window's do, which is
        // also the order they appear in: placeholders are positional.
        $sql = $this->function->to_sql($dialect, $params) . ' OVER (';

        $inside = [];

        if ($this->partition_by !== []) {
            $columns = [];

            foreach ($this->partition_by as $column) {
                $columns[] = $this->render_operand($column, $dialect, $params);
            }

            $inside[] = 'PARTITION BY ' . implode(', ', $columns);
        }

        if ($this->order_by !== []) {
            $terms = [];

            foreach ($this->order_by as $term) {
                $terms[] = $this->render_order_term($term, $dialect, $params);
            }

            $inside[] = 'ORDER BY ' . implode(', ', $terms);
        }

        if ($this->frame !== null) {
            $inside[] = $this->frame;
        }

        $sql .= implode(' ', $inside) . ')';

        if ($this->alias !== null) {
            $sql .= ' AS ' . $this->quote_identifier($this->alias, $dialect);
        }

        return $sql;
    }

    // -------------------------------------------------------------------------

    /** @param Column|SQLExpression|OrderDirection $term */
    protected function render_order_term($term, string $dialect, array &$params): string
    {
        if ($term instanceof OrderDirection) {
            return $this->render_operand($term->column, $dialect, $params) . ' ' . $term->direction;
        }

        if ($term instanceof Column || $term instanceof SQLExpression) {
            return $this->render_operand($term, $dialect, $params);
        }

        throw new InvalidArgumentException(
            'A window ORDER BY takes a column, an expression, or asc()/desc(); '
            . (is_object($term) ? get_class($term) : gettype($term)) . ' given.'
        );
    }

    /**
     * A frame bound, checked rather than interpolated.
     *
     * `UNBOUNDED PRECEDING`, `CURRENT ROW`, `UNBOUNDED FOLLOWING`, or `N
     * PRECEDING` / `N FOLLOWING`. Anything else is refused: a frame is the one
     * place in a window where free text would let arbitrary SQL through, and
     * there are only five shapes worth having.
     */
    protected function with_frame(string $kind, string $start, string $end): self
    {
        $start_sql = $this->frame_bound($start);
        $end_sql   = $this->frame_bound($end);

        // Neither is ever legal, in any engine, and saying so here beats a
        // syntax error from a server about a clause the caller did not type.
        if ($start_sql === 'UNBOUNDED FOLLOWING' || $end_sql === 'UNBOUNDED PRECEDING') {
            throw new InvalidArgumentException(
                'A frame runs from earlier to later: "' . $start_sql . ' AND ' . $end_sql
                . '" is empty by construction.'
            );
        }

        $window        = clone $this;
        $window->frame = $kind . ' BETWEEN ' . $start_sql . ' AND ' . $end_sql;

        return $window;
    }

    protected function frame_bound(string $bound): string
    {
        $normalised = strtoupper(trim(preg_replace('/\s+/', ' ', $bound) ?? ''));

        // Qualified: this namespace defines its own in_array() operator, and an
        // unqualified call would reach that one instead of PHP's.
        if (\in_array($normalised, ['UNBOUNDED PRECEDING', 'UNBOUNDED FOLLOWING', 'CURRENT ROW'], true)) {
            return $normalised;
        }

        if (preg_match('/^(\d+) (PRECEDING|FOLLOWING)$/', $normalised, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2];
        }

        throw new InvalidArgumentException(
            'A frame bound is "unbounded preceding", "unbounded following", "current row", '
            . '"N preceding" or "N following"; "' . $bound . '" is none of them.'
        );
    }
}
