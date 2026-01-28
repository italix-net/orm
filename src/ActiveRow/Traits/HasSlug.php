<?php

namespace Italix\Orm\ActiveRow\Traits;

/**
 * Trait HasSlug
 *
 * Automatically generates URL-friendly slugs from a source field.
 * Works with the hook system - adds before_save_slug hook.
 *
 * @example
 * class PostRow extends ActiveRow {
 *     use Persistable, HasSlug;
 *
 *     protected static $slug_source = 'title';  // Generate from title
 * }
 *
 * $post = PostRow::make(['title' => 'Hello World!']);
 * $post->save();
 * echo $post['slug'];  // 'hello-world'
 */
trait HasSlug
{
    /**
     * Column name for the slug
     * @var string
     */
    protected static $slug_column = 'slug';

    /**
     * Source column to generate slug from
     * @var string
     */
    protected static $slug_source = 'title';

    /**
     * Whether to regenerate slug on update
     * @var bool
     */
    protected static $slug_on_update = false;

    /**
     * Maximum slug length
     * @var int
     */
    protected static $slug_max_length = 255;

    /**
     * Hook: Generate slug before save
     *
     * @return void
     */
    protected function before_save_slug(): void
    {
        $slugColumn = static::$slug_column;
        $sourceColumn = static::$slug_source;

        // Skip if slug already set and we're not updating
        if (!empty($this->data[$slugColumn]) && $this->exists() && !static::$slug_on_update) {
            return;
        }

        // Skip if source is empty
        if (empty($this->data[$sourceColumn])) {
            return;
        }

        // Generate slug only if:
        // 1. Creating new record and slug is empty
        // 2. Source field changed and slug_on_update is true
        $shouldGenerate = false;

        if (!$this->exists() && empty($this->data[$slugColumn])) {
            $shouldGenerate = true;
        } elseif (static::$slug_on_update && $this->is_dirty($sourceColumn)) {
            $shouldGenerate = true;
        }

        if ($shouldGenerate) {
            $this->data[$slugColumn] = $this->generate_slug($this->data[$sourceColumn]);
        }
    }

    /**
     * Generate a URL-friendly slug from text
     *
     * @param string $text Source text
     * @return string
     */
    public function generate_slug(string $text): string
    {
        // Convert to lowercase
        $slug = mb_strtolower($text, 'UTF-8');

        // Replace accented characters with ASCII equivalents
        $slug = $this->transliterate($slug);

        // Replace non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');

        // Collapse multiple hyphens
        $slug = preg_replace('/-+/', '-', $slug);

        // Truncate if necessary
        if (mb_strlen($slug) > static::$slug_max_length) {
            $slug = mb_substr($slug, 0, static::$slug_max_length);
            $slug = rtrim($slug, '-');
        }

        return $slug;
    }

    /**
     * Transliterate accented characters to ASCII
     *
     * @param string $text
     * @return string
     */
    protected function transliterate(string $text): string
    {
        $transliterations = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ð' => 'd',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ß' => 'ss',
            'þ' => 'th',
        ];

        return strtr($text, $transliterations);
    }

    /**
     * Get the slug value
     *
     * @return string|null
     */
    public function get_slug(): ?string
    {
        return $this->data[static::$slug_column] ?? null;
    }

    /**
     * Manually set the slug
     *
     * @param string $slug
     * @return static
     */
    public function set_slug(string $slug): self
    {
        $this->data[static::$slug_column] = $this->generate_slug($slug);
        return $this;
    }

    /**
     * Regenerate the slug from the source field
     *
     * @return static
     */
    public function regenerate_slug(): self
    {
        $sourceColumn = static::$slug_source;
        if (!empty($this->data[$sourceColumn])) {
            $this->data[static::$slug_column] = $this->generate_slug($this->data[$sourceColumn]);
        }
        return $this;
    }

    /**
     * Get a unique slug (appends number if necessary)
     *
     * This is a simple implementation - for production use,
     * you'd want to check the database for existing slugs.
     *
     * @param string $baseSlug
     * @param int $suffix
     * @return string
     */
    public function unique_slug(string $baseSlug, int $suffix = 0): string
    {
        if ($suffix === 0) {
            return $baseSlug;
        }
        return $baseSlug . '-' . $suffix;
    }
}
