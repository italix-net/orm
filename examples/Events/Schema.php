<?php
/**
 * Schema.org Events Database Schema
 *
 * Demonstrates two-level delegated types with:
 * - Thing → Event → DanceEvent/MusicEvent/CourseInstance
 * - Thing → Place → LocalBusiness
 * - A participation junction table linking Things to Agents with roles
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
    public Table $thing;
    public Table $person;
    public Table $organization;
    public Table $place;
    public Table $local_business;
    public Table $postal_address;
    public Table $course;
    public Table $event;
    public Table $dance_event;
    public Table $music_event;
    public Table $course_instance;
    public Table $participation;

    private string $dialect;

    public function __construct(string $dialect = 'sqlite')
    {
        $this->dialect = $dialect;
        $this->create_thing_table();
        $this->create_person_table();
        $this->create_organization_table();
        $this->create_place_table();
        $this->create_local_business_table();
        $this->create_postal_address_table();
        $this->create_course_table();
        $this->create_event_table();
        $this->create_dance_event_table();
        $this->create_music_event_table();
        $this->create_course_instance_table();
        $this->create_participation_table();
    }

    /**
     * Central table for all entities (Thing in Schema.org)
     */
    private function create_thing_table(): void
    {
        $this->thing = new Table('sch_thing', [
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

        $this->thing->add_index('idx_sch_thing_type', ['type']);
        $this->thing->add_index('idx_sch_thing_type_path', ['type_path']);
        $this->thing->add_index('idx_sch_thing_is_event', ['is_event']);
        $this->thing->add_index('idx_sch_thing_is_agent', ['is_agent']);
    }

    /**
     * Person-specific attributes
     */
    private function create_person_table(): void
    {
        $this->person = new Table('sch_person', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'thing_id'    => bigint()->not_null()->unique(),  // FK to sch_thing

            'given_name'  => varchar(200),
            'family_name' => varchar(200),
            'email'       => varchar(500),
            'telephone'   => varchar(50),
            'birth_date'  => date(),
        ], $this->dialect);

        $this->person->add_index('idx_sch_person_thing_id', ['thing_id']);
        $this->person->add_index('idx_sch_person_family_name', ['family_name']);
    }

    /**
     * Organization-specific attributes
     */
    private function create_organization_table(): void
    {
        $this->organization = new Table('sch_organization', [
            'id'            => bigint()->primary_key()->auto_increment(),
            'thing_id'      => bigint()->not_null()->unique(),  // FK to sch_thing

            'legal_name'    => varchar(500),
            'founding_date' => date(),
            'email'         => varchar(500),
            'telephone'     => varchar(50),
        ], $this->dialect);

        $this->organization->add_index('idx_sch_organization_thing_id', ['thing_id']);
    }

    /**
     * Place-specific attributes (first-level delegate)
     */
    private function create_place_table(): void
    {
        $this->place = new Table('sch_place', [
            'id'           => bigint()->primary_key()->auto_increment(),
            'thing_id'     => bigint()->not_null()->unique(),  // FK to sch_thing

            'latitude'     => decimal(10, 7),
            'longitude'    => decimal(10, 7),
            'telephone'    => varchar(50),
            'max_capacity' => integer(),
        ], $this->dialect);

        $this->place->add_index('idx_sch_place_thing_id', ['thing_id']);
    }

    /**
     * LocalBusiness-specific attributes (second-level delegate under Place)
     */
    private function create_local_business_table(): void
    {
        $this->local_business = new Table('sch_local_business', [
            'id'            => bigint()->primary_key()->auto_increment(),
            'place_id'      => bigint()->not_null()->unique(),  // FK to sch_place

            'legal_name'    => varchar(500),
            'price_range'   => varchar(10),   // '$', '$$', '$$$', '$$$$'
            'opening_hours' => varchar(500),
            'telephone'     => varchar(50),
            'email'         => varchar(500),
        ], $this->dialect);

        $this->local_business->add_index('idx_sch_local_business_place_id', ['place_id']);
    }

    /**
     * Postal address for places (one-to-one with Place)
     */
    private function create_postal_address_table(): void
    {
        $this->postal_address = new Table('sch_postal_address', [
            'id'               => bigint()->primary_key()->auto_increment(),
            'place_id'         => bigint()->not_null()->unique(),  // FK to sch_place

            'street_address'   => varchar(500),
            'address_locality' => varchar(200),   // city
            'address_region'   => varchar(200),   // state/province
            'postal_code'      => varchar(20),
            'address_country'  => varchar(100),
        ], $this->dialect);

        $this->postal_address->add_index('idx_sch_postal_address_place_id', ['place_id']);
    }

    /**
     * Course-specific attributes (represents a course series)
     */
    private function create_course_table(): void
    {
        $this->course = new Table('sch_course', [
            'id'             => bigint()->primary_key()->auto_increment(),
            'thing_id'       => bigint()->not_null()->unique(),  // FK to sch_thing

            'course_code'    => varchar(50),
            'course_level'   => varchar(50),     // 'Beginner', 'Intermediate', 'Advanced'
            'total_sessions' => integer(),
        ], $this->dialect);

        $this->course->add_index('idx_sch_course_thing_id', ['thing_id']);
    }

    /**
     * Event-specific attributes (first-level delegate)
     */
    private function create_event_table(): void
    {
        $this->event = new Table('sch_event', [
            'id'                    => bigint()->primary_key()->auto_increment(),
            'thing_id'              => bigint()->not_null()->unique(),  // FK to sch_thing

            'start_date'            => timestamp(),
            'end_date'              => timestamp(),
            'duration'              => integer(),     // minutes
            'event_status'          => varchar(30),   // EventScheduled, EventCancelled, etc.
            'event_attendance_mode' => varchar(30),   // Offline, Online, Mixed
            'location_id'           => bigint(),      // FK to sch_thing (Place)
            'in_language'           => varchar(10),
            'max_attendees'         => integer(),
            'is_free'               => boolean()->default(false),
        ], $this->dialect);

        $this->event->add_index('idx_sch_event_thing_id', ['thing_id']);
        $this->event->add_index('idx_sch_event_start_date', ['start_date']);
        $this->event->add_index('idx_sch_event_location_id', ['location_id']);
        $this->event->add_index('idx_sch_event_event_status', ['event_status']);
    }

    /**
     * DanceEvent-specific attributes (second-level delegate under Event)
     */
    private function create_dance_event_table(): void
    {
        $this->dance_event = new Table('sch_dance_event', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'event_id'    => bigint()->not_null()->unique(),  // FK to sch_event

            'dance_style' => varchar(100),
        ], $this->dialect);

        $this->dance_event->add_index('idx_sch_dance_event_event_id', ['event_id']);
    }

    /**
     * MusicEvent-specific attributes (second-level delegate under Event)
     */
    private function create_music_event_table(): void
    {
        $this->music_event = new Table('sch_music_event', [
            'id'          => bigint()->primary_key()->auto_increment(),
            'event_id'    => bigint()->not_null()->unique(),  // FK to sch_event

            'music_genre' => varchar(100),
        ], $this->dialect);

        $this->music_event->add_index('idx_sch_music_event_event_id', ['event_id']);
    }

    /**
     * CourseInstance-specific attributes (second-level delegate under Event)
     */
    private function create_course_instance_table(): void
    {
        $this->course_instance = new Table('sch_course_instance', [
            'id'             => bigint()->primary_key()->auto_increment(),
            'event_id'       => bigint()->not_null()->unique(),  // FK to sch_event

            'course_id'      => bigint(),       // FK to sch_thing (Course)
            'session_number' => integer(),
        ], $this->dialect);

        $this->course_instance->add_index('idx_sch_course_instance_event_id', ['event_id']);
        $this->course_instance->add_index('idx_sch_course_instance_course_id', ['course_id']);
    }

    /**
     * Participation table for polymorphic relationships
     * Links any Thing (typically Events) to Agents (Person/Organization) with roles
     */
    private function create_participation_table(): void
    {
        $this->participation = new Table('sch_participation', [
            'id'       => bigint()->primary_key()->auto_increment(),
            'thing_id' => bigint()->not_null(),    // FK to sch_thing (Event or other)
            'agent_id' => bigint()->not_null(),    // FK to sch_thing (Person/Organization)
            'role'     => varchar(50)->not_null(),  // 'organizer', 'performer', 'instructor', etc.
            'position' => integer()->default(0),
            'billing'  => varchar(50),             // 'headline', 'support', etc.
        ], $this->dialect);

        $this->participation->add_index('idx_sch_participation_thing_id', ['thing_id']);
        $this->participation->add_index('idx_sch_participation_agent_id', ['agent_id']);
        $this->participation->add_index('idx_sch_participation_role', ['role']);
    }

    /**
     * Get all tables in creation order (respects FK dependencies)
     *
     * @return Table[]
     */
    public function get_tables(): array
    {
        return [
            $this->thing,
            $this->person,
            $this->organization,
            $this->place,
            $this->local_business,
            $this->postal_address,
            $this->course,
            $this->event,
            $this->dance_event,
            $this->music_event,
            $this->course_instance,
            $this->participation,
        ];
    }
}
