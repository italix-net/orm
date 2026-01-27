<?php
/**
 * Italix ORM - Relation Builder
 *
 * Drizzle-style relation builder with one() and many() helper functions.
 *
 * @package Italix\Orm\Relations
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Relations;

use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\Column;

/**
 * Relation builder helpers passed to define_relations() callback
 *
 * Usage:
 *   define_relations($users, function(RelationBuilder $r) use ($posts, $profiles) {
 *       return [
 *           'posts' => $r->many($posts),
 *           'profile' => $r->one($profiles, [
 *               'fields' => [$users->id],
 *               'references' => [$profiles->user_id]
 *           ])
 *       ];
 *   });
 */
class RelationBuilder
{
    /** @var Table The source table for relations */
    protected Table $source_table;

    /**
     * Create a new RelationBuilder
     */
    public function __construct(Table $source_table)
    {
        $this->source_table = $source_table;
    }

    /**
     * Define a "one" relation (one-to-one or many-to-one)
     *
     * Use this when:
     * - Many-to-one: current table has FK to target (e.g., posts.author_id -> users.id)
     * - One-to-one: current table's PK is referenced by target's FK
     *
     * @param Table $target Target table
     * @param array $config Configuration:
     *   - fields: array<Column> - columns on source table
     *   - references: array<Column> - columns on target table
     *   - alias: string|null - optional alias for the relation
     *   - relation_name: string|null - name of inverse relation on target
     */
    public function one(Table $target, array $config = []): OneBuilder
    {
        return new OneBuilder($this->source_table, $target, $config);
    }

    /**
     * Define a "many" relation (one-to-many or many-to-many)
     *
     * Use this when:
     * - One-to-many: target table has FK pointing to current table
     * - Many-to-many: with 'through' option for junction table
     *
     * @param Table $target Target table
     * @param array $config Configuration:
     *   - fields: array<Column> - columns on source table (optional, auto-inferred)
     *   - references: array<Column> - columns on target table (optional, auto-inferred)
     *   - through: Table - junction table for many-to-many
     *   - through_fields: array<Column> - junction columns referencing source
     *   - target_fields: array<Column> - junction columns referencing target
     *   - target_references: array<Column> - PK columns on target
     *   - alias: string|null - optional alias
     *   - relation_name: string|null - name of inverse relation
     */
    public function many(Table $target, array $config = []): ManyBuilder
    {
        return new ManyBuilder($this->source_table, $target, $config);
    }

    /**
     * Define a polymorphic "one" relation (belongs to polymorphic)
     *
     * Use this when the current table can belong to multiple different tables.
     *
     * @param array $config Configuration:
     *   - type_column: Column - stores the type discriminator
     *   - id_column: Column - stores the polymorphic ID
     *   - targets: array<string, Table> - map of type value => target table
     *   - alias: string|null - optional alias
     */
    public function one_polymorphic(array $config): PolymorphicOneBuilder
    {
        return new PolymorphicOneBuilder($this->source_table, $config);
    }

    /**
     * Define a polymorphic "many" relation (has many polymorphic)
     *
     * Use this when the current table can have many of a polymorphic child.
     *
     * @param Table $target Target polymorphic table
     * @param array $config Configuration:
     *   - type_column: Column - column on target storing type discriminator
     *   - id_column: Column - column on target storing polymorphic ID
     *   - type_value: string - the type value that identifies this relation
     *   - references: array<Column> - PK columns on source table
     *   - alias: string|null - optional alias
     */
    public function many_polymorphic(Table $target, array $config): PolymorphicManyBuilder
    {
        return new PolymorphicManyBuilder($this->source_table, $target, $config);
    }
}

/**
 * Builder for One relations with fluent interface
 */
class OneBuilder
{
    protected Table $source_table;
    protected Table $target_table;
    protected array $config;

    public function __construct(Table $source_table, Table $target_table, array $config = [])
    {
        $this->source_table = $source_table;
        $this->target_table = $target_table;
        $this->config = $config;
    }

    /**
     * Set the fields (columns on source table)
     *
     * @param Column|array<Column> $fields
     */
    public function fields($fields): self
    {
        $this->config['fields'] = is_array($fields) ? $fields : [$fields];
        return $this;
    }

    /**
     * Set the references (columns on target table)
     *
     * @param Column|array<Column> $references
     */
    public function references($references): self
    {
        $this->config['references'] = is_array($references) ? $references : [$references];
        return $this;
    }

    /**
     * Set an alias for the relation
     */
    public function alias(string $alias): self
    {
        $this->config['alias'] = $alias;
        return $this;
    }

    /**
     * Set the relation name on the target table (for bidirectional)
     */
    public function relation_name(string $name): self
    {
        $this->config['relation_name'] = $name;
        return $this;
    }

    /**
     * Build the One relation
     *
     * @param string $name Relation name
     */
    public function build(string $name): One
    {
        return new One($name, $this->source_table, $this->target_table, $this->config);
    }

    /**
     * Get source table
     */
    public function get_source_table(): Table
    {
        return $this->source_table;
    }

    /**
     * Get target table
     */
    public function get_target_table(): Table
    {
        return $this->target_table;
    }

    /**
     * Get config
     */
    public function get_config(): array
    {
        return $this->config;
    }
}

/**
 * Builder for Many relations with fluent interface
 */
class ManyBuilder
{
    protected Table $source_table;
    protected Table $target_table;
    protected array $config;

    public function __construct(Table $source_table, Table $target_table, array $config = [])
    {
        $this->source_table = $source_table;
        $this->target_table = $target_table;
        $this->config = $config;
    }

    /**
     * Set the fields (columns on source table)
     *
     * @param Column|array<Column> $fields
     */
    public function fields($fields): self
    {
        $this->config['fields'] = is_array($fields) ? $fields : [$fields];
        return $this;
    }

    /**
     * Set the references (columns on target table)
     *
     * @param Column|array<Column> $references
     */
    public function references($references): self
    {
        $this->config['references'] = is_array($references) ? $references : [$references];
        return $this;
    }

    /**
     * Set the junction table for many-to-many
     */
    public function through(Table $junction): self
    {
        $this->config['through'] = $junction;
        return $this;
    }

    /**
     * Set the through fields (junction columns referencing source)
     *
     * @param Column|array<Column> $fields
     */
    public function through_fields($fields): self
    {
        $this->config['through_fields'] = is_array($fields) ? $fields : [$fields];
        return $this;
    }

    /**
     * Set the target fields (junction columns referencing target)
     *
     * @param Column|array<Column> $fields
     */
    public function target_fields($fields): self
    {
        $this->config['target_fields'] = is_array($fields) ? $fields : [$fields];
        return $this;
    }

    /**
     * Set the target references (PK columns on target table)
     *
     * @param Column|array<Column> $references
     */
    public function target_references($references): self
    {
        $this->config['target_references'] = is_array($references) ? $references : [$references];
        return $this;
    }

    /**
     * Set an alias for the relation
     */
    public function alias(string $alias): self
    {
        $this->config['alias'] = $alias;
        return $this;
    }

    /**
     * Set the relation name on the target table (for bidirectional)
     */
    public function relation_name(string $name): self
    {
        $this->config['relation_name'] = $name;
        return $this;
    }

    /**
     * Build the Many relation
     *
     * @param string $name Relation name
     */
    public function build(string $name): Many
    {
        return new Many($name, $this->source_table, $this->target_table, $this->config);
    }

    /**
     * Get source table
     */
    public function get_source_table(): Table
    {
        return $this->source_table;
    }

    /**
     * Get target table
     */
    public function get_target_table(): Table
    {
        return $this->target_table;
    }

    /**
     * Get config
     */
    public function get_config(): array
    {
        return $this->config;
    }
}

/**
 * Builder for PolymorphicOne relations
 */
class PolymorphicOneBuilder
{
    protected Table $source_table;
    protected array $config;

    public function __construct(Table $source_table, array $config)
    {
        $this->source_table = $source_table;
        $this->config = $config;
    }

    /**
     * Set the type discriminator column
     */
    public function type_column(Column $column): self
    {
        $this->config['type_column'] = $column;
        return $this;
    }

    /**
     * Set the polymorphic ID column
     */
    public function id_column(Column $column): self
    {
        $this->config['id_column'] = $column;
        return $this;
    }

    /**
     * Add a target table with its type value
     */
    public function target(string $type, Table $table): self
    {
        if (!isset($this->config['targets'])) {
            $this->config['targets'] = [];
        }
        $this->config['targets'][$type] = $table;
        return $this;
    }

    /**
     * Set all targets at once
     *
     * @param array<string, Table> $targets
     */
    public function targets(array $targets): self
    {
        $this->config['targets'] = $targets;
        return $this;
    }

    /**
     * Set an alias for the relation
     */
    public function alias(string $alias): self
    {
        $this->config['alias'] = $alias;
        return $this;
    }

    /**
     * Build the PolymorphicOne relation
     *
     * @param string $name Relation name
     */
    public function build(string $name): PolymorphicOne
    {
        return new PolymorphicOne($name, $this->source_table, $this->config);
    }

    /**
     * Get source table
     */
    public function get_source_table(): Table
    {
        return $this->source_table;
    }

    /**
     * Get config
     */
    public function get_config(): array
    {
        return $this->config;
    }
}

/**
 * Builder for PolymorphicMany relations
 */
class PolymorphicManyBuilder
{
    protected Table $source_table;
    protected Table $target_table;
    protected array $config;

    public function __construct(Table $source_table, Table $target_table, array $config)
    {
        $this->source_table = $source_table;
        $this->target_table = $target_table;
        $this->config = $config;
    }

    /**
     * Set the type discriminator column
     */
    public function type_column(Column $column): self
    {
        $this->config['type_column'] = $column;
        return $this;
    }

    /**
     * Set the polymorphic ID column
     */
    public function id_column(Column $column): self
    {
        $this->config['id_column'] = $column;
        return $this;
    }

    /**
     * Set the type value that identifies this relation
     */
    public function type_value(string $value): self
    {
        $this->config['type_value'] = $value;
        return $this;
    }

    /**
     * Set the references (PK columns on source table)
     *
     * @param Column|array<Column> $references
     */
    public function references($references): self
    {
        $this->config['references'] = is_array($references) ? $references : [$references];
        return $this;
    }

    /**
     * Set an alias for the relation
     */
    public function alias(string $alias): self
    {
        $this->config['alias'] = $alias;
        return $this;
    }

    /**
     * Build the PolymorphicMany relation
     *
     * @param string $name Relation name
     */
    public function build(string $name): PolymorphicMany
    {
        return new PolymorphicMany($name, $this->source_table, $this->target_table, $this->config);
    }

    /**
     * Get source table
     */
    public function get_source_table(): Table
    {
        return $this->source_table;
    }

    /**
     * Get target table
     */
    public function get_target_table(): Table
    {
        return $this->target_table;
    }

    /**
     * Get config
     */
    public function get_config(): array
    {
        return $this->config;
    }
}
