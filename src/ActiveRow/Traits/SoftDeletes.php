<?php

namespace Italix\Orm\ActiveRow\Traits;

/**
 * Trait SoftDeletes
 *
 * Adds soft delete functionality - records are marked as deleted
 * rather than being permanently removed from the database.
 *
 * Requires a 'deleted_at' column (nullable timestamp) in the table.
 *
 * @example
 * class PostRow extends ActiveRow {
 *     use Persistable, SoftDeletes;
 * }
 *
 * $post->soft_delete();   // Sets deleted_at timestamp
 * $post->is_deleted();    // true
 * $post->restore();       // Clears deleted_at
 * $post->force_delete();  // Permanently removes from database
 */
trait SoftDeletes
{
    /**
     * Column name for soft delete timestamp
     * @var string
     */
    protected static $deleted_at_column = 'deleted_at';

    /**
     * Timestamp format for soft delete
     * @var string
     */
    protected static $soft_delete_format = 'Y-m-d H:i:s';

    /**
     * Soft delete the record
     *
     * Sets the deleted_at timestamp instead of permanently deleting.
     *
     * @return static
     */
    public function soft_delete(): self
    {
        // Run before_soft_delete hooks
        $this->run_hooks('before_soft_delete');

        $this->data[static::$deleted_at_column] = date(static::$soft_delete_format);

        // Save if persistable
        if (method_exists($this, 'save')) {
            $this->save();
        }

        // Run after_soft_delete hooks
        $this->run_hooks('after_soft_delete');

        return $this;
    }

    /**
     * Restore a soft-deleted record
     *
     * Clears the deleted_at timestamp.
     *
     * @return static
     */
    public function restore(): self
    {
        // Run before_restore hooks
        $this->run_hooks('before_restore');

        $this->data[static::$deleted_at_column] = null;

        // Save if persistable
        if (method_exists($this, 'save')) {
            $this->save();
        }

        // Run after_restore hooks
        $this->run_hooks('after_restore');

        return $this;
    }

    /**
     * Check if the record is soft deleted
     *
     * @return bool
     */
    public function is_deleted(): bool
    {
        $deletedAt = $this->data[static::$deleted_at_column] ?? null;
        return $deletedAt !== null;
    }

    /**
     * Check if the record is not soft deleted
     *
     * @return bool
     */
    public function is_active(): bool
    {
        return !$this->is_deleted();
    }

    /**
     * Get the deleted_at value
     *
     * @return string|null
     */
    public function get_deleted_at(): ?string
    {
        return $this->data[static::$deleted_at_column] ?? null;
    }

    /**
     * Get deleted_at as DateTime object
     *
     * @return \DateTime|null
     */
    public function get_deleted_at_datetime(): ?\DateTime
    {
        $value = $this->get_deleted_at();
        return $value ? new \DateTime($value) : null;
    }

    /**
     * Permanently delete the record (bypass soft delete)
     *
     * @return static
     */
    public function force_delete(): self
    {
        // Run before_force_delete hooks
        $this->run_hooks('before_force_delete');

        // Use parent delete if available (from Persistable trait)
        if (method_exists($this, 'delete')) {
            // Call the parent delete directly
            $this->perform_hard_delete();
        }

        // Run after_force_delete hooks
        $this->run_hooks('after_force_delete');

        return $this;
    }

    /**
     * Perform the actual hard delete
     *
     * @return void
     */
    protected function perform_hard_delete(): void
    {
        if (!$this->exists()) {
            throw new \LogicException('Cannot delete a record that does not exist');
        }

        $db = static::get_db();
        $table = static::get_table();
        $pk = static::$primary_key;

        $db->delete($table)
            ->where(\Italix\Orm\Operators\eq($table->$pk, $this[$pk]))
            ->execute();

        // Clear the primary key
        unset($this->data[$pk]);
    }

    /**
     * Check if the record was deleted within the last N seconds
     *
     * @param int $seconds Number of seconds to consider as "recent"
     * @return bool
     */
    public function was_recently_deleted(int $seconds = 60): bool
    {
        $deletedAt = $this->get_deleted_at_datetime();
        if ($deletedAt === null) {
            return false;
        }

        $diff = (new \DateTime())->getTimestamp() - $deletedAt->getTimestamp();
        return $diff <= $seconds;
    }

    /**
     * Get time since deletion
     *
     * @return \DateInterval|null
     */
    public function time_since_deletion(): ?\DateInterval
    {
        $deletedAt = $this->get_deleted_at_datetime();
        if ($deletedAt === null) {
            return null;
        }

        return $deletedAt->diff(new \DateTime());
    }
}
