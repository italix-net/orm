<?php

namespace Italix\Orm\ActiveRow;

use ArrayAccess;
use Countable;
use JsonSerializable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

/**
 * Base class for Active Row objects.
 *
 * Provides array-like access to row data while allowing custom methods.
 * Supports composition via traits for behaviors like timestamps, soft deletes, etc.
 *
 * @example
 * class UserRow extends ActiveRow {
 *     use Persistable, HasTimestamps;
 *
 *     public function full_name(): string {
 *         return $this['first_name'] . ' ' . $this['last_name'];
 *     }
 * }
 *
 * $user = UserRow::wrap(['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe']);
 * echo $user['first_name'];     // Array access: "John"
 * echo $user->full_name();      // Method: "John Doe"
 * $array = $user->to_array();   // Back to plain array
 */
abstract class ActiveRow implements ArrayAccess, Countable, JsonSerializable, IteratorAggregate
{
    /**
     * The row data
     * @var array
     */
    protected $data = [];

    /**
     * Original data (for dirty tracking)
     * @var array
     */
    protected $original = [];

    /**
     * Primary key column name
     * @var string
     */
    protected static $primary_key = 'id';

    /**
     * Map of relation names to their row classes for auto-wrapping
     * Override in subclasses to enable automatic relation wrapping
     *
     * @var array<string, string|array>
     * @example ['posts' => PostRow::class, 'author' => [PersonRow::class, OrganizationRow::class]]
     */
    protected static $relation_classes = [];

    /**
     * Whether to auto-wrap relations when accessed via array syntax
     * @var bool
     */
    protected static $auto_wrap_relations = false;

    /**
     * Cache for wrapped relations
     * @var array
     */
    protected $wrapped_relations_cache = [];

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Create a new instance (for internal use)
     */
    public function __construct()
    {
        // Empty constructor for flexibility
    }

    /**
     * Wrap an existing array into this ActiveRow type
     *
     * @param array $data The row data to wrap
     * @return static
     */
    public static function wrap(array $data): self
    {
        $instance = new static();
        $instance->data = $data;
        $instance->original = $data;
        $instance->run_hooks('after_wrap');
        return $instance;
    }

    /**
     * Wrap multiple arrays into ActiveRow instances
     *
     * @param array $rows Array of row data arrays
     * @return array<static>
     */
    public static function wrap_many(array $rows): array
    {
        return array_map(function ($row) {
            return static::wrap($row);
        }, $rows);
    }

    /**
     * Create a new empty instance (for creating new records)
     *
     * @param array $data Optional initial data
     * @return static
     */
    public static function make(array $data = []): self
    {
        $instance = new static();
        $instance->data = $data;
        $instance->original = []; // Empty original = all fields are "new"
        return $instance;
    }

    // =========================================================================
    // UNWRAP METHODS
    // =========================================================================

    /**
     * Get the underlying data as a plain array
     *
     * @return array
     */
    public function to_array(): array
    {
        return $this->data;
    }

    /**
     * Alias for to_array()
     *
     * @return array
     */
    public function unwrap(): array
    {
        return $this->data;
    }

    /**
     * Get the underlying data (alias property access)
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($name === 'data') {
            return $this->data;
        }

        if ($name === 'original') {
            return $this->original;
        }

        // Unknown property
        trigger_error("Undefined property: " . get_class($this) . "::\$$name", E_USER_NOTICE);
        return null;
    }

    // =========================================================================
    // ArrayAccess IMPLEMENTATION
    // =========================================================================

    /**
     * Check if a key exists
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    /**
     * Get a value by key
     *
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $value = $this->data[$offset] ?? null;

        // Auto-wrap relations if enabled
        if (static::$auto_wrap_relations && $value !== null && isset(static::$relation_classes[$offset])) {
            return $this->get_wrapped_relation($offset, $value);
        }

        return $value;
    }

    /**
     * Set a value by key
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
            // Clear cached wrapped relation if data changes
            unset($this->wrapped_relations_cache[$offset]);
        }
    }

    /**
     * Unset a value by key
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
        unset($this->wrapped_relations_cache[$offset]);
    }

    // =========================================================================
    // Countable IMPLEMENTATION
    // =========================================================================

    /**
     * Count the number of fields
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->data);
    }

    // =========================================================================
    // JsonSerializable IMPLEMENTATION
    // =========================================================================

    /**
     * Serialize to JSON
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->data;
    }

    // =========================================================================
    // IteratorAggregate IMPLEMENTATION
    // =========================================================================

    /**
     * Get iterator for foreach loops
     *
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    // =========================================================================
    // DIRTY TRACKING
    // =========================================================================

    /**
     * Get fields that have changed since wrapping/saving
     *
     * @return array
     */
    public function get_dirty(): array
    {
        $dirty = [];
        foreach ($this->data as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Check if the row has unsaved changes
     *
     * @param string|null $key Check specific key, or all if null
     * @return bool
     */
    public function is_dirty(?string $key = null): bool
    {
        if ($key !== null) {
            if (!array_key_exists($key, $this->data)) {
                return false;
            }
            if (!array_key_exists($key, $this->original)) {
                return true;
            }
            return $this->data[$key] !== $this->original[$key];
        }

        return !empty($this->get_dirty());
    }

    /**
     * Check if the row has no unsaved changes
     *
     * @return bool
     */
    public function is_clean(): bool
    {
        return !$this->is_dirty();
    }

    /**
     * Get the original value of a field
     *
     * @param string $key
     * @return mixed
     */
    public function get_original(string $key)
    {
        return $this->original[$key] ?? null;
    }

    /**
     * Reset dirty tracking (mark current state as clean)
     *
     * @return static
     */
    public function sync_original(): self
    {
        $this->original = $this->data;
        return $this;
    }

    // =========================================================================
    // STATE METHODS
    // =========================================================================

    /**
     * Check if the row exists in the database (has a primary key)
     *
     * @return bool
     */
    public function exists(): bool
    {
        $pk = static::$primary_key;
        return isset($this->data[$pk]) && $this->data[$pk] !== null;
    }

    /**
     * Check if this is a new record (not yet persisted)
     *
     * @return bool
     */
    public function is_new(): bool
    {
        return !$this->exists();
    }

    /**
     * Get the primary key value
     *
     * @return mixed
     */
    public function get_key()
    {
        return $this->data[static::$primary_key] ?? null;
    }

    /**
     * Get the primary key column name
     *
     * @return string
     */
    public static function get_key_name(): string
    {
        return static::$primary_key;
    }

    // =========================================================================
    // RELATION WRAPPING
    // =========================================================================

    /**
     * Get a relation value wrapped in its appropriate ActiveRow class
     *
     * @param string $relation Relation name
     * @param mixed $value Raw relation data
     * @return mixed Wrapped ActiveRow(s) or original value
     */
    protected function get_wrapped_relation(string $relation, $value)
    {
        // Return from cache if available
        if (isset($this->wrapped_relations_cache[$relation])) {
            return $this->wrapped_relations_cache[$relation];
        }

        $wrapper = static::$relation_classes[$relation] ?? null;
        if ($wrapper === null) {
            return $value;
        }

        // Already wrapped?
        if ($value instanceof ActiveRow) {
            return $value;
        }

        // Array of records (one-to-many)
        if (is_array($value) && !empty($value) && isset($value[0]) && is_array($value[0])) {
            $wrapped = $this->wrap_relation_array($value, $wrapper);
            $this->wrapped_relations_cache[$relation] = $wrapped;
            return $wrapped;
        }

        // Single record (one-to-one / many-to-one)
        if (is_array($value) && !isset($value[0])) {
            $wrapped = $this->wrap_single_relation($value, $wrapper);
            $this->wrapped_relations_cache[$relation] = $wrapped;
            return $wrapped;
        }

        return $value;
    }

    /**
     * Wrap an array of relation records
     *
     * @param array $rows
     * @param string|array $wrapper
     * @return array
     */
    protected function wrap_relation_array(array $rows, $wrapper): array
    {
        return array_map(function ($row) use ($wrapper) {
            return $this->wrap_single_relation($row, $wrapper);
        }, $rows);
    }

    /**
     * Wrap a single relation record
     *
     * @param array $data
     * @param string|array $wrapper Class name or array of class names (polymorphic)
     * @return ActiveRow
     */
    protected function wrap_single_relation(array $data, $wrapper): ActiveRow
    {
        // Single class
        if (is_string($wrapper)) {
            return $wrapper::wrap($data);
        }

        // Polymorphic: array of possible classes - use type detection
        // Subclasses can override this method for custom type detection
        if (is_array($wrapper) && !empty($wrapper)) {
            return $wrapper[0]::wrap($data);
        }

        throw new \InvalidArgumentException("Invalid relation wrapper configuration");
    }

    /**
     * Manually get a relation as wrapped ActiveRow instances
     * Use this when auto_wrap_relations is false
     *
     * @param string $relation Relation name
     * @param string|null $class Optional class to use (overrides $relation_classes)
     * @return array|ActiveRow|null
     */
    public function relation(string $relation, ?string $class = null)
    {
        $value = $this->data[$relation] ?? null;
        if ($value === null) {
            return null;
        }

        $wrapper = $class ?? static::$relation_classes[$relation] ?? null;
        if ($wrapper === null) {
            return $value;
        }

        return $this->get_wrapped_relation($relation, $value);
    }

    // =========================================================================
    // HOOK SYSTEM (AOP)
    // =========================================================================

    /**
     * Run hooks for an event
     *
     * Looks for methods named {event}_{suffix} and calls them.
     * This allows traits to add behavior without method conflicts.
     *
     * @param string $event Event name (e.g., 'before_save', 'after_wrap')
     * @return void
     */
    protected function run_hooks(string $event): void
    {
        $prefix = $event . '_';
        $methods = get_class_methods($this);

        foreach ($methods as $method) {
            // Match methods like before_save_timestamps, after_wrap_validation, etc.
            if (strpos($method, $prefix) === 0 && $method !== $event) {
                $this->$method();
            }
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Fill the row with data (mass assignment)
     *
     * @param array $data
     * @return static
     */
    public function fill(array $data): self
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
        return $this;
    }

    /**
     * Get only specific keys from the row
     *
     * @param array $keys
     * @return array
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * Get all keys except specific ones
     *
     * @param array $keys
     * @return array
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    /**
     * Check if a key has a non-null value
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Get a value with a default fallback
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Clone the row with new data merged
     *
     * @param array $data
     * @return static
     */
    public function with(array $data): self
    {
        $clone = clone $this;
        $clone->fill($data);
        return $clone;
    }

    /**
     * Create a copy of this row
     *
     * @return static
     */
    public function replicate(): self
    {
        $clone = static::wrap($this->data);
        // Remove primary key so it's treated as new
        unset($clone->data[static::$primary_key]);
        $clone->original = [];
        return $clone;
    }

    /**
     * Convert to string (JSON representation)
     *
     * @return string
     */
    public function __toString(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT);
    }

    /**
     * Debug info for var_dump
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return [
            'data' => $this->data,
            'original' => $this->original,
            'dirty' => $this->get_dirty(),
            'exists' => $this->exists(),
        ];
    }
}
