<?php
/**
 * Italix ORM - Relation Classes
 *
 * Drizzle-style relation definitions for one-to-one, one-to-many,
 * many-to-one, and many-to-many relationships.
 *
 * @package Italix\Orm\Relations
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Relations;

use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\Column;

/**
 * Base class for all relation types
 */
abstract class Relation
{
    /** @var string Relation name (used as property name) */
    protected string $name;

    /** @var Table Source table */
    protected Table $source_table;

    /** @var Table Target/referenced table */
    protected Table $target_table;

    /** @var array<Column> Fields on the source table */
    protected array $fields = [];

    /** @var array<Column> References on the target table */
    protected array $references = [];

    /** @var string|null Optional alias for the relation */
    protected ?string $alias = null;

    /** @var string|null Relation name on the target table (for bidirectional) */
    protected ?string $relation_name = null;

    /** @var array Extra configuration options */
    protected array $config = [];

    /**
     * Get relation name
     */
    public function get_name(): string
    {
        return $this->name;
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
     * Get fields (columns on source table)
     *
     * @return array<Column>
     */
    public function get_fields(): array
    {
        return $this->fields;
    }

    /**
     * Get references (columns on target table)
     *
     * @return array<Column>
     */
    public function get_references(): array
    {
        return $this->references;
    }

    /**
     * Get alias
     */
    public function get_alias(): ?string
    {
        return $this->alias;
    }

    /**
     * Get relation name for bidirectional relations
     */
    public function get_relation_name(): ?string
    {
        return $this->relation_name;
    }

    /**
     * Get extra configuration
     */
    public function get_config(): array
    {
        return $this->config;
    }

    /**
     * Get the type of relation
     */
    abstract public function get_type(): string;

    /**
     * Check if this is a "one" type relation (one-to-one or many-to-one)
     */
    abstract public function is_one(): bool;

    /**
     * Check if this is a "many" type relation (one-to-many or many-to-many)
     */
    abstract public function is_many(): bool;
}

/**
 * One relation - represents one-to-one or many-to-one relationships
 *
 * Used when the current table holds the foreign key pointing to another table.
 *
 * Example (many-to-one: posts -> users):
 *   one($users, [
 *       'fields' => [$posts->author_id],
 *       'references' => [$users->id]
 *   ])
 *
 * Example (one-to-one: users -> profiles):
 *   one($profiles, [
 *       'fields' => [$users->id],
 *       'references' => [$profiles->user_id]
 *   ])
 */
class One extends Relation
{
    /**
     * Create a One relation
     *
     * @param string $name Relation name
     * @param Table $source_table Source table (where relation is defined)
     * @param Table $target_table Target table to relate to
     * @param array $config Configuration array with 'fields' and 'references'
     */
    public function __construct(
        string $name,
        Table $source_table,
        Table $target_table,
        array $config = []
    ) {
        $this->name = $name;
        $this->source_table = $source_table;
        $this->target_table = $target_table;
        $this->fields = $config['fields'] ?? [];
        $this->references = $config['references'] ?? [];
        $this->alias = $config['alias'] ?? null;
        $this->relation_name = $config['relation_name'] ?? null;
        $this->config = $config;
    }

    public function get_type(): string
    {
        return 'one';
    }

    public function is_one(): bool
    {
        return true;
    }

    public function is_many(): bool
    {
        return false;
    }
}

/**
 * Many relation - represents one-to-many or many-to-many relationships
 *
 * Used when multiple records in another table reference the current table.
 *
 * Example (one-to-many: users -> posts):
 *   many($posts)  // Auto-inferred from posts.author_id -> users.id
 *
 * Example (many-to-many: users -> roles through user_roles):
 *   many($roles, [
 *       'through' => $user_roles,
 *       'fields' => [$users->id],
 *       'references' => [$user_roles->user_id],
 *       'target_fields' => [$user_roles->role_id],
 *       'target_references' => [$roles->id]
 *   ])
 */
class Many extends Relation
{
    /** @var Table|null Junction table for many-to-many */
    protected ?Table $through_table = null;

    /** @var array<Column> Fields on junction table referencing source */
    protected array $through_fields = [];

    /** @var array<Column> Fields on junction table referencing target */
    protected array $target_fields = [];

    /** @var array<Column> References on target table (for many-to-many) */
    protected array $target_references = [];

    /**
     * Create a Many relation
     *
     * @param string $name Relation name
     * @param Table $source_table Source table (where relation is defined)
     * @param Table $target_table Target table to relate to
     * @param array $config Configuration array
     */
    public function __construct(
        string $name,
        Table $source_table,
        Table $target_table,
        array $config = []
    ) {
        $this->name = $name;
        $this->source_table = $source_table;
        $this->target_table = $target_table;
        $this->fields = $config['fields'] ?? [];
        $this->references = $config['references'] ?? [];
        $this->alias = $config['alias'] ?? null;
        $this->relation_name = $config['relation_name'] ?? null;
        $this->through_table = $config['through'] ?? null;
        $this->through_fields = $config['through_fields'] ?? [];
        $this->target_fields = $config['target_fields'] ?? [];
        $this->target_references = $config['target_references'] ?? [];
        $this->config = $config;
    }

    public function get_type(): string
    {
        return 'many';
    }

    public function is_one(): bool
    {
        return false;
    }

    public function is_many(): bool
    {
        return true;
    }

    /**
     * Check if this is a many-to-many relation (has through table)
     */
    public function is_many_to_many(): bool
    {
        return $this->through_table !== null;
    }

    /**
     * Get the junction/through table
     */
    public function get_through_table(): ?Table
    {
        return $this->through_table;
    }

    /**
     * Get through fields (source side of junction)
     *
     * @return array<Column>
     */
    public function get_through_fields(): array
    {
        return $this->through_fields;
    }

    /**
     * Get target fields (target side of junction)
     *
     * @return array<Column>
     */
    public function get_target_fields(): array
    {
        return $this->target_fields;
    }

    /**
     * Get target references
     *
     * @return array<Column>
     */
    public function get_target_references(): array
    {
        return $this->target_references;
    }
}

/**
 * Polymorphic relation - for relationships where target type varies
 *
 * Used for patterns like comments that can belong to posts, videos, etc.
 *
 * Example:
 *   $comments_relations = define_relations($comments, function($helpers) use ($posts, $videos) {
 *       return [
 *           'commentable' => $helpers->one_polymorphic([
 *               'type_column' => $comments->commentable_type,
 *               'id_column' => $comments->commentable_id,
 *               'targets' => [
 *                   'post' => $posts,
 *                   'video' => $videos,
 *               ]
 *           ])
 *       ];
 *   });
 */
class PolymorphicOne extends Relation
{
    /** @var Column Column storing the type discriminator */
    protected Column $type_column;

    /** @var Column Column storing the polymorphic ID */
    protected Column $id_column;

    /** @var array<string, Table> Map of type value => target table */
    protected array $targets = [];

    /**
     * Create a PolymorphicOne relation
     *
     * @param string $name Relation name
     * @param Table $source_table Source table
     * @param array $config Configuration with type_column, id_column, targets
     */
    public function __construct(
        string $name,
        Table $source_table,
        array $config
    ) {
        $this->name = $name;
        $this->source_table = $source_table;
        $this->type_column = $config['type_column'];
        $this->id_column = $config['id_column'];
        $this->targets = $config['targets'] ?? [];
        $this->alias = $config['alias'] ?? null;
        $this->config = $config;

        // Set target_table to first target for compatibility
        if (!empty($this->targets)) {
            $this->target_table = reset($this->targets);
        }
    }

    public function get_type(): string
    {
        return 'polymorphic_one';
    }

    public function is_one(): bool
    {
        return true;
    }

    public function is_many(): bool
    {
        return false;
    }

    /**
     * Get the type discriminator column
     */
    public function get_type_column(): Column
    {
        return $this->type_column;
    }

    /**
     * Get the polymorphic ID column
     */
    public function get_id_column(): Column
    {
        return $this->id_column;
    }

    /**
     * Get all target tables with their type keys
     *
     * @return array<string, Table>
     */
    public function get_targets(): array
    {
        return $this->targets;
    }

    /**
     * Get target table for a specific type
     */
    public function get_target_for_type(string $type): ?Table
    {
        return $this->targets[$type] ?? null;
    }
}

/**
 * Polymorphic Many - for "has many" polymorphic relationships
 *
 * Example: A post/video can have many comments
 *   $posts_relations = define_relations($posts, function($helpers) use ($comments) {
 *       return [
 *           'comments' => $helpers->many_polymorphic($comments, [
 *               'type_column' => $comments->commentable_type,
 *               'id_column' => $comments->commentable_id,
 *               'type_value' => 'post',
 *               'references' => [$posts->id]
 *           ])
 *       ];
 *   });
 */
class PolymorphicMany extends Relation
{
    /** @var Column Column storing the type discriminator */
    protected Column $type_column;

    /** @var Column Column storing the polymorphic ID */
    protected Column $id_column;

    /** @var string The type value that identifies this relation */
    protected string $type_value;

    /**
     * Create a PolymorphicMany relation
     *
     * @param string $name Relation name
     * @param Table $source_table Source table (the "parent" like Post)
     * @param Table $target_table Target table (the polymorphic child like Comment)
     * @param array $config Configuration
     */
    public function __construct(
        string $name,
        Table $source_table,
        Table $target_table,
        array $config
    ) {
        $this->name = $name;
        $this->source_table = $source_table;
        $this->target_table = $target_table;
        $this->type_column = $config['type_column'];
        $this->id_column = $config['id_column'];
        $this->type_value = $config['type_value'];
        $this->references = $config['references'] ?? [];
        $this->alias = $config['alias'] ?? null;
        $this->config = $config;
    }

    public function get_type(): string
    {
        return 'polymorphic_many';
    }

    public function is_one(): bool
    {
        return false;
    }

    public function is_many(): bool
    {
        return true;
    }

    /**
     * Get the type discriminator column
     */
    public function get_type_column(): Column
    {
        return $this->type_column;
    }

    /**
     * Get the polymorphic ID column
     */
    public function get_id_column(): Column
    {
        return $this->id_column;
    }

    /**
     * Get the type value for this relation
     */
    public function get_type_value(): string
    {
        return $this->type_value;
    }
}
