<?php
/**
 * Delegated Types Example
 *
 * Demonstrates the Delegated Types pattern with a Schema.org-inspired
 * data model. Shows how to:
 * - Create Things with delegates (Book, Movie, Person, etc.)
 * - Query across types efficiently
 * - Work with polymorphic relationships (contributions)
 * - Use type-specific behaviors
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';

use Italix\Orm\Dialects\Driver;
use Italix\Orm\IxOrm;
use Examples\DelegatedTypes\Schema;
use Examples\DelegatedTypes\Models\Thing;
use Examples\DelegatedTypes\Models\Book;
use Examples\DelegatedTypes\Models\Movie;
use Examples\DelegatedTypes\Models\Article;
use Examples\DelegatedTypes\Models\Person;
use Examples\DelegatedTypes\Models\Organization;
use Examples\DelegatedTypes\Models\Contribution;

// Autoload example classes
spl_autoload_register(function ($class) {
    $prefix = 'Examples\\DelegatedTypes\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative_class = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

echo "=== Delegated Types Example ===\n\n";

// ============================================
// 1. Setup Database
// ============================================
echo "1. Setting up database...\n";

$driver = Driver::sqlite_memory();
$db = new IxOrm($driver);
$schema = new Schema();

// Create tables
$db->create_tables(...$schema->get_tables());
echo "   Created tables: " . implode(', ', array_map(fn($t) => $t->get_name(), $schema->get_tables())) . "\n";

// Setup persistence for all model classes
Thing::set_persistence($db, $schema->things);
Book::set_persistence($db, $schema->books);
Movie::set_persistence($db, $schema->movies);
Article::set_persistence($db, $schema->articles);
Person::set_persistence($db, $schema->persons);
Organization::set_persistence($db, $schema->organizations);
Contribution::set_persistence($db, $schema->contributions);

echo "   Models configured\n\n";

// ============================================
// 2. Create Authors (Persons)
// ============================================
echo "2. Creating authors...\n";

$gamma = Thing::create_person([
    'name' => 'Erich Gamma',
], [
    'given_name'  => 'Erich',
    'family_name' => 'Gamma',
    'birth_date'  => '1961-03-13',
]);
echo "   Created: " . $gamma['name'] . " (UUID: " . $gamma['uuid'] . ")\n";

$helm = Thing::create_person([
    'name' => 'Richard Helm',
], [
    'given_name'  => 'Richard',
    'family_name' => 'Helm',
]);
echo "   Created: " . $helm['name'] . "\n";

$johnson = Thing::create_person([
    'name' => 'Ralph Johnson',
], [
    'given_name'  => 'Ralph',
    'family_name' => 'Johnson',
]);
echo "   Created: " . $johnson['name'] . "\n";

$vlissides = Thing::create_person([
    'name' => 'John Vlissides',
], [
    'given_name'  => 'John',
    'family_name' => 'Vlissides',
    'birth_date'  => '1961-08-02',
    'death_date'  => '2005-11-24',
]);
echo "   Created: " . $vlissides['name'] . " (" . $vlissides->delegate()->life_span() . ")\n";

// Create a publisher
$addison_wesley = Thing::create_organization([
    'name' => 'Addison-Wesley',
], [
    'legal_name'    => 'Addison-Wesley Professional',
    'founding_date' => '1942-01-01',
]);
echo "   Created publisher: " . $addison_wesley['name'] . "\n\n";

// ============================================
// 3. Create a Book with Authors
// ============================================
echo "3. Creating book with authors...\n";

$design_patterns = Thing::create_book([
    'name'        => 'Design Patterns: Elements of Reusable Object-Oriented Software',
    'description' => 'The Gang of Four book on software design patterns.',
], [
    'isbn13'          => '978-0201633610',
    'number_of_pages' => 416,
    'date_published'  => '1994-10-31',
]);

echo "   Created: " . $design_patterns['name'] . "\n";
echo "   Type: " . $design_patterns['type'] . "\n";
echo "   Type path: " . $design_patterns['type_path'] . "\n";
echo "   Is creative work: " . ($design_patterns->is_creative_work() ? 'Yes' : 'No') . "\n";

// Add authors (the Gang of Four)
$book = $design_patterns->delegate();
$book->set_publisher($addison_wesley)->save();
$book->add_author($gamma, 0);
$book->add_author($helm, 1);
$book->add_author($johnson, 2);
$book->add_author($vlissides, 3);

echo "   Added 4 authors\n";
echo "   Authors: " . $book->authors_string() . "\n";
echo "   ISBN: " . $book->formatted_isbn() . "\n";
echo "   Publisher: " . $book->publisher()['name'] . "\n\n";

// ============================================
// 4. Create a Movie
// ============================================
echo "4. Creating a movie...\n";

$nolan = Thing::create_person([
    'name' => 'Christopher Nolan',
], [
    'given_name'  => 'Christopher',
    'family_name' => 'Nolan',
    'birth_date'  => '1970-07-30',
]);

$dicaprio = Thing::create_person([
    'name' => 'Leonardo DiCaprio',
], [
    'given_name'  => 'Leonardo',
    'family_name' => 'DiCaprio',
    'birth_date'  => '1974-11-11',
]);

$inception = Thing::create_movie([
    'name'        => 'Inception',
    'description' => 'A thief who steals corporate secrets through dream-sharing technology.',
], [
    'duration'       => 148,
    'content_rating' => 'PG-13',
    'date_released'  => '2010-07-16',
]);

$movie = $inception->delegate();
$movie->add_director($nolan, 0);
$movie->add_actor($dicaprio, 0);

echo "   Created: " . $movie->title_with_year() . "\n";
echo "   Duration: " . $movie->formatted_duration() . "\n";
echo "   Director: " . $movie->directors_string() . "\n\n";

// ============================================
// 5. Query Across Types
// ============================================
echo "5. Querying across types...\n";

// Find all creative works
$works = Thing::find_creative_works();
echo "   All creative works (" . count($works) . "):\n";
foreach ($works as $work) {
    echo "      - " . $work['name'] . " (" . $work['type'] . ")\n";
}

// Find all agents
$agents = Thing::find_agents();
echo "   All agents (" . count($agents) . "):\n";
foreach ($agents as $agent) {
    $delegate = $agent->delegate();
    $type_info = $delegate->is_person() ? 'Person' : 'Organization';
    echo "      - " . $agent['name'] . " ($type_info)\n";
}

// Find by specific type
$books = Thing::find_by_type('Book');
echo "   Books only (" . count($books) . "):\n";
foreach ($books as $b) {
    echo "      - " . $b['name'] . "\n";
}

echo "\n";

// ============================================
// 6. Type Checking with Magic Methods
// ============================================
echo "6. Type checking...\n";

echo "   \$design_patterns->is_book(): " . ($design_patterns->is_book() ? 'true' : 'false') . "\n";
echo "   \$design_patterns->is_movie(): " . ($design_patterns->is_movie() ? 'true' : 'false') . "\n";
echo "   \$gamma->is_person(): " . ($gamma->is_person() ? 'true' : 'false') . "\n";
echo "   \$gamma->is_organization(): " . ($gamma->is_organization() ? 'true' : 'false') . "\n\n";

// ============================================
// 7. Method Delegation
// ============================================
echo "7. Method delegation (calling delegate methods via Thing)...\n";

// These methods are delegated from Thing to Book
echo "   \$design_patterns->formatted_isbn(): " . $design_patterns->formatted_isbn() . "\n";
echo "   \$design_patterns->pages(): " . $design_patterns->pages() . "\n";

// These are delegated from Thing to Person
echo "   \$gamma->display_name(): " . $gamma->display_name() . "\n";
echo "   \$gamma->citation_name(): " . $gamma->citation_name() . "\n\n";

// ============================================
// 8. Working with Contributions
// ============================================
echo "8. Finding works by author...\n";

$gamma_person = $gamma->delegate();
$gamma_works = $gamma_person->authored_works();
echo "   Works authored by " . $gamma_person->display_name() . ":\n";
foreach ($gamma_works as $work) {
    echo "      - " . $work['name'] . "\n";
}

echo "\n";

// ============================================
// 9. Citation Formatting
// ============================================
echo "9. Citation formatting...\n";

echo "   APA: " . $book->cite_apa() . "\n";
echo "   Chicago: " . $book->cite_chicago() . "\n\n";

// ============================================
// 10. Eager Loading
// ============================================
echo "10. Eager loading delegates...\n";

// Find all things with delegates loaded in batches (efficient)
$all_things = Thing::find_with_delegates();
echo "    Loaded " . count($all_things) . " things with delegates\n";

foreach ($all_things as $thing) {
    $delegate = $thing->delegate();
    $delegate_type = $delegate ? get_class($delegate) : 'none';
    $delegate_type = basename(str_replace('\\', '/', $delegate_type));
    echo "      - " . $thing['name'] . " -> " . $delegate_type . "\n";
}

echo "\n";

// ============================================
// 11. Update with Delegate
// ============================================
echo "11. Updating thing and delegate atomically...\n";

$design_patterns->update_with_delegate(
    ['description' => 'The classic Gang of Four book on software design patterns.'],
    ['number_of_pages' => 395]  // Paperback edition
);

echo "    Updated description and page count\n";
echo "    New page count: " . $design_patterns->delegate()['number_of_pages'] . "\n\n";

// ============================================
// Cleanup
// ============================================
echo "12. Cleanup...\n";
$db->drop_tables(...array_reverse($schema->get_tables()));
echo "    Tables dropped\n\n";

echo "=== Example completed successfully ===\n";
