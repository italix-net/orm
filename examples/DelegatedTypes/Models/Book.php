<?php
/**
 * Book - Delegate class for Book-type Things
 *
 * Represents book-specific attributes and behaviors.
 * The parent Thing holds shared attributes (name, description, etc.)
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\DelegatedTypes\Traits\CreativeWorkBehavior;

class Book extends ActiveRow
{
    use Persistable, CreativeWorkBehavior;

    /**
     * Cache for parent Thing
     * @var Thing|null
     */
    protected ?Thing $thing_cache = null;

    /**
     * Get the thing_id (foreign key)
     *
     * @return int
     */
    public function thing_id(): int
    {
        return (int) $this['thing_id'];
    }

    /**
     * Get the parent Thing
     *
     * @return Thing
     */
    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find($this['thing_id']);
        }
        return $this->thing_cache;
    }

    /**
     * Set the cached Thing (for eager loading)
     *
     * @param Thing $thing
     * @return static
     */
    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    // =========================================
    // BOOK-SPECIFIC METHODS
    // =========================================

    /**
     * Get formatted ISBN-13
     *
     * @return string|null
     */
    public function formatted_isbn(): ?string
    {
        $isbn = $this['isbn13'] ?? $this['isbn'];
        if (!$isbn) {
            return null;
        }

        // Format ISBN-13: 978-0-201-63361-0
        $clean = preg_replace('/[^0-9X]/', '', strtoupper($isbn));
        if (strlen($clean) === 13) {
            return substr($clean, 0, 3) . '-' .
                   substr($clean, 3, 1) . '-' .
                   substr($clean, 4, 3) . '-' .
                   substr($clean, 7, 5) . '-' .
                   substr($clean, 12, 1);
        }

        return $isbn;
    }

    /**
     * Get the publisher (Organization)
     *
     * @return Thing|null
     */
    public function publisher(): ?Thing
    {
        if (empty($this['publisher_id'])) {
            return null;
        }
        return Thing::find_with_delegate($this['publisher_id']);
    }

    /**
     * Set the publisher
     *
     * @param Thing $organization
     * @return static
     */
    public function set_publisher(Thing $organization): static
    {
        $this['publisher_id'] = $organization['id'];
        return $this;
    }

    /**
     * Get page count
     *
     * @return int|null
     */
    public function pages(): ?int
    {
        return $this['number_of_pages'] ? (int) $this['number_of_pages'] : null;
    }

    /**
     * Get publication date
     *
     * @return \DateTime|null
     */
    public function date_published(): ?\DateTime
    {
        if (empty($this['date_published'])) {
            return null;
        }
        return new \DateTime($this['date_published']);
    }

    /**
     * Get publication year
     *
     * @return int|null
     */
    public function year_published(): ?int
    {
        $date = $this->date_published();
        return $date ? (int) $date->format('Y') : null;
    }

    // =========================================
    // CITATION FORMATTING
    // =========================================

    /**
     * Generate APA-style citation
     *
     * @return string
     */
    public function cite_apa(): string
    {
        $parts = [];

        // Authors
        $authors = $this->citation_authors();
        if ($authors) {
            $parts[] = $authors;
        }

        // Year
        $year = $this->year_published();
        if ($year) {
            $parts[] = "({$year})";
        }

        // Title (italicized in real formatting)
        $thing = $this->thing();
        $parts[] = $thing['name'] . '.';

        // Publisher
        $publisher = $this->publisher();
        if ($publisher) {
            $parts[] = $publisher['name'] . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * Generate Chicago-style citation
     *
     * @return string
     */
    public function cite_chicago(): string
    {
        $parts = [];

        // Authors
        $authors = $this->authors_string(' and ');
        if ($authors) {
            $parts[] = $authors . '.';
        }

        // Title
        $thing = $this->thing();
        $parts[] = '"' . $thing['name'] . '."';

        // Publisher and year
        $publisher = $this->publisher();
        if ($publisher) {
            $parts[] = $publisher['name'] . ',';
        }

        $year = $this->year_published();
        if ($year) {
            $parts[] = $year . '.';
        }

        return implode(' ', $parts);
    }
}
