<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Italix\Orm\ActiveRow\Traits;

use Italix\Orm\ActiveRow\ActiveRowRegistry;
use Italix\Orm\ActiveRow\TypedQuery;
use Italix\Orm\DataManager;
use Italix\Orm\Operators\SQLExpression;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Operators\{and_, eq};

/**
 * Trait Persistable
 *
 * Adds save(), delete(), and refresh() methods to ActiveRow classes.
 * Requires setting up the database connection and table reference.
 *
 * @example
 * class UserRow extends ActiveRow {
 *     use Persistable;
 * }
 *
 * // Setup (once at bootstrap)
 * UserRow::set_persistence($dm, $users_table);
 *
 * // Usage
 * $user = UserRow::wrap($data);
 * $user['name'] = 'New Name';
 * $user->save();
 */
trait Persistable
{
    /**
     * Registry of database connections by class name
     * @var array<string, DataManager>
     */
    private static array $dm_registry = [];

    /**
     * Registry of table definitions by class name
     * @var array<string, Table>
     */
    private static array $table_registry = [];

    /**
     * Set up persistence for this row class
     *
     * @param DataManager $dm Database connection
     * @param Table $table Table definition
     * @return void
     */
    public static function set_persistence(DataManager $dm, Table $table): void
    {
        self::$dm_registry[static::class] = $dm;
        self::$table_registry[static::class] = $table;

        // The reverse index — see ActiveRowRegistry's own docblock for why a
        // trait's own static properties cannot serve this by themselves.
        ActiveRowRegistry::register($table, static::class);
    }

    /**
     * Get the database connection
     *
     * @return DataManager
     * @throws \RuntimeException If persistence not configured
     */
    public static function get_dm(): DataManager
    {
        if (!isset(self::$dm_registry[static::class])) {
            throw new \RuntimeException(
                'Persistence not configured for ' . static::class . '. ' .
                'Call ' . static::class . '::set_persistence($dm, $table) first.'
            );
        }
        return self::$dm_registry[static::class];
    }

    /**
     * Get the table definition
     *
     * @return Table
     * @throws \RuntimeException If persistence not configured
     */
    public static function get_table(): Table
    {
        if (!isset(self::$table_registry[static::class])) {
            throw new \RuntimeException(
                'Persistence not configured for ' . static::class . '. ' .
                'Call ' . static::class . '::set_persistence($dm, $table) first.'
            );
        }
        return self::$table_registry[static::class];
    }

    /**
     * Check if persistence is configured
     *
     * @return bool
     */
    public static function has_persistence(): bool
    {
        return isset(self::$dm_registry[static::class]) && isset(self::$table_registry[static::class]);
    }

    /**
     * `WHERE {pk} = {value}` for the ordinary single-column key — the exact
     * condition every one of `save()`/`delete()` already built by hand — or
     * every composite-key column ANDed together, in declared order, for a
     * `static::$primary_key` array. Built from `get_key()`, so a row without
     * a full key (mid-construction, or a composite key missing a column)
     * fails inside `get_key()`'s own `??`-to-`null` rather than compiling a
     * condition that would silently match nothing or, worse, everything.
     */
    protected function primary_key_condition(Table $table): SQLExpression
    {
        $key = $this->get_key();

        if (!is_array(static::$primary_key)) {
            return eq($table->{static::$primary_key}, $key);
        }

        $conditions = [];
        foreach ($key as $col => $value) {
            $conditions[] = eq($table->$col, $value);
        }

        return and_(...$conditions);
    }

    /**
     * Save the row to the database
     *
     * Performs INSERT for new records, UPDATE for existing ones.
     * Only updates dirty (changed) fields.
     *
     * @return static
     * @throws \RuntimeException If persistence not configured
     */
    public function save(): self
    {
        $dm = static::get_dm();
        $table = static::get_table();
        $pk = static::$primary_key;

        // Run before_save hooks
        $this->run_hooks('before_save');

        if ($this->exists()) {
            // UPDATE existing record
            $dirty = $this->get_dirty();

            if (!empty($dirty)) {
                // Don't update the primary key — every column of it, not
                // only the first, for a composite one. (Not independently
                // mutation-provable end to end: primary_key_condition()
                // below reads the same current $this->data a stripped
                // column's value would have come from, so an UPDATE that
                // wrongly re-sent a composite-key column always writes back
                // the value already there — there is no black-box way to
                // observe the difference. Correct by inspection and by the
                // identical single-column case this generalises, which is.)
                foreach (static::get_key_names() as $pk_col) {
                    unset($dirty[$pk_col]);
                }

                if (!empty($dirty)) {
                    $query = $dm->update($table)
                        ->set($dirty)
                        ->where($this->primary_key_condition($table));

                    // Table::optimistic_locking() — guard the UPDATE with the
                    // version this instance last read, whether or not the
                    // version column itself is among the dirty fields (it
                    // usually is not: nothing here ever sets it by hand).
                    // QueryBuilder::build_update() bumps it unconditionally;
                    // the in-memory copy below is kept in sync since this
                    // does not re-SELECT after writing.
                    if ($table->has_optimistic_locking()) {
                        $version_column = $table->version_column();
                        $query = $query->expect_version($this->original[$version_column] ?? null);
                    }

                    $query->execute();

                    if ($table->has_optimistic_locking()) {
                        $version_column = $table->version_column();
                        $this->data[$version_column] = ($this->original[$version_column] ?? 0) + 1;
                    }
                }
            }
        } else {
            // INSERT new record
            // Use get_persistent_data() to exclude transient (dot-prefixed) keys
            $data = $this->get_persistent_data();

            // Remove null primary key — auto-increment single-column keys
            // only; a composite key has no such thing and every column must
            // already be present, so this step does not apply to it.
            if (!is_array($pk) && isset($data[$pk]) && $data[$pk] === null) {
                unset($data[$pk]);
            }

            $dm->insert($table)
                ->values($data)
                ->execute();

            // Get the auto-generated ID — again, single-column keys only.
            if (!is_array($pk)) {
                $new_id = $dm->last_insert_id();
                if ($new_id) {
                    $this->data[$pk] = (int) $new_id;
                }
            }

            // QueryBuilder::build_insert() defaults Table::optimistic_locking()'s
            // column to 1 when the caller did not set it — reflected here too,
            // since this does not re-SELECT after writing.
            if ($table->has_optimistic_locking()) {
                $version_column = $table->version_column();
                if (!array_key_exists($version_column, $data)) {
                    $this->data[$version_column] = 1;
                }
            }
        }

        // Mark as clean (sync original with current data)
        $this->original = $this->data;

        // Run after_save hooks
        $this->run_hooks('after_save');

        return $this;
    }

    /**
     * Delete the row from the database
     *
     * @return static
     * @throws \RuntimeException If persistence not configured
     * @throws \LogicException If trying to delete a non-existent record
     */
    public function delete(): self
    {
        if (!$this->exists()) {
            throw new \LogicException('Cannot delete a record that does not exist');
        }

        $dm = static::get_dm();
        $table = static::get_table();

        // Run before_delete hooks
        $this->run_hooks('before_delete');

        $dm->delete($table)
            ->where($this->primary_key_condition($table))
            ->execute();

        // Run after_delete hooks
        $this->run_hooks('after_delete');

        // Clear the primary key to mark as non-existent
        foreach (static::get_key_names() as $pk_col) {
            unset($this->data[$pk_col]);
        }

        return $this;
    }

    /**
     * Refresh the row from the database
     *
     * Reloads all data, discarding any unsaved changes.
     *
     * @return static
     * @throws \RuntimeException If persistence not configured
     * @throws \LogicException If trying to refresh a non-existent record
     */
    public function refresh(): self
    {
        if (!$this->exists()) {
            throw new \LogicException('Cannot refresh a record that does not exist');
        }

        $dm = static::get_dm();
        $table = static::get_table();

        // get_key() already returns the exact shape find() expects either
        // way — a scalar for a single-column key, [column => value, ...]
        // for a composite one.
        $fresh = $dm->query_table($table)->find($this->get_key());

        if ($fresh === null) {
            throw new \RuntimeException('Record no longer exists in database');
        }

        $this->data = $fresh;
        $this->original = $fresh;
        $this->wrapped_relations_cache = [];

        // Run after_refresh hooks
        $this->run_hooks('after_refresh');

        return $this;
    }

    /**
     * A fluent, typed query — `->where()->with()->order_by()` in a chain,
     * the same as `$dm->query_table($table)`, ending in an `ActiveRow`
     * instance (or a list of them) instead of a plain array.
     *
     * `find()`/`find_all()`/`find_one()` below are unaffected by this and
     * stay exactly as they are: this is a second way to reach the same
     * `TableQuery`, not a replacement for the first.
     *
     * @example
     * UserRow::query()->where(eq($users->role, 'admin'))->order_by(desc($users->id))->find_many();
     */
    public static function query(): TypedQuery
    {
        return new TypedQuery(static::get_dm()->query_table(static::get_table()), static::class);
    }

    /**
     * Find a record by primary key
     *
     * @param mixed $id Primary key value
     * @param array $with Relations to eager load
     * @return static|null
     */
    public static function find($id, array $with = []): ?self
    {
        $dm = static::get_dm();
        $table = static::get_table();

        $query = $dm->query_table($table);

        if (!empty($with)) {
            $query->with($with);
        }

        $data = $query->find($id);

        if ($data === null) {
            return null;
        }

        return static::wrap($data);
    }

    /**
     * Find multiple records
     *
     * @param array $options Query options (where, with, order_by, limit, offset, with_trashed, without_scopes)
     * @return array<static>
     */
    public static function find_all(array $options = []): array
    {
        $dm = static::get_dm();
        $table = static::get_table();

        $query = $dm->query_table($table);

        if (isset($options['where'])) {
            $query = $query->where($options['where']);
        }

        if (isset($options['with'])) {
            $query = $query->with($options['with']);
        }

        if (isset($options['order_by'])) {
            $order_by = is_array($options['order_by']) ? $options['order_by'] : [$options['order_by']];
            $query = $query->order_by(...$order_by);
        }

        if (isset($options['limit'])) {
            $query = $query->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $query = $query->offset($options['offset']);
        }

        if (!empty($options['with_trashed'])) {
            $query = $query->with_trashed();
        }

        if (isset($options['without_scopes'])) {
            $query = $query->without_scopes($options['without_scopes'] === true ? [] : $options['without_scopes']);
        }

        $rows = $query->find_many();

        return static::wrap_many($rows);
    }

    /**
     * Find the first matching record
     *
     * @param array $options Query options (where, with, order_by)
     * @return static|null
     */
    public static function find_one(array $options = []): ?self
    {
        $options['limit'] = 1;
        $results = static::find_all($options);
        return $results[0] ?? null;
    }

    /**
     * Create and save a new record
     *
     * @param array $data Record data
     * @return static
     */
    public static function create(array $data): self
    {
        $instance = static::make($data);
        $instance->save();
        return $instance;
    }

    /**
     * Update the record with new data and save
     *
     * @param array $data Data to update
     * @return static
     */
    public function update(array $data): self
    {
        $this->fill($data);
        return $this->save();
    }

    /**
     * Save or update based on a unique key
     *
     * @param array $attributes Attributes to match
     * @param array $values Values to set if creating/updating
     * @return static
     */
    public static function upsert(array $attributes, array $values = []): self
    {
        $dm = static::get_dm();
        $table = static::get_table();
        $pk = static::$primary_key;

        // Build where condition
        $conditions = [];
        foreach ($attributes as $key => $value) {
            $conditions[] = eq($table->$key, $value);
        }

        // Try to find existing
        $existing = static::find_one([
            'where' => count($conditions) === 1 ? $conditions[0] : call_user_func_array('\Italix\Orm\Operators\and_', $conditions),
        ]);

        if ($existing !== null) {
            // Update existing
            return $existing->update($values);
        }

        // Create new
        return static::create(array_merge($attributes, $values));
    }
}
