<?php
/**
 * Italix ORM - Relational Query Builder
 *
 * Drizzle-style query builder with find_first(), find_many(), and eager loading.
 *
 * @package Italix\Orm\Relations
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Relations;

use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\Column;
use Italix\Orm\Operators\SQLExpression;
use Italix\Orm\Operators\Comparison;
use Italix\Orm\Operators\AndExpression;
use Italix\Orm\Operators\OrderDirection;
use PDO;

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
            $results = $this->load_relations($results);
        }

        return $results;
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
     * Find a record by its primary key
     *
     * @param mixed $id Primary key value
     * @return array|null
     */
    public function find(mixed $id): ?array
    {
        $pk_columns = $this->table->get_primary_keys();
        if (empty($pk_columns)) {
            throw new \RuntimeException("Table {$this->table->get_name()} has no primary key defined");
        }

        $pk_column = $this->table->get_column($pk_columns[0]);
        if ($pk_column === null) {
            throw new \RuntimeException("Primary key column not found");
        }

        return $this->where(new Comparison($pk_column, '=', $id))->find_first();
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

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $cols = [];
            foreach ($this->columns as $col) {
                if ($col instanceof Column) {
                    $cols[] = $this->get_column_ref($col);
                } else {
                    $cols[] = $this->quote_identifier((string)$col);
                }
            }
            $parts[] = implode(', ', $cols);
        }

        // FROM
        $parts[] = 'FROM ' . $this->quote_identifier($this->table->get_full_name());

        // WHERE
        if ($this->where_condition !== null) {
            $parts[] = 'WHERE ' . $this->where_condition->to_sql($this->dialect, $params);
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
            $related_query = $related_query->columns($config['columns']);
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
            $related_query = $related_query->columns($config['columns']);
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

        if (isset($config['limit'])) {
            // Note: per-parent limit requires more complex handling
            // For now, apply global limit
            $related_query = $related_query->limit($config['limit']);
        }

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

        // Attach to results
        foreach ($results as &$row) {
            $pk_value = $row[$field_name] ?? null;
            $row[$key] = $related_map[$pk_value] ?? [];
        }

        return $results;
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
            $related_query = $related_query->columns($config['columns']);
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
                $related_query = $related_query->columns($config['columns']);
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
            $related_query = $related_query->columns($config['columns']);
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
        return in_array($this->dialect, ['postgresql', 'supabase']) ? '$' . $index : '?';
    }
}
