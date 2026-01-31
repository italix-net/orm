<?php

namespace Italix\Orm\ActiveRow\Traits;

use Italix\Orm\ActiveRow\ActiveRow;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\in_;

/**
 * Get all traits used by a class, including traits of parent classes and traits of traits.
 *
 * @param string $class
 * @return array<string>
 */
function class_uses_recursive(string $class): array
{
    $results = [];

    // Get traits from the class and all its parents
    foreach (array_merge([$class], class_parents($class) ?: []) as $class_name) {
        $results = array_merge($results, class_uses($class_name) ?: []);
    }

    // Get traits from traits (recursive)
    $traits_to_check = $results;
    while (!empty($traits_to_check)) {
        $trait = array_pop($traits_to_check);
        $trait_traits = class_uses($trait) ?: [];
        foreach ($trait_traits as $trait_trait) {
            if (!in_array($trait_trait, $results, true)) {
                $results[] = $trait_trait;
                $traits_to_check[] = $trait_trait;
            }
        }
    }

    return array_unique($results);
}

/**
 * Trait DelegatedTypes
 *
 * Implements the Delegated Types pattern (inspired by Rails) with support
 * for N-level delegation chains. Allows a "superclass" record to delegate
 * behavior and attributes to type-specific "subclass" records stored in
 * separate tables, with support for arbitrary nesting depth.
 *
 * This pattern is ideal for:
 * - Schema.org-style hierarchies (Thing → CreativeWork → Book → TextBook)
 * - Polymorphic content systems (Entry → Message|Comment|Photo)
 * - Any scenario requiring efficient queries across related types
 *
 * Features:
 * - N-level chained delegation (Thing → Book → TextBook → ...)
 * - Atomic creation/update/delete across all levels
 * - Recursive eager loading with depth control
 * - Deep method delegation through the entire chain
 * - Chain traversal helpers (get_chain, leaf, get_from_chain)
 *
 * @example Two-level delegation
 * class Thing extends ActiveRow {
 *     use Persistable, DelegatedTypes;
 *
 *     protected function get_delegated_types(): array {
 *         return [
 *             'Book'   => Book::class,
 *             'Person' => Person::class,
 *         ];
 *     }
 * }
 *
 * // Create with two levels
 * $thing = Thing::create_with_delegate('Book',
 *     ['name' => 'Design Patterns'],
 *     ['isbn' => '978-0201633610']
 * );
 *
 * @example N-level delegation (3+ levels)
 * class Book extends ActiveRow {
 *     use Persistable, DelegatedTypes;
 *
 *     protected function get_delegated_types(): array {
 *         return [
 *             'TextBook'  => TextBook::class,
 *             'AudioBook' => AudioBook::class,
 *         ];
 *     }
 * }
 *
 * // Create with three levels using create_chain()
 * $thing = Thing::create_chain([
 *     'Thing'    => ['name' => 'Calculus'],
 *     'Book'     => ['isbn' => '978-1234567890'],
 *     'TextBook' => ['edition' => '5th', 'grade_level' => 'college'],
 * ]);
 *
 * // Access any level
 * $thing->delegate();                    // Book
 * $thing->delegate()->delegate();        // TextBook
 * $thing->leaf();                        // TextBook (deepest)
 * $thing->get_chain();                   // [Thing, Book, TextBook]
 *
 * // Methods delegate through the entire chain
 * $thing->formatted_isbn();              // → Book::formatted_isbn()
 * $thing->edition();                     // → TextBook::edition()
 */
trait DelegatedTypes
{
    /**
     * Cache for loaded delegate
     * @var ActiveRow|null
     */
    protected ?ActiveRow $delegate_cache = null;

    // =========================================
    // CONFIGURATION (override in subclass)
    // =========================================

    /**
     * Map of type names to ActiveRow classes
     * Override this method to define your delegated types.
     *
     * @return array<string, class-string<ActiveRow>>
     */
    protected function get_delegated_types(): array
    {
        return [];
    }

    /**
     * Column that stores the type name
     *
     * @return string
     */
    protected function get_type_column(): string
    {
        return 'type';
    }

    /**
     * Column that stores the hierarchy path (optional)
     * Set to null to disable hierarchy path support.
     *
     * @return string|null
     */
    protected function get_type_path_column(): ?string
    {
        return 'type_path';
    }

    /**
     * Foreign key column in delegate tables pointing back to this table
     *
     * @return string
     */
    protected function get_delegate_foreign_key(): string
    {
        return 'thing_id';
    }

    // =========================================
    // DELEGATE ACCESS
    // =========================================

    /**
     * Get the delegated row (Book, Movie, Person, etc.)
     * Lazy-loads and caches the result.
     *
     * @return ActiveRow|null
     */
    public function delegate(): ?ActiveRow
    {
        if ($this->delegate_cache !== null) {
            return $this->delegate_cache;
        }

        $class = $this->delegate_class();
        if ($class === null) {
            return null;
        }

        $foreign_key = $this->get_delegate_foreign_key();
        $table = $class::get_table();

        $this->delegate_cache = $class::find_one([
            'where' => eq($table->$foreign_key, $this['id']),
        ]);

        return $this->delegate_cache;
    }

    /**
     * Check if this thing has a delegate loadable
     *
     * @return bool
     */
    public function has_delegate(): bool
    {
        return $this->delegate_class() !== null;
    }

    /**
     * Get the delegate class for this thing's type
     *
     * @return class-string<ActiveRow>|null
     */
    public function delegate_class(): ?string
    {
        $type = $this[$this->get_type_column()];
        $types = $this->get_delegated_types();

        return $types[$type] ?? null;
    }

    /**
     * Set a pre-loaded delegate (for eager loading)
     *
     * @param ActiveRow $delegate
     * @return static
     */
    public function set_delegate(ActiveRow $delegate): static
    {
        $this->delegate_cache = $delegate;
        return $this;
    }

    /**
     * Clear the delegate cache
     *
     * @return static
     */
    public function clear_delegate_cache(): static
    {
        $this->delegate_cache = null;
        return $this;
    }

    // =========================================
    // TYPE CHECKING
    // =========================================

    /**
     * Check if this thing is of a specific type
     *
     * @param string $type
     * @return bool
     */
    public function is_type(string $type): bool
    {
        return $this[$this->get_type_column()] === $type;
    }

    /**
     * Check if this thing is in a type hierarchy
     * e.g., is_in_hierarchy('CreativeWork') for a Book
     *
     * @param string $ancestor
     * @return bool
     */
    public function is_in_hierarchy(string $ancestor): bool
    {
        $path_column = $this->get_type_path_column();
        if ($path_column === null) {
            return false;
        }

        $path = $this[$path_column] ?? '';
        return str_contains($path, $ancestor);
    }

    /**
     * Get the type name
     *
     * @return string
     */
    public function type_name(): string
    {
        return $this[$this->get_type_column()] ?? '';
    }

    /**
     * Get the type hierarchy path
     *
     * @return string
     */
    public function type_path(): string
    {
        $col = $this->get_type_path_column();
        return $col ? ($this[$col] ?? '') : '';
    }

    // =========================================
    // MAGIC METHODS
    // =========================================

    /**
     * Handle is_book(), is_movie(), as_book(), as_movie(), etc.
     * Also delegates method calls to the delegate object.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $args): mixed
    {
        // Handle is_* type checks (is_book, is_movie, etc.)
        if (str_starts_with($method, 'is_')) {
            $type = $this->method_to_type(substr($method, 3));
            if (array_key_exists($type, $this->get_delegated_types())) {
                return $this->is_type($type);
            }
        }

        // Handle as_* accessors (returns typed delegate or null)
        if (str_starts_with($method, 'as_')) {
            $type = $this->method_to_type(substr($method, 3));
            if ($this->is_type($type)) {
                return $this->delegate();
            }
            return null;
        }

        // Deep method delegation through the entire chain
        $found = false;
        $result = $this->delegate_method_call($method, $args, $found);

        if ($found) {
            return $result;
        }

        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist in delegation chain', static::class, $method)
        );
    }

    /**
     * Convert method suffix to type name
     * book -> Book, creative_work -> CreativeWork
     *
     * @param string $suffix
     * @return string
     */
    protected function method_to_type(string $suffix): string
    {
        return str_replace('_', '', ucwords($suffix, '_'));
    }

    /**
     * Recursively search for a method in the delegation chain.
     * This enables deep method delegation where a method call on Thing
     * can be handled by TextBook (through Book).
     *
     * @param string $method Method name to find
     * @param array $args Arguments to pass
     * @param bool &$found Set to true if method was found and called
     * @return mixed The result of the method call
     */
    public function delegate_method_call(string $method, array $args, bool &$found = false): mixed
    {
        $delegate = $this->delegate();

        if ($delegate === null) {
            $found = false;
            return null;
        }

        // Check if delegate has this method directly
        if (method_exists($delegate, $method)) {
            $found = true;
            return $delegate->$method(...$args);
        }

        // If delegate also uses DelegatedTypes, recurse deeper
        if (method_exists($delegate, 'delegate_method_call')) {
            return $delegate->delegate_method_call($method, $args, $found);
        }

        $found = false;
        return null;
    }

    // =========================================
    // CREATION HELPERS
    // =========================================

    /**
     * Create a thing with its delegate in one atomic operation
     * Uses transaction for atomicity.
     *
     * @param string $type Type name (e.g., 'Book', 'Movie')
     * @param array $base_data Data for the base thing
     * @param array $delegate_data Data for the delegate
     * @return static
     * @throws \InvalidArgumentException If type is unknown
     */
    public static function create_with_delegate(
        string $type,
        array $base_data,
        array $delegate_data = []
    ): static {
        $instance = new static([]);
        $types = $instance->get_delegated_types();

        if (!isset($types[$type])) {
            throw new \InvalidArgumentException("Unknown delegate type: $type");
        }

        $delegate_class = $types[$type];
        $db = static::get_db();

        return $db->transaction(function ($db) use ($type, $base_data, $delegate_data, $delegate_class, $instance) {
            // Set the type column
            $type_column = $instance->get_type_column();
            $base_data[$type_column] = $type;

            // Create the base thing
            $thing = static::create($base_data);

            // Create the delegate with foreign key
            $foreign_key = $instance->get_delegate_foreign_key();
            $delegate_data[$foreign_key] = $thing['id'];

            $delegate = $delegate_class::create($delegate_data);

            // Cache the delegate
            $thing->set_delegate($delegate);

            return $thing;
        });
    }

    /**
     * Update both thing and delegate atomically
     *
     * @param array $base_data Data to update on the base thing
     * @param array $delegate_data Data to update on the delegate
     * @return static
     */
    public function update_with_delegate(
        array $base_data,
        array $delegate_data = []
    ): static {
        $db = static::get_db();

        return $db->transaction(function ($db) use ($base_data, $delegate_data) {
            // Update base thing
            if (!empty($base_data)) {
                $this->update($base_data);
            }

            // Update delegate
            $delegate = $this->delegate();
            if ($delegate !== null && !empty($delegate_data)) {
                $delegate->update($delegate_data);
            }

            return $this;
        });
    }

    /**
     * Delete thing and its delegate atomically
     *
     * @return static
     */
    public function delete_with_delegate(): static
    {
        $db = static::get_db();

        return $db->transaction(function ($db) {
            // Delete delegate first (foreign key constraint)
            $delegate = $this->delegate();
            if ($delegate !== null) {
                $delegate->delete();
            }

            // Delete base thing
            $this->delete();

            return $this;
        });
    }

    // =========================================
    // N-LEVEL CHAIN OPERATIONS
    // =========================================

    /**
     * Create an entity with any depth of delegates atomically.
     * Each key in the chain array represents a level in the hierarchy.
     *
     * @param array $chain Associative array: ['TypeName' => [...data...], ...]
     *                     Keys should be in order from root to leaf type.
     * @return static The root entity with all delegates created and cached
     * @throws \InvalidArgumentException If chain is empty or types are invalid
     *
     * @example
     * // Three-level creation
     * $textbook = Thing::create_chain([
     *     'Thing'    => ['name' => 'Calculus', 'description' => '...'],
     *     'Book'     => ['isbn' => '978-1234567890', 'number_of_pages' => 500],
     *     'TextBook' => ['edition' => '5th', 'grade_level' => 'college'],
     * ]);
     *
     * // Two-level still works
     * $person = Thing::create_chain([
     *     'Thing'  => ['name' => 'John Doe'],
     *     'Person' => ['given_name' => 'John', 'family_name' => 'Doe'],
     * ]);
     */
    public static function create_chain(array $chain): static
    {
        if (empty($chain)) {
            throw new \InvalidArgumentException('Chain cannot be empty');
        }

        $types = array_keys($chain);
        if (count($types) < 2) {
            throw new \InvalidArgumentException('Chain must have at least 2 levels (root + delegate)');
        }

        $db = static::get_db();

        return $db->transaction(function () use ($chain, $types) {
            $leaf_type = end($types);
            $type_path = implode('/', $types);

            // Create root entity
            $root_type = reset($types);
            $root_data = $chain[$root_type];

            // Get instance for configuration
            $instance = new static([]);
            $type_column = $instance->get_type_column();
            $type_path_column = $instance->get_type_path_column();

            // Set type info on root
            $root_data[$type_column] = $leaf_type;
            if ($type_path_column !== null) {
                $root_data[$type_path_column] = $type_path;
            }

            $root = static::create($root_data);

            // Track parent for each level
            $parent = $root;
            $remaining_types = array_slice($types, 1);  // Remove root type

            foreach ($remaining_types as $index => $type_name) {
                $delegate_class = static::resolve_delegate_class_in_chain($parent, $type_name, $leaf_type);

                if ($delegate_class === null) {
                    throw new \InvalidArgumentException("Cannot resolve delegate class for type: {$type_name}");
                }

                $delegate_data = $chain[$type_name];

                // Add foreign key to parent - use parent's FK definition
                $fk = $parent->get_delegate_foreign_key();

                // Use parent's ID as FK value
                $delegate_data[$fk] = $parent['id'];

                // If delegate also uses DelegatedTypes, set its type column
                if (static::uses_delegated_types($delegate_class)) {
                    $delegate_instance = new $delegate_class([]);
                    $delegate_type_column = $delegate_instance->get_type_column();
                    $delegate_data[$delegate_type_column] = $leaf_type;
                }

                $delegate = $delegate_class::create($delegate_data);

                // Cache delegate on parent
                if (method_exists($parent, 'set_delegate')) {
                    $parent->set_delegate($delegate);
                }

                // Move to next level
                $parent = $delegate;
            }

            return $root;
        });
    }

    /**
     * Resolve the delegate class for a type in the chain.
     * Searches through the delegation hierarchy to find the correct class.
     *
     * @param ActiveRow $parent The parent entity
     * @param string $type_name The type name to resolve
     * @param string $leaf_type The final leaf type
     * @return class-string<ActiveRow>|null
     */
    protected static function resolve_delegate_class_in_chain(
        ActiveRow $parent,
        string $type_name,
        string $leaf_type
    ): ?string {
        $types = static::get_types_from_instance($parent);
        if ($types === null) {
            return null;
        }

        // Direct match for this type
        if (isset($types[$type_name])) {
            return $types[$type_name];
        }

        // If searching for a deeper type, find which immediate child leads to it
        foreach ($types as $child_type => $child_class) {
            // Check if this child type eventually leads to our target
            if ($child_type === $leaf_type) {
                return $child_class;
            }

            // Check if child class has DelegatedTypes that includes our target
            $child_types = static::get_types_from_class($child_class);
            if ($child_types !== null) {
                if (isset($child_types[$type_name]) || isset($child_types[$leaf_type])) {
                    return $child_class;
                }

                // Recursive search for deeper hierarchies
                if (static::type_exists_in_hierarchy($child_class, $leaf_type)) {
                    return $child_class;
                }
            }
        }

        return null;
    }

    /**
     * Get delegated types from an instance using reflection to access protected method.
     *
     * @param ActiveRow $instance
     * @return array<string, class-string>|null
     */
    protected static function get_types_from_instance(ActiveRow $instance): ?array
    {
        if (!static::uses_delegated_types($instance::class)) {
            return null;
        }

        try {
            $reflection = new \ReflectionMethod($instance, 'get_delegated_types');
            $reflection->setAccessible(true);
            return $reflection->invoke($instance);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Get delegated types from a class name using reflection.
     *
     * @param string $class
     * @return array<string, class-string>|null
     */
    protected static function get_types_from_class(string $class): ?array
    {
        if (!static::uses_delegated_types($class)) {
            return null;
        }

        try {
            $instance = new $class([]);
            $reflection = new \ReflectionMethod($instance, 'get_delegated_types');
            $reflection->setAccessible(true);
            return $reflection->invoke($instance);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Check if a class uses the DelegatedTypes trait.
     *
     * @param string $class
     * @return bool
     */
    protected static function uses_delegated_types(string $class): bool
    {
        $traits = \Italix\Orm\ActiveRow\Traits\class_uses_recursive($class);
        return in_array(DelegatedTypes::class, $traits, true);
    }

    /**
     * Check if a type exists anywhere in a class's delegation hierarchy
     *
     * @param string $class The class to search from
     * @param string $type_name The type to find
     * @param int $max_depth Maximum search depth
     * @return bool
     */
    protected static function type_exists_in_hierarchy(
        string $class,
        string $type_name,
        int $max_depth = 10
    ): bool {
        if ($max_depth <= 0) {
            return false;
        }

        $types = static::get_types_from_class($class);
        if ($types === null) {
            return false;
        }

        if (isset($types[$type_name])) {
            return true;
        }

        foreach ($types as $child_class) {
            if (static::type_exists_in_hierarchy($child_class, $type_name, $max_depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update the entire delegation chain atomically.
     *
     * @param array $chain Associative array: ['TypeName' => [...data...], ...]
     * @return static
     *
     * @example
     * $thing->update_chain([
     *     'Thing'    => ['name' => 'Updated Name'],
     *     'Book'     => ['number_of_pages' => 450],
     *     'TextBook' => ['edition' => '6th'],
     * ]);
     */
    public function update_chain(array $chain): static
    {
        $db = static::get_db();

        return $db->transaction(function () use ($chain) {
            $current = $this;
            $level = 0;
            $types = array_keys($chain);

            foreach ($chain as $type_name => $data) {
                if (empty($data)) {
                    // Skip to next level
                    if ($level > 0 && method_exists($current, 'delegate')) {
                        $current = $current->delegate();
                    }
                    $level++;
                    continue;
                }

                if ($level === 0) {
                    // Update root
                    $this->update($data);
                } else {
                    // Update delegate at this level
                    if ($current !== null) {
                        $current->update($data);
                    }
                }

                // Move to next level
                if (method_exists($current, 'delegate')) {
                    $next = $current->delegate();
                    if ($next !== null) {
                        $current = $next;
                    }
                }
                $level++;
            }

            return $this;
        });
    }

    /**
     * Delete the entire delegation chain atomically.
     * Deletes from leaf to root to respect foreign key constraints.
     *
     * @return static
     */
    public function delete_chain(): static
    {
        $db = static::get_db();

        return $db->transaction(function () {
            // Get the full chain
            $chain = $this->get_chain();

            // Delete in reverse order (leaf first)
            $reversed = array_reverse($chain);
            foreach ($reversed as $entity) {
                $entity->delete();
            }

            return $this;
        });
    }

    // =========================================
    // CHAIN TRAVERSAL
    // =========================================

    /**
     * Get the full delegation chain as an array.
     * Returns all entities from this level to the deepest delegate.
     *
     * @return array<ActiveRow>
     *
     * @example
     * $thing = Thing::find_with_delegates(...);
     * $chain = $thing->get_chain();
     * // Returns: [Thing, Book, TextBook]
     */
    public function get_chain(): array
    {
        $chain = [$this];
        $current = $this;

        while (true) {
            if (!method_exists($current, 'delegate')) {
                break;
            }

            $delegate = $current->delegate();
            if ($delegate === null) {
                break;
            }

            $chain[] = $delegate;
            $current = $delegate;
        }

        return $chain;
    }

    /**
     * Get the leaf delegate (deepest level in the chain).
     *
     * @return ActiveRow The deepest delegate, or $this if no delegates
     *
     * @example
     * $thing->leaf();  // Returns TextBook for Thing→Book→TextBook chain
     */
    public function leaf(): ActiveRow
    {
        $chain = $this->get_chain();
        return end($chain) ?: $this;
    }

    /**
     * Get a value from anywhere in the delegation chain.
     * Searches from this level through all delegates.
     *
     * @param string $key The key/column to find
     * @return mixed The value, or null if not found
     *
     * @example
     * $thing->get_from_chain('isbn');      // From Book level
     * $thing->get_from_chain('edition');   // From TextBook level
     */
    public function get_from_chain(string $key): mixed
    {
        // Check self first
        if (isset($this[$key]) && $this[$key] !== null) {
            return $this[$key];
        }

        // Search delegation chain
        $current = $this;
        while (method_exists($current, 'delegate')) {
            $delegate = $current->delegate();
            if ($delegate === null) {
                break;
            }

            if (isset($delegate[$key]) && $delegate[$key] !== null) {
                return $delegate[$key];
            }

            $current = $delegate;
        }

        return null;
    }

    /**
     * Get the depth of the delegation chain.
     *
     * @return int Number of levels (1 = no delegates, 2 = one delegate, etc.)
     */
    public function chain_depth(): int
    {
        return count($this->get_chain());
    }

    // =========================================
    // EAGER LOADING
    // =========================================

    /**
     * Eager load delegates for a collection of things (single level).
     * Groups by type for efficient batch queries.
     *
     * @param array<static> $things
     * @return array<static>
     */
    public static function eager_load_delegates(array $things): array
    {
        if (empty($things)) {
            return $things;
        }

        $sample = $things[0];
        $types = $sample->get_delegated_types();
        $type_column = $sample->get_type_column();
        $foreign_key = $sample->get_delegate_foreign_key();

        // Group things by type
        $by_type = [];
        foreach ($things as $thing) {
            $type = $thing[$type_column];
            $by_type[$type][] = $thing;
        }

        // Load delegates for each type in batch
        foreach ($by_type as $type => $type_things) {
            if (!isset($types[$type])) {
                // Type might be a deeper leaf type - find the intermediate
                $delegate_class = static::find_delegate_class_for_leaf($types, $type);
                if ($delegate_class === null) {
                    continue;
                }
            } else {
                $delegate_class = $types[$type];
            }

            $ids = array_map(fn($t) => $t['id'], $type_things);

            $table = $delegate_class::get_table();
            $delegates = $delegate_class::find_all([
                'where' => in_($table->$foreign_key, $ids),
            ]);

            // Index delegates by foreign key
            $delegate_map = [];
            foreach ($delegates as $delegate) {
                $delegate_map[$delegate[$foreign_key]] = $delegate;
            }

            // Attach delegates to things
            foreach ($type_things as $thing) {
                if (isset($delegate_map[$thing['id']])) {
                    $thing->set_delegate($delegate_map[$thing['id']]);
                }
            }
        }

        return $things;
    }

    /**
     * Find the delegate class that handles a leaf type.
     * Used when the type column contains a deeper leaf type (e.g., "TextBook")
     * but we need to find the immediate delegate class (e.g., Book::class).
     *
     * @param array $types The delegated types mapping
     * @param string $leaf_type The leaf type name
     * @return class-string<ActiveRow>|null
     */
    protected static function find_delegate_class_for_leaf(array $types, string $leaf_type): ?string
    {
        foreach ($types as $type_name => $class) {
            if ($type_name === $leaf_type) {
                return $class;
            }

            // Check if this class has DelegatedTypes that includes our leaf
            $sub_types = static::get_types_from_class($class);
            if ($sub_types !== null) {
                if (isset($sub_types[$leaf_type])) {
                    return $class;
                }

                // Recursive check for deeper hierarchies
                if (static::type_exists_in_hierarchy($class, $leaf_type)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Recursively eager load delegates for a collection to any depth.
     * Automatically continues loading until no more delegates are found.
     *
     * @param array<ActiveRow> $items Collection of entities to load delegates for
     * @param int $max_depth Maximum depth to recurse (default 10)
     * @return array<ActiveRow>
     *
     * @example
     * // Load all levels automatically
     * $things = Thing::find_all();
     * $things = Thing::eager_load_delegates_recursive($things);
     *
     * // Each thing now has Book loaded, and each Book has TextBook loaded
     * foreach ($things as $thing) {
     *     $chain = $thing->get_chain();  // [Thing, Book, TextBook]
     * }
     */
    public static function eager_load_delegates_recursive(array $items, int $max_depth = 10): array
    {
        if (empty($items) || $max_depth <= 0) {
            return $items;
        }

        // First, eager load this level
        $items = static::eager_load_delegates($items);

        // Collect all delegates that also use DelegatedTypes
        $delegates_by_class = [];
        foreach ($items as $item) {
            $delegate = $item->delegate();
            if ($delegate !== null && method_exists($delegate, 'get_delegated_types')) {
                $class = get_class($delegate);
                if (!isset($delegates_by_class[$class])) {
                    $delegates_by_class[$class] = [];
                }
                $delegates_by_class[$class][] = $delegate;
            }
        }

        // Recursively load each delegate class's delegates
        foreach ($delegates_by_class as $class => $delegates) {
            if (method_exists($class, 'eager_load_delegates_recursive')) {
                $class::eager_load_delegates_recursive($delegates, $max_depth - 1);
            }
        }

        return $items;
    }

    /**
     * Find things with their delegates eagerly loaded to any depth.
     *
     * @param array $options Query options:
     *   - 'where': Query condition
     *   - 'order_by': Ordering
     *   - 'limit': Result limit
     *   - 'offset': Result offset
     *   - 'max_depth': Maximum delegation depth (default: 10)
     * @return array<static>
     *
     * @example
     * // Load all levels (default)
     * $things = Thing::find_with_delegates();
     *
     * // Limit depth to 2 levels (Thing → Book only)
     * $things = Thing::find_with_delegates(['max_depth' => 1]);
     *
     * // With query conditions
     * $textbooks = Thing::find_with_delegates([
     *     'where' => eq($table->type, 'TextBook'),
     *     'max_depth' => 10,
     * ]);
     */
    public static function find_with_delegates(array $options = []): array
    {
        $max_depth = $options['max_depth'] ?? 10;
        unset($options['max_depth']);

        $things = static::find_all($options);

        return static::eager_load_delegates_recursive($things, $max_depth);
    }

    /**
     * Find one thing with its full delegate chain eagerly loaded.
     *
     * @param mixed $id Primary key value
     * @param int $max_depth Maximum delegation depth (default: 10)
     * @return static|null
     */
    public static function find_with_delegate($id, int $max_depth = 10): ?static
    {
        $thing = static::find($id);
        if ($thing === null) {
            return null;
        }

        // Recursively load the delegate chain
        $current = $thing;
        $depth = 0;

        while ($depth < $max_depth) {
            if (!method_exists($current, 'delegate')) {
                break;
            }

            $delegate = $current->delegate();
            if ($delegate === null) {
                break;
            }

            $current = $delegate;
            $depth++;
        }

        return $thing;
    }

    // =========================================
    // SERIALIZATION / RECONSTRUCTION
    // =========================================

    /**
     * Convert the entity and its entire delegate chain to an array.
     * This can be used for JSON serialization that preserves the full hierarchy.
     *
     * @param bool $include_transient Whether to include transient attributes
     * @return array Structure with '_type', '_data', and '_delegate' keys
     *
     * @example
     * $data = $thing->to_array_with_delegates();
     * // Returns:
     * // [
     * //     '_type' => 'Thing',
     * //     '_class' => 'App\Models\Thing',
     * //     '_data' => ['id' => 1, 'name' => 'Calculus', 'type' => 'TextBook', ...],
     * //     '_delegate' => [
     * //         '_type' => 'Book',
     * //         '_class' => 'App\Models\Book',
     * //         '_data' => ['id' => 1, 'isbn' => '...', ...],
     * //         '_delegate' => [
     * //             '_type' => 'TextBook',
     * //             '_class' => 'App\Models\TextBook',
     * //             '_data' => ['id' => 1, 'edition' => '5th', ...],
     * //             '_delegate' => null
     * //         ]
     * //     ]
     * // ]
     */
    public function to_array_with_delegates(bool $include_transient = false): array
    {
        $result = [
            '_type' => $this->type_name() ?: (new \ReflectionClass($this))->getShortName(),
            '_class' => static::class,
            '_data' => $include_transient ? $this->to_array() : $this->get_persistent_data(),
            '_delegate' => null,
        ];

        $delegate = $this->delegate();
        if ($delegate !== null) {
            if (method_exists($delegate, 'to_array_with_delegates')) {
                $result['_delegate'] = $delegate->to_array_with_delegates($include_transient);
            } else {
                // Delegate doesn't use DelegatedTypes, just serialize its data
                $result['_delegate'] = [
                    '_type' => (new \ReflectionClass($delegate))->getShortName(),
                    '_class' => get_class($delegate),
                    '_data' => $include_transient ? $delegate->to_array() : $delegate->get_persistent_data(),
                    '_delegate' => null,
                ];
            }
        }

        return $result;
    }

    /**
     * Convert to JSON with full delegate chain.
     *
     * @param bool $include_transient Whether to include transient attributes
     * @param int $flags JSON encoding flags
     * @return string JSON string
     */
    public function to_json_with_delegates(bool $include_transient = false, int $flags = 0): string
    {
        return json_encode($this->to_array_with_delegates($include_transient), $flags);
    }

    /**
     * Reconstruct an entity and its delegate chain from serialized data.
     * Note: This creates in-memory objects only - they are NOT saved to database.
     * The objects will have exists() = true if they have an 'id' in their data.
     *
     * @param array $data The serialized data from to_array_with_delegates()
     * @return static|null The reconstructed entity with delegates attached
     *
     * @example
     * // Serialize on Server A
     * $json = $thing->to_json_with_delegates();
     *
     * // Reconstruct on Server B (after setting up persistence)
     * $data = json_decode($json, true);
     * $thing = Thing::from_serialized($data);
     *
     * // The object is now usable (read-only until saved)
     * echo $thing->name;
     * echo $thing->delegate()->isbn;
     */
    public static function from_serialized(array $data): ?static
    {
        if (!isset($data['_class']) || !isset($data['_data'])) {
            return null;
        }

        $class = $data['_class'];

        // Security: Verify the class exists and is a valid ActiveRow
        if (!class_exists($class) || !is_subclass_of($class, ActiveRow::class)) {
            // Fall back to static class if provided class is invalid
            $class = static::class;
        }

        // Create the entity
        $entity = $class::wrap($data['_data']);

        // Recursively reconstruct delegate
        if (!empty($data['_delegate'])) {
            $delegate = static::reconstruct_delegate($data['_delegate']);
            if ($delegate !== null && method_exists($entity, 'set_delegate')) {
                $entity->set_delegate($delegate);
            }
        }

        return $entity;
    }

    /**
     * Reconstruct a delegate from serialized data.
     *
     * @param array $data Delegate data
     * @return ActiveRow|null
     */
    protected static function reconstruct_delegate(array $data): ?ActiveRow
    {
        if (!isset($data['_class']) || !isset($data['_data'])) {
            return null;
        }

        $class = $data['_class'];

        // Security: Verify the class exists and is a valid ActiveRow
        if (!class_exists($class) || !is_subclass_of($class, ActiveRow::class)) {
            return null;
        }

        $delegate = $class::wrap($data['_data']);

        // Recursively reconstruct nested delegates
        if (!empty($data['_delegate'])) {
            $nested = static::reconstruct_delegate($data['_delegate']);
            if ($nested !== null && method_exists($delegate, 'set_delegate')) {
                $delegate->set_delegate($nested);
            }
        }

        return $delegate;
    }

    /**
     * Create from JSON string with delegates.
     *
     * @param string $json JSON string from to_json_with_delegates()
     * @return static|null
     */
    public static function from_json(string $json): ?static
    {
        $data = json_decode($json, true);
        if ($data === null) {
            return null;
        }

        return static::from_serialized($data);
    }
}
