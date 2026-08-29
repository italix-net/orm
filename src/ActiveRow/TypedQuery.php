<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Typed, fluent query for an ActiveRow class
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\ActiveRow;

use Italix\Orm\Operators\SQLExpression;
use Italix\Orm\Relations\TableQuery;
use Italix\Orm\Schema\Column;

/**
 * The fluency `Persistable::find_all(['where' => …, 'with' => …])` never had.
 *
 * `TableQuery` (`$dm->query_table($table)`) was always chainable —
 * `->where()->with()->order_by()`, clone-based, one call per fact — but
 * returns plain arrays. `find_all()` returns `ActiveRow` instances, but only
 * by taking every fact as one options array assembled up front rather than
 * built up call by call. Reading `find_all()`'s own body shows why both
 * exist without one replacing the other: it *is* a `TableQuery`, assembled
 * from the options array key by key, then wrapped at the end. This class is
 * the same assembly, exposed as the chain instead of hidden inside a loop
 * over `isset($options[...])`.
 *
 * Every fluent method here does nothing but forward to the identically-named
 * one on `TableQuery` and wrap the class name alongside it — there is no
 * second query-building implementation to keep in sync with the first.
 *
 * Immutable, like `TableQuery` and `QueryBuilder` before it: every call
 * clones rather than mutates, so `$base = UserRow::query()->with([...]);`
 * followed by two different `$base->where(...)` calls does not have the
 * second overwrite what the first was still holding.
 *
 * @example
 * UserRow::query()
 *     ->where(eq($users->role, 'admin'))
 *     ->order_by(desc($users->id))
 *     ->with(['posts' => true])
 *     ->limit(10)
 *     ->find_many();   // array<UserRow>, not array<array>
 */
final class TypedQuery
{
    private TableQuery $query;

    /** @var class-string<ActiveRow> */
    private string $class;

    /** @param class-string<ActiveRow> $class */
    public function __construct(TableQuery $query, string $class)
    {
        $this->query = $query;
        $this->class = $class;
    }

    public function where(SQLExpression $condition): self
    {
        $copy = clone $this;
        $copy->query = $this->query->where($condition);

        return $copy;
    }

    /** @param array<Column|string> $columns */
    public function columns(array $columns): self
    {
        $copy = clone $this;
        $copy->query = $this->query->columns($columns);

        return $copy;
    }

    /**
     * @param mixed ...$columns
     *
     * Calling this more than once accumulates — `TableQuery::order_by()`
     * appends to the list rather than replacing it — unlike `where()`,
     * `limit()`, `offset()`, `columns()` and `with()`, which each replace
     * whatever the same call set before. Order among *different* methods in
     * the chain never matters — each sets its own independent fact — but
     * repeating the *same* one is not the same story for every method, and
     * that asymmetry lives in `TableQuery`, not introduced here.
     */
    public function order_by(...$columns): self
    {
        $copy = clone $this;
        $copy->query = $this->query->order_by(...$columns);

        return $copy;
    }

    public function limit(int $limit): self
    {
        $copy = clone $this;
        $copy->query = $this->query->limit($limit);

        return $copy;
    }

    public function offset(int $offset): self
    {
        $copy = clone $this;
        $copy->query = $this->query->offset($offset);

        return $copy;
    }

    /** @param array $relations Same shape TableQuery::with() and find_all()['with'] already take. */
    public function with(array $relations): self
    {
        $copy = clone $this;
        $copy->query = $this->query->with($relations);

        return $copy;
    }

    /**
     * Include soft-deleted rows too — undoing the automatic
     * `WHERE deleted_col IS NULL` a table with `Table::soft_deletes()`
     * declared otherwise carries on every read. A no-op on a table with no
     * soft-delete column.
     */
    public function with_trashed(): self
    {
        $copy = clone $this;
        $copy->query = $this->query->with_trashed();

        return $copy;
    }

    /**
     * Skip global scopes registered with `DataManager::add_global_scope()`
     * — every one of them with no arguments, or only the named ones. A
     * no-op on a table with none registered.
     *
     * @param array<int, string> $names
     */
    public function without_scopes(array $names = []): self
    {
        $copy = clone $this;
        $copy->query = $this->query->without_scopes($names);

        return $copy;
    }

    /**
     * Every matching row, wrapped.
     *
     * `find_many()` names the underlying `TableQuery` method it forwards to;
     * `find_all()` and `all()` name the two conventions `Persistable`'s own
     * static finders and, further back, Laravel's `Model::all()` already
     * use elsewhere in this codebase. All three call the same code — pick
     * whichever reads better where you are writing, not which is "correct".
     *
     * @return array<int, ActiveRow>
     */
    public function find_many(): array
    {
        $class = $this->class;

        return $class::wrap_many($this->query->find_many());
    }

    /** Alias for {@see find_many()}. */
    public function find_all(): array
    {
        return $this->find_many();
    }

    /** Alias for {@see find_many()}. */
    public function all(): array
    {
        return $this->find_many();
    }

    /**
     * The first matching row, wrapped, or `null`.
     *
     * `find_first()`, `find_one()`, `first()` and `one()` are all this same
     * call — the same four-way choice as `find_many()`/`find_all()`/`all()`
     * above, on the singular side. `one()` is the short form to reach for
     * beside `all()`; the longer names are there for whichever convention a
     * reader already knows.
     *
     * @return ActiveRow|null
     */
    public function find_first(): ?ActiveRow
    {
        $row = $this->query->find_first();

        if ($row === null) {
            return null;
        }

        $class = $this->class;

        return $class::wrap($row);
    }

    /** Alias for {@see find_first()} — TableQuery::find_one() is already exactly this. */
    public function find_one(): ?ActiveRow
    {
        return $this->find_first();
    }

    /** Alias for {@see find_first()}. */
    public function first(): ?ActiveRow
    {
        return $this->find_first();
    }

    /** Alias for {@see find_first()} — the short form, matching `all()` on the plural side. */
    public function one(): ?ActiveRow
    {
        return $this->find_first();
    }

    /**
     * The row with this primary key, wrapped, or `null`.
     *
     * @param mixed $id
     * @return ActiveRow|null
     */
    public function find($id): ?ActiveRow
    {
        $row = $this->query->find($id);

        if ($row === null) {
            return null;
        }

        $class = $this->class;

        return $class::wrap($row);
    }
}
