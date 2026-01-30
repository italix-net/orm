<?php
/**
 * Thing - Base class for all Schema.org entities
 *
 * Uses the DelegatedTypes pattern to delegate type-specific
 * behavior to separate classes (Book, Movie, Person, etc.)
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;
use Italix\Orm\ActiveRow\Traits\DelegatedTypes;

class Thing extends ActiveRow
{
    use Persistable, HasTimestamps, DelegatedTypes;

    /**
     * Define the delegated types
     *
     * @return array<string, class-string<ActiveRow>>
     */
    protected function get_delegated_types(): array
    {
        return [
            'Book'         => Book::class,
            'Movie'        => Movie::class,
            'Article'      => Article::class,
            'Person'       => Person::class,
            'Organization' => Organization::class,
        ];
    }

    /**
     * Generate a UUID for new things
     */
    protected function before_save_uuid(): void
    {
        if (empty($this->data['uuid'])) {
            $this->data['uuid'] = $this->generate_uuid();
        }
    }

    /**
     * Set type path based on type
     */
    protected function before_save_type_path(): void
    {
        if (empty($this->data['type_path']) && !empty($this->data['type'])) {
            $this->data['type_path'] = $this->get_default_type_path($this->data['type']);
        }
    }

    /**
     * Set is_creative_work and is_agent flags
     */
    protected function before_save_flags(): void
    {
        $type = $this->data['type'] ?? '';
        $creative_work_types = ['Book', 'Movie', 'Article'];
        $agent_types = ['Person', 'Organization'];

        $this->data['is_creative_work'] = in_array($type, $creative_work_types);
        $this->data['is_agent'] = in_array($type, $agent_types);
    }

    /**
     * Get the default type path for a type
     *
     * @param string $type
     * @return string
     */
    protected function get_default_type_path(string $type): string
    {
        $paths = [
            'Book'         => 'Thing/CreativeWork/Book',
            'Movie'        => 'Thing/CreativeWork/Movie',
            'Article'      => 'Thing/CreativeWork/Article',
            'Person'       => 'Thing/Agent/Person',
            'Organization' => 'Thing/Agent/Organization',
        ];

        return $paths[$type] ?? "Thing/{$type}";
    }

    /**
     * Generate a simple UUID v4
     *
     * @return string
     */
    protected function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // =========================================
    // CONVENIENCE METHODS
    // =========================================

    /**
     * Check if this is a creative work
     *
     * @return bool
     */
    public function is_creative_work(): bool
    {
        return (bool) ($this['is_creative_work'] ?? false);
    }

    /**
     * Check if this is an agent (Person or Organization)
     *
     * @return bool
     */
    public function is_agent(): bool
    {
        return (bool) ($this['is_agent'] ?? false);
    }

    /**
     * Get the UUID
     *
     * @return string|null
     */
    public function uuid(): ?string
    {
        return $this['uuid'];
    }

    // =========================================
    // STATIC FACTORY METHODS
    // =========================================

    /**
     * Create a new Book
     *
     * @param array $thing_data Thing attributes (name, description, etc.)
     * @param array $book_data Book attributes (isbn, pages, etc.)
     * @return static
     */
    public static function create_book(array $thing_data, array $book_data = []): static
    {
        return static::create_with_delegate('Book', $thing_data, $book_data);
    }

    /**
     * Create a new Movie
     *
     * @param array $thing_data Thing attributes
     * @param array $movie_data Movie attributes
     * @return static
     */
    public static function create_movie(array $thing_data, array $movie_data = []): static
    {
        return static::create_with_delegate('Movie', $thing_data, $movie_data);
    }

    /**
     * Create a new Article
     *
     * @param array $thing_data Thing attributes
     * @param array $article_data Article attributes
     * @return static
     */
    public static function create_article(array $thing_data, array $article_data = []): static
    {
        return static::create_with_delegate('Article', $thing_data, $article_data);
    }

    /**
     * Create a new Person
     *
     * @param array $thing_data Thing attributes
     * @param array $person_data Person attributes
     * @return static
     */
    public static function create_person(array $thing_data, array $person_data = []): static
    {
        return static::create_with_delegate('Person', $thing_data, $person_data);
    }

    /**
     * Create a new Organization
     *
     * @param array $thing_data Thing attributes
     * @param array $org_data Organization attributes
     * @return static
     */
    public static function create_organization(array $thing_data, array $org_data = []): static
    {
        return static::create_with_delegate('Organization', $thing_data, $org_data);
    }

    // =========================================
    // QUERY HELPERS
    // =========================================

    /**
     * Find all creative works
     *
     * @param array $options Additional query options
     * @return array<static>
     */
    public static function find_creative_works(array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = isset($options['where'])
            ? \Italix\Orm\Operators\and_($options['where'], \Italix\Orm\Operators\eq($table->is_creative_work, 1))
            : \Italix\Orm\Operators\eq($table->is_creative_work, 1);

        return static::find_with_delegates($options);
    }

    /**
     * Find all agents (persons and organizations)
     *
     * @param array $options Additional query options
     * @return array<static>
     */
    public static function find_agents(array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = isset($options['where'])
            ? \Italix\Orm\Operators\and_($options['where'], \Italix\Orm\Operators\eq($table->is_agent, 1))
            : \Italix\Orm\Operators\eq($table->is_agent, 1);

        return static::find_with_delegates($options);
    }

    /**
     * Find things by type
     *
     * @param string $type Type name
     * @param array $options Additional query options
     * @return array<static>
     */
    public static function find_by_type(string $type, array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = isset($options['where'])
            ? \Italix\Orm\Operators\and_($options['where'], \Italix\Orm\Operators\eq($table->type, $type))
            : \Italix\Orm\Operators\eq($table->type, $type);

        return static::find_with_delegates($options);
    }
}
