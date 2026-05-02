<?php
/**
 * Events Example
 *
 * Demonstrates two-level delegated types with a Schema.org Events model:
 * - Thing → Event → DanceEvent/MusicEvent/CourseInstance
 * - Thing → Place → LocalBusiness
 * - Participations junction table for organizer/performer/instructor roles
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

echo "=== Schema.org Events Example ===\n\n";

// ============================================
// 1. Setup Database
// ============================================
echo "1. Setting up database...\n";

$driver = Driver::sqlite_memory();
$dm = new DataManager($driver);
$schema = new Schema();

$dm->create_tables(...$schema->get_tables());
echo "   Created " . count($schema->get_tables()) . " tables: " .
    implode(', ', array_map(fn($t) => $t->get_name(), $schema->get_tables())) . "\n";

// Setup persistence for all model classes
Thing::set_persistence($dm, $schema->things);
Person::set_persistence($dm, $schema->persons);
Organization::set_persistence($dm, $schema->organizations);
Place::set_persistence($dm, $schema->places);
LocalBusiness::set_persistence($dm, $schema->local_businesses);
PostalAddress::set_persistence($dm, $schema->postal_addresses);
Course::set_persistence($dm, $schema->courses);
Event::set_persistence($dm, $schema->events);
DanceEvent::set_persistence($dm, $schema->dance_events);
MusicEvent::set_persistence($dm, $schema->music_events);
CourseInstance::set_persistence($dm, $schema->course_instances);
Participation::set_persistence($dm, $schema->participations);

echo "   Models configured\n\n";

// ============================================
// 2. Create Agents (Persons & Organizations)
// ============================================
echo "2. Creating agents...\n";

$carlos = Thing::create_person([
    'name' => 'Carlos Gavito',
], [
    'given_name'  => 'Carlos',
    'family_name' => 'Gavito',
    'email'       => 'carlos@tango.ar',
]);
echo "   Person: " . $carlos['name'] . " (UUID: " . $carlos['uuid'] . ")\n";

$maria = Thing::create_person([
    'name' => 'Maria Nieves',
], [
    'given_name'  => 'Maria',
    'family_name' => 'Nieves',
]);
echo "   Person: " . $maria['name'] . "\n";

$miles = Thing::create_person([
    'name' => 'Miles Davis',
], [
    'given_name'  => 'Miles',
    'family_name' => 'Davis',
    'birth_date'  => '1926-05-26',
]);
echo "   Person: " . $miles['name'] . " (born " . $miles->delegate()->birth_date()->format('Y') . ")\n";

$herbie = Thing::create_person([
    'name' => 'Herbie Hancock',
], [
    'given_name'  => 'Herbie',
    'family_name' => 'Hancock',
]);
echo "   Person: " . $herbie['name'] . "\n";

$prof_tanaka = Thing::create_person([
    'name' => 'Prof. Yuki Tanaka',
], [
    'given_name'  => 'Yuki',
    'family_name' => 'Tanaka',
    'email'       => 'tanaka@university.edu',
]);
echo "   Person: " . $prof_tanaka['name'] . "\n";

$tango_academy = Thing::create_organization([
    'name' => 'Buenos Aires Tango Academy',
], [
    'legal_name'    => 'Buenos Aires Tango Academy S.A.',
    'founding_date' => '1998-03-15',
    'email'         => 'info@batango.ar',
]);
echo "   Organization: " . $tango_academy['name'] . "\n";

$jazz_foundation = Thing::create_organization([
    'name' => 'Blue Note Jazz Foundation',
], [
    'legal_name' => 'Blue Note Jazz Foundation Inc.',
]);
echo "   Organization: " . $jazz_foundation['name'] . "\n\n";

// ============================================
// 3. Create Places with Addresses
// ============================================
echo "3. Creating places...\n";

$teatro = Thing::create_place([
    'name'        => 'Teatro Colón',
    'description' => 'Historic opera house in Buenos Aires',
], [
    'latitude'     => '-34.6011',
    'longitude'    => '-58.3833',
    'max_capacity' => 2500,
]);

$teatro_place = $teatro->delegate();
$teatro_place->set_address([
    'street_address'   => 'Cerrito 628',
    'address_locality' => 'Buenos Aires',
    'address_region'   => 'CABA',
    'postal_code'      => 'C1010',
    'address_country'  => 'Argentina',
]);
echo "   Place: " . $teatro['name'] . "\n";
echo "   Address: " . $teatro_place->formatted_address() . "\n";
echo "   Capacity: " . $teatro_place->max_capacity() . "\n";

// Create a LocalBusiness (two-level: Thing + Place + LocalBusiness)
$club = Thing::create_local_business([
    'name'        => 'Blue Note Jazz Club',
    'description' => 'Legendary jazz club in Greenwich Village',
], [
    'latitude'     => '40.7308',
    'longitude'    => '-73.9973',
    'max_capacity' => 300,
], [
    'legal_name'    => 'Blue Note Entertainment Group',
    'price_range'   => '$$$',
    'opening_hours' => 'Mon-Sun 18:00-02:00',
    'email'         => 'info@bluenotejazz.com',
]);

$club_place = $club->delegate();
$club_place->set_address([
    'street_address'   => '131 W 3rd St',
    'address_locality' => 'New York',
    'address_region'   => 'NY',
    'postal_code'      => '10012',
    'address_country'  => 'USA',
]);
echo "   LocalBusiness: " . $club['name'] . " (type: " . $club['type'] . ")\n";
echo "   Type path: " . $club['type_path'] . "\n";
echo "   Address: " . $club_place->formatted_address() . "\n";

$club_business = $club_place->sub_delegate();
echo "   Price range: " . $club_business->price_range() . "\n";
echo "   Hours: " . $club_business->opening_hours() . "\n\n";

// ============================================
// 4. Create a Course (series)
// ============================================
echo "4. Creating course series...\n";

$tango_course = Thing::create_course([
    'name'        => 'Introduction to Argentine Tango',
    'description' => 'A 10-session course covering tango fundamentals',
], [
    'course_code'    => 'TANGO-101',
    'course_level'   => 'Beginner',
    'total_sessions' => 10,
]);

$course_delegate = $tango_course->delegate();
echo "   Course: " . $course_delegate->label() . "\n";
echo "   Total sessions: " . $course_delegate->total_sessions() . "\n\n";

// ============================================
// 5. Create Events (two-level delegated types)
// ============================================
echo "5. Creating events...\n";

// DanceEvent
$milonga = Thing::create_dance_event([
    'name'        => 'Gran Milonga at Teatro Colón',
    'description' => 'An evening of Argentine Tango at the legendary Teatro Colón',
], [
    'start_date'            => '2026-06-15 20:00:00',
    'end_date'              => '2026-06-15 23:30:00',
    'duration'              => 210,
    'event_status'          => 'EventScheduled',
    'event_attendance_mode' => 'OfflineEventAttendanceMode',
    'location_id'           => $teatro['id'],
    'in_language'           => 'es',
    'max_attendees'         => 500,
    'is_free'               => false,
], [
    'dance_style' => 'Argentine Tango',
]);

echo "   DanceEvent: " . $milonga['name'] . "\n";
echo "   Type: " . $milonga['type'] . "\n";
echo "   Type path: " . $milonga['type_path'] . "\n";
echo "   Is event: " . ($milonga->is_event() ? 'Yes' : 'No') . "\n";

$milonga_event = $milonga->delegate();
echo "   Date: " . $milonga_event->formatted_date_range() . "\n";
echo "   Duration: " . $milonga_event->formatted_duration() . "\n";
echo "   Location: " . $milonga_event->location_name() . "\n";

$milonga_dance = $milonga->sub_delegate();
echo "   Dance style: " . $milonga_dance->dance_style() . "\n";

// Add participants
$milonga_event->add_organizer($tango_academy, 0);
$milonga_event->add_performer($carlos, 0, 'headline');
$milonga_event->add_performer($maria, 1, 'headline');
echo "   Organizer: " . $milonga_event->organizers_string() . "\n";
echo "   Performers: " . $milonga_event->performers_string() . "\n\n";

// MusicEvent
$jazz_night = Thing::create_music_event([
    'name'        => 'Blue Note Jazz Night',
    'description' => 'An evening of classic and modern jazz',
], [
    'start_date'            => '2026-07-20 21:00:00',
    'end_date'              => '2026-07-21 00:00:00',
    'duration'              => 180,
    'event_status'          => 'EventScheduled',
    'event_attendance_mode' => 'OfflineEventAttendanceMode',
    'location_id'           => $club['id'],
    'in_language'           => 'en',
    'max_attendees'         => 250,
    'is_free'               => false,
], [
    'music_genre' => 'Jazz',
]);

$jazz_event = $jazz_night->delegate();
$jazz_event->add_organizer($jazz_foundation, 0);
$jazz_event->add_performer($miles, 0, 'headline');
$jazz_event->add_performer($herbie, 1, 'support');

echo "   MusicEvent: " . $jazz_night['name'] . "\n";
echo "   Type path: " . $jazz_night['type_path'] . "\n";
echo "   Genre: " . $jazz_night->sub_delegate()->music_genre() . "\n";
echo "   Location: " . $jazz_event->location_name() . "\n";
echo "   Performers: " . $jazz_event->performers_string() . "\n\n";

// CourseInstance (session 1 of the tango course)
$session_1 = Thing::create_course_instance([
    'name'        => 'Tango Fundamentals - Session 1',
    'description' => 'Introduction, basic walk, and embrace',
], [
    'start_date'            => '2026-09-01 19:00:00',
    'end_date'              => '2026-09-01 20:30:00',
    'duration'              => 90,
    'event_status'          => 'EventScheduled',
    'event_attendance_mode' => 'OfflineEventAttendanceMode',
    'location_id'           => $teatro['id'],
    'max_attendees'         => 30,
    'is_free'               => false,
], [
    'course_id'      => $tango_course['id'],
    'session_number' => 1,
]);

$session_1_event = $session_1->delegate();
$session_1_event->add_instructor($prof_tanaka, 0);

echo "   CourseInstance: " . $session_1['name'] . "\n";
echo "   Type path: " . $session_1['type_path'] . "\n";
$ci = $session_1->sub_delegate();
echo "   Label: " . $ci->label() . "\n";
echo "   Course: " . $ci->course()['name'] . "\n";
echo "   Instructor: " . $session_1_event->participants_string('instructor') . "\n\n";

// ============================================
// 6. Query Across Types
// ============================================
echo "6. Querying across types...\n";

$all_events = Thing::find_events();
echo "   All events (" . count($all_events) . "):\n";
foreach ($all_events as $event) {
    echo "      - " . $event['name'] . " (" . $event['type'] . ")\n";
}

$agents = Thing::find_agents();
echo "   All agents (" . count($agents) . "):\n";
foreach ($agents as $agent) {
    $delegate = $agent->delegate();
    $type_info = $delegate->is_person() ? 'Person' : 'Organization';
    echo "      - " . $agent['name'] . " ($type_info)\n";
}

$dance_events = Thing::find_by_type('DanceEvent');
echo "   DanceEvents only (" . count($dance_events) . "):\n";
foreach ($dance_events as $de) {
    echo "      - " . $de['name'] . "\n";
}

echo "\n";

// ============================================
// 7. Type Checking
// ============================================
echo "7. Type checking...\n";

echo "   \$milonga->is_dance_event(): " . ($milonga->is_dance_event() ? 'true' : 'false') . "\n";
echo "   \$milonga->is_event(): " . ($milonga->is_event() ? 'true' : 'false') . "\n";
echo "   \$milonga->is_music_event(): " . ($milonga->is_music_event() ? 'true' : 'false') . "\n";
echo "   \$milonga->is_in_hierarchy('Event'): " . ($milonga->is_in_hierarchy('Event') ? 'true' : 'false') . "\n";
echo "   \$carlos->is_person(): " . ($carlos->is_person() ? 'true' : 'false') . "\n";
echo "   \$tango_academy->is_organization(): " . ($tango_academy->is_organization() ? 'true' : 'false') . "\n\n";

// ============================================
// 8. Method Delegation
// ============================================
echo "8. Method delegation (calling delegate methods via Thing)...\n";

echo "   \$carlos->display_name(): " . $carlos->display_name() . "\n";
echo "   \$carlos->citation_name(): " . $carlos->citation_name() . "\n";
echo "   \$tango_academy->display_name(): " . $tango_academy->display_name() . "\n\n";

// ============================================
// 9. Working with Participations
// ============================================
echo "9. Finding events by participant...\n";

$carlos_person = $carlos->delegate();
$carlos_events = $carlos_person->performed_at();
echo "   Events where " . $carlos_person->display_name() . " performed:\n";
foreach ($carlos_events as $event) {
    echo "      - " . $event['name'] . "\n";
}

$tanaka_person = $prof_tanaka->delegate();
$tanaka_events = $tanaka_person->instructed_at();
echo "   Events where " . $tanaka_person->display_name() . " was instructor:\n";
foreach ($tanaka_events as $event) {
    echo "      - " . $event['name'] . "\n";
}

echo "\n";

// ============================================
// 10. Course Instances
// ============================================
echo "10. Course instances...\n";

// Create a second session
$session_2 = Thing::create_course_instance([
    'name'        => 'Tango Fundamentals - Session 2',
    'description' => 'The cross system and backward ocho',
], [
    'start_date'   => '2026-09-08 19:00:00',
    'end_date'     => '2026-09-08 20:30:00',
    'duration'     => 90,
    'event_status' => 'EventScheduled',
    'location_id'  => $teatro['id'],
], [
    'course_id'      => $tango_course['id'],
    'session_number' => 2,
]);

$course = $tango_course->delegate();
$instances = $course->instances();
echo "    Course: " . $course->label() . "\n";
echo "    Instances created: " . count($instances) . " of " . $course->total_sessions() . "\n";
foreach ($instances as $inst) {
    echo "      - Session " . $inst->session_number() . ": " . $inst->label() . "\n";
}

echo "\n";

// ============================================
// 11. Eager Loading
// ============================================
echo "11. Eager loading delegates...\n";

$all_things = Thing::find_with_delegates();
echo "    Loaded " . count($all_things) . " things with delegates\n";

foreach ($all_things as $thing) {
    $delegate = $thing->delegate();
    $delegate_type = $delegate ? get_class($delegate) : 'none';
    $delegate_type = basename(str_replace('\\', '/', $delegate_type));
    echo "      - " . $thing['name'] . " -> " . $delegate_type . " (" . $thing['type_path'] . ")\n";
}

echo "\n";

// ============================================
// Cleanup
// ============================================
echo "12. Cleanup...\n";
$dm->drop_tables(...array_reverse($schema->get_tables()));
echo "    Tables dropped\n\n";

echo "=== Example completed successfully ===\n";
