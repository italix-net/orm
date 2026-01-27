<?php
/**
 * Italix ORM - Relations Registry
 *
 * Global registry for table relations, following Drizzle's pattern.
 *
 * @package Italix\Orm\Relations
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Relations;

use Italix\Orm\Schema\Table;

/**
 * Global registry for table relations
 *
 * This singleton stores all defined relations and provides lookup methods.
 */
class RelationsRegistry
{
    /** @var RelationsRegistry|null Singleton instance */
    protected static ?RelationsRegistry $instance = null;

    /** @var array<string, TableRelations> Relations indexed by table name */
    protected array $relations = [];

    /**
     * Private constructor for singleton
     */
    private function __construct()
    {
    }

    /**
     * Get the singleton instance
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register relations for a table
     */
    public function register(Table $table, TableRelations $relations): void
    {
        $this->relations[$table->get_name()] = $relations;
    }

    /**
     * Get relations for a table
     */
    public function get(Table $table): ?TableRelations
    {
        return $this->relations[$table->get_name()] ?? null;
    }

    /**
     * Get relations by table name
     */
    public function get_by_name(string $table_name): ?TableRelations
    {
        return $this->relations[$table_name] ?? null;
    }

    /**
     * Check if a table has registered relations
     */
    public function has(Table $table): bool
    {
        return isset($this->relations[$table->get_name()]);
    }

    /**
     * Get all registered relations
     *
     * @return array<string, TableRelations>
     */
    public function all(): array
    {
        return $this->relations;
    }

    /**
     * Clear all registered relations (useful for testing)
     */
    public function clear(): void
    {
        $this->relations = [];
    }

    /**
     * Reset the singleton instance (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

/**
 * Container for a table's relations
 */
class TableRelations
{
    /** @var Table The table these relations belong to */
    protected Table $table;

    /** @var array<string, Relation> Relations indexed by name */
    protected array $relations = [];

    /**
     * Create a new TableRelations container
     */
    public function __construct(Table $table)
    {
        $this->table = $table;
    }

    /**
     * Get the table
     */
    public function get_table(): Table
    {
        return $this->table;
    }

    /**
     * Add a relation
     */
    public function add(Relation $relation): self
    {
        $this->relations[$relation->get_name()] = $relation;
        return $this;
    }

    /**
     * Get a relation by name
     */
    public function get(string $name): ?Relation
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Check if a relation exists
     */
    public function has(string $name): bool
    {
        return isset($this->relations[$name]);
    }

    /**
     * Get all relations
     *
     * @return array<string, Relation>
     */
    public function all(): array
    {
        return $this->relations;
    }

    /**
     * Get all "one" type relations
     *
     * @return array<string, Relation>
     */
    public function get_one_relations(): array
    {
        return array_filter($this->relations, fn($r) => $r->is_one());
    }

    /**
     * Get all "many" type relations
     *
     * @return array<string, Relation>
     */
    public function get_many_relations(): array
    {
        return array_filter($this->relations, fn($r) => $r->is_many());
    }

    /**
     * Magic getter for relation access
     *
     * @param string $name
     * @return Relation|null
     */
    public function __get(string $name)
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Check if relation exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->relations[$name]);
    }
}
