<?php

namespace Italix\Orm\ActiveRow\Traits;

/**
 * Trait CanBeAuthor
 *
 * Provides author-related functionality for entities that can be authors
 * of creative works (Person, Organization, etc.).
 *
 * This trait uses HasDisplayName internally, so the class must implement
 * the display_name() method.
 *
 * @example
 * class PersonRow extends ActiveRow {
 *     use CanBeAuthor;
 *
 *     public function display_name(): string {
 *         return $this['given_name'] . ' ' . $this['family_name'];
 *     }
 * }
 *
 * class OrganizationRow extends ActiveRow {
 *     use CanBeAuthor;
 *
 *     public function display_name(): string {
 *         return $this['name'];
 *     }
 * }
 *
 * // Both can be used as authors
 * foreach ($work->authors() as $author) {
 *     echo $author->author_label();      // Works for Person or Organization
 *     echo $author->author_type();       // "person" or "organization"
 *     echo $author->citation_name();     // Formatted for citations
 * }
 */
trait CanBeAuthor
{
    use HasDisplayName;

    /**
     * Get the author label (display name by default)
     *
     * @return string
     */
    public function author_label(): string
    {
        return $this->display_name();
    }

    /**
     * Get the author type identifier
     *
     * Returns a lowercase string identifying the type of author.
     * Defaults to the class name without "Row" suffix.
     *
     * @return string
     */
    public function author_type(): string
    {
        $class = get_class($this);

        // Get just the class name without namespace
        $pos = strrpos($class, '\\');
        $shortName = $pos !== false ? substr($class, $pos + 1) : $class;

        // Remove "Row" suffix if present
        if (substr($shortName, -3) === 'Row') {
            $shortName = substr($shortName, 0, -3);
        }

        return strtolower($shortName);
    }

    /**
     * Get the name formatted for academic citations
     *
     * Override in specific classes for custom formatting.
     * Default: returns display_name()
     *
     * @return string
     */
    public function citation_name(): string
    {
        return $this->display_name();
    }

    /**
     * Check if this is a person author
     *
     * @return bool
     */
    public function is_person(): bool
    {
        return $this->author_type() === 'person';
    }

    /**
     * Check if this is an organization author
     *
     * @return bool
     */
    public function is_organization(): bool
    {
        return $this->author_type() === 'organization';
    }

    /**
     * Get author metadata for serialization
     *
     * @return array
     */
    public function author_meta(): array
    {
        return [
            'type' => $this->author_type(),
            'name' => $this->author_label(),
            'citation_name' => $this->citation_name(),
        ];
    }
}
