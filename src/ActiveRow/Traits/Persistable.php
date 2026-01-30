<?php

namespace Italix\Orm\ActiveRow\Traits;

use Italix\Orm\IxOrm;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Operators\eq;

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
 * UserRow::set_persistence($db, $users_table);
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
     * @var array<string, IxOrm>
     */
    private static array $db_registry = [];

    /**
     * Registry of table definitions by class name
     * @var array<string, Table>
     */
    private static array $table_registry = [];

    /**
     * Set up persistence for this row class
     *
     * @param IxOrm $db Database connection
     * @param Table $table Table definition
     * @return void
     */
    public static function set_persistence(IxOrm $db, Table $table): void
    {
        self::$db_registry[static::class] = $db;
        self::$table_registry[static::class] = $table;
    }

    /**
     * Get the database connection
     *
     * @return IxOrm
     * @throws \RuntimeException If persistence not configured
     */
    public static function get_db(): IxOrm
    {
        if (!isset(self::$db_registry[static::class])) {
            throw new \RuntimeException(
                'Persistence not configured for ' . static::class . '. ' .
                'Call ' . static::class . '::set_persistence($db, $table) first.'
            );
        }
        return self::$db_registry[static::class];
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
                'Call ' . static::class . '::set_persistence($db, $table) first.'
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
        return isset(self::$db_registry[static::class]) && isset(self::$table_registry[static::class]);
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
        $db = static::get_db();
        $table = static::get_table();
        $pk = static::$primary_key;

        // Run before_save hooks
        $this->run_hooks('before_save');

        if ($this->exists()) {
            // UPDATE existing record
            $dirty = $this->get_dirty();

            if (!empty($dirty)) {
                // Don't update the primary key
                unset($dirty[$pk]);

                if (!empty($dirty)) {
                    $db->update($table)
                        ->set($dirty)
                        ->where(eq($table->$pk, $this[$pk]))
                        ->execute();
                }
            }
        } else {
            // INSERT new record
            $data = $this->data;

            // Remove null primary key
            if (isset($data[$pk]) && $data[$pk] === null) {
                unset($data[$pk]);
            }

            $db->insert($table)
                ->values($data)
                ->execute();

            // Get the auto-generated ID
            $newId = $db->last_insert_id();
            if ($newId) {
                $this->data[$pk] = (int) $newId;
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

        $db = static::get_db();
        $table = static::get_table();
        $pk = static::$primary_key;

        // Run before_delete hooks
        $this->run_hooks('before_delete');

        $db->delete($table)
            ->where(eq($table->$pk, $this[$pk]))
            ->execute();

        // Run after_delete hooks
        $this->run_hooks('after_delete');

        // Clear the primary key to mark as non-existent
        unset($this->data[$pk]);

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

        $db = static::get_db();
        $table = static::get_table();
        $pk = static::$primary_key;

        $fresh = $db->query_table($table)->find($this[$pk]);

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
     * Find a record by primary key
     *
     * @param mixed $id Primary key value
     * @param array $with Relations to eager load
     * @return static|null
     */
    public static function find($id, array $with = []): ?self
    {
        $db = static::get_db();
        $table = static::get_table();

        $query = $db->query_table($table);

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
     * @param array $options Query options (where, with, order_by, limit, offset)
     * @return array<static>
     */
    public static function find_all(array $options = []): array
    {
        $db = static::get_db();
        $table = static::get_table();

        $query = $db->query_table($table);

        if (isset($options['where'])) {
            $query->where($options['where']);
        }

        if (isset($options['with'])) {
            $query->with($options['with']);
        }

        if (isset($options['order_by'])) {
            $orderBy = is_array($options['order_by']) ? $options['order_by'] : [$options['order_by']];
            $query->order_by(...$orderBy);
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $query->offset($options['offset']);
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
        $db = static::get_db();
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
