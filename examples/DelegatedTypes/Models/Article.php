<?php
/**
 * Article - Delegate class for Article-type Things
 *
 * Represents article-specific attributes and behaviors.
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\DelegatedTypes\Traits\CreativeWorkBehavior;

class Article extends ActiveRow
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
    // ARTICLE-SPECIFIC METHODS
    // =========================================

    /**
     * Get word count
     *
     * @return int|null
     */
    public function word_count(): ?int
    {
        return $this['word_count'] ? (int) $this['word_count'] : null;
    }

    /**
     * Get article body
     *
     * @return string|null
     */
    public function body(): ?string
    {
        return $this['article_body'];
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
     * Get estimated reading time in minutes
     * Assumes average reading speed of 200 words per minute
     *
     * @param int $words_per_minute
     * @return int|null
     */
    public function reading_time(int $words_per_minute = 200): ?int
    {
        $words = $this->word_count();
        if ($words === null) {
            return null;
        }
        return (int) ceil($words / $words_per_minute);
    }

    /**
     * Get formatted reading time
     *
     * @return string|null
     */
    public function formatted_reading_time(): ?string
    {
        $minutes = $this->reading_time();
        if ($minutes === null) {
            return null;
        }
        return $minutes . ' min read';
    }

    /**
     * Get an excerpt from the article body
     *
     * @param int $length
     * @param string $suffix
     * @return string|null
     */
    public function excerpt(int $length = 200, string $suffix = '...'): ?string
    {
        $body = $this->body();
        if ($body === null) {
            return null;
        }

        if (mb_strlen($body) <= $length) {
            return $body;
        }

        $excerpt = mb_substr($body, 0, $length);
        // Try to break at a word boundary
        $last_space = mb_strrpos($excerpt, ' ');
        if ($last_space !== false && $last_space > $length * 0.8) {
            $excerpt = mb_substr($excerpt, 0, $last_space);
        }

        return $excerpt . $suffix;
    }
}
