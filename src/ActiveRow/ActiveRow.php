<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

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
     * Primary key column name — or, for a table with a composite primary
     * key (`Table::primary_key([...])`), an array naming every column, in
     * the same order. A plain string is the ordinary case and is
     * completely unaffected by composite-key support existing at all.
     *
     *     protected static $primary_key = ['tenant_id', 'order_id'];
     *
     * @var string|array<int, string>
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
     * Whether to include transient (dot-prefixed) keys in JSON serialization
     * @var bool
     */
    protected static $json_include_transient = false;

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
     * @param bool $include_transient Whether to include transient (dot-prefixed) keys
     * @return array
     */
    public function to_array(bool $include_transient = true): array
    {
        if ($include_transient) {
            return $this->data;
        }
        return $this->get_persistent_data();
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
        if (static::$auto_wrap_relations && $value !== null && isset(static::resolved_relation_classes()[$offset])) {
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
     * By default excludes transient (dot-prefixed) keys.
     * Set static::$json_include_transient = true to include them.
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        if (static::$json_include_transient) {
            return $this->data;
        }
        return $this->get_persistent_data();
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
     * Excludes transient (dot-prefixed) keys which are not persisted.
     *
     * @return array
     */
    public function get_dirty(): array
    {
        $dirty = [];
        foreach ($this->data as $key => $value) {
            // Skip transient keys
            if (static::is_transient_key($key)) {
                continue;
            }
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
     * Only syncs persistent data; transient keys are not tracked.
     *
     * @return static
     */
    public function sync_original(): self
    {
        $this->original = $this->get_persistent_data();
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
        // $original is empty exactly for an instance make() produced and
        // nothing has persisted since — the same signal get_dirty() already
        // treats as "every field is new". Checking $data's primary key
        // alone was not enough: a composite key (and, already, a natural
        // single-column one) is supplied by the caller up front rather than
        // assigned on INSERT, so a freshly-made row already carries it —
        // this second check is what tells "about to be created" apart from
        // "already was".
        if (empty($this->original)) {
            return false;
        }

        foreach ((array) static::$primary_key as $pk) {
            if (!isset($this->data[$pk]) || $this->data[$pk] === null) {
                return false;
            }
        }

        return true;
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
     * The primary key value — a scalar for the ordinary single-column case,
     * or `[column => value, ...]` for a composite key (`static::$primary_key`
     * declared as an array). This is exactly the shape `TableQuery::find()`
     * already accepts for either case, so `$row::find($row->get_key())`
     * round-trips regardless of which kind of key the table has.
     *
     * @return mixed
     */
    public function get_key()
    {
        if (is_array(static::$primary_key)) {
            $key = [];
            foreach (static::$primary_key as $pk) {
                $key[$pk] = $this->data[$pk] ?? null;
            }

            return $key;
        }

        return $this->data[static::$primary_key] ?? null;
    }

    /**
     * The primary key column name — only for the single-column case. Raises
     * on a composite key, where there is no one name to return; use
     * {@see get_key_names()} there instead.
     *
     * @return string
     */
    public static function get_key_name(): string
    {
        if (is_array(static::$primary_key)) {
            throw new \LogicException(
                static::class . ' has a composite primary key (' . implode(', ', static::$primary_key)
                . '); get_key_name() has no single name to return — use get_key_names() instead.'
            );
        }

        return static::$primary_key;
    }

    /**
     * Every primary key column name, in declared order — `[static::
     * $primary_key]` for the ordinary single-column case, so this is the
     * one to reach for when the code should work the same regardless of
     * which kind of key the table has.
     *
     * @return array<int, string>
     */
    public static function get_key_names(): array
    {
        return (array) static::$primary_key;
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

        $wrapper = static::resolved_relation_classes()[$relation] ?? null;
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

        $wrapper = $class ?? (static::resolved_relation_classes()[$relation] ?? null);
        if ($wrapper === null) {
            return $value;
        }

        return $this->get_wrapped_relation($relation, $value);
    }

    /** Per-class cache for {@see resolved_relation_classes()} — never invalidated, see its docblock. */
    private static array $resolved_relation_classes_cache = [];

    /**
     * Which class wraps each relation's rows, name => class: everything in
     * `$relation_classes` the subclass declared — still checked first, and
     * still wins — plus, for any relation `RelationsRegistry` knows about
     * that is *not* already in there, a class derived from the relation
     * itself.
     *
     * Before this, a class not overriding `$relation_classes` (or
     * overriding it incompletely) simply had no wrapper for a relation, even
     * when the relation's target table was, itself, bound to a perfectly
     * good `ActiveRow` subclass elsewhere — the same fact declared once for
     * `with()` to use and a second time, separately, for this to use, with
     * nothing checking the two agreed.
     *
     * The derivation: `RelationsRegistry` already knows, for this class's
     * own bound `Table` (needs `Persistable`), every `Relation` and which
     * `Table` each one targets; `ActiveRowRegistry` (built for exactly this)
     * says which class, if any, is registered for that target `Table`. Two
     * lookups through data this package already had, not a third place
     * naming the same relation.
     *
     * A one-to-many, a many-to-many (`through`) and a `many_polymorphic`
     * relation all have exactly one real target `Table` — `many_polymorphic`
     * is filtered to a single concrete type at declaration
     * (`'type_value' => 'book'`, one target table, not a set of them), the
     * same way `Many::get_target_table()` already returns the actual far
     * side of a `through` relation (e.g. `$roles`, not the junction
     * `$user_roles`) — so all three are derived.
     *
     * **`one_polymorphic` is the one genuinely ambiguous case, and the one
     * deliberately excluded.** A `commentable` can be a `Post` *or* a
     * `Video`; `PolymorphicOne::get_target_table()` (inherited from the same
     * base method every relation has) answers with "the first configured
     * target, for compatibility" — not a real single answer, and deriving a
     * wrapper class from it would silently mis-wrap a row as the wrong class
     * instead of leaving it as an array. Detected by `get_targets()`, a
     * method that exists only on `PolymorphicOne` — `PolymorphicMany` does
     * not have it, which is exactly why it is not excluded here.
     * `$relation_classes` (or `relation($name, $class)`'s explicit `$class`)
     * is still the only way to wrap a `one_polymorphic` relation.
     *
     * Computed once per class and cached: `offsetGet()` consults this on
     * every array access to decide whether a key is a relation at all, and
     * a `RelationsRegistry`/`ActiveRowRegistry` round trip on every read of
     * every plain column would be a cost nobody asked for. Safe to cache for
     * the process's lifetime — the bound `Table` and its relations do not
     * change after `set_persistence()`/`define_relations()` bootstrap.
     *
     * @return array<string, string>
     */
    protected static function resolved_relation_classes(): array
    {
        if (isset(self::$resolved_relation_classes_cache[static::class])) {
            return self::$resolved_relation_classes_cache[static::class];
        }

        $resolved = static::$relation_classes;

        if (method_exists(static::class, 'has_persistence') && static::has_persistence()) {
            $table_relations = \Italix\Orm\Relations\RelationsRegistry::get_instance()->get(static::get_table());

            if ($table_relations !== null) {
                foreach ($table_relations->all() as $name => $rel) {
                    if (isset($resolved[$name])) {
                        continue; // an explicit override always wins
                    }

                    if (!method_exists($rel, 'get_target_table') || method_exists($rel, 'get_targets')) {
                        continue; // polymorphic, or a relation type with no single target
                    }

                    $class = ActiveRowRegistry::class_for_table($rel->get_target_table());

                    if ($class !== null) {
                        $resolved[$name] = $class;
                    }
                }
            }
        }

        return self::$resolved_relation_classes_cache[static::class] = $resolved;
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
     * Set a value by key
     *
     * Supports transient (dot-prefixed) keys that are stored in memory
     * but not persisted to the database.
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function set(string $key, $value): self
    {
        $this->data[$key] = $value;
        // Clear cached wrapped relation if data changes
        unset($this->wrapped_relations_cache[$key]);
        return $this;
    }

    /**
     * Check if a key is a transient (dot-prefixed) key
     *
     * Transient keys start with "." and are stored in memory but not persisted.
     *
     * @param string $key
     * @return bool
     */
    public static function is_transient_key(string $key): bool
    {
        return strlen($key) > 0 && $key[0] === '.';
    }

    /**
     * Get only the persistent data (excludes transient dot-prefixed keys)
     *
     * @return array
     */
    public function get_persistent_data(): array
    {
        return array_filter($this->data, function ($key) {
            return !static::is_transient_key($key);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get only the transient data (dot-prefixed keys only)
     *
     * @return array
     */
    public function get_transient_data(): array
    {
        return array_filter($this->data, function ($key) {
            return static::is_transient_key($key);
        }, ARRAY_FILTER_USE_KEY);
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
        foreach ((array) static::$primary_key as $pk) {
            unset($clone->data[$pk]);
        }
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
