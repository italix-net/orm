<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Relational Query Builder
 *
 * Drizzle-style query builder with find_first(), find_many(), and eager loading.
 *
 * @package Italix\Orm\Relations
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Relations;

use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\Column;
use Italix\Orm\Operators\SQLExpression;
use Italix\Orm\Operators\Comparison;
use Italix\Orm\Operators\AndExpression;
use Italix\Orm\Operators\OrderDirection;
use Italix\Orm\Scopes\ScopeRegistry;
use PDO;

use function Italix\Orm\Operators\{is_null, raw};

/**
 * Relational Query Builder - Drizzle-style queries with eager loading
 *
 * Usage:
 *   $query = new RelationalQueryBuilder($pdo, 'mysql');
 *
 *   // Find many with relations
 *   $users = $query->query($users_table)
 *       ->with([
 *           'posts' => true,
 *           'profile' => true
 *       ])
 *       ->find_many();
 *
 *   // Find first with nested relations
 *   $user = $query->query($users_table)
 *       ->with([
 *           'posts' => [
 *               'with' => ['comments' => true]
 *           ]
 *       ])
 *       ->where(eq($users_table->id, 1))
 *       ->find_first();
 */
class RelationalQueryBuilder
{
    /** @var PDO Database connection */
    protected PDO $connection;

    /** @var string Database dialect */
    protected string $dialect;

    /**
     * Create a new RelationalQueryBuilder
     */
    public function __construct(PDO $connection, string $dialect = 'mysql')
    {
        $this->connection = $connection;
        $this->dialect = $dialect;
    }

    /**
     * Start a query for a table
     */
    public function query(Table $table): TableQuery
    {
        return new TableQuery($this->connection, $this->dialect, $table);
    }

    /**
     * Get the PDO connection
     */
    public function get_connection(): PDO
    {
        return $this->connection;
    }

    /**
     * Get the dialect
     */
    public function get_dialect(): string
    {
        return $this->dialect;
    }
}

/**
 * Query for a specific table with relation loading
 */
class TableQuery
{
    /** @var PDO Database connection */
    protected PDO $connection;

    /** @var string Database dialect */
    protected string $dialect;

    /** @var Table Target table */
    protected Table $table;

    /** @var array<Column> Columns to select */
    protected array $columns = [];

    /** @var SQLExpression|null WHERE condition */
    protected ?SQLExpression $where_condition = null;

    /** @var array ORDER BY clauses */
    protected array $order_by = [];

    /** @var int|null LIMIT value */
    protected ?int $limit_value = null;

    /** @var int|null OFFSET value */
    protected ?int $offset_value = null;

    /** @var array Relations to load */
    protected array $with_relations = [];

    /** @var array Extra configuration */
    protected array $extras = [];

    /** A query that includes soft-deleted rows too. See `with_trashed()`. */
    protected bool $with_trashed_flag = false;

    /** @var ScopeRegistry|null Global scopes; null for a TableQuery built without a DataManager */
    protected ?ScopeRegistry $scopes = null;

    /** All global scopes disabled for this query. See without_scopes(). */
    protected bool $scopes_disabled = false;

    /** @var array<int, string> Specific scope names disabled for this query. See without_scopes(). */
    protected array $disabled_scope_names = [];

    /**
     * Create a new TableQuery
     */
    public function __construct(PDO $connection, string $dialect, Table $table)
    {
        $this->connection = $connection;
        $this->dialect = $dialect;
        $this->table = $table;
    }

    /**
     * Select specific columns
     *
     * @param array<Column|string> $columns
     */
    public function columns(array $columns): self
    {
        $query = clone $this;
        $query->columns = $columns;
        return $query;
    }

    /**
     * Add WHERE condition
     */
    public function where(SQLExpression $condition): self
    {
        $query = clone $this;
        $query->where_condition = $condition;
        return $query;
    }

    /**
     * Add ORDER BY clause
     *
     * @param mixed ...$columns
     */
    public function order_by(...$columns): self
    {
        $query = clone $this;
        $query->order_by = array_merge($query->order_by, $columns);
        return $query;
    }

    /**
     * Set LIMIT
     */
    public function limit(int $limit): self
    {
        $query = clone $this;
        $query->limit_value = $limit;
        return $query;
    }

    /**
     * Set OFFSET
     */
    public function offset(int $offset): self
    {
        $query = clone $this;
        $query->offset_value = $offset;
        return $query;
    }

    /**
     * Specify relations to eager load (Drizzle-style)
     *
     * @param array $relations Relations configuration:
     *   - 'relation_name' => true                    // Load all columns
     *   - 'relation_name' => ['columns' => [...]]   // Load specific columns
     *   - 'relation_name' => ['with' => [...]]      // Nested relations
     *   - 'relation_name' => ['where' => expr]      // Filter relation
     *   - 'relation_name' => ['order_by' => [...]]  // Order relation
     *   - 'relation_name' => ['limit' => n]         // Limit relation results
     */
    public function with(array $relations): self
    {
        $query = clone $this;
        $query->with_relations = $relations;
        return $query;
    }

    /**
     * Set extra configuration
     *
     * @param array $extras
     */
    public function extras(array $extras): self
    {
        $query = clone $this;
        $query->extras = $extras;
        return $query;
    }

    /**
     * Include soft-deleted rows too — undoing the automatic
     * `WHERE deleted_col IS NULL` every other query against a table with
     * `Table::soft_deletes()` declared carries. A no-op on a table with no
     * soft-delete column.
     */
    public function with_trashed(): self
    {
        $query = clone $this;
        $query->with_trashed_flag = true;
        return $query;
    }

    /**
     * Set by `DataManager::query_table()` right after construction, so
     * every `TableQuery` it hands out carries the same registry — there is
     * no persistent template to inherit this from the way `QueryBuilder`
     * has, since `query_table()` builds a fresh instance every call.
     */
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
     * @param array<int, string> $names
     */
    public function without_scopes(array $names = []): self
    {
        $query = clone $this;

        if ($names === []) {
            $query->scopes_disabled = true;
        } else {
            $query->disabled_scope_names = array_unique(array_merge($query->disabled_scope_names, $names));
        }

        return $query;
    }

    /**
     * The `WHERE` this query actually runs with: `$this->where_condition`,
     * AND'd with `deleted_col IS NULL` when the table declares
     * `soft_deletes()` and `with_trashed()` was not called, further AND'd
     * with every active `DataManager::add_global_scope()` registered for
     * this table — see `QueryBuilder::effective_where()`, the identical
     * rule for the other query engine.
     */
    protected function effective_where(): ?SQLExpression
    {
        $where = $this->where_condition;

        if ($this->table->has_soft_deletes() && !$this->with_trashed_flag) {
            $column_name = $this->table->soft_delete_column();
            $column      = $this->table->get_column($column_name);
            $not_deleted = is_null($column ?? raw($this->quote_identifier($column_name)));

            $where = $where === null ? $not_deleted : new AndExpression($where, $not_deleted);
        }

        if ($this->scopes !== null && !$this->scopes_disabled) {
            foreach ($this->scopes->for_table($this->table) as $name => $scope) {
                if (in_array($name, $this->disabled_scope_names, true)) {
                    continue;
                }

                $condition = $scope($this->table);
                $where     = $where === null ? $condition : new AndExpression($where, $condition);
            }
        }

        return $where;
    }

    /**
     * Find multiple records
     *
     * @return array<array> Array of records with loaded relations
     */
    public function find_many(): array
    {
        // Execute main query
        $results = $this->execute_query();

        // Load relations
        if (!empty($results) && !empty($this->with_relations)) {
            $added = $this->added_relation_columns();
            $results = $this->load_relations($results);

            // The keys fetched only so the relations could be matched. The
            // caller asked for a column list and gets that list, plus what it
            // asked to load — not the plumbing in between.
            $results = $this->strip_internal_columns($results, $added);
        }

        return $results;
    }

    /**
     * The key columns added to the SELECT that the caller did not ask for.
     *
     * @return string[]
     */
    protected function added_relation_columns(): array
    {
        if (empty($this->columns)) {
            return [];
        }

        $selected = [];

        foreach ($this->columns as $column) {
            $selected[] = $column instanceof Column ? $column->get_name() : (string) $column;
        }

        return array_values(array_diff($this->required_relation_columns(), $selected));
    }

    /**
     * Find the first matching record
     *
     * @return array|null Single record or null
     */
    public function find_first(): ?array
    {
        $query = clone $this;
        $query->limit_value = 1;

        $results = $query->find_many();
        return $results[0] ?? null;
    }

    /**
     * Alias for find_first()
     */
    public function find_one(): ?array
    {
        return $this->find_first();
    }

    /**
     * Find a record by its primary key.
     *
     * A single-column key takes the value directly, unchanged from before:
     * `find(5)`. A **composite** key needs a value for every column, so it
     * takes an array keyed by column name: `find(['tenant_id' => 3, 'order_id' => 5])`.
     *
     * A composite key given a bare scalar used to silently match against
     * only the *first* primary key column — `$pk_columns[0]` — and ignore
     * the rest entirely, which does not fail: it returns a row, just not
     * necessarily the right one (any row sharing that first column's value,
     * regardless of tenant). Refused outright now instead, since a wrong
     * row returned without error is worse than an exception that says what
     * is missing.
     *
     * @param mixed $id A scalar for a single-column key; an array keyed by
     *                  column name for a composite one.
     * @return array|null
     */
    public function find(mixed $id): ?array
    {
        $pk_columns = $this->table->get_primary_keys();
        if (empty($pk_columns)) {
            throw new \RuntimeException("Table {$this->table->get_name()} has no primary key defined");
        }

        if (count($pk_columns) === 1) {
            $pk_column = $this->table->get_column($pk_columns[0]);
            if ($pk_column === null) {
                throw new \RuntimeException("Primary key column not found");
            }

            return $this->where(new Comparison($pk_column, '=', $id))->find_first();
        }

        if (!is_array($id)) {
            throw new \RuntimeException(sprintf(
                "Table %s has a composite primary key (%s); find() needs an array keyed by column "
                    . "name, e.g. find(['%s' => …, '%s' => …]) — not a bare value, which would silently "
                    . 'match only the first column and ignore the rest.',
                $this->table->get_name(),
                implode(', ', $pk_columns),
                $pk_columns[0],
                $pk_columns[1]
            ));
        }

        $missing = array_diff($pk_columns, array_keys($id));
        if (!empty($missing)) {
            throw new \RuntimeException(sprintf(
                'find() is missing a value for: %s (composite primary key on %s)',
                implode(', ', $missing),
                $this->table->get_name()
            ));
        }

        $condition = null;

        foreach ($pk_columns as $col_name) {
            $column = $this->table->get_column($col_name);
            if ($column === null) {
                throw new \RuntimeException("Primary key column '{$col_name}' not found");
            }

            $comparison = new Comparison($column, '=', $id[$col_name]);
            $condition  = $condition === null ? $comparison : new AndExpression($condition, $comparison);
        }

        return $this->where($condition)->find_first();
    }

    /**
     * Execute the main query
     *
     * @return array<array>
     */
    protected function execute_query(): array
    {
        $params = [];
        $sql = $this->build_sql($params);

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        // Column::cast_as() / enum(BackedEnum::class) — see QueryBuilder's
        // own decode_result(), the identical fix for the other query engine.
        // Applies to eager-loaded child rows too: each relation is its own
        // TableQuery against its own table, decoded by its own cast rules.
        return \Italix\Orm\Casts\Cast::decode_rows($this->table, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The column a child of this relation is matched on, or null.
     */
    protected function matching_key_of($relation): ?Column
    {
        foreach (['get_references', 'get_target_references'] as $accessor) {
            if (!method_exists($relation, $accessor)) {
                continue;
            }

            $columns = $relation->{$accessor}();

            if (!empty($columns)) {
                return $columns[0];
            }
        }

        if (method_exists($relation, 'get_id_column')) {
            return $relation->get_id_column();
        }

        return null;
    }

    /**
     * Remove the matching key from loaded children, when it was not asked for.
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<int, mixed>                $requested
     * @return array<int, array<string, mixed>>
     */
    protected function strip_key_from_children(array $results, string $key, Column $matched, array $requested): array
    {
        $names = [];

        foreach ($requested as $column) {
            $names[] = $column instanceof Column ? $column->get_name() : (string) $column;
        }

        if (in_array($matched->get_name(), $names, true)) {
            return $results;
        }

        $name = $matched->get_name();

        foreach ($results as &$row) {
            if (!isset($row[$key])) {
                continue;
            }

            if (is_array($row[$key]) && array_key_exists($name, $row[$key])) {
                unset($row[$key][$name]);          // a single related row
                continue;
            }

            if (is_array($row[$key])) {
                foreach ($row[$key] as &$child) {
                    if (is_array($child)) {
                        unset($child[$name]);
                    }
                }

                unset($child);
            }
        }

        unset($row);

        return $results;
    }

    /**
     * The child column list, widened by the key the child is matched on.
     *
     * The mirror of {@see required_relation_columns()} and the same silent
     * failure: `with(['posts' => ['columns' => ['title']]])` fetches titles
     * with no `author_id`, so nothing can be attached to a parent and every
     * relation comes back empty without a word.
     *
     * @param  array<int, mixed> $requested
     * @return array<int, mixed>
     */
    protected function with_matching_key(array $requested, Column $key): array
    {
        $names = [];

        foreach ($requested as $column) {
            $names[] = $column instanceof Column ? $column->get_name() : (string) $column;
        }

        if (!in_array($key->get_name(), $names, true)) {
            $requested[] = $key->get_name();
        }

        return $requested;
    }

    /**
     * The parent columns the requested relations need in order to be matched.
     *
     * Eager loading works by reading a key out of each parent row and looking
     * up children by it. Narrow the select with `columns()` and that key is not
     * there — so `array_column()` finds nothing, every relation comes back
     * empty, and **nothing raises**. A query that asks for a name and its posts
     * returns the name and no posts, which reads like a data problem.
     *
     * So the keys are added to the SELECT here and removed from the rows in
     * {@see strip_internal_columns()}, leaving the caller with exactly the
     * columns it asked for plus the relations it asked for.
     *
     * @return string[] column names
     */
    protected function required_relation_columns(): array
    {
        if (empty($this->columns) || empty($this->with_relations)) {
            return [];
        }

        $relations = RelationsRegistry::get_instance()->get($this->table);

        if ($relations === null) {
            return [];
        }

        $needed = [];

        foreach (array_keys($this->with_relations) as $relation_name) {
            $actual_name = strpos($relation_name, ':') !== false
                ? explode(':', $relation_name, 2)[1]
                : $relation_name;

            $relation = $relations->get($actual_name);

            if ($relation === null) {
                continue;
            }

            // One, Many and many-to-many all match on the source-side fields.
            foreach ($relation->get_fields() as $column) {
                $needed[] = $column->get_name();
            }

            // A polymorphic parent is matched on both discriminator and id.
            foreach (['get_type_column', 'get_id_column'] as $accessor) {
                if (!method_exists($relation, $accessor)) {
                    continue;
                }

                $column = $relation->{$accessor}();

                if ($column !== null) {
                    $needed[] = $column->get_name();
                }
            }
        }

        return array_values(array_unique($needed));
    }

    /**
     * Remove the columns added only so the relations could be matched.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string[]                         $added
     * @return array<int, array<string, mixed>>
     */
    protected function strip_internal_columns(array $rows, array $added): array
    {
        if ($added === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($added as $name) {
                unset($row[$name]);
            }
        }

        unset($row);

        return $rows;
    }

    /**
     * Build the SQL query
     */
    protected function build_sql(array &$params): string
    {
        $parts = ['SELECT'];

        // Columns
        if (empty($this->columns)) {
            $parts[] = '*';
        } else {
            $selected = [];
            $cols = [];

            foreach ($this->columns as $col) {
                if ($col instanceof Column) {
                    $selected[] = $col->get_name();
                    $cols[] = $this->get_column_ref($col);
                } else {
                    $selected[] = (string) $col;
                    $cols[] = $this->quote_identifier((string) $col);
                }
            }

            // Keys the requested relations need, which the caller did not ask
            // for. Added here, removed from the rows afterwards.
            foreach ($this->required_relation_columns() as $name) {
                if (in_array($name, $selected, true)) {
                    continue;
                }

                $cols[] = $this->quote_identifier($name);
            }

            $parts[] = implode(', ', $cols);
        }

        // FROM
        $parts[] = 'FROM ' . $this->quote_identifier($this->table->get_full_name());

        // WHERE — see effective_where() for the automatic soft-delete filter
        $where = $this->effective_where();
        if ($where !== null) {
            $parts[] = 'WHERE ' . $where->to_sql($this->dialect, $params);
        }

        // ORDER BY
        if (!empty($this->order_by)) {
            $order_parts = [];
            foreach ($this->order_by as $order) {
                if ($order instanceof OrderDirection) {
                    if ($order->column instanceof Column) {
                        $order_parts[] = $this->get_column_ref($order->column) . ' ' . $order->direction;
                    }
                } elseif ($order instanceof Column) {
                    $order_parts[] = $this->get_column_ref($order);
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
     * Load relations for the results
     *
     * @param array<array> $results
     * @return array<array>
     */
    protected function load_relations(array $results): array
    {
        $table_relations = RelationsRegistry::get_instance()->get($this->table);
        if ($table_relations === null) {
            return $results;
        }

        foreach ($this->with_relations as $relation_name => $config) {
            // Handle aliased relations: 'alias:relation_name'
            $actual_name = $relation_name;
            $alias = null;

            if (strpos($relation_name, ':') !== false) {
                [$alias, $actual_name] = explode(':', $relation_name, 2);
            }

            $relation = $table_relations->get($actual_name);
            if ($relation === null) {
                continue;
            }

            // Normalize config
            $relation_config = $this->normalize_relation_config($config);

            // Use alias if provided, otherwise use relation name
            $result_key = $alias ?? $relation_name;

            // The key a child was matched on, when the caller narrowed the
            // child's columns and did not include it. Recorded before loading
            // so it can be removed from the rows afterwards — the caller asked
            // for a list of columns and should get that list.
            $child_key = null;

            if (isset($relation_config['columns'])) {
                $child_key = $this->matching_key_of($relation);
            }

            // Load based on relation type
            if ($relation instanceof One) {
                $results = $this->load_one_relation($results, $relation, $relation_config, $result_key);
            } elseif ($relation instanceof Many) {
                if ($relation->is_many_to_many()) {
                    $results = $this->load_many_to_many_relation($results, $relation, $relation_config, $result_key);
                } else {
                    $results = $this->load_many_relation($results, $relation, $relation_config, $result_key);
                }
            } elseif ($relation instanceof PolymorphicOne) {
                $results = $this->load_polymorphic_one_relation($results, $relation, $relation_config, $result_key);
            } elseif ($relation instanceof PolymorphicMany) {
                $results = $this->load_polymorphic_many_relation($results, $relation, $relation_config, $result_key);
            }

            if ($child_key !== null) {
                $results = $this->strip_key_from_children($results, $result_key, $child_key, $relation_config['columns']);
            }
        }

        return $results;
    }

    /**
     * Normalize relation configuration
     */
    protected function normalize_relation_config($config): array
    {
        if ($config === true) {
            return [];
        }

        if (is_array($config)) {
            return $config;
        }

        return [];
    }

    /**
     * Load a "one" relation (one-to-one or many-to-one)
     */
    protected function load_one_relation(array $results, One $relation, array $config, string $key): array
    {
        if (empty($results)) {
            return $results;
        }

        $fields = $relation->get_fields();
        $references = $relation->get_references();
        $target_table = $relation->get_target_table();

        if (empty($fields) || empty($references)) {
            // Try to auto-infer
            return $results;
        }

        // Collect foreign key values from results
        $field_name = $fields[0]->get_name();
        $ref_name = $references[0]->get_name();

        $fk_values = array_unique(array_filter(
            array_column($results, $field_name),
            fn($v) => $v !== null
        ));

        if (empty($fk_values)) {
            // No FK values, set all to null
            foreach ($results as &$row) {
                $row[$key] = null;
            }
            return $results;
        }

        // Build and execute related query
        $related_query = new TableQuery($this->connection, $this->dialect, $target_table);

        // Apply config filters
        if (isset($config['columns'])) {
            $related_query = $related_query->columns($this->with_matching_key($config['columns'], $references[0]));
        }

        // Build WHERE IN condition
        $ref_column = $references[0];
        $where = new \Italix\Orm\Operators\InExpression($ref_column, $fk_values);

        if (isset($config['where'])) {
            $where = new AndExpression($where, $config['where']);
        }

        $related_query = $related_query->where($where);

        // Load nested relations
        if (isset($config['with'])) {
            $related_query = $related_query->with($config['with']);
        }

        $related_results = $related_query->find_many();

        // Index related results by reference column
        $related_map = [];
        foreach ($related_results as $related_row) {
            $ref_value = $related_row[$ref_name] ?? null;
            if ($ref_value !== null) {
                $related_map[$ref_value] = $related_row;
            }
        }

        // Attach to results
        foreach ($results as &$row) {
            $fk_value = $row[$field_name] ?? null;
            $row[$key] = $related_map[$fk_value] ?? null;
        }

        return $results;
    }

    /**
     * Load a "many" relation (one-to-many)
     */
    protected function load_many_relation(array $results, Many $relation, array $config, string $key): array
    {
        if (empty($results)) {
            return $results;
        }

        $fields = $relation->get_fields();
        $references = $relation->get_references();
        $target_table = $relation->get_target_table();

        // For one-to-many, fields are on source (PK), references are on target (FK)
        if (empty($fields)) {
            // Auto-infer from primary key
            $pk_columns = $this->table->get_primary_keys();
            if (!empty($pk_columns)) {
                $pk_column = $this->table->get_column($pk_columns[0]);
                if ($pk_column !== null) {
                    $fields = [$pk_column];
                }
            }
        }

        if (empty($fields)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        if (empty($references)) {
            // Try to auto-infer FK column on target
            $source_name = $this->table->get_name();
            $fk_name = rtrim($source_name, 's') . '_id';
            $fk_column = $target_table->get_column($fk_name);
            if ($fk_column !== null) {
                $references = [$fk_column];
            }
        }

        if (empty($references)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        $field_name = $fields[0]->get_name();
        $ref_name = $references[0]->get_name();

        // Collect PK values from results
        $pk_values = array_unique(array_filter(
            array_column($results, $field_name),
            fn($v) => $v !== null
        ));

        if (empty($pk_values)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        // Build related query
        $related_query = new TableQuery($this->connection, $this->dialect, $target_table);

        if (isset($config['columns'])) {
            $related_query = $related_query->columns($this->with_matching_key($config['columns'], $references[0]));
        }

        $ref_column = $references[0];
        $where = new \Italix\Orm\Operators\InExpression($ref_column, $pk_values);

        if (isset($config['where'])) {
            $where = new AndExpression($where, $config['where']);
        }

        $related_query = $related_query->where($where);

        if (isset($config['order_by'])) {
            $related_query = $related_query->order_by(...(array)$config['order_by']);
        }

        // `limit` is applied per parent, after grouping — see cap_per_parent().
        // It is deliberately NOT put on the child query: one LIMIT across a
        // batched fetch caps the whole result, which gives the first parent its
        // rows and the rest none.

        if (isset($config['with'])) {
            $related_query = $related_query->with($config['with']);
        }

        $related_results = $related_query->find_many();

        // Group by FK value
        $related_map = [];
        foreach ($related_results as $related_row) {
            $fk_value = $related_row[$ref_name] ?? null;
            if ($fk_value !== null) {
                if (!isset($related_map[$fk_value])) {
                    $related_map[$fk_value] = [];
                }
                $related_map[$fk_value][] = $related_row;
            }
        }

        if (isset($config['limit'])) {
            $related_map = $this->cap_per_parent($related_map, (int) $config['limit']);
        }

        // Attach to results
        foreach ($results as &$row) {
            $pk_value = $row[$field_name] ?? null;
            $row[$key] = $related_map[$pk_value] ?? [];
        }

        return $results;
    }

    /**
     * Keep at most `$limit` children for each parent.
     *
     * `limit` on a relation means "this many **per parent**" — the three most
     * recent orders of every customer — and that is not what a `LIMIT` on the
     * batched child query does. One `LIMIT 1` across a fetch for every parent
     * returns a single row in total: the first parent gets it and the others
     * get nothing, silently. Measured before this existed:
     *
     *     with limit 1 → [{Alice: [120]}, {Bob: []}]      // Bob has two orders
     *
     * The children are already grouped in memory here, and the child query's
     * own `ORDER BY` has already decided which come first, so capping each
     * group is exact on every dialect.
     *
     * The cost is that more rows cross the wire than are kept. A window
     * function — `ROW_NUMBER() OVER (PARTITION BY …)` — would push the cap into
     * the database, and this package can now write one (`row_number()`), but it
     * is not used here: window functions need SQLite 3.25, MySQL 8.0 or
     * MariaDB 10.2, a floor this package does not otherwise impose, and relation
     * loading is not the place to impose it silently. Reach for it by hand in
     * the queries where the extra rows actually cost something.
     *
     * @param  array<mixed, array<int, array<string, mixed>>> $grouped
     * @return array<mixed, array<int, array<string, mixed>>>
     */
    protected function cap_per_parent(array $grouped, int $limit): array
    {
        if ($limit < 0) {
            return $grouped;
        }

        foreach ($grouped as $parent => $children) {
            $grouped[$parent] = array_slice($children, 0, $limit);
        }

        return $grouped;
    }

    /**
     * Load a many-to-many relation through junction table
     */
    protected function load_many_to_many_relation(array $results, Many $relation, array $config, string $key): array
    {
        if (empty($results)) {
            return $results;
        }

        $through_table = $relation->get_through_table();
        $target_table = $relation->get_target_table();

        $fields = $relation->get_fields(); // Source PK
        $through_fields = $relation->get_through_fields(); // Junction -> source
        $target_fields = $relation->get_target_fields(); // Junction -> target
        $target_references = $relation->get_target_references(); // Target PK

        // Validate required config
        if ($through_table === null || empty($through_fields) || empty($target_fields)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        // Auto-infer source fields from PK
        if (empty($fields)) {
            $pk_columns = $this->table->get_primary_keys();
            if (!empty($pk_columns)) {
                $pk_column = $this->table->get_column($pk_columns[0]);
                if ($pk_column !== null) {
                    $fields = [$pk_column];
                }
            }
        }

        // Auto-infer target references from target PK
        if (empty($target_references)) {
            $target_pk_columns = $target_table->get_primary_keys();
            if (!empty($target_pk_columns)) {
                $target_pk_column = $target_table->get_column($target_pk_columns[0]);
                if ($target_pk_column !== null) {
                    $target_references = [$target_pk_column];
                }
            }
        }

        if (empty($fields) || empty($target_references)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        $field_name = $fields[0]->get_name();
        $through_field_name = $through_fields[0]->get_name();
        $target_field_name = $target_fields[0]->get_name();
        $target_ref_name = $target_references[0]->get_name();

        // Collect source PK values
        $pk_values = array_unique(array_filter(
            array_column($results, $field_name),
            fn($v) => $v !== null
        ));

        if (empty($pk_values)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        // Query junction table
        $params = [];
        $junction_sql = sprintf(
            'SELECT * FROM %s WHERE %s IN (%s)',
            $this->quote_identifier($through_table->get_full_name()),
            $this->quote_identifier($through_field_name),
            $this->build_placeholders(count($pk_values), $params, $pk_values)
        );

        $stmt = $this->connection->prepare($junction_sql);
        $stmt->execute($params);
        $junction_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get target IDs
        $target_ids = array_unique(array_filter(
            array_column($junction_rows, $target_field_name),
            fn($v) => $v !== null
        ));

        if (empty($target_ids)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        // Query target table
        $related_query = new TableQuery($this->connection, $this->dialect, $target_table);

        if (isset($config['columns'])) {
            $related_query = $related_query->columns($this->with_matching_key($config['columns'], $target_references[0]));
        }

        $where = new \Italix\Orm\Operators\InExpression($target_references[0], $target_ids);

        if (isset($config['where'])) {
            $where = new AndExpression($where, $config['where']);
        }

        $related_query = $related_query->where($where);

        if (isset($config['order_by'])) {
            $related_query = $related_query->order_by(...(array)$config['order_by']);
        }

        if (isset($config['with'])) {
            $related_query = $related_query->with($config['with']);
        }

        $target_results = $related_query->find_many();

        // Index target results by PK
        $target_map = [];
        foreach ($target_results as $target_row) {
            $pk = $target_row[$target_ref_name] ?? null;
            if ($pk !== null) {
                $target_map[$pk] = $target_row;
            }
        }

        // Build source -> targets mapping via junction
        $source_targets = [];
        foreach ($junction_rows as $junction) {
            $source_pk = $junction[$through_field_name] ?? null;
            $target_pk = $junction[$target_field_name] ?? null;

            if ($source_pk !== null && $target_pk !== null && isset($target_map[$target_pk])) {
                if (!isset($source_targets[$source_pk])) {
                    $source_targets[$source_pk] = [];
                }
                // Optionally include pivot data
                $target_row = $target_map[$target_pk];
                if (isset($config['with_pivot']) && $config['with_pivot']) {
                    $pivot_data = $junction;
                    unset($pivot_data[$through_field_name], $pivot_data[$target_field_name]);
                    $target_row['_pivot'] = $pivot_data;
                }
                $source_targets[$source_pk][] = $target_row;
            }
        }

        if (isset($config['limit'])) {
            $source_targets = $this->cap_per_parent($source_targets, (int) $config['limit']);
        }

        // Attach to results
        foreach ($results as &$row) {
            $pk_value = $row[$field_name] ?? null;
            $row[$key] = $source_targets[$pk_value] ?? [];
        }

        return $results;
    }

    /**
     * Load a polymorphic one relation
     */
    protected function load_polymorphic_one_relation(
        array $results,
        PolymorphicOne $relation,
        array $config,
        string $key
    ): array {
        if (empty($results)) {
            return $results;
        }

        $type_column = $relation->get_type_column();
        $id_column = $relation->get_id_column();
        $targets = $relation->get_targets();

        $type_col_name = $type_column->get_name();
        $id_col_name = $id_column->get_name();

        // Group results by type
        $by_type = [];
        foreach ($results as $index => $row) {
            $type = $row[$type_col_name] ?? null;
            $id = $row[$id_col_name] ?? null;

            if ($type !== null && $id !== null && isset($targets[$type])) {
                if (!isset($by_type[$type])) {
                    $by_type[$type] = [];
                }
                $by_type[$type][$index] = $id;
            }
        }

        // Initialize all results with null
        foreach ($results as &$row) {
            $row[$key] = null;
        }

        // Load each type's related records
        foreach ($by_type as $type => $indices_ids) {
            $target_table = $targets[$type];
            $target_pk_columns = $target_table->get_primary_keys();

            if (empty($target_pk_columns)) {
                continue;
            }

            $target_pk = $target_table->get_column($target_pk_columns[0]);
            if ($target_pk === null) {
                continue;
            }

            $ids = array_values($indices_ids);

            $related_query = new TableQuery($this->connection, $this->dialect, $target_table);

            if (isset($config['columns'])) {
                $related_query = $related_query->columns($this->with_matching_key($config['columns'], $target_pk));
            }

            $where = new \Italix\Orm\Operators\InExpression($target_pk, $ids);
            $related_query = $related_query->where($where);

            if (isset($config['with'])) {
                $related_query = $related_query->with($config['with']);
            }

            $related_results = $related_query->find_many();

            // Index by PK
            $related_map = [];
            $pk_name = $target_pk->get_name();
            foreach ($related_results as $related_row) {
                $pk_value = $related_row[$pk_name] ?? null;
                if ($pk_value !== null) {
                    $related_map[$pk_value] = $related_row;
                }
            }

            // Attach to results
            foreach ($indices_ids as $result_index => $id) {
                if (isset($related_map[$id])) {
                    $results[$result_index][$key] = $related_map[$id];
                }
            }
        }

        return $results;
    }

    /**
     * Load a polymorphic many relation
     */
    protected function load_polymorphic_many_relation(
        array $results,
        PolymorphicMany $relation,
        array $config,
        string $key
    ): array {
        if (empty($results)) {
            return $results;
        }

        $type_column = $relation->get_type_column();
        $id_column = $relation->get_id_column();
        $type_value = $relation->get_type_value();
        $target_table = $relation->get_target_table();
        $references = $relation->get_references();

        // Get source PK
        if (empty($references)) {
            $pk_columns = $this->table->get_primary_keys();
            if (!empty($pk_columns)) {
                $pk_column = $this->table->get_column($pk_columns[0]);
                if ($pk_column !== null) {
                    $references = [$pk_column];
                }
            }
        }

        if (empty($references)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        $ref_name = $references[0]->get_name();
        $type_col_name = $type_column->get_name();
        $id_col_name = $id_column->get_name();

        // Collect source PK values
        $pk_values = array_unique(array_filter(
            array_column($results, $ref_name),
            fn($v) => $v !== null
        ));

        if (empty($pk_values)) {
            foreach ($results as &$row) {
                $row[$key] = [];
            }
            return $results;
        }

        // Build query for polymorphic children
        $related_query = new TableQuery($this->connection, $this->dialect, $target_table);

        if (isset($config['columns'])) {
            $related_query = $related_query->columns($this->with_matching_key($config['columns'], $id_column));
        }

        // WHERE type = ? AND id IN (...)
        $type_condition = new Comparison($type_column, '=', $type_value);
        $id_condition = new \Italix\Orm\Operators\InExpression($id_column, $pk_values);
        $where = new AndExpression($type_condition, $id_condition);

        if (isset($config['where'])) {
            $where = new AndExpression($where, $config['where']);
        }

        $related_query = $related_query->where($where);

        if (isset($config['order_by'])) {
            $related_query = $related_query->order_by(...(array)$config['order_by']);
        }

        if (isset($config['with'])) {
            $related_query = $related_query->with($config['with']);
        }

        $related_results = $related_query->find_many();

        // Group by polymorphic ID
        $related_map = [];
        foreach ($related_results as $related_row) {
            $parent_id = $related_row[$id_col_name] ?? null;
            if ($parent_id !== null) {
                if (!isset($related_map[$parent_id])) {
                    $related_map[$parent_id] = [];
                }
                $related_map[$parent_id][] = $related_row;
            }
        }

        // Attach to results
        if (isset($config['limit'])) {
            $related_map = $this->cap_per_parent($related_map, (int) $config['limit']);
        }

        foreach ($results as &$row) {
            $pk_value = $row[$ref_name] ?? null;
            $row[$key] = $related_map[$pk_value] ?? [];
        }

        return $results;
    }

    /**
     * Build placeholder string and add values to params
     */
    protected function build_placeholders(int $count, array &$params, array $values): string
    {
        $placeholders = [];
        foreach ($values as $value) {
            $params[] = $value;
            $placeholders[] = $this->get_placeholder(count($params));
        }
        return implode(', ', $placeholders);
    }

    /**
     * Quote identifier based on dialect
     */
    protected function quote_identifier(string $name): string
    {
        if ($this->dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Get column reference
     */
    protected function get_column_ref(Column $column): string
    {
        $table = $column->get_table();
        if ($table !== null) {
            return $this->quote_identifier($table->get_name()) . '.' .
                   $this->quote_identifier($column->get_db_name());
        }
        return $this->quote_identifier($column->get_db_name());
    }

    /**
     * Get parameter placeholder
     */
    protected function get_placeholder(int $index): string
    {
        // `?` on every dialect — see QueryBuilder::get_placeholder() for what the
        // `$1` form did to a PostgreSQL query going through PDO.
        return '?';
    }
}
