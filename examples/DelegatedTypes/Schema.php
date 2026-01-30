<?php
/**
 * Schema.org-inspired Database Schema
 *
 * This schema demonstrates the Delegated Types pattern with:
 * - A central 'things' table for all entities
 * - Type-specific tables for additional attributes
 * - A contributions table for polymorphic author/creator relationships
 */

namespace Examples\DelegatedTypes;

use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\bigint;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\boolean;
use function Italix\Orm\Schema\date;
use function Italix\Orm\Schema\timestamp;

/**
 * Create the Schema.org-inspired database schema
 */
class Schema
{
    public Table $things;
    public Table $books;
    public Table $movies;
    public Table $articles;
    public Table $persons;
    public Table $organizations;
    public Table $contributions;

    private string $dialect;

    public function __construct(string $dialect = 'sqlite')
    {
        $this->dialect = $dialect;
        $this->create_things_table();
        $this->create_books_table();
        $this->create_movies_table();
        $this->create_articles_table();
        $this->create_persons_table();
        $this->create_organizations_table();
        $this->create_contributions_table();
    }

    /**
     * Central table for all entities (Thing in Schema.org)
     * Contains shared attributes and type information
     */
    private function create_things_table(): void
    {
        $this->things = new Table('things', [
            'id'               => bigint()->primary_key()->auto_increment(),
            'uuid'             => varchar(36)->unique(),

            // Type hierarchy
            'type'             => varchar(50)->not_null(),      // 'Book', 'Movie', 'Person', etc.
            'type_path'        => varchar(200)->not_null(),     // 'Thing/CreativeWork/Book'

            // Universal Schema.org Thing properties
            'name'             => varchar(500)->not_null(),
            'description'      => text(),
            'url'              => varchar(2000),
            'image_url'        => varchar(2000),

            // Denormalized flags for fast queries
            'is_creative_work' => boolean()->default(false),
            'is_agent'         => boolean()->default(false),

            // Timestamps
            'created_at'       => timestamp(),
            'updated_at'       => timestamp(),
        ], $this->dialect);

        $this->things->add_index('idx_things_type', ['type']);
        $this->things->add_index('idx_things_type_path', ['type_path']);
        $this->things->add_index('idx_things_is_creative_work', ['is_creative_work']);
        $this->things->add_index('idx_things_is_agent', ['is_agent']);
    }

    /**
     * Book-specific attributes (extends CreativeWork)
     */
    private function create_books_table(): void
    {
        $this->books = new Table('books', [
            'id'              => bigint()->primary_key()->auto_increment(),
            'thing_id'        => bigint()->not_null()->unique(),  // FK to things

            // Book-specific properties
            'isbn'            => varchar(20),
            'isbn13'          => varchar(20),
            'number_of_pages' => integer(),
            'publisher_id'    => bigint(),  // FK to things (Organization)
            'date_published'  => date(),
        ], $this->dialect);

        $this->books->add_index('idx_books_isbn', ['isbn']);
        $this->books->add_index('idx_books_isbn13', ['isbn13']);
        $this->books->add_index('idx_books_thing_id', ['thing_id']);
    }

    /**
     * Movie-specific attributes (extends CreativeWork)
     */
    private function create_movies_table(): void
    {
        $this->movies = new Table('movies', [
            'id'             => bigint()->primary_key()->auto_increment(),
            'thing_id'       => bigint()->not_null()->unique(),  // FK to things

            // Movie-specific properties
            'duration'       => integer(),  // minutes
            'content_rating' => varchar(20),
            'date_released'  => date(),
        ], $this->dialect);

        $this->movies->add_index('idx_movies_thing_id', ['thing_id']);
    }

    /**
     * Article-specific attributes (extends CreativeWork)
     */
    private function create_articles_table(): void
    {
        $this->articles = new Table('articles', [
            'id'             => bigint()->primary_key()->auto_increment(),
            'thing_id'       => bigint()->not_null()->unique(),  // FK to things

            // Article-specific properties
            'word_count'     => integer(),
            'date_published' => date(),
            'article_body'   => text(),
        ], $this->dialect);

        $this->articles->add_index('idx_articles_thing_id', ['thing_id']);
    }

    /**
     * Person-specific attributes (extends Agent)
     */
    private function create_persons_table(): void
    {
        $this->persons = new Table('persons', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'thing_id'    => bigint()->not_null()->unique(),  // FK to things

            // Person-specific properties
            'given_name'  => varchar(200),
            'family_name' => varchar(200),
            'birth_date'  => date(),
            'death_date'  => date(),
        ], $this->dialect);

        $this->persons->add_index('idx_persons_thing_id', ['thing_id']);
        $this->persons->add_index('idx_persons_family_name', ['family_name']);
    }

    /**
     * Organization-specific attributes (extends Agent)
     */
    private function create_organizations_table(): void
    {
        $this->organizations = new Table('organizations', [
            'id'               => bigint()->primary_key()->auto_increment(),
            'thing_id'         => bigint()->not_null()->unique(),  // FK to things

            // Organization-specific properties
            'legal_name'       => varchar(500),
            'founding_date'    => date(),
            'dissolution_date' => date(),
        ], $this->dialect);

        $this->organizations->add_index('idx_organizations_thing_id', ['thing_id']);
    }

    /**
     * Contributions table for polymorphic relationships
     * Links Agents (Person/Organization) to CreativeWorks with roles
     */
    private function create_contributions_table(): void
    {
        $this->contributions = new Table('contributions', [
            'id'       => bigint()->primary_key()->auto_increment(),
            'work_id'  => bigint()->not_null(),  // FK to things (CreativeWork)
            'agent_id' => bigint()->not_null(),  // FK to things (Person/Organization)
            'role'     => varchar(50)->not_null(),  // 'author', 'translator', 'director', etc.
            'position' => integer()->default(0),    // Ordering
        ], $this->dialect);

        $this->contributions->add_index('idx_contributions_work_id', ['work_id']);
        $this->contributions->add_index('idx_contributions_agent_id', ['agent_id']);
        $this->contributions->add_index('idx_contributions_role', ['role']);
    }

    /**
     * Get all tables for creation
     *
     * @return Table[]
     */
    public function get_tables(): array
    {
        return [
            $this->things,
            $this->books,
            $this->movies,
            $this->articles,
            $this->persons,
            $this->organizations,
            $this->contributions,
        ];
    }
}
