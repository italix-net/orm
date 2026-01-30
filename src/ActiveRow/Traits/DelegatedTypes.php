<?php

namespace Italix\Orm\ActiveRow\Traits;

use Italix\Orm\ActiveRow\ActiveRow;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\in_;

/**
 * Trait DelegatedTypes
 *
 * Implements the Delegated Types pattern (inspired by Rails).
 * Allows a "superclass" record to delegate behavior and attributes
 * to type-specific "subclass" records stored in separate tables.
 *
 * This pattern is ideal for:
 * - Schema.org-style hierarchies (Thing → CreativeWork → Book)
 * - Polymorphic content systems (Entry → Message|Comment|Photo)
 * - Any scenario requiring efficient queries across related types
 *
 * @example
 * class Thing extends ActiveRow {
 *     use Persistable, DelegatedTypes;
 *
 *     protected function get_delegated_types(): array {
 *         return [
 *             'Book'   => Book::class,
 *             'Movie'  => Movie::class,
 *             'Person' => Person::class,
 *         ];
 *     }
 * }
 *
 * // Create a book with its delegate
 * $thing = Thing::create_with_delegate('Book', [
 *     'name' => 'Design Patterns',
 * ], [
 *     'isbn' => '978-0201633610',
 *     'number_of_pages' => 416,
 * ]);
 *
 * // Access delegate
 * $book = $thing->delegate();  // Returns Book instance
 * echo $book['isbn'];
 *
 * // Type checking
 * $thing->is_book();    // true
 * $thing->is_movie();   // false
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

        // Delegate method calls to the delegate object
        $delegate = $this->delegate();
        if ($delegate !== null && method_exists($delegate, $method)) {
            return $delegate->$method(...$args);
        }

        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist', static::class, $method)
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
    // EAGER LOADING
    // =========================================

    /**
     * Eager load delegates for a collection of things
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
                continue;
            }

            $delegate_class = $types[$type];
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
     * Find things with their delegates eagerly loaded
     *
     * @param array $options Query options (where, order_by, limit, offset)
     * @return array<static>
     */
    public static function find_with_delegates(array $options = []): array
    {
        $things = static::find_all($options);
        return static::eager_load_delegates($things);
    }

    /**
     * Find one thing with its delegate eagerly loaded
     *
     * @param mixed $id Primary key value
     * @return static|null
     */
    public static function find_with_delegate($id): ?static
    {
        $thing = static::find($id);
        if ($thing === null) {
            return null;
        }

        // Trigger delegate loading
        $thing->delegate();

        return $thing;
    }
}
