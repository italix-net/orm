<?php
/**
 * Schema.org Events Database Schema
 *
 * Demonstrates two-level delegated types with:
 * - Thing → Event → DanceEvent/MusicEvent/CourseInstance
 * - Thing → Place → LocalBusiness
 * - A participations junction table linking Things to Agents with roles
 */

namespace Examples\Events;

use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\bigint;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\boolean;
use function Italix\Orm\Schema\date;
use function Italix\Orm\Schema\timestamp;
use function Italix\Orm\Schema\decimal;

class Schema
{
    public Table $things;
    public Table $persons;
    public Table $organizations;
    public Table $places;
    public Table $local_businesses;
    public Table $postal_addresses;
    public Table $courses;
    public Table $events;
    public Table $dance_events;
    public Table $music_events;
    public Table $course_instances;
    public Table $participations;

    private string $dialect;

    public function __construct(string $dialect = 'sqlite')
    {
        $this->dialect = $dialect;
        $this->create_things_table();
        $this->create_persons_table();
        $this->create_organizations_table();
        $this->create_places_table();
        $this->create_local_businesses_table();
        $this->create_postal_addresses_table();
        $this->create_courses_table();
        $this->create_events_table();
        $this->create_dance_events_table();
        $this->create_music_events_table();
        $this->create_course_instances_table();
        $this->create_participations_table();
    }

    /**
     * Central table for all entities (Thing in Schema.org)
     */
    private function create_things_table(): void
    {
        $this->things = new Table('things', [
            'id'         => bigint()->primary_key()->auto_increment(),
            'uuid'       => varchar(36)->unique(),

            // Type hierarchy
            'type'       => varchar(50)->not_null(),      // 'DanceEvent', 'Person', 'Place', etc.
            'type_path'  => varchar(200)->not_null(),     // 'Thing/Event/DanceEvent'

            // Universal Schema.org Thing properties
            'name'       => varchar(500)->not_null(),
            'description'=> text(),
            'url'        => varchar(2000),
            'image_url'  => varchar(2000),

            // Denormalized flags for fast queries
            'is_event'   => boolean()->default(false),
            'is_agent'   => boolean()->default(false),

            // Timestamps
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ], $this->dialect);

        $this->things->add_index('idx_things_type', ['type']);
        $this->things->add_index('idx_things_type_path', ['type_path']);
        $this->things->add_index('idx_things_is_event', ['is_event']);
        $this->things->add_index('idx_things_is_agent', ['is_agent']);
    }

    /**
     * Person-specific attributes
     */
    private function create_persons_table(): void
    {
        $this->persons = new Table('persons', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'thing_id'    => bigint()->not_null()->unique(),  // FK to things

            'given_name'  => varchar(200),
            'family_name' => varchar(200),
            'email'       => varchar(500),
            'telephone'   => varchar(50),
            'birth_date'  => date(),
        ], $this->dialect);

        $this->persons->add_index('idx_persons_thing_id', ['thing_id']);
        $this->persons->add_index('idx_persons_family_name', ['family_name']);
    }

    /**
     * Organization-specific attributes
     */
    private function create_organizations_table(): void
    {
        $this->organizations = new Table('organizations', [
            'id'            => bigint()->primary_key()->auto_increment(),
            'thing_id'      => bigint()->not_null()->unique(),  // FK to things

            'legal_name'    => varchar(500),
            'founding_date' => date(),
            'email'         => varchar(500),
            'telephone'     => varchar(50),
        ], $this->dialect);

        $this->organizations->add_index('idx_organizations_thing_id', ['thing_id']);
    }

    /**
     * Place-specific attributes (first-level delegate)
     */
    private function create_places_table(): void
    {
        $this->places = new Table('places', [
            'id'           => bigint()->primary_key()->auto_increment(),
            'thing_id'     => bigint()->not_null()->unique(),  // FK to things

            'latitude'     => decimal(10, 7),
            'longitude'    => decimal(10, 7),
            'telephone'    => varchar(50),
            'max_capacity' => integer(),
        ], $this->dialect);

        $this->places->add_index('idx_places_thing_id', ['thing_id']);
    }

    /**
     * LocalBusiness-specific attributes (second-level delegate under Place)
     */
    private function create_local_businesses_table(): void
    {
        $this->local_businesses = new Table('local_businesses', [
            'id'            => bigint()->primary_key()->auto_increment(),
            'place_id'      => bigint()->not_null()->unique(),  // FK to places

            'legal_name'    => varchar(500),
            'price_range'   => varchar(10),   // '$', '$$', '$$$', '$$$$'
            'opening_hours' => varchar(500),
            'telephone'     => varchar(50),
            'email'         => varchar(500),
        ], $this->dialect);

        $this->local_businesses->add_index('idx_local_businesses_place_id', ['place_id']);
    }

    /**
     * Postal address for places (one-to-one with Place)
     */
    private function create_postal_addresses_table(): void
    {
        $this->postal_addresses = new Table('postal_addresses', [
            'id'               => bigint()->primary_key()->auto_increment(),
            'place_id'         => bigint()->not_null()->unique(),  // FK to places

            'street_address'   => varchar(500),
            'address_locality' => varchar(200),   // city
            'address_region'   => varchar(200),   // state/province
            'postal_code'      => varchar(20),
            'address_country'  => varchar(100),
        ], $this->dialect);

        $this->postal_addresses->add_index('idx_postal_addresses_place_id', ['place_id']);
    }

    /**
     * Course-specific attributes (represents a course series)
     */
    private function create_courses_table(): void
    {
        $this->courses = new Table('courses', [
            'id'             => bigint()->primary_key()->auto_increment(),
            'thing_id'       => bigint()->not_null()->unique(),  // FK to things

            'course_code'    => varchar(50),
            'course_level'   => varchar(50),     // 'Beginner', 'Intermediate', 'Advanced'
            'total_sessions' => integer(),
        ], $this->dialect);

        $this->courses->add_index('idx_courses_thing_id', ['thing_id']);
    }

    /**
     * Event-specific attributes (first-level delegate)
     */
    private function create_events_table(): void
    {
        $this->events = new Table('events', [
            'id'                    => bigint()->primary_key()->auto_increment(),
            'thing_id'              => bigint()->not_null()->unique(),  // FK to things

            'start_date'            => timestamp(),
            'end_date'              => timestamp(),
            'duration'              => integer(),     // minutes
            'event_status'          => varchar(30),   // EventScheduled, EventCancelled, etc.
            'event_attendance_mode' => varchar(30),   // Offline, Online, Mixed
            'location_id'           => bigint(),      // FK to things (Place)
            'in_language'           => varchar(10),
            'max_attendees'         => integer(),
            'is_free'               => boolean()->default(false),
        ], $this->dialect);

        $this->events->add_index('idx_events_thing_id', ['thing_id']);
        $this->events->add_index('idx_events_start_date', ['start_date']);
        $this->events->add_index('idx_events_location_id', ['location_id']);
        $this->events->add_index('idx_events_event_status', ['event_status']);
    }

    /**
     * DanceEvent-specific attributes (second-level delegate under Event)
     */
    private function create_dance_events_table(): void
    {
        $this->dance_events = new Table('dance_events', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'event_id'    => bigint()->not_null()->unique(),  // FK to events

            'dance_style' => varchar(100),
        ], $this->dialect);

        $this->dance_events->add_index('idx_dance_events_event_id', ['event_id']);
    }

    /**
     * MusicEvent-specific attributes (second-level delegate under Event)
     */
    private function create_music_events_table(): void
    {
        $this->music_events = new Table('music_events', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'event_id'    => bigint()->not_null()->unique(),  // FK to events

            'music_genre' => varchar(100),
        ], $this->dialect);

        $this->music_events->add_index('idx_music_events_event_id', ['event_id']);
    }

    /**
     * CourseInstance-specific attributes (second-level delegate under Event)
     */
    private function create_course_instances_table(): void
    {
        $this->course_instances = new Table('course_instances', [
            'id'             => bigint()->primary_key()->auto_increment(),
            'event_id'       => bigint()->not_null()->unique(),  // FK to events

            'course_id'      => bigint(),       // FK to things (Course)
            'session_number' => integer(),
        ], $this->dialect);

        $this->course_instances->add_index('idx_course_instances_event_id', ['event_id']);
        $this->course_instances->add_index('idx_course_instances_course_id', ['course_id']);
    }

    /**
     * Participations table for polymorphic relationships
     * Links any Thing (typically Events) to Agents (Person/Organization) with roles
     */
    private function create_participations_table(): void
    {
        $this->participations = new Table('participations', [
            'id'       => bigint()->primary_key()->auto_increment(),
            'thing_id' => bigint()->not_null(),    // FK to things (Event or other)
            'agent_id' => bigint()->not_null(),    // FK to things (Person/Organization)
            'role'     => varchar(50)->not_null(),  // 'organizer', 'performer', 'instructor', etc.
            'position' => integer()->default(0),
            'billing'  => varchar(50),             // 'headline', 'support', etc.
        ], $this->dialect);

        $this->participations->add_index('idx_participations_thing_id', ['thing_id']);
        $this->participations->add_index('idx_participations_agent_id', ['agent_id']);
        $this->participations->add_index('idx_participations_role', ['role']);
    }

    /**
     * Get all tables in creation order (respects FK dependencies)
     *
     * @return Table[]
     */
    public function get_tables(): array
    {
        return [
            $this->things,
            $this->persons,
            $this->organizations,
            $this->places,
            $this->local_businesses,
            $this->postal_addresses,
            $this->courses,
            $this->events,
            $this->dance_events,
            $this->music_events,
            $this->course_instances,
            $this->participations,
        ];
    }
}
