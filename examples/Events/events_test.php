<?php
/**
 * Events Test Suite
 *
 * Tests for the two-level Delegated Types pattern with Events.
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';

use Italix\Orm\Dialects\Driver;
use Italix\Orm\DataManager;
use Examples\Events\Schema;
use Examples\Events\Models\Thing;
use Examples\Events\Models\Person;
use Examples\Events\Models\Organization;
use Examples\Events\Models\Place;
use Examples\Events\Models\LocalBusiness;
use Examples\Events\Models\PostalAddress;
use Examples\Events\Models\Course;
use Examples\Events\Models\Event;
use Examples\Events\Models\DanceEvent;
use Examples\Events\Models\MusicEvent;
use Examples\Events\Models\CourseInstance;
use Examples\Events\Models\Participation;

// Autoload example classes
spl_autoload_register(function ($class) {
    $prefix = 'Examples\\Events\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative_class = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

class EventsTestRunner
{
    private DataManager $dm;
    private Schema $schema;
    private array $passed = [];
    private array $failed = [];

    public function run_all(): void
    {
        echo "Events Test Suite\n";
        echo str_repeat('=', 50) . "\n\n";

        $this->setup();

        $this->test_thing_creation();
        $this->test_person_delegate();
        $this->test_organization_delegate();
        $this->test_place_and_address();
        $this->test_local_business_two_level();
        $this->test_course_creation();
        $this->test_dance_event_two_level();
        $this->test_music_event_two_level();
        $this->test_course_instance_two_level();
        $this->test_participations();
        $this->test_type_checking();
        $this->test_flags_and_queries();
        $this->test_event_methods();
        $this->test_eager_loading();

        $this->teardown();
        $this->print_summary();
    }

    private function setup(): void
    {
        $driver = Driver::sqlite_memory();
        $this->dm = new DataManager($driver);
        $this->schema = new Schema();

        $this->dm->create_tables(...$this->schema->get_tables());

        Thing::set_persistence($this->dm, $this->schema->things);
        Person::set_persistence($this->dm, $this->schema->persons);
        Organization::set_persistence($this->dm, $this->schema->organizations);
        Place::set_persistence($this->dm, $this->schema->places);
        LocalBusiness::set_persistence($this->dm, $this->schema->local_businesses);
        PostalAddress::set_persistence($this->dm, $this->schema->postal_addresses);
        Course::set_persistence($this->dm, $this->schema->courses);
        Event::set_persistence($this->dm, $this->schema->events);
        DanceEvent::set_persistence($this->dm, $this->schema->dance_events);
        MusicEvent::set_persistence($this->dm, $this->schema->music_events);
        CourseInstance::set_persistence($this->dm, $this->schema->course_instances);
        Participation::set_persistence($this->dm, $this->schema->participations);
    }

    private function teardown(): void
    {
        $this->dm->drop_tables(...array_reverse($this->schema->get_tables()));
    }

    private function assert(string $name, bool $condition): void
    {
        if ($condition) {
            $this->passed[] = $name;
            echo "  ✓ $name\n";
        } else {
            $this->failed[] = $name;
            echo "  ✗ $name\n";
        }
    }

    // =========================================
    // TESTS
    // =========================================

    private function test_thing_creation(): void
    {
        echo "Thing Creation\n";
        echo str_repeat('-', 30) . "\n";

        $person = Thing::create_person(['name' => 'Test Person'], [
            'given_name' => 'Test', 'family_name' => 'Person',
        ]);

        $this->assert('Person thing has ID', $person['id'] > 0);
        $this->assert('Person has UUID', !empty($person['uuid']));
        $this->assert('Person type is correct', $person['type'] === 'Person');
        $this->assert('Person type_path is correct', $person['type_path'] === 'Thing/Person');
        $this->assert('Person is_agent flag', $person['is_agent'] === true);
        $this->assert('Person is_event flag is false', $person['is_event'] === false);

        echo "\n";
    }

    private function test_person_delegate(): void
    {
        echo "Person Delegate\n";
        echo str_repeat('-', 30) . "\n";

        $thing = Thing::create_person(['name' => 'Jane Doe'], [
            'given_name' => 'Jane', 'family_name' => 'Doe',
            'email' => 'jane@example.com', 'birth_date' => '1990-05-15',
        ]);

        $delegate = $thing->delegate();
        $this->assert('delegate() returns Person', $delegate instanceof Person);
        $this->assert('display_name() correct', $delegate->display_name() === 'Jane Doe');
        $this->assert('citation_name() correct', $delegate->citation_name() === 'Doe, J.');
        $this->assert('formal_name() correct', $delegate->formal_name() === 'Doe, Jane');
        $this->assert('email() correct', $delegate->email() === 'jane@example.com');
        $this->assert('birth_date() returns DateTime', $delegate->birth_date() instanceof \DateTime);
        $this->assert('age() returns int', is_int($delegate->age()));
        $this->assert('is_person() true', $delegate->is_person());
        $this->assert('is_organization() false', !$delegate->is_organization());

        echo "\n";
    }

    private function test_organization_delegate(): void
    {
        echo "Organization Delegate\n";
        echo str_repeat('-', 30) . "\n";

        $thing = Thing::create_organization(['name' => 'Acme Corp'], [
            'legal_name' => 'Acme Corporation Ltd.',
            'founding_date' => '2000-01-01',
            'email' => 'info@acme.com',
        ]);

        $delegate = $thing->delegate();
        $this->assert('delegate() returns Organization', $delegate instanceof Organization);
        $this->assert('display_name() uses legal_name', $delegate->display_name() === 'Acme Corporation Ltd.');
        $this->assert('legal_name() correct', $delegate->legal_name() === 'Acme Corporation Ltd.');
        $this->assert('founding_year() correct', $delegate->founding_year() === 2000);
        $this->assert('is_organization() true', $delegate->is_organization());

        echo "\n";
    }

    private function test_place_and_address(): void
    {
        echo "Place and PostalAddress\n";
        echo str_repeat('-', 30) . "\n";

        $thing = Thing::create_place(['name' => 'Test Venue'], [
            'latitude' => '45.4642',
            'longitude' => '9.1900',
            'max_capacity' => 1000,
            'telephone' => '+39-02-1234567',
        ]);

        $place = $thing->delegate();
        $this->assert('delegate() returns Place', $place instanceof Place);
        $this->assert('latitude correct', $place->latitude() === '45.4642');
        $this->assert('coordinates() works', str_contains($place->coordinates(), '45.4642'));
        $this->assert('max_capacity correct', $place->max_capacity() === 1000);
        $this->assert('sub_delegate() null for plain Place', $place->sub_delegate() === null);

        // Add address
        $address = $place->set_address([
            'street_address' => 'Via Roma 1',
            'address_locality' => 'Milano',
            'postal_code' => '20121',
            'address_country' => 'Italy',
        ]);

        $this->assert('set_address() returns PostalAddress', $address instanceof PostalAddress);
        $this->assert('address() returns same', $place->address() !== null);
        $this->assert('street_address correct', $address->street_address() === 'Via Roma 1');
        $this->assert('formatted() includes city', str_contains($address->formatted(), 'Milano'));
        $this->assert('formatted_address() on place works', str_contains($place->formatted_address(), 'Milano'));

        echo "\n";
    }

    private function test_local_business_two_level(): void
    {
        echo "LocalBusiness (Two-Level Delegate)\n";
        echo str_repeat('-', 30) . "\n";

        $thing = Thing::create_local_business(['name' => 'Test Restaurant'], [
            'latitude' => '41.9028',
            'longitude' => '12.4964',
            'max_capacity' => 80,
        ], [
            'legal_name' => 'Test Restaurant S.r.l.',
            'price_range' => '$$',
            'opening_hours' => 'Tue-Sun 12:00-23:00',
        ]);

        $this->assert('Thing type is LocalBusiness', $thing['type'] === 'LocalBusiness');
        $this->assert('Type path is Thing/Place/LocalBusiness', $thing['type_path'] === 'Thing/Place/LocalBusiness');

        $place = $thing->delegate();
        $this->assert('First-level delegate is Place', $place instanceof Place);
        $this->assert('Place has max_capacity', $place->max_capacity() === 80);

        $business = $place->sub_delegate();
        $this->assert('sub_delegate() returns LocalBusiness', $business instanceof LocalBusiness);
        $this->assert('legal_name correct', $business->legal_name() === 'Test Restaurant S.r.l.');
        $this->assert('price_range correct', $business->price_range() === '$$');
        $this->assert('opening_hours correct', str_contains($business->opening_hours(), 'Tue-Sun'));

        // Thing->sub_delegate() shortcut
        $shortcut = $thing->sub_delegate();
        $this->assert('Thing->sub_delegate() returns LocalBusiness', $shortcut instanceof LocalBusiness);

        echo "\n";
    }

    private function test_course_creation(): void
    {
        echo "Course Creation\n";
        echo str_repeat('-', 30) . "\n";

        $thing = Thing::create_course(['name' => 'Test Course'], [
            'course_code' => 'TEST-101',
            'course_level' => 'Beginner',
            'total_sessions' => 8,
        ]);

        $this->assert('Course type correct', $thing['type'] === 'Course');
        $this->assert('Course type_path correct', $thing['type_path'] === 'Thing/Course');

        $course = $thing->delegate();
        $this->assert('delegate() returns Course', $course instanceof Course);
        $this->assert('course_code correct', $course->course_code() === 'TEST-101');
        $this->assert('label() formatted', $course->label() === 'TEST-101 (Beginner)');
        $this->assert('total_sessions correct', $course->total_sessions() === 8);

        echo "\n";
    }

    private function test_dance_event_two_level(): void
    {
        echo "DanceEvent (Two-Level Delegate)\n";
        echo str_repeat('-', 30) . "\n";

        $venue = Thing::create_place(['name' => 'Dance Hall'], ['max_capacity' => 200]);

        $thing = Thing::create_dance_event([
            'name' => 'Salsa Night',
        ], [
            'start_date'   => '2026-08-01 20:00:00',
            'end_date'     => '2026-08-01 23:00:00',
            'duration'     => 180,
            'event_status' => 'EventScheduled',
            'location_id'  => $venue['id'],
            'is_free'      => true,
        ], [
            'dance_style' => 'Salsa',
        ]);

        $this->assert('Thing type is DanceEvent', $thing['type'] === 'DanceEvent');
        $this->assert('Type path correct', $thing['type_path'] === 'Thing/Event/DanceEvent');
        $this->assert('is_event flag true', $thing['is_event'] === true);

        $event = $thing->delegate();
        $this->assert('First-level delegate is Event', $event instanceof Event);
        $this->assert('Event duration correct', $event->duration() === 180);
        $this->assert('Event is_free correct', $event->is_free() === true);
        $this->assert('Event location correct', $event->location_name() === 'Dance Hall');

        $dance = $event->sub_delegate();
        $this->assert('sub_delegate() returns DanceEvent', $dance instanceof DanceEvent);
        $this->assert('dance_style correct', $dance->dance_style() === 'Salsa');
        $this->assert('label() formatted', str_contains($dance->label(), 'Salsa'));

        $shortcut = $thing->sub_delegate();
        $this->assert('Thing->sub_delegate() returns DanceEvent', $shortcut instanceof DanceEvent);

        echo "\n";
    }

    private function test_music_event_two_level(): void
    {
        echo "MusicEvent (Two-Level Delegate)\n";
        echo str_repeat('-', 30) . "\n";

        $thing = Thing::create_music_event([
            'name' => 'Rock Concert',
        ], [
            'start_date'   => '2026-09-15 19:00:00',
            'duration'     => 240,
            'event_status' => 'EventScheduled',
        ], [
            'music_genre' => 'Rock',
        ]);

        $this->assert('Thing type is MusicEvent', $thing['type'] === 'MusicEvent');
        $this->assert('Type path correct', $thing['type_path'] === 'Thing/Event/MusicEvent');

        $event = $thing->delegate();
        $this->assert('First-level delegate is Event', $event instanceof Event);

        $music = $event->sub_delegate();
        $this->assert('sub_delegate() returns MusicEvent', $music instanceof MusicEvent);
        $this->assert('music_genre correct', $music->music_genre() === 'Rock');

        echo "\n";
    }

    private function test_course_instance_two_level(): void
    {
        echo "CourseInstance (Two-Level Delegate)\n";
        echo str_repeat('-', 30) . "\n";

        $course_thing = Thing::create_course(['name' => 'Piano 101'], [
            'course_code' => 'PIANO-101',
            'total_sessions' => 12,
        ]);

        $thing = Thing::create_course_instance([
            'name' => 'Piano 101 - Session 1',
        ], [
            'start_date'   => '2026-10-01 10:00:00',
            'duration'     => 60,
            'event_status' => 'EventScheduled',
        ], [
            'course_id'      => $course_thing['id'],
            'session_number' => 1,
        ]);

        $this->assert('Thing type is CourseInstance', $thing['type'] === 'CourseInstance');
        $this->assert('Type path correct', $thing['type_path'] === 'Thing/Event/CourseInstance');

        $event = $thing->delegate();
        $this->assert('First-level delegate is Event', $event instanceof Event);

        $ci = $event->sub_delegate();
        $this->assert('sub_delegate() returns CourseInstance', $ci instanceof CourseInstance);
        $this->assert('session_number correct', $ci->session_number() === 1);
        $this->assert('course() returns Course Thing', $ci->course()['name'] === 'Piano 101');
        $this->assert('label() includes session info', str_contains($ci->label(), 'Session 1/12'));

        // Create session 2 and check course->instances()
        Thing::create_course_instance(['name' => 'Piano 101 - Session 2'], [
            'start_date' => '2026-10-08 10:00:00', 'duration' => 60,
        ], [
            'course_id' => $course_thing['id'], 'session_number' => 2,
        ]);

        $course = $course_thing->delegate();
        $instances = $course->instances();
        $this->assert('Course has 2 instances', count($instances) === 2);
        $this->assert('Instances ordered by session_number', $instances[0]->session_number() === 1);

        echo "\n";
    }

    private function test_participations(): void
    {
        echo "Participations\n";
        echo str_repeat('-', 30) . "\n";

        $organizer = Thing::create_organization(['name' => 'Event Corp'], [
            'legal_name' => 'Event Corp Ltd.',
        ]);
        $performer1 = Thing::create_person(['name' => 'Singer One'], [
            'given_name' => 'Singer', 'family_name' => 'One',
        ]);
        $performer2 = Thing::create_person(['name' => 'Singer Two'], [
            'given_name' => 'Singer', 'family_name' => 'Two',
        ]);

        $event_thing = Thing::create_music_event(['name' => 'Participation Test Event'], [
            'event_status' => 'EventScheduled',
        ], [
            'music_genre' => 'Pop',
        ]);

        $event = $event_thing->delegate();

        // Add participants
        $event->add_organizer($organizer, 0);
        $event->add_performer($performer1, 0, 'headline');
        $event->add_performer($performer2, 1, 'support');

        $organizers = $event->organizers();
        $this->assert('Event has 1 organizer', count($organizers) === 1);
        $this->assert('Organizer is correct', $organizers[0]['name'] === 'Event Corp');

        $performers = $event->performers();
        $this->assert('Event has 2 performers', count($performers) === 2);
        $this->assert('First performer is headline', $performers[0]['name'] === 'Singer One');

        $this->assert('organizers_string() works', $event->organizers_string() === 'Event Corp Ltd.');
        $this->assert('performers_string() works', str_contains($event->performers_string(), 'Singer One'));

        // Test agent-side queries
        $p1 = $performer1->delegate();
        $events = $p1->performed_at();
        $this->assert('Performer can find their events', count($events) === 1);
        $this->assert('Found event is correct', $events[0]['name'] === 'Participation Test Event');

        // Test Participation static helpers
        $all_for_event = Participation::for_thing($event_thing['id']);
        $this->assert('for_thing() returns all participations', count($all_for_event) === 3);

        $performers_only = Participation::for_thing($event_thing['id'], 'performer');
        $this->assert('for_thing() with role filter works', count($performers_only) === 2);

        // Test get_or_create
        $existing = Participation::get_or_create($event_thing['id'], $performer1['id'], 'performer');
        $this->assert('get_or_create returns existing', $existing['id'] > 0);
        $still_two = Participation::for_thing($event_thing['id'], 'performer');
        $this->assert('get_or_create does not duplicate', count($still_two) === 2);

        echo "\n";
    }

    private function test_type_checking(): void
    {
        echo "Type Checking\n";
        echo str_repeat('-', 30) . "\n";

        $dance = Thing::create_dance_event(['name' => 'TC Dance'], [], ['dance_style' => 'Waltz']);
        $music = Thing::create_music_event(['name' => 'TC Music'], [], ['music_genre' => 'Blues']);
        $person = Thing::create_person(['name' => 'TC Person'], []);
        $place = Thing::create_place(['name' => 'TC Place'], []);

        // is_type()
        $this->assert('is_type(DanceEvent) on dance', $dance->is_type('DanceEvent'));
        $this->assert('is_type(MusicEvent) on dance is false', !$dance->is_type('MusicEvent'));

        // Magic is_* methods
        $this->assert('is_dance_event() on dance', $dance->is_dance_event());
        $this->assert('is_music_event() on music', $music->is_music_event());
        $this->assert('is_person() on person', $person->is_person());
        $this->assert('is_place() on place', $place->is_place());

        // is_in_hierarchy()
        $this->assert('DanceEvent is in Event hierarchy', $dance->is_in_hierarchy('Event'));
        $this->assert('Person is not in Event hierarchy', !$person->is_in_hierarchy('Event'));

        // Flags
        $this->assert('DanceEvent has is_event flag', $dance->is_event());
        $this->assert('Person has is_agent flag', $person->is_agent());
        $this->assert('DanceEvent does not have is_agent', !$dance->is_agent());

        echo "\n";
    }

    private function test_flags_and_queries(): void
    {
        echo "Query Helpers\n";
        echo str_repeat('-', 30) . "\n";

        // find_events() should return only events
        $events = Thing::find_events();
        $all_are_events = true;
        foreach ($events as $event) {
            if (!$event->is_event()) {
                $all_are_events = false;
                break;
            }
        }
        $this->assert('find_events() returns only events', $all_are_events && count($events) > 0);

        // find_agents() should return only agents
        $agents = Thing::find_agents();
        $all_are_agents = true;
        foreach ($agents as $agent) {
            if (!$agent->is_agent()) {
                $all_are_agents = false;
                break;
            }
        }
        $this->assert('find_agents() returns only agents', $all_are_agents && count($agents) > 0);

        // find_by_type
        $dance_events = Thing::find_by_type('DanceEvent');
        $all_dance = true;
        foreach ($dance_events as $de) {
            if ($de['type'] !== 'DanceEvent') {
                $all_dance = false;
                break;
            }
        }
        $this->assert('find_by_type(DanceEvent) correct', $all_dance && count($dance_events) > 0);

        echo "\n";
    }

    private function test_event_methods(): void
    {
        echo "Event Methods\n";
        echo str_repeat('-', 30) . "\n";

        $venue = Thing::create_place(['name' => 'Method Test Venue'], []);

        $thing = Thing::create_dance_event(['name' => 'Method Test Event'], [
            'start_date'            => '2026-12-25 20:00:00',
            'end_date'              => '2026-12-25 23:30:00',
            'duration'              => 210,
            'event_status'          => 'EventScheduled',
            'event_attendance_mode' => 'OfflineEventAttendanceMode',
            'location_id'           => $venue['id'],
            'in_language'           => 'en',
            'max_attendees'         => 500,
            'is_free'               => false,
        ], ['dance_style' => 'Waltz']);

        $event = $thing->delegate();

        $this->assert('start_date() returns DateTime', $event->start_date() instanceof \DateTime);
        $this->assert('end_date() returns DateTime', $event->end_date() instanceof \DateTime);
        $this->assert('duration() correct', $event->duration() === 210);
        $this->assert('formatted_duration() correct', $event->formatted_duration() === '3h 30m');
        $this->assert('formatted_date_range() same day', str_contains($event->formatted_date_range(), '25 Dec 2026'));
        $this->assert('formatted_date_range() has time range', str_contains($event->formatted_date_range(), '20:00 - 23:30'));
        $this->assert('is_scheduled() true', $event->is_scheduled());
        $this->assert('is_cancelled() false', !$event->is_cancelled());
        $this->assert('event_attendance_mode() correct', $event->event_attendance_mode() === 'OfflineEventAttendanceMode');
        $this->assert('is_free() false', !$event->is_free());
        $this->assert('max_attendees() correct', $event->max_attendees() === 500);
        $this->assert('in_language() correct', $event->in_language() === 'en');
        $this->assert('location() returns Thing', $event->location() instanceof Thing);
        $this->assert('location_name() correct', $event->location_name() === 'Method Test Venue');

        echo "\n";
    }

    private function test_eager_loading(): void
    {
        echo "Eager Loading\n";
        echo str_repeat('-', 30) . "\n";

        $things = Thing::find_with_delegates();
        $this->assert('find_with_delegates() returns results', count($things) > 0);

        $all_have_delegates = true;
        foreach ($things as $thing) {
            if ($thing->has_delegate() && $thing->delegate() === null) {
                $all_have_delegates = false;
                break;
            }
        }
        $this->assert('All delegates are loaded', $all_have_delegates);

        echo "\n";
    }

    // =========================================
    // SUMMARY
    // =========================================

    private function print_summary(): void
    {
        echo str_repeat('=', 50) . "\n";
        echo "SUMMARY\n";
        echo str_repeat('=', 50) . "\n\n";

        $total = count($this->passed) + count($this->failed);
        $status = count($this->failed) === 0 ? '✓' : '✗';

        echo "$status Total: " . count($this->passed) . " passed, " . count($this->failed) . " failed\n";

        if (count($this->failed) > 0) {
            echo "\nFailed tests:\n";
            foreach ($this->failed as $name) {
                echo "  - $name\n";
            }
            echo "\n⚠️  Some tests failed!\n";
            exit(1);
        } else {
            echo "\n✓ All tests passed!\n";
        }
    }
}

$runner = new EventsTestRunner();
$runner->run_all();
