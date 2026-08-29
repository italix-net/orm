<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Query Builder
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\QueryBuilder;

use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\View;
use Italix\Orm\Schema\Column;
use Italix\Orm\Operators\SQLExpression;
use InvalidArgumentException;
use Italix\Orm\Operators\OrderDirection;
use Italix\Orm\Cache\QueryCache;
use Italix\Orm\Hooks\HookRegistry;
use Italix\Orm\Locking\OptimisticLockException;
use Italix\Orm\Profiling\QueryLog;
use Italix\Orm\Scopes\ScopeRegistry;
use PDO;

use function Italix\Orm\Operators\{and_, gt, is_null, raw};

/**
 * Query Builder - builds and executes SQL queries.
 */
class QueryBuilder
{
    /** @var string Query type (SELECT, INSERT, UPDATE, DELETE) */
    protected string $type = '';
    
    /** @var Table|null Target table */
    protected ?Table $table = null;
    
    /** @var array Columns to select */
    protected array $select_columns = [];
    
    /** @var SQLExpression|null WHERE condition */
    protected ?SQLExpression $where_condition = null;
    
    /** @var array ORDER BY clauses */
    protected array $order_by = [];
    
    /** @var int|null LIMIT value */
    protected ?int $limit_value = null;
    
    /** @var int|null OFFSET value */
    protected ?int $offset_value = null;
    
    /** @var array Values for INSERT */
    protected array $insert_values = [];
    
    /** @var array Values for UPDATE */
    protected array $update_values = [];

    /** A DELETE that bypasses `Table::soft_deletes()` and removes the row for real. See `force()`. */
    protected bool $force_delete_flag = false;

    /** A SELECT that includes soft-deleted rows too. See `with_trashed()`. */
    protected bool $with_trashed_flag = false;

    /** @var mixed The version value an UPDATE's WHERE must still match. See expect_version(). */
    protected $expected_version = null;

    /** @var array JOIN clauses */
    protected array $join_clauses = [];
    
    /** @var array GROUP BY columns */
    protected array $group_by_columns = [];

    /** @var array<int, array{name: string, query: Subquery, columns: string[]}> CTEs */
    protected array $ctes = [];

    /** @var bool whether any CTE is recursive */
    protected bool $cte_recursive = false;

    /** @var array<int, array{operator: string, query: QueryBuilder}> UNION / INTERSECT / EXCEPT */
    protected array $set_operations = [];

    /** @var Subquery|null a derived table used in place of a named one */
    protected ?Subquery $from_subquery = null;

    /** @var bool SELECT DISTINCT */
    protected bool $distinct = false;

    /** @var Column[] SELECT DISTINCT ON (…) — PostgreSQL only */
    protected array $distinct_on = [];
    
    /** @var SQLExpression|null HAVING condition */
    protected ?SQLExpression $having_condition = null;
    
    /** @var bool Use RETURNING clause */
    protected bool $return_values = false;
    
    /** @var array Columns to return */
    protected array $returning_columns = [];
    
    /** @var array|null ON CONFLICT target columns */
    protected ?array $conflict_target = null;
    
    /** @var array|null ON CONFLICT DO UPDATE SET values */
    protected ?array $conflict_update = null;
    
    /** @var bool ON CONFLICT DO NOTHING */
    protected bool $conflict_do_nothing = false;
    
    /** @var string Database dialect */
    protected string $dialect;
    
    /** @var PDO|null Database connection */
    protected ?PDO $connection = null;

    /** @var QueryCache|null Where answered queries are kept, when the manager has one */
    protected ?QueryCache $query_cache = null;

    /** @var QueryLog|null Where statements report what they cost */
    protected ?QueryLog $query_log = null;

    /** @var HookRegistry|null Lifecycle hooks; null for a builder with no DataManager behind it */
    protected ?HookRegistry $hooks = null;

    /** @var ScopeRegistry|null Global scopes; null for a builder with no DataManager behind it */
    protected ?ScopeRegistry $scopes = null;

    /** All global scopes disabled for this query. See without_scopes(). */
    protected bool $scopes_disabled = false;

    /** @var array<int, string> Specific scope names disabled for this query. See without_scopes(). */
    protected array $disabled_scope_names = [];

    /** @var int|null Seconds this query's answer may be reused; null means "not cached" */
    protected ?int $cache_ttl_n = null;

    /** @var array<int, string> Tables this query reads that the SQL does not name */
    protected array $cache_tables = [];

    /**
     * Create a new QueryBuilder
     */
    public function __construct(string $dialect = 'mysql')
    {
        $this->dialect = $dialect;
    }

    /**
     * Set the database connection
     */
    public function set_connection(PDO $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Start a SELECT query
     * 
     * @param array|null $columns Columns to select, null for all (*)
     */
    public function select(?array $columns = null): self
    {
        $builder = clone $this;
        $builder->type = 'SELECT';
        $builder->select_columns = $columns ?? [];
        return $builder;
    }

    /**
     * Set the table to query from.
     *
     * A {@see Subquery} is accepted in place of a table — a derived table. It
     * must carry an alias, because every dialect here requires one and the
     * error a server gives for its absence names a line number rather than a
     * cause.
     *
     * @param Table|Subquery $source
     */
    public function from($source): self
    {
        $builder = clone $this;

        if ($source instanceof Subquery) {
            if ($source->get_alias() === null) {
                throw new InvalidArgumentException(
                    'A subquery used as a table needs an alias: sub($query)->alias(\'name\'). '
                    . 'Every dialect requires one, and the error without it does not say so.'
                );
            }

            $builder->from_subquery = $source;
            $builder->table = null;

            // Take the dialect from the query being wrapped. Without this a
            // builder constructed directly — `new QueryBuilder()` defaults to
            // MySQL — kept that default and emitted backticks around a SQLite
            // derived table. The statement is syntactically fine and fails at
            // the server, or worse, quietly matches nothing.
            $builder->dialect = $source->dialect();

            return $builder;
        }

        if (!$source instanceof Table) {
            throw new InvalidArgumentException(
                'from() takes a Table or a Subquery; ' . (is_object($source) ? get_class($source) : gettype($source)) . ' given.'
            );
        }

        $builder->table = $source;
        $builder->from_subquery = null;
        $builder->dialect = $source->get_dialect();

        return $builder;
    }

    /**
     * `SELECT DISTINCT` — one row per distinct combination of the selected columns.
     *
     *     $dm->select([$orders->customer_id])->from($orders)->distinct()
     *
     * Distinct over **everything selected**, which is the part people mean
     * differently from what SQL does: adding a column to the select list
     * changes which rows are considered duplicates. That is why `distinct_on()`
     * exists separately.
     */
    public function distinct(bool $enabled = true): self
    {
        $builder = clone $this;
        $builder->distinct = $enabled;

        return $builder;
    }

    /**
     * `SELECT DISTINCT ON (col, …)` — the first row of each group.
     *
     *     ->distinct_on([$orders->customer_id])->order_by($orders->customer_id, desc($orders->placed_dt))
     *
     * PostgreSQL only, and refused elsewhere rather than approximated. MySQL
     * and SQLite have no equivalent; the usual rewrite is a window function or
     * a correlated subquery, and which one is right depends on the query. A
     * builder that silently produced plain `DISTINCT` here would return a
     * different number of rows on a different server.
     *
     * "The first row" is decided by `ORDER BY`, whose leading expressions must
     * match these columns — a rule PostgreSQL enforces itself, so it is left to
     * say so rather than duplicated here where it could drift.
     *
     * @param Column[] $columns
     */
    public function distinct_on(array $columns): self
    {
        if (!$this->is_postgres_dialect()) {
            throw new InvalidArgumentException(
                'DISTINCT ON is PostgreSQL-only; this query is for "' . $this->dialect . '". '
                . 'Rewrite it with a window function (row_number() ranked in a subquery, then filter '
                . 'outside) or a correlated subquery — which one is right depends on the query, so it '
                . 'is not done for you.'
            );
        }

        $builder = clone $this;
        $builder->distinct = true;
        $builder->distinct_on = $columns;

        return $builder;
    }

    private function is_postgres_dialect(): bool
    {
        return $this->dialect === 'postgresql' || $this->dialect === 'supabase';
    }

    /**
     * A common table expression: `WITH name AS (SELECT …)`.
     *
     *     $builder
     *         ->with_cte('recent', sub($recent_orders))
     *         ->from($recent)                       // referenced like a table
     *
     * Several may be declared; they are emitted in the order given, which is
     * also the order in which one may refer to another.
     *
     * The point is not brevity. A query written as three CTEs reads top to
     * bottom in the order the work happens; the same query as nested
     * subqueries reads inside out, and the version somebody has to change six
     * months later is the one they can follow.
     *
     * @param string[] $columns optional explicit column list for the CTE
     */
    public function with_cte(string $name, Subquery $query, array $columns = []): self
    {
        $builder = clone $this;
        $builder->ctes[] = ['name' => $name, 'query' => $query, 'columns' => $columns];

        return $builder;
    }

    /**
     * A recursive CTE: `WITH RECURSIVE name AS (anchor UNION ALL step)`.
     *
     * The query given must already be the whole recursive body — the anchor
     * term, a `UNION ALL`, and the step that refers back to `name`. This method
     * does not assemble that for you, because the shape of the recursion is the
     * part only the caller knows:
     *
     *     $tree = sub(
     *         QueryBuilder::select($nodes, [...])->where(is_null($nodes->parent_id))
     *             ->union_all(QueryBuilder::select($nodes, [...])
     *                 ->inner_join($named_cte_table, eq(...)))
     *     );
     *
     *     $builder->with_recursive('tree', $tree)->from($tree_table);
     *
     * `RECURSIVE` is a property of the whole `WITH` clause, not of one term, so
     * declaring any recursive CTE marks the clause — which is what SQL requires.
     *
     * @param string[] $columns
     */
    public function with_recursive(string $name, Subquery $query, array $columns = []): self
    {
        $builder = $this->with_cte($name, $query, $columns);
        $builder->cte_recursive = true;

        return $builder;
    }

    /** `UNION` — rows from both, duplicates removed. */
    public function union(QueryBuilder $query): self
    {
        return $this->add_set_operation('UNION', $query);
    }

    /** `UNION ALL` — rows from both, duplicates kept. Cheaper: nothing is sorted. */
    public function union_all(QueryBuilder $query): self
    {
        return $this->add_set_operation('UNION ALL', $query);
    }

    /** `INTERSECT` — rows in both. */
    public function intersect(QueryBuilder $query): self
    {
        return $this->add_set_operation('INTERSECT', $query);
    }

    /** `EXCEPT` — rows in this one and not the other. */
    public function except(QueryBuilder $query): self
    {
        return $this->add_set_operation('EXCEPT', $query);
    }

    /**
     * Attach a branch, refusing the combinations that are not portable here.
     *
     * `ORDER BY` and `LIMIT` on a *branch* are refused rather than emitted.
     * In standard SQL they belong to the whole compound and must follow the
     * last branch; a dialect that accepts them inside one either parenthesises
     * (MySQL, PostgreSQL) or rejects the statement (SQLite). Emitting them and
     * hoping is how a query returns different rows on the developer's SQLite
     * and the server's MySQL.
     */
    private function add_set_operation(string $operator, QueryBuilder $query): self
    {
        if ($query->type !== 'SELECT') {
            throw new InvalidArgumentException(
                "{$operator} combines SELECT statements; the branch given is a {$query->type}."
            );
        }

        if (!empty($query->order_by) || $query->limit_value !== null || $query->offset_value !== null) {
            throw new InvalidArgumentException(
                "ORDER BY, LIMIT and OFFSET belong to the whole {$operator}, not to one branch of it. "
                . 'Put them on the query you call ' . strtolower(str_replace(' ', '_', $operator)) . '() on.'
            );
        }

        if (!empty($query->set_operations)) {
            throw new InvalidArgumentException(
                'A branch may not carry set operations of its own; chain them on the outer query '
                . 'instead, which is what SQL evaluates anyway.'
            );
        }

        $builder = clone $this;
        $builder->set_operations[] = ['operator' => $operator, 'query' => $query];

        return $builder;
    }

    /**
     * Bind each value with its own type, rather than all of them as strings.
     *
     * `PDOStatement::execute($params)` binds everything as `PDO::PARAM_STR`.
     * On a real column that is invisible, because the engine coerces the string
     * to the column's type. On a column with **no declared type** — one coming
     * out of a derived table or a CTE — SQLite has nothing to coerce toward,
     * and in its ordering a TEXT is greater than every number. So:
     *
     *     SELECT * FROM (SELECT SUM(n) AS s FROM t) d WHERE d.s > ?    -- 100
     *
     * matches nothing at all, whatever the data. Measured, on `s = 245`.
     *
     * It returns no rows rather than raising, which is why this went unnoticed
     * until derived tables existed to reach it.
     *
     * @param array<int, mixed> $params
     */
    protected function bind_params(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $index => $value) {
            $type = PDO::PARAM_STR;

            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }

            // Floats have no PDO type of their own; bound as a string they are
            // coerced correctly, because a numeric literal is unambiguous.
            $stmt->bindValue($index + 1, $value, $type);
        }
    }

    /** The dialect this query renders for. */
    public function dialect(): string
    {
        return $this->dialect;
    }

    /**
     * Render this query for another dialect.
     *
     * A subquery keeps the dialect of the table it was built from, which is
     * wrong the moment it is embedded in a statement for a different one:
     * backticks and `?` would appear inside a statement using double quotes and
     * `$1`. Returns a copy — the caller's builder is not moved.
     */
    public function for_dialect(string $dialect): self
    {
        if ($dialect === $this->dialect) {
            return $this;
        }

        $builder = clone $this;
        $builder->dialect = $dialect;

        return $builder;
    }

    /**
     * Add WHERE condition
     */
    public function where(SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->where_condition = $condition;
        return $builder;
    }

    /**
     * Add ORDER BY clause
     * 
     * @param mixed ...$columns Column objects or OrderDirection objects
     */
    public function order_by(...$columns): self
    {
        $builder = clone $this;
        $builder->order_by = array_merge($builder->order_by, $columns);
        return $builder;
    }

    /**
     * Set LIMIT
     */
    public function limit(int $limit): self
    {
        $builder = clone $this;
        $builder->limit_value = $limit;
        return $builder;
    }

    /**
     * Set OFFSET
     */
    public function offset(int $offset): self
    {
        $builder = clone $this;
        $builder->offset_value = $offset;
        return $builder;
    }

    /**
     * A view is read, not written.
     *
     * Whether a view accepts a write depends on the engine, on the SELECT that
     * defines it and — on MySQL — on the algorithm the optimiser picked, so the
     * only portable answer is no. Refusing here names the line in the caller's
     * own code; letting it through produces a server error about a statement the
     * caller never wrote.
     */
    protected static function refuse_view(Table $table, string $operation): void
    {
        if ($table instanceof View) {
            throw new \RuntimeException(
                $operation . ' cannot be run against the view "' . $table->get_name()
                . '". A view is read-only here — write to the table(s) it selects from.'
            );
        }
    }

    /**
     * Start an INSERT query
     */
    public function insert(Table $table): self
    {
        self::refuse_view($table, 'INSERT');

        $builder = clone $this;
        $builder->type = 'INSERT';
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * Set values for INSERT
     * 
     * @param array|array[] $values Single row or multiple rows
     */
    public function values($values): self
    {
        $builder = clone $this;
        
        // Check if it's a single row or multiple rows
        if (isset($values[0]) && is_array($values[0])) {
            $builder->insert_values = $values;
        } else {
            $builder->insert_values = [$values];
        }
        
        return $builder;
    }

    /**
     * ON CONFLICT DO UPDATE (upsert)
     * 
     * @param array $target Conflict target columns (e.g., ['email'] or ['user_id', 'date'])
     * @param array $update Values to update on conflict
     */
    public function on_conflict_do_update(array $target, array $update): self
    {
        $builder = clone $this;
        $builder->conflict_target = $target;
        $builder->conflict_update = $update;
        $builder->conflict_do_nothing = false;
        return $builder;
    }

    /**
     * ON CONFLICT DO NOTHING
     * 
     * @param array|null $target Optional conflict target columns
     */
    public function on_conflict_do_nothing(?array $target = null): self
    {
        $builder = clone $this;
        $builder->conflict_target = $target;
        $builder->conflict_update = null;
        $builder->conflict_do_nothing = true;
        return $builder;
    }

    /**
     * Start an UPDATE query
     */
    public function update(Table $table): self
    {
        self::refuse_view($table, 'UPDATE');

        $builder = clone $this;
        $builder->type = 'UPDATE';
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * Set values for UPDATE
     */
    public function set(array $values): self
    {
        $builder = clone $this;
        $builder->update_values = $values;
        return $builder;
    }

    /**
     * Start a DELETE query
     */
    public function delete(Table $table): self
    {
        self::refuse_view($table, 'DELETE');

        $builder = clone $this;
        $builder->type = 'DELETE';
        $builder->table = $table;
        $builder->dialect = $table->get_dialect();
        return $builder;
    }

    /**
     * On a DELETE against a table with `Table::soft_deletes()` declared,
     * remove the row for real instead of the automatic `UPDATE`. A no-op on
     * a table with no soft-delete column, or on any other query type.
     */
    public function force(): self
    {
        $builder = clone $this;
        $builder->force_delete_flag = true;
        return $builder;
    }

    /**
     * On an UPDATE against a table with `Table::optimistic_locking()`
     * declared, require the row's current version to still be `$value` —
     * ANDed onto the WHERE alongside whatever `->where()` already set.
     * `execute()` throws `Locking\OptimisticLockException` if that leaves
     * zero rows affected, rather than silently overwriting a row someone
     * else already changed.
     *
     * Raises immediately, rather than silently checking nothing, if the
     * table has no `optimistic_locking()` column declared — the caller
     * asked for a guarantee this table cannot provide.
     */
    public function expect_version($value): self
    {
        if ($this->type !== 'UPDATE') {
            throw new \LogicException(
                'expect_version() only applies to update() — it names the version an UPDATE\'s WHERE '
                . 'must still match, and is meaningless on any other query type.'
            );
        }

        if ($this->table === null || !$this->table->has_optimistic_locking()) {
            throw new \LogicException(
                'expect_version() requires Table::optimistic_locking() to be declared on the target table.'
            );
        }

        $builder = clone $this;
        $builder->expected_version = $value;
        return $builder;
    }

    /**
     * On a SELECT against a table with `Table::soft_deletes()` declared,
     * include the soft-deleted rows too — undoing the automatic
     * `WHERE deleted_col IS NULL` every other SELECT against that table
     * carries. A no-op on a table with no soft-delete column.
     */
    public function with_trashed(): self
    {
        $builder = clone $this;
        $builder->with_trashed_flag = true;
        return $builder;
    }

    /**
     * The `WHERE` a SELECT actually runs with: `$this->where_condition`,
     * AND'd with `deleted_col IS NULL` when `Table::soft_deletes()` is
     * declared and `with_trashed()` was not called, further AND'd with
     * every active `DataManager::add_global_scope()` registered for this
     * table. `UPDATE`/`DELETE` do not go through this — soft-deleted rows
     * (and scoped-out ones) must still be reachable to correct or purge,
     * the same way Eloquent's own scopes only ever narrow a *read*.
     */
    protected function effective_where(): ?SQLExpression
    {
        $where = $this->where_condition;

        if ($this->table !== null && $this->table->has_soft_deletes() && !$this->with_trashed_flag) {
            $column_name = $this->table->soft_delete_column();
            $column      = $this->table->get_column($column_name);
            $not_deleted = is_null($column ?? raw($this->quote_identifier($column_name)));

            $where = $where === null ? $not_deleted : and_($where, $not_deleted);
        }

        if ($this->scopes !== null && $this->table !== null && !$this->scopes_disabled) {
            foreach ($this->scopes->for_table($this->table) as $name => $scope) {
                if (in_array($name, $this->disabled_scope_names, true)) {
                    continue;
                }

                $condition = $scope($this->table);
                $where     = $where === null ? $condition : and_($where, $condition);
            }
        }

        return $where;
    }

    /**
     * Add RETURNING clause (PostgreSQL, SQLite)
     * 
     * @param mixed ...$columns
     */
    public function returning(...$columns): self
    {
        $builder = clone $this;
        $builder->return_values = true;
        $builder->returning_columns = $columns;
        return $builder;
    }

    /**
     * Add LEFT JOIN
     */
    public function left_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'LEFT JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add INNER JOIN
     */
    public function inner_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'INNER JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add RIGHT JOIN
     */
    public function right_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'RIGHT JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add FULL OUTER JOIN
     */
    public function full_join(Table $table, SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'FULL OUTER JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $builder;
    }

    /**
     * Add CROSS JOIN
     */
    public function cross_join(Table $table): self
    {
        $builder = clone $this;
        $builder->join_clauses[] = [
            'type' => 'CROSS JOIN',
            'table' => $table,
            'condition' => null
        ];
        return $builder;
    }

    /**
     * Add GROUP BY
     * 
     * @param mixed ...$columns
     */
    public function group_by(...$columns): self
    {
        $builder = clone $this;
        $builder->group_by_columns = array_merge($builder->group_by_columns, $columns);
        return $builder;
    }

    /**
     * Add HAVING condition
     */
    public function having(SQLExpression $condition): self
    {
        $builder = clone $this;
        $builder->having_condition = $condition;
        return $builder;
    }

    /**
     * Build the SQL query
     * 
     * @param array &$params Parameter bindings
     * @return string SQL query
     */

    /**
     * The rows one at a time, instead of all of them in an array.
     *
     *     foreach ($dm->select()->from($orders)->cursor() as $row) { … }
     *
     * {@see execute()} calls `fetchAll()`, which builds a PHP array of every
     * row before the first one can be looked at. At a few thousand rows that is
     * nothing; at a few hundred thousand it is the reason the export died.
     *
     * What this controls is the PHP array. The driver may still buffer the
     * result on its own — PDO's MySQL driver does by default — so this is a
     * saving, not a guarantee of constant memory. For a table too large for the
     * driver's buffer as well, page through it with {@see chunk_by()}, which
     * asks for one bounded slice at a time and holds no cursor open between
     * them.
     *
     * The statement stays open for the whole loop, so do not run other queries
     * on this connection while it is running.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        if ($this->type !== 'SELECT') {
            throw new \RuntimeException('cursor() reads rows, so it is for SELECT; this is a ' . $this->type . '.');
        }

        if ($this->connection === null) {
            throw new \RuntimeException('No database connection set');
        }

        $params = [];
        $sql    = $this->to_sql($params);
        $stmt   = $this->connection->prepare($sql);

        $this->bind_params($stmt, $params);
        $stmt->execute();

        try {
            while (($row = $stmt->fetch()) !== false) {
                yield $row;
            }
        } finally {
            // A `break` in the caller's loop leaves the statement mid-result;
            // closing it here frees the server's side of it rather than waiting
            // for the object to fall out of scope.
            $stmt->closeCursor();
        }
    }

    /**
     * Hand every row to a callback, one at a time.
     *
     * The callback receives the row and its zero-based position. Returning
     * `false` from it stops the loop — the same signal Laravel uses, and the
     * only way to stop early without throwing.
     *
     * @param callable(array<string, mixed>, int): mixed $handler
     * @return int rows handed over
     */
    public function each(callable $handler): int
    {
        $seen = 0;

        foreach ($this->cursor() as $row) {
            // Counted before the verdict: a row the callback saw and then asked
            // to stop on was still handed over, and chunk() counts the same way.
            $verdict = $handler($row, $seen);
            $seen++;

            if ($verdict === false) {
                return $seen;
            }
        }

        return $seen;
    }

    /**
     * Page through the result with `LIMIT`/`OFFSET`, a chunk at a time.
     *
     *     $dm->select()->from($orders)->order_by($orders->id)->chunk(500, function (array $rows) { … });
     *
     * Each chunk is a separate statement, so nothing is held open between them
     * and the memory in play is one chunk.
     *
     * **An `ORDER BY` is required**, and refused if absent: without one a
     * database may return rows in any order it likes, and it need not be the
     * same order twice — so page 2 could repeat rows from page 1 and skip
     * others entirely. That failure is silent, and looks like data that went
     * missing on its own.
     *
     * Two things this cannot fix, both inherent to offset paging: rows inserted
     * or deleted between chunks shift the window, and `OFFSET n` makes the
     * server walk n rows to discard them, which gets slower as you go. When
     * either matters, use {@see chunk_by()}.
     *
     * @param callable(array<int, array<string, mixed>>, int): mixed $handler rows, and the chunk number from 1
     * @return int rows handed over
     */
    public function chunk(int $size, callable $handler): int
    {
        if ($size < 1) {
            throw new InvalidArgumentException('A chunk holds at least one row; ' . $size . ' asked for.');
        }

        if ($this->order_by === []) {
            throw new \RuntimeException(
                'chunk() needs an ORDER BY. Without one the server may return rows in a different '
                . 'order for each page, so a chunk can repeat rows the previous one had and skip '
                . 'others — silently. Order by something unique, or use chunk_by().'
            );
        }

        if ($this->limit_value !== null || $this->offset_value !== null) {
            throw new \RuntimeException(
                'chunk() sets LIMIT and OFFSET itself, and this query already has one. Take it off, '
                . 'or fetch that slice with execute().'
            );
        }

        $seen   = 0;
        $number = 1;

        while (true) {
            $rows = $this->limit($size)->offset(($number - 1) * $size)->execute();

            if ($rows === []) {
                return $seen;
            }

            $seen += count($rows);

            if ($handler($rows, $number) === false) {
                return $seen;
            }

            if (count($rows) < $size) {
                return $seen;
            }

            $number++;
        }
    }

    /**
     * Page through the result by key instead of by offset.
     *
     *     $dm->select()->from($orders)->chunk_by($orders->id, 500, function (array $rows) { … });
     *
     * Each page asks for `key > <last one seen>` and orders by the key, which is
     * the difference that matters: the server jumps straight to the position
     * with an index instead of counting rows to skip, so the last page costs
     * what the first one did. Rows inserted or deleted while it runs cannot
     * shift the window either — the key of the last row seen is not a position,
     * it is a place.
     *
     * The key must be **unique and ordered** — a primary key is the usual one.
     * A column with duplicates loses every row after the first at each boundary.
     *
     * The query keeps its own `WHERE`; it may not carry its own `ORDER BY`,
     * `LIMIT` or `OFFSET`, since this sets all three.
     *
     * @param callable(array<int, array<string, mixed>>, int): mixed $handler rows, and the page number from 1
     * @return int rows handed over
     */
    public function chunk_by(Column $key, int $size, callable $handler): int
    {
        if ($size < 1) {
            throw new InvalidArgumentException('A chunk holds at least one row; ' . $size . ' asked for.');
        }

        if ($this->order_by !== [] || $this->limit_value !== null || $this->offset_value !== null) {
            throw new \RuntimeException(
                'chunk_by() sets the ORDER BY, LIMIT and OFFSET itself — it orders by the key it '
                . 'pages on, and a different order would make its "> last seen" mean nothing.'
            );
        }

        $name   = $key->get_name();
        $seen   = 0;
        $number = 1;
        $last   = null;

        while (true) {
            $page = $this;

            if ($last !== null) {
                $bound = gt($key, $last);
                $page  = $page->where(
                    $this->where_condition === null ? $bound : and_($this->where_condition, $bound)
                );
            }

            $rows = $page->order_by($key)->limit($size)->execute();

            if ($rows === []) {
                return $seen;
            }

            $final = $rows[count($rows) - 1];

            if (!array_key_exists($name, $final)) {
                throw new \RuntimeException(
                    'chunk_by() pages on "' . $name . '", and the rows coming back do not contain it. '
                    . 'Select that column, or page on one you did select.'
                );
            }

            $last  = $final[$name];
            $seen += count($rows);

            if ($handler($rows, $number) === false) {
                return $seen;
            }

            if (count($rows) < $size) {
                return $seen;
            }

            $number++;
        }
    }

    public function to_sql(array &$params = []): string
    {
        switch ($this->type) {
            case 'SELECT':
                return $this->build_select($params);
            case 'INSERT':
                return $this->build_insert($params);
            case 'UPDATE':
                return $this->build_update($params);
            case 'DELETE':
                return $this->build_delete($params);
            default:
                throw new \RuntimeException("No query type specified");
        }
    }

    /**
     * Attach the cache answered queries are kept in.
     *
     * Set by `DataManager::use_query_cache()`, so every builder that manager
     * makes carries it — reads can be cached and writes can invalidate.
     */
    public function set_query_cache(?QueryCache $cache): self
    {
        $this->query_cache = $cache;

        return $this;
    }

    /** Where this builder's statements report what they cost. */
    public function set_query_log(?QueryLog $log): self
    {
        $this->query_log = $log;

        return $this;
    }

    /** Set by `DataManager::__construct()`, so every builder it makes carries the same registry. */
    public function set_hooks(?HookRegistry $hooks): self
    {
        $this->hooks = $hooks;

        return $this;
    }

    /**
     * Every hook registered for `$event` against this builder's target table
     * — `[]` when there is no `HookRegistry` at all (a `QueryBuilder` built
     * without a `DataManager`) or none were registered for this table/event,
     * which is the ordinary case.
     *
     * @return callable[]
     */
    protected function hooks_for(string $event): array
    {
        if ($this->hooks === null || $this->table === null) {
            return [];
        }

        return $this->hooks->for_table($this->table, $event);
    }

    /** Set by `DataManager::__construct()`, so every builder it makes carries the same registry. */
    public function set_scopes(?ScopeRegistry $scopes): self
    {
        $this->scopes = $scopes;

        return $this;
    }

    /**
     * Skip global scopes on this query — every one of them with no
     * arguments, or only the named ones. A no-op on a table with none
     * registered. Does not affect `Table::soft_deletes()`'s own filter,
     * which `effective_where()` applies independently of this — see
     * {@see with_trashed()} for that one.
     *
     * Repeated calls with names accumulate (`->without_scopes(['a'])
     * ->without_scopes(['b'])` skips both); a bare `->without_scopes()`
     * skips everything regardless of what an earlier call named.
     *
     * @param array<int, string> $names
     */
    public function without_scopes(array $names = []): self
    {
        $builder = clone $this;

        if ($names === []) {
            $builder->scopes_disabled = true;
        } else {
            $builder->disabled_scope_names = array_unique(array_merge($builder->disabled_scope_names, $names));
        }

        return $builder;
    }

    /**
     * What the server says it will do with this query.
     *
     *     $plan = $dm->select()->from($orders)->where(eq($orders->customer_id, 7))->explain();
     *
     *     $plan->has_full_scan();     // did it say it would read the whole table?
     *     $plan->rows();              // the server's own answer, unedited
     *     (string) $plan;             // …as text
     *
     * The plan is **not normalised**: three servers describe their work in three
     * vocabularies and flattening them into one would mean inventing a fourth
     * that none of them speaks. What is normalised is the single question worth
     * asking automatically — see {@see ExplainResult::has_full_scan()}.
     *
     * `EXPLAIN` alone never executes the statement. `$analyze` asks the server to
     * run it and report what really happened, which is why it is refused here on
     * anything but a `SELECT`: `EXPLAIN ANALYZE` on an `UPDATE` **performs the
     * update**, and finding that out from a production database is not a way to
     * learn it.
     */
    public function explain(bool $analyze = false): ExplainResult
    {
        if ($this->connection === null) {
            throw new \RuntimeException('No database connection set');
        }

        if ($analyze && $this->type !== 'SELECT') {
            throw new \RuntimeException(
                'EXPLAIN ANALYZE runs the statement, and this is a ' . $this->type
                . '. Ask for the plan without analyze, or run it and mean it.'
            );
        }

        $params = [];
        $sql    = $this->to_sql($params);
        $prefix = ExplainResult::prefix_for($this->dialect, $analyze);

        $stmt = $this->connection->prepare($prefix . ' ' . $sql);
        $this->bind_params($stmt, $params);
        $stmt->execute();

        return new ExplainResult($this->dialect, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Reuse this query's answer for a while.
     *
     *     $dm->select()->from($products)->where(…)->cached(300)->execute();
     *
     * The answer is forgotten when something writes to a table it reads
     * **through this package**, and in any case when the lifetime runs out. See
     * {@see QueryCache} for what that does and does not cover — raw SQL and other
     * processes are not seen, which is what the lifetime is for.
     *
     * `$tables` names tables the answer depends on that the statement does not
     * mention on its face: the ones inside a subquery or a CTE. Without them a
     * change there leaves this answer standing until it expires.
     *
     * @param int|null           $ttl_n  seconds, or null for the cache's default
     * @param array<int, string> $tables extra tables this answer depends on
     */
    public function cached(?int $ttl_n = null, array $tables = []): self
    {
        if ($this->type !== 'SELECT') {
            throw new \RuntimeException(
                'Only a SELECT has an answer worth keeping; this is a ' . $this->type . '.'
            );
        }

        if ($ttl_n !== null && $ttl_n < 1) {
            throw new InvalidArgumentException('A cached answer needs a lifetime; ' . $ttl_n . ' is not one.');
        }

        $builder                = clone $this;
        $builder->cache_ttl_n   = $ttl_n ?? -1;
        $builder->cache_tables  = $tables;

        return $builder;
    }

    /**
     * The tables whose contents this query's answer depends on.
     *
     * What the statement names on its face — the `FROM` and the joins — plus
     * anything `cached()` was told about. A table reached only through a
     * subquery is not here, and cannot be: see {@see QueryCache}.
     *
     * @return array<int, string>
     */
    public function tables_read(): array
    {
        $tables = $this->cache_tables;

        if ($this->table !== null) {
            $tables[] = $this->table->get_name();
        }

        foreach ($this->join_clauses as $join) {
            $tables[] = $join['table']->get_name();
        }

        return array_values(array_unique($tables));
    }

    /**
     * Execute the query
     * 
     * @return array|int Query results or affected rows
     */
    public function execute()
    {
        if ($this->connection === null) {
            throw new \RuntimeException("No database connection set");
        }

        if ($this->cache_ttl_n !== null) {
            return $this->execute_cached();
        }

        $params = [];
        $sql = $this->to_sql($params);

        $stmt = $this->connection->prepare($sql);
        $this->bind_params($stmt, $params);

        $started_t = microtime(true);
        $stmt->execute();

        if ($this->query_log !== null) {
            $this->query_log->record($sql, $params, microtime(true) - $started_t);
        }
        
        if ($this->type === 'SELECT') {
            return $this->decode_result($stmt->fetchAll());
        }

        $returned = ($this->return_values && $this->supports_returning()) ? $stmt->fetchAll() : null;

        // A write makes every cached answer about this table a guess. Moving the
        // table's generation is what retires them — cheap, and no more expensive
        // for a million entries than for one.
        if ($this->query_cache !== null && $this->table !== null) {
            $this->query_cache->invalidate($this->table->get_name());
        }

        if ($returned !== null) {
            $decoded = $this->decode_result($returned);
            $this->assert_version_matched(count($decoded));
            $this->fire_after_write_hook(count($decoded));

            return $decoded;
        }

        if ($this->type === 'INSERT') {
            $last_id = (int) $this->connection->lastInsertId();
            $this->fire_hooks('after_insert', [$this->insert_values, $last_id]);

            return $last_id;
        }

        $affected_n = $stmt->rowCount();
        $this->assert_version_matched($affected_n);
        $this->fire_after_write_hook($affected_n);

        return $affected_n;
    }

    /**
     * `->expect_version()` was called and the UPDATE it guarded affected no
     * rows — the row was deleted, or another write already moved the
     * version. Raised before any `after_update` hook fires: a lock failure
     * is not a successful write, whatever the hook is there to react to.
     */
    protected function assert_version_matched(int $affected_n): void
    {
        if ($this->expected_version !== null && $affected_n === 0) {
            throw new OptimisticLockException($this->table->get_name(), $this->expected_version);
        }
    }

    /**
     * `after_insert`/`after_update`/`after_delete` for the `RETURNING`
     * path and the plain `UPDATE`/`DELETE` path alike — dispatched by
     * `$this->type` rather than by which SQL actually ran, so a
     * `soft_deletes()` row (a DELETE that compiles to an UPDATE) still
     * fires `after_delete`, matching what the caller asked for rather than
     * the statement underneath it. `$affected_n` is `rowCount()` normally,
     * or the number of rows `RETURNING` handed back when that path is the
     * one in use — `lastInsertId()` is never called there, so an
     * `after_insert` fired from `RETURNING` gets `null` for the id instead.
     */
    protected function fire_after_write_hook(int $affected_n): void
    {
        switch ($this->type) {
            case 'INSERT':
                $this->fire_hooks('after_insert', [$this->insert_values, null]);
                break;

            case 'UPDATE':
                $this->fire_hooks('after_update', [$this->update_values, $affected_n]);
                break;

            case 'DELETE':
                $this->fire_hooks('after_delete', [$affected_n]);
                break;
        }
    }

    /** @param array<int, mixed> $args */
    protected function fire_hooks(string $event, array $args): void
    {
        foreach ($this->hooks_for($event) as $hook) {
            $hook(...$args);
        }
    }

    /**
     * A result set with every castable column (`Column::cast_as()`, or an
     * `enum()` backed by a `BackedEnum`) decoded — a no-op when `$this->table`
     * is unset (a derived-table `FROM`) or declares no cast at all, which is
     * the ordinary case and why this is called unconditionally rather than
     * only when a cast happens to be in play.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function decode_result(array $rows): array
    {
        if ($this->table === null) {
            return $rows;
        }

        return \Italix\Orm\Casts\Cast::decode_rows($this->table, $rows);
    }

    /**
     * The answer, from the cache when it is there.
     *
     * The key covers the statement, its values, and the generation of every
     * table it reads — so a write through this package retires it, and the TTL
     * bounds everything the package cannot see. See {@see QueryCache}.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function execute_cached(): array
    {
        if ($this->query_cache === null) {
            throw new \RuntimeException(
                'cached() was asked for, and this connection has no query cache. '
                . 'Give the manager one with $dm->use_query_cache(new QueryCache($cache)) — '
                . 'silently running it uncached would make the call a decoration.'
            );
        }

        $params = [];
        $sql    = $this->to_sql($params);
        $ttl_n  = $this->cache_ttl_n === -1 ? null : $this->cache_ttl_n;

        $connection = $this->connection;
        $binder     = function (\PDOStatement $stmt, array $params): void {
            $this->bind_params($stmt, $params);
        };

        // Decoded outside the remembered closure, uniformly, whether the
        // answer just came from the server or from the cache — a cast is
        // not something the cache backend needs to know how to store.
        return $this->decode_result($this->query_cache->remember(
            $sql,
            $params,
            $this->tables_read(),
            $ttl_n,
            function () use ($connection, $sql, $params, $binder): array {
                $stmt = $connection->prepare($sql);
                $binder($stmt, $params);

                $started_t = microtime(true);
                $stmt->execute();

                // Only on a miss: a cache hit never reaches the server, and
                // counting it as a query would hide exactly what the cache is
                // there to show.
                if ($this->query_log !== null) {
                    $this->query_log->record($sql, $params, microtime(true) - $started_t);
                }

                return $stmt->fetchAll();
            }
        ));
    }

    // ============================================
    // Protected Build Methods
    // ============================================

    /**
     * Build SELECT query
     */
    protected function build_select(array &$params): string
    {
        if ($this->table === null && $this->from_subquery === null) {
            throw new \RuntimeException("No table specified for SELECT");
        }
        
        $parts = [];

        // WITH comes first in the statement, so its bindings must come first in
        // the array. Placeholders are positional: build the clauses in any
        // other order and the query still runs, against the wrong values.
        $cte_sql = $this->build_ctes($params);

        if ($cte_sql !== '') {
            $parts[] = $cte_sql;
        }

        $parts[] = $this->build_select_body($params);

        // Set operations, before ORDER BY and LIMIT — which in standard SQL
        // apply to the whole compound and must follow the last branch. This is
        // why a branch carrying its own is refused at the point it is attached.
        foreach ($this->set_operations as $operation) {
            $branch = $operation['query']->for_dialect($this->dialect);
            $parts[] = $operation['operator'];
            $parts[] = $branch->build_select_body($params);
        }

        $parts[] = $this->build_select_tail($params);

        return trim(implode(' ', array_filter($parts, static function (string $part): bool {
            return $part !== '';
        })));
    }

    /**
     * `SELECT … FROM … JOIN … WHERE … GROUP BY … HAVING …`
     *
     * Everything a branch of a `UNION` may carry, and nothing it may not. Kept
     * separate from {@see build_select()} so a branch is emitted by the same
     * code as the first query rather than by a second implementation that
     * drifts from it.
     */
    protected function build_select_body(array &$params): string
    {
        if ($this->table === null && $this->from_subquery === null) {
            throw new \RuntimeException("No table specified for SELECT");
        }

        $parts = ['SELECT'];

        if ($this->distinct) {
            $parts[] = 'DISTINCT';

            if (!empty($this->distinct_on)) {
                $on = [];

                foreach ($this->distinct_on as $column) {
                    $on[] = $column instanceof Column ? $this->get_column_ref($column) : (string) $column;
                }

                $parts[] = 'ON (' . implode(', ', $on) . ')';
            }
        }
        
        // Columns
        if (empty($this->select_columns)) {
            $parts[] = '*';
        } else {
            $cols = [];
            foreach ($this->select_columns as $col) {
                if ($col instanceof Column) {
                    $cols[] = $this->get_column_ref($col);
                } elseif ($col instanceof SQLExpression) {
                    $cols[] = $col->to_sql($this->dialect, $params);
                } else {
                    $cols[] = (string)$col;
                }
            }
            $parts[] = implode(', ', $cols);
        }
        
        // FROM — a named table, or a derived one
        $parts[] = 'FROM ' . ($this->from_subquery !== null
            ? $this->from_subquery->to_derived_table($this->dialect, $params)
            : $this->quote_identifier($this->table->get_full_name()));
        
        // JOINs
        foreach ($this->join_clauses as $join) {
            $join_table = $this->quote_identifier($join['table']->get_full_name());
            $parts[] = $join['type'] . ' ' . $join_table;
            
            // CROSS JOIN doesn't have ON condition
            if ($join['condition'] !== null) {
                $parts[] = 'ON ' . $join['condition']->to_sql($this->dialect, $params);
            }
        }
        
        // WHERE — see effective_where() for the automatic soft-delete filter
        $where = $this->effective_where();
        if ($where !== null) {
            $parts[] = 'WHERE ' . $where->to_sql($this->dialect, $params);
        }

        // GROUP BY
        if (!empty($this->group_by_columns)) {
            $group_parts = [];
            foreach ($this->group_by_columns as $column) {
                if ($column instanceof Column) {
                    $group_parts[] = $this->get_column_ref($column);
                } else {
                    $group_parts[] = (string)$column;
                }
            }
            $parts[] = 'GROUP BY ' . implode(', ', $group_parts);
        }
        
        // HAVING
        if ($this->having_condition !== null) {
            $parts[] = 'HAVING ' . $this->having_condition->to_sql($this->dialect, $params);
        }
        
        return implode(' ', $parts);
    }

    /** `ORDER BY … LIMIT … OFFSET …` — of the whole statement, compound included. */
    protected function build_select_tail(array &$params): string
    {
        $parts = [];

        // ORDER BY
        if (!empty($this->order_by)) {
            $order_parts = [];
            foreach ($this->order_by as $order) {
                if ($order instanceof OrderDirection) {
                    if ($order->column instanceof Column) {
                        $order_parts[] = $this->get_column_ref($order->column) . ' ' . $order->direction;
                    } elseif ($order->column instanceof SQLExpression) {
                        $order_parts[] = $order->column->to_sql($this->dialect, $params) . ' ' . $order->direction;
                    }
                } elseif ($order instanceof Column) {
                    $order_parts[] = $this->get_column_ref($order);
                } elseif ($order instanceof SQLExpression) {
                    $order_parts[] = $order->to_sql($this->dialect, $params);
                } elseif (is_array($order) && isset($order['column'])) {
                    $col = $order['column'];
                    $dir = $order['direction'] ?? 'ASC';
                    if ($col instanceof Column) {
                        $order_parts[] = $this->get_column_ref($col) . ' ' . $dir;
                    }
                }
            }
            if (!empty($order_parts)) {
                $parts[] = 'ORDER BY ' . implode(', ', $order_parts);
            }
        }
        
        // LIMIT
        if ($this->limit_value !== null) {
            $parts[] = 'LIMIT ' . $this->limit_value;
        }
        
        // OFFSET
        if ($this->offset_value !== null) {
            $parts[] = 'OFFSET ' . $this->offset_value;
        }
        
        return implode(' ', $parts);
    }

    /**
     * `WITH [RECURSIVE] a AS (…), b AS (…)`, or an empty string.
     *
     * Emitted before everything else, which is also why it binds first: the
     * placeholders are positional, and a clause built out of order produces a
     * statement that executes happily against the wrong values.
     */
    protected function build_ctes(array &$params): string
    {
        if (empty($this->ctes)) {
            return '';
        }

        $terms = [];

        foreach ($this->ctes as $cte) {
            $name = $this->quote_identifier($cte['name']);

            if (!empty($cte['columns'])) {
                $name .= ' (' . implode(', ', array_map(
                    function (string $column): string {
                        return $this->quote_identifier($column);
                    },
                    $cte['columns']
                )) . ')';
            }

            $terms[] = $name . ' AS (' . $cte['query']->to_sql($this->dialect, $params) . ')';
        }

        return 'WITH ' . ($this->cte_recursive ? 'RECURSIVE ' : '') . implode(', ', $terms);
    }

    /**
     * Build INSERT query
     */
    protected function build_insert(array &$params): string
    {
        if (empty($this->insert_values)) {
            throw new \RuntimeException("No values provided for INSERT");
        }

        // before_insert — runs per row, on the caller's own raw values,
        // before Table::timestamps()/optimistic_locking() below ever touch
        // the row. A hook returning an array replaces the row; anything
        // else (including void) leaves it as the previous hook, or the
        // caller, set it.
        //
        // Deliberately ahead of those two: a hook that returns a full
        // replacement (a field whitelist, say) fully replaces the row — if
        // the automatic defaults were injected before this ran, a hook
        // shaped that way would silently drop them from the row it hands
        // back, with no error, only a NOT NULL failure downstream if the
        // caller was lucky, or a quietly wrong write if not. Running hooks
        // first means timestamps()/optimistic_locking() always get the
        // final say over what actually gets sent.
        $before_insert_hooks = $this->hooks_for('before_insert');
        if ($before_insert_hooks !== []) {
            foreach ($this->insert_values as &$row) {
                foreach ($before_insert_hooks as $hook) {
                    $replacement = $hook($row);
                    if (is_array($replacement)) {
                        $row = $replacement;
                    }
                }
            }
            unset($row);
        }

        // Table::timestamps() — every row of a single statement gets the
        // same instant, and a value the caller (or a before_insert hook,
        // above) already provided is never overwritten (ActiveRow's own
        // HasTimestamps trait already sets both columns in PHP before this
        // is ever reached; this only fills them in for callers that did
        // not).
        if ($this->table->has_timestamps()) {
            $now             = date('Y-m-d H:i:s');
            $insert_column   = $this->table->timestamp_insert_column();
            $update_column   = $this->table->timestamp_update_column();

            foreach ($this->insert_values as &$row) {
                if (!array_key_exists($insert_column, $row)) {
                    $row[$insert_column] = $now;
                }
                if (!array_key_exists($update_column, $row)) {
                    $row[$update_column] = $now;
                }
            }
            unset($row);
        }

        // Table::optimistic_locking() — every row starts at version 1
        // unless the caller (or a before_insert hook) already gave it one
        // (e.g. importing rows whose version numbers are already
        // meaningful).
        if ($this->table->has_optimistic_locking()) {
            $version_column = $this->table->version_column();

            foreach ($this->insert_values as &$row) {
                if (!array_key_exists($version_column, $row)) {
                    $row[$version_column] = 1;
                }
            }
            unset($row);
        }

        // Column::cast_as() / enum(BackedEnum::class) — a PHP array, a
        // DateTimeInterface or an enum instance becomes whatever the column
        // actually stores. A row that already holds the raw form (a string,
        // an int) passes through unchanged; see Cast::encode().
        foreach ($this->insert_values as &$row) {
            $row = \Italix\Orm\Casts\Cast::encode_values($this->table, $row);
        }
        unset($row);

        $table_name = $this->quote_identifier($this->table->get_full_name());

        // Get column names from first row
        $columns = array_keys($this->insert_values[0]);
        $column_names = array_map(function($col) {
            $column = $this->table->get_column($col);
            if ($column !== null) {
                return $this->quote_identifier($column->get_db_name());
            }
            return $this->quote_identifier($col);
        }, $columns);
        
        // Build value placeholders
        $value_rows = [];
        foreach ($this->insert_values as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                if ($value instanceof SQLExpression) {
                    $placeholders[] = $value->to_sql($this->dialect, $params);
                } else {
                    $params[] = $value;
                    $placeholders[] = $this->get_placeholder(count($params));
                }
            }
            $value_rows[] = '(' . implode(', ', $placeholders) . ')';
        }
        
        // MySQL uses INSERT IGNORE for DO NOTHING behavior
        $insert_keyword = 'INSERT';
        if ($this->conflict_do_nothing && $this->dialect === 'mysql') {
            $insert_keyword = 'INSERT IGNORE';
        }
        
        $sql = "{$insert_keyword} INTO {$table_name} (" . implode(', ', $column_names) . ") VALUES " . implode(', ', $value_rows);
        
        // ON CONFLICT clause (not needed for MySQL INSERT IGNORE)
        if ($this->conflict_do_nothing && $this->dialect !== 'mysql') {
            $sql .= $this->build_on_conflict($params);
        } elseif ($this->conflict_update !== null) {
            $sql .= $this->build_on_conflict($params);
        }
        
        // RETURNING
        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }
        
        return $sql;
    }

    /**
     * Build ON CONFLICT clause
     */
    protected function build_on_conflict(array &$params): string
    {
        // MySQL uses different syntax: INSERT ... ON DUPLICATE KEY UPDATE
        if ($this->dialect === 'mysql') {
            return $this->build_on_duplicate_key($params);
        }
        
        // PostgreSQL / SQLite syntax
        $sql = ' ON CONFLICT';
        
        // Target columns
        if (!empty($this->conflict_target)) {
            $target_cols = array_map(
                fn($col) => $this->quote_identifier($col),
                $this->conflict_target
            );
            $sql .= ' (' . implode(', ', $target_cols) . ')';
        }
        
        // DO NOTHING or DO UPDATE
        if ($this->conflict_do_nothing) {
            $sql .= ' DO NOTHING';
        } else if ($this->conflict_update !== null) {
            $sql .= ' DO UPDATE SET ';
            $set_parts = [];
            
            foreach ($this->conflict_update as $col => $value) {
                $col_name = $this->quote_identifier($col);
                
                if ($value instanceof SQLExpression) {
                    $set_parts[] = "{$col_name} = " . $value->to_sql($this->dialect, $params);
                } else {
                    $params[] = $value;
                    $set_parts[] = "{$col_name} = " . $this->get_placeholder(count($params));
                }
            }
            
            $sql .= implode(', ', $set_parts);
        }
        
        return $sql;
    }

    /**
     * Build MySQL ON DUPLICATE KEY UPDATE clause
     */
    protected function build_on_duplicate_key(array &$params): string
    {
        if ($this->conflict_do_nothing) {
            // MySQL doesn't have DO NOTHING, use INSERT IGNORE instead
            // We need to modify the INSERT at the beginning
            return ''; // Handled separately
        }
        
        if ($this->conflict_update === null) {
            return '';
        }
        
        $sql = ' ON DUPLICATE KEY UPDATE ';
        $set_parts = [];
        
        foreach ($this->conflict_update as $col => $value) {
            $col_name = $this->quote_identifier($col);
            
            if ($value instanceof SQLExpression) {
                $set_parts[] = "{$col_name} = " . $value->to_sql($this->dialect, $params);
            } else {
                $params[] = $value;
                $set_parts[] = "{$col_name} = " . $this->get_placeholder(count($params));
            }
        }
        
        return $sql . implode(', ', $set_parts);
    }

    /**
     * Build UPDATE query
     */
    protected function build_update(array &$params): string
    {
        if (empty($this->update_values)) {
            throw new \RuntimeException("No values provided for UPDATE");
        }

        // before_update — same replace-if-array rule as before_insert(), but
        // runs once, against the whole SET clause rather than per row.
        //
        // Deliberately ahead of Table::timestamps()/optimistic_locking()
        // below: a hook that returns a full replacement (a field whitelist,
        // say) fully replaces $this->update_values — if either of those
        // defaults were injected before this ran, a hook shaped that way
        // would silently drop it from the SET clause with no error (a NOT
        // NULL failure downstream if the caller was lucky, a quietly wrong
        // write if not — proven for both by reverting this order and
        // watching HooksTest.php's and OptimisticLockingTest.php's own
        // regression assertions for it fail). Running hooks first means
        // both defaults always get the final say over what actually gets
        // sent, the same fix and the same reason for both.
        foreach ($this->hooks_for('before_update') as $hook) {
            $replacement = $hook($this->update_values);
            if (is_array($replacement)) {
                $this->update_values = $replacement;
            }
        }

        // Table::timestamps() — refilled on every UPDATE unless the caller
        // (or a before_update hook, above) is already setting it (same rule
        // as build_insert()).
        if ($this->table->has_timestamps()) {
            $update_column = $this->table->timestamp_update_column();

            if (!array_key_exists($update_column, $this->update_values)) {
                $this->update_values[$update_column] = date('Y-m-d H:i:s');
            }
        }

        // Table::optimistic_locking() — SET {col} = {col} + 1, every UPDATE,
        // unconditionally: unlike timestamps() above, any value a caller (or
        // a before_update hook, above) put under this key is discarded
        // rather than trusted — see Table::optimistic_locking()'s docblock
        // for why this one is different. ->expect_version() additionally
        // names the value the WHERE clause below must still match.
        $expected_version_col  = null;
        $expected_version_value = null;

        if ($this->table->has_optimistic_locking()) {
            $version_column   = $this->table->version_column();
            $version_col_meta = $this->table->get_column($version_column);
            $expected_version_col = $version_col_meta !== null ? $version_col_meta->get_db_name() : $version_column;

            $this->update_values[$version_column] = raw($this->quote_identifier($expected_version_col) . ' + 1');

            if ($this->expected_version !== null) {
                $expected_version_value = $this->expected_version;
            }
        }

        // Column::cast_as() / enum(BackedEnum::class) — see build_insert().
        $this->update_values = \Italix\Orm\Casts\Cast::encode_values($this->table, $this->update_values);

        $table_name = $this->quote_identifier($this->table->get_full_name());

        // Build SET clause
        $set_parts = [];
        foreach ($this->update_values as $col => $value) {
            $column = $this->table->get_column($col);
            $col_name = $column !== null ? $column->get_db_name() : $col;
            
            if ($value instanceof SQLExpression) {
                $set_parts[] = $this->quote_identifier($col_name) . ' = ' . $value->to_sql($this->dialect, $params);
            } else {
                $params[] = $value;
                $set_parts[] = $this->quote_identifier($col_name) . ' = ' . $this->get_placeholder(count($params));
            }
        }
        
        $sql = "UPDATE {$table_name} SET " . implode(', ', $set_parts);

        // WHERE — plus, when ->expect_version() was called, an extra
        // AND {version_col} = ? that turns a lost race into zero rows
        // affected rather than a silent overwrite. See execute(), which
        // raises Locking\OptimisticLockException when that happens.
        $where_parts = [];
        if ($this->where_condition !== null) {
            $where_parts[] = $this->where_condition->to_sql($this->dialect, $params);
        }
        if ($expected_version_value !== null) {
            $params[] = $expected_version_value;
            $where_parts[] = $this->quote_identifier($expected_version_col) . ' = ' . $this->get_placeholder(count($params));
        }
        if ($where_parts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where_parts);
        }

        // RETURNING
        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }

        return $sql;
    }

    /**
     * Build DELETE query
     */
    protected function build_delete(array &$params): string
    {
        // before_delete — side effects only: DELETE has no values to hand a
        // hook, and nothing it returns is used. Fires here, ahead of the
        // soft_deletes() branch below, so it fires once regardless of
        // whether the row ends up hard-deleted or just marked.
        foreach ($this->hooks_for('before_delete') as $hook) {
            $hook();
        }

        // Table::soft_deletes() — a DELETE becomes an UPDATE unless the
        // caller asked to bypass it with ->force(). One rule, reached by
        // every caller: Data Mapper's own $dm->delete($table) and
        // ActiveRow's Persistable::delete() alike, since both compile
        // through this same method.
        if ($this->table->has_soft_deletes() && !$this->force_delete_flag) {
            return $this->build_soft_delete($params);
        }

        $table_name = $this->quote_identifier($this->table->get_full_name());

        $sql = "DELETE FROM {$table_name}";

        // WHERE
        if ($this->where_condition !== null) {
            $sql .= ' WHERE ' . $this->where_condition->to_sql($this->dialect, $params);
        }

        // LIMIT (MySQL)
        if ($this->limit_value !== null && $this->dialect === 'mysql') {
            $sql .= ' LIMIT ' . $this->limit_value;
        }

        // RETURNING
        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }

        return $sql;
    }

    /**
     * The UPDATE a DELETE compiles to when `Table::soft_deletes()` is
     * declared and `force()` was not called.
     */
    protected function build_soft_delete(array &$params): string
    {
        $table_name = $this->quote_identifier($this->table->get_full_name());
        $column     = $this->table->soft_delete_column();
        $col_meta   = $this->table->get_column($column);
        $col_name   = $col_meta !== null ? $col_meta->get_db_name() : $column;

        $params[] = date('Y-m-d H:i:s');
        $sql = "UPDATE {$table_name} SET " . $this->quote_identifier($col_name)
             . ' = ' . $this->get_placeholder(count($params));

        if ($this->where_condition !== null) {
            $sql .= ' WHERE ' . $this->where_condition->to_sql($this->dialect, $params);
        }

        if ($this->return_values && $this->supports_returning()) {
            $sql .= $this->build_returning();
        }

        return $sql;
    }

    /**
     * Build RETURNING clause
     */
    protected function build_returning(): string
    {
        if (empty($this->returning_columns)) {
            return ' RETURNING *';
        }
        
        $cols = [];
        foreach ($this->returning_columns as $col) {
            if ($col instanceof Column) {
                $cols[] = $this->quote_identifier($col->get_db_name());
            } else {
                $cols[] = $this->quote_identifier((string)$col);
            }
        }
        
        return ' RETURNING ' . implode(', ', $cols);
    }

    /**
     * Quote identifier based on dialect
     * Properly escapes the quote character to prevent SQL injection
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            // Escape backticks by doubling them
            return '`' . str_replace('`', '``', $name) . '`';
        }
        // PostgreSQL/SQLite: escape double quotes by doubling them
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Get column reference for SQL
     */
    protected function get_column_ref(Column $column): string
    {
        $table = $column->get_table();
        if ($table !== null) {
            return $this->quote_identifier($table->get_name()) . '.' . $this->quote_identifier($column->get_db_name());
        }
        return $this->quote_identifier($column->get_db_name());
    }

    /**
     * Get placeholder for parameter
     */
    protected function get_placeholder(int $index): string
    {
        // PostgreSQL and Supabase use numbered placeholders ($1, $2, etc.)
        // `?`, on every dialect, because this SQL is executed through PDO and PDO
        // understands `?` and named placeholders — nothing else. The `$1` form
        // is libpq's, and PDO does not parse it: it passes the text through, the
        // server finds a parameter nobody bound, and the comparison yields NULL.
        // The query then returns **no rows and no error**, which is the worst
        // way for a query builder to be wrong. Measured on PostgreSQL 12.
        return '?';
    }

    /**
     * Check if current dialect is PostgreSQL-compatible (PostgreSQL or Supabase)
     */
    protected function is_postgres_compatible(): bool
    {
        return in_array($this->dialect, ['postgresql', 'supabase']);
    }

    /**
     * Check if current dialect supports RETURNING clause
     */
    protected function supports_returning(): bool
    {
        return in_array($this->dialect, ['postgresql', 'supabase', 'sqlite']);
    }
}
