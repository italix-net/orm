<?php
/**
 * Italix ORM - Relation Functions
 *
 * Drizzle-style relation definition functions.
 *
 * @package Italix\Orm\Relations
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Relations;

// Ensure relation classes are loaded
require_once __DIR__ . '/Relation.php';
require_once __DIR__ . '/RelationBuilder.php';
require_once __DIR__ . '/RelationsRegistry.php';
require_once __DIR__ . '/RelationalQueryBuilder.php';

use Italix\Orm\Schema\Table;

/**
 * Define relations for a table (Drizzle-style)
 *
 * This is the main function to define relationships between tables.
 * It follows Drizzle ORM's pattern of using a callback that receives
 * helper functions for creating relations.
 *
 * Usage:
 *   $users_relations = define_relations($users, function($r) use ($posts, $profiles) {
 *       return [
 *           'posts' => $r->many($posts),
 *           'profile' => $r->one($profiles, [
 *               'fields' => [$users->id],
 *               'references' => [$profiles->user_id]
 *           ])
 *       ];
 *   });
 *
 * @param Table $table The table to define relations for
 * @param callable $callback Callback receiving RelationBuilder, returns array of relations
 * @return TableRelations The created relations container
 */
function define_relations(Table $table, callable $callback): TableRelations
{
    $builder = new RelationBuilder($table);
    $relation_defs = $callback($builder);

    $table_relations = new TableRelations($table);

    foreach ($relation_defs as $name => $definition) {
        if ($definition instanceof Relation) {
            // Already built relation
            $table_relations->add($definition);
        } elseif ($definition instanceof OneBuilder) {
            $table_relations->add($definition->build($name));
        } elseif ($definition instanceof ManyBuilder) {
            $table_relations->add($definition->build($name));
        } elseif ($definition instanceof PolymorphicOneBuilder) {
            $table_relations->add($definition->build($name));
        } elseif ($definition instanceof PolymorphicManyBuilder) {
            $table_relations->add($definition->build($name));
        } else {
            throw new \InvalidArgumentException(
                "Invalid relation definition for '{$name}'. " .
                "Expected Relation or Builder instance."
            );
        }
    }

    // Register in global registry
    RelationsRegistry::get_instance()->register($table, $table_relations);

    return $table_relations;
}

/**
 * Shorthand for creating a One relation directly
 *
 * @param Table $target Target table
 * @param array $config Configuration
 */
function one(Table $target, array $config = []): array
{
    return ['__type' => 'one', 'target' => $target, 'config' => $config];
}

/**
 * Shorthand for creating a Many relation directly
 *
 * @param Table $target Target table
 * @param array $config Configuration
 */
function many(Table $target, array $config = []): array
{
    return ['__type' => 'many', 'target' => $target, 'config' => $config];
}

/**
 * Get relations for a table from the global registry
 *
 * @param Table $table
 * @return TableRelations|null
 */
function get_relations(Table $table): ?TableRelations
{
    return RelationsRegistry::get_instance()->get($table);
}

/**
 * Get a specific relation from a table
 *
 * @param Table $table
 * @param string $name Relation name
 * @return Relation|null
 */
function get_relation(Table $table, string $name): ?Relation
{
    $relations = RelationsRegistry::get_instance()->get($table);
    return $relations ? $relations->get($name) : null;
}
