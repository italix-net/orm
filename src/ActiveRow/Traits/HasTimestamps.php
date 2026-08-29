<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Italix\Orm\ActiveRow\Traits;

/**
 * Trait HasTimestamps
 *
 * Automatically manages created_at and updated_at timestamps.
 * Works with the hook system - adds before_save_timestamps hook.
 *
 * @example
 * class UserRow extends ActiveRow {
 *     use Persistable, HasTimestamps;
 * }
 *
 * $user = UserRow::make(['name' => 'John']);
 * $user->save();
 * // created_at and updated_at are automatically set
 *
 * $user['name'] = 'Jane';
 * $user->save();
 * // updated_at is automatically updated
 */
trait HasTimestamps
{
    /**
     * Column name for creation timestamp
     * @var string
     */
    protected static $created_at_column = 'created_at';

    /**
     * Column name for update timestamp
     * @var string
     */
    protected static $updated_at_column = 'updated_at';

    /**
     * Timestamp format
     * @var string
     */
    protected static $timestamp_format = 'Y-m-d H:i:s';

    /**
     * Whether timestamps are enabled
     * @var bool
     */
    protected static $timestamps_enabled = true;

    /**
     * Hook: Set timestamps before save
     *
     * Called automatically by the hook system when save() is called.
     *
     * @return void
     */
    protected function before_save_timestamps(): void
    {
        if (!static::$timestamps_enabled) {
            return;
        }

        $now = $this->fresh_timestamp();

        // Set created_at for new records
        if (!$this->exists()) {
            $created_at_column = static::$created_at_column;
            if (!isset($this->data[$created_at_column])) {
                $this->data[$created_at_column] = $now;
            }
        }

        // Always update updated_at
        $updated_at_column = static::$updated_at_column;
        $this->data[$updated_at_column] = $now;
    }

    /**
     * Get a fresh timestamp
     *
     * @return string
     */
    protected function fresh_timestamp(): string
    {
        return date(static::$timestamp_format);
    }

    /**
     * Get a fresh timestamp as DateTime object
     *
     * @return \DateTime
     */
    protected function fresh_timestamp_datetime(): \DateTime
    {
        return new \DateTime();
    }

    /**
     * Touch the updated_at timestamp
     *
     * @return static
     */
    public function touch(): self
    {
        $this->data[static::$updated_at_column] = $this->fresh_timestamp();
        return $this;
    }

    /**
     * Get the created_at value
     *
     * @return string|null
     */
    public function get_created_at(): ?string
    {
        return $this->data[static::$created_at_column] ?? null;
    }

    /**
     * Get the updated_at value
     *
     * @return string|null
     */
    public function get_updated_at(): ?string
    {
        return $this->data[static::$updated_at_column] ?? null;
    }

    /**
     * Get created_at as DateTime object
     *
     * @return \DateTime|null
     */
    public function get_created_at_datetime(): ?\DateTime
    {
        $value = $this->get_created_at();
        return $value ? new \DateTime($value) : null;
    }

    /**
     * Get updated_at as DateTime object
     *
     * @return \DateTime|null
     */
    public function get_updated_at_datetime(): ?\DateTime
    {
        $value = $this->get_updated_at();
        return $value ? new \DateTime($value) : null;
    }

    /**
     * Disable timestamps for this instance
     *
     * @return static
     */
    public function without_timestamps(): self
    {
        // Note: This affects the class, not just the instance
        // For instance-level control, we'd need a different approach
        static::$timestamps_enabled = false;
        return $this;
    }

    /**
     * Re-enable timestamps
     *
     * @return static
     */
    public function with_timestamps(): self
    {
        static::$timestamps_enabled = true;
        return $this;
    }

    /**
     * Check if the record was recently created (within the last N seconds)
     *
     * @param int $seconds Number of seconds to consider as "recent"
     * @return bool
     */
    public function was_recently_created(int $seconds = 60): bool
    {
        $created_at = $this->get_created_at_datetime();
        if ($created_at === null) {
            return false;
        }

        $diff = (new \DateTime())->getTimestamp() - $created_at->getTimestamp();
        return $diff <= $seconds;
    }

    /**
     * Check if the record was recently updated (within the last N seconds)
     *
     * @param int $seconds Number of seconds to consider as "recent"
     * @return bool
     */
    public function was_recently_updated(int $seconds = 60): bool
    {
        $updated_at = $this->get_updated_at_datetime();
        if ($updated_at === null) {
            return false;
        }

        $diff = (new \DateTime())->getTimestamp() - $updated_at->getTimestamp();
        return $diff <= $seconds;
    }
}
