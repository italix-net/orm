<?php

namespace Italix\Orm\ActiveRow\Traits;

/**
 * Trait HasDisplayName
 *
 * Provides a standard interface for entities that have a displayable name.
 * Classes using this trait must implement the display_name() method.
 *
 * This trait is useful for entities that can be displayed in lists,
 * dropdowns, or other UI elements where a human-readable name is needed.
 *
 * @example
 * class PersonRow extends ActiveRow {
 *     use HasDisplayName;
 *
 *     public function display_name(): string {
 *         return $this['first_name'] . ' ' . $this['last_name'];
 *     }
 * }
 *
 * class OrganizationRow extends ActiveRow {
 *     use HasDisplayName;
 *
 *     public function display_name(): string {
 *         return $this['name'];
 *     }
 * }
 */
trait HasDisplayName
{
    /**
     * Get the display name for this entity
     *
     * @return string
     */
    abstract public function display_name(): string;

    /**
     * Get initial letters for avatars, etc.
     *
     * @param int $count Number of initials to return
     * @return string
     */
    public function initials(int $count = 2): string
    {
        $name = $this->display_name();
        $words = preg_split('/\s+/', trim($name));

        $initials = '';
        foreach (array_slice($words, 0, $count) as $word) {
            if (!empty($word)) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }

        return $initials;
    }

    /**
     * Get a truncated display name
     *
     * @param int $maxLength Maximum length
     * @param string $suffix Suffix to add when truncated
     * @return string
     */
    public function truncated_name(int $maxLength = 30, string $suffix = '...'): string
    {
        $name = $this->display_name();

        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        return mb_substr($name, 0, $maxLength - mb_strlen($suffix)) . $suffix;
    }

    /**
     * Get display name in lowercase
     *
     * @return string
     */
    public function display_name_lower(): string
    {
        return mb_strtolower($this->display_name());
    }

    /**
     * Get display name in uppercase
     *
     * @return string
     */
    public function display_name_upper(): string
    {
        return mb_strtoupper($this->display_name());
    }

    /**
     * Get a slug-friendly version of the display name
     *
     * @return string
     */
    public function display_name_slug(): string
    {
        $name = $this->display_name();
        $name = mb_strtolower($name);
        $name = preg_replace('/[^a-z0-9\s-]/', '', $name);
        $name = preg_replace('/[\s-]+/', '-', $name);
        return trim($name, '-');
    }
}
