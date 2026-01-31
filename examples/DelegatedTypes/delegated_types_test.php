<?php
/**
 * Delegated Types Test Suite
 *
 * Tests for the Delegated Types pattern implementation.
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

/**
 * Simple test runner
 */
class DelegatedTypesTestRunner
{
    private IxOrm $db;
    private Schema $schema;
    private array $passed = [];
    private array $failed = [];

    public function run_all(): void
    {
        echo "Delegated Types Test Suite\n";
        echo str_repeat('=', 50) . "\n\n";

        $this->setup();

        // Run test groups
        $this->test_thing_creation();
        $this->test_delegate_access();
        $this->test_type_checking();
        $this->test_method_delegation();
        $this->test_contributions();
        $this->test_eager_loading();
        $this->test_atomic_operations();
        $this->test_queries();
        $this->test_serialization();

        $this->teardown();
        $this->print_summary();
    }

    private function setup(): void
    {
        $driver = Driver::sqlite_memory();
        $this->db = new IxOrm($driver);
        $this->schema = new Schema();

        $this->db->create_tables(...$this->schema->get_tables());

        Thing::set_persistence($this->db, $this->schema->things);
        Book::set_persistence($this->db, $this->schema->books);
        Movie::set_persistence($this->db, $this->schema->movies);
        Article::set_persistence($this->db, $this->schema->articles);
        Person::set_persistence($this->db, $this->schema->persons);
        Organization::set_persistence($this->db, $this->schema->organizations);
        Contribution::set_persistence($this->db, $this->schema->contributions);
    }

    private function teardown(): void
    {
        $this->db->drop_tables(...array_reverse($this->schema->get_tables()));
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

    private function test_thing_creation(): void
    {
        echo "Thing Creation\n";
        echo str_repeat('-', 30) . "\n";

        // Create a person
        $person = Thing::create_person([
            'name' => 'Test Person',
        ], [
            'given_name'  => 'Test',
            'family_name' => 'Person',
        ]);

        $this->assert('Person thing created with ID', $person['id'] > 0);
        $this->assert('Person has UUID', !empty($person['uuid']));
        $this->assert('Person type is correct', $person['type'] === 'Person');
        $this->assert('Person type_path is correct', $person['type_path'] === 'Thing/Agent/Person');
        $this->assert('Person is_agent flag is true', $person['is_agent'] === true);
        $this->assert('Person is_creative_work flag is false', $person['is_creative_work'] === false);

        // Create a book
        $book = Thing::create_book([
            'name' => 'Test Book',
        ], [
            'isbn13' => '978-1234567890',
        ]);

        $this->assert('Book thing created with ID', $book['id'] > 0);
        $this->assert('Book type is correct', $book['type'] === 'Book');
        $this->assert('Book is_creative_work flag is true', $book['is_creative_work'] === true);
        $this->assert('Book is_agent flag is false', $book['is_agent'] === false);

        // Create an organization
        $org = Thing::create_organization([
            'name' => 'Test Organization',
        ], [
            'legal_name' => 'Test Organization Inc.',
        ]);

        $this->assert('Organization created', $org['id'] > 0);
        $this->assert('Organization is_agent flag is true', $org['is_agent'] === true);

        echo "\n";
    }

    private function test_delegate_access(): void
    {
        echo "Delegate Access\n";
        echo str_repeat('-', 30) . "\n";

        $book_thing = Thing::create_book([
            'name' => 'Delegate Test Book',
        ], [
            'isbn13'          => '978-0000000001',
            'number_of_pages' => 200,
        ]);

        // Access delegate
        $delegate = $book_thing->delegate();
        $this->assert('delegate() returns Book instance', $delegate instanceof Book);
        $this->assert('Delegate has correct ISBN', $delegate['isbn13'] === '978-0000000001');
        $this->assert('Delegate has correct pages', $delegate['number_of_pages'] === 200);

        // has_delegate check
        $this->assert('has_delegate() returns true', $book_thing->has_delegate());

        // delegate_class check
        $this->assert('delegate_class() returns Book class', $book_thing->delegate_class() === Book::class);

        echo "\n";
    }

    private function test_type_checking(): void
    {
        echo "Type Checking\n";
        echo str_repeat('-', 30) . "\n";

        $book = Thing::create_book(['name' => 'Type Check Book'], []);
        $person = Thing::create_person(['name' => 'Type Check Person'], []);
        $movie = Thing::create_movie(['name' => 'Type Check Movie'], []);

        // is_type() method
        $this->assert('is_type(Book) on book returns true', $book->is_type('Book'));
        $this->assert('is_type(Movie) on book returns false', !$book->is_type('Movie'));

        // Magic is_* methods
        $this->assert('is_book() on book returns true', $book->is_book());
        $this->assert('is_movie() on book returns false', !$book->is_movie());
        $this->assert('is_person() on person returns true', $person->is_person());
        $this->assert('is_movie() on movie returns true', $movie->is_movie());

        // is_in_hierarchy() method
        $this->assert('Book is in CreativeWork hierarchy', $book->is_in_hierarchy('CreativeWork'));
        $this->assert('Person is in Agent hierarchy', $person->is_in_hierarchy('Agent'));
        $this->assert('Person is not in CreativeWork hierarchy', !$person->is_in_hierarchy('CreativeWork'));

        // Convenience methods
        $this->assert('is_creative_work() on book returns true', $book->is_creative_work());
        $this->assert('is_agent() on person returns true', $person->is_agent());

        echo "\n";
    }

    private function test_method_delegation(): void
    {
        echo "Method Delegation\n";
        echo str_repeat('-', 30) . "\n";

        $book_thing = Thing::create_book([
            'name' => 'Method Delegation Book',
        ], [
            'isbn13'          => '978-1111111111',
            'number_of_pages' => 350,
        ]);

        // Method delegation from Thing to Book
        $this->assert('pages() delegated correctly', $book_thing->pages() === 350);
        $this->assert('formatted_isbn() delegated correctly', str_contains($book_thing->formatted_isbn(), '978'));

        $person_thing = Thing::create_person([
            'name' => 'Jane Doe',
        ], [
            'given_name'  => 'Jane',
            'family_name' => 'Doe',
        ]);

        // Method delegation from Thing to Person
        $this->assert('display_name() delegated correctly', $person_thing->display_name() === 'Jane Doe');
        $this->assert('citation_name() delegated correctly', $person_thing->citation_name() === 'Doe, J.');

        echo "\n";
    }

    private function test_contributions(): void
    {
        echo "Contributions (Polymorphic Relations)\n";
        echo str_repeat('-', 30) . "\n";

        // Create authors
        $author1 = Thing::create_person(['name' => 'Author One'], [
            'given_name' => 'Author', 'family_name' => 'One'
        ]);
        $author2 = Thing::create_person(['name' => 'Author Two'], [
            'given_name' => 'Author', 'family_name' => 'Two'
        ]);
        $org_author = Thing::create_organization(['name' => 'Corp Author'], [
            'legal_name' => 'Corporation Author Inc.'
        ]);

        // Create a book
        $book_thing = Thing::create_book(['name' => 'Multi-Author Book'], []);
        $book = $book_thing->delegate();

        // Add authors
        $book->add_author($author1, 0);
        $book->add_author($author2, 1);
        $book->add_author($org_author, 2);  // Organization as author

        $authors = $book->authors();
        $this->assert('Book has 3 authors', count($authors) === 3);
        $this->assert('First author is correct', $authors[0]['name'] === 'Author One');
        $this->assert('Third author is organization', $authors[2]->is_organization());

        // Test authors_string
        $author_string = $book->authors_string();
        $this->assert('authors_string() contains all names',
            str_contains($author_string, 'Author One') &&
            str_contains($author_string, 'Author Two'));

        // Test finding works by author
        $author1_person = $author1->delegate();
        $authored_works = $author1_person->authored_works();
        $this->assert('Author can find their works', count($authored_works) === 1);
        $this->assert('Authored work is correct', $authored_works[0]['name'] === 'Multi-Author Book');

        echo "\n";
    }

    private function test_eager_loading(): void
    {
        echo "Eager Loading\n";
        echo str_repeat('-', 30) . "\n";

        // Create several things
        Thing::create_book(['name' => 'Eager Book 1'], ['isbn' => '111']);
        Thing::create_book(['name' => 'Eager Book 2'], ['isbn' => '222']);
        Thing::create_movie(['name' => 'Eager Movie 1'], ['duration' => 120]);
        Thing::create_person(['name' => 'Eager Person 1'], ['given_name' => 'Eager']);

        // Find with delegates (should batch load)
        $things = Thing::find_with_delegates();
        $this->assert('find_with_delegates() returns results', count($things) > 0);

        // Check that delegates are loaded
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

    private function test_atomic_operations(): void
    {
        echo "Atomic Operations\n";
        echo str_repeat('-', 30) . "\n";

        // Test create_with_delegate (atomic)
        $book = Thing::create_with_delegate('Book', [
            'name' => 'Atomic Book',
        ], [
            'isbn13' => '978-9999999999',
        ]);

        $this->assert('create_with_delegate() creates thing', $book['id'] > 0);
        $this->assert('create_with_delegate() creates delegate', $book->delegate()['isbn13'] === '978-9999999999');

        // Test update_with_delegate (atomic)
        $book->update_with_delegate(
            ['description' => 'Updated description'],
            ['number_of_pages' => 500]
        );

        // Refresh to verify
        $fresh = Thing::find_with_delegate($book['id']);
        $this->assert('update_with_delegate() updates thing', $fresh['description'] === 'Updated description');
        $this->assert('update_with_delegate() updates delegate', $fresh->delegate()['number_of_pages'] === 500);

        // Test delete_with_delegate (atomic)
        $delete_id = $book['id'];
        $book->delete_with_delegate();

        $deleted = Thing::find($delete_id);
        $this->assert('delete_with_delegate() removes thing', $deleted === null);

        echo "\n";
    }

    private function test_queries(): void
    {
        echo "Query Helpers\n";
        echo str_repeat('-', 30) . "\n";

        // Ensure we have clean data
        Thing::create_book(['name' => 'Query Book'], []);
        Thing::create_movie(['name' => 'Query Movie'], []);
        Thing::create_article(['name' => 'Query Article'], []);
        Thing::create_person(['name' => 'Query Person'], []);
        Thing::create_organization(['name' => 'Query Org'], []);

        // Test find_creative_works
        $works = Thing::find_creative_works();
        $all_are_works = true;
        foreach ($works as $work) {
            if (!$work->is_creative_work()) {
                $all_are_works = false;
                break;
            }
        }
        $this->assert('find_creative_works() returns only creative works', $all_are_works);

        // Test find_agents
        $agents = Thing::find_agents();
        $all_are_agents = true;
        foreach ($agents as $agent) {
            if (!$agent->is_agent()) {
                $all_are_agents = false;
                break;
            }
        }
        $this->assert('find_agents() returns only agents', $all_are_agents);

        // Test find_by_type
        $books = Thing::find_by_type('Book');
        $all_are_books = true;
        foreach ($books as $book) {
            if ($book['type'] !== 'Book') {
                $all_are_books = false;
                break;
            }
        }
        $this->assert('find_by_type(Book) returns only books', $all_are_books);

        echo "\n";
    }

    private function test_serialization(): void
    {
        echo "Serialization / Reconstruction\n";
        echo str_repeat('-', 30) . "\n";

        // Create a book with delegate
        $book = Thing::create_with_delegate('Book',
            ['name' => 'Serialization Test', 'description' => 'A test book'],
            ['isbn' => '978-1111111111', 'number_of_pages' => 200]
        );

        // Test to_array_with_delegates
        $serialized = $book->to_array_with_delegates();
        $this->assert('to_array_with_delegates() returns array', is_array($serialized));
        $this->assert('Serialized has _type key', isset($serialized['_type']));
        $this->assert('Serialized has _class key', isset($serialized['_class']));
        $this->assert('Serialized has _data key', isset($serialized['_data']));
        $this->assert('Serialized has _delegate key', array_key_exists('_delegate', $serialized));
        $this->assert('Serialized _data contains name', $serialized['_data']['name'] === 'Serialization Test');
        $this->assert('Serialized _delegate has Book data', isset($serialized['_delegate']['_data']['isbn']));
        $this->assert('Delegate data contains ISBN', $serialized['_delegate']['_data']['isbn'] === '978-1111111111');

        // Test to_json_with_delegates
        $json = $book->to_json_with_delegates();
        $this->assert('to_json_with_delegates() returns valid JSON', json_decode($json) !== null);

        // Test from_serialized (reconstruction)
        $reconstructed = Thing::from_serialized($serialized);
        $this->assert('from_serialized() returns Thing', $reconstructed instanceof Thing);
        $this->assert('Reconstructed has same name', $reconstructed['name'] === 'Serialization Test');
        $this->assert('Reconstructed has delegate', $reconstructed->delegate() !== null);
        $this->assert('Reconstructed delegate has ISBN', $reconstructed->delegate()['isbn'] === '978-1111111111');

        // Test from_json
        $from_json = Thing::from_json($json);
        $this->assert('from_json() returns Thing', $from_json instanceof Thing);
        $this->assert('from_json() data matches', $from_json['name'] === 'Serialization Test');

        // Test round-trip (serialize -> deserialize -> same data)
        $original_data = $book->to_array_with_delegates();
        $reconstructed_data = $reconstructed->to_array_with_delegates();
        // Remove IDs since reconstructed won't have real DB IDs in comparison
        unset($original_data['_data']['id']);
        unset($reconstructed_data['_data']['id']);
        unset($original_data['_delegate']['_data']['id']);
        unset($reconstructed_data['_delegate']['_data']['id']);
        $this->assert('Round-trip preserves data structure',
            $original_data['_data']['name'] === $reconstructed_data['_data']['name'] &&
            $original_data['_delegate']['_data']['isbn'] === $reconstructed_data['_delegate']['_data']['isbn']
        );

        echo "\n";
    }

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

// Run the tests
$runner = new DelegatedTypesTestRunner();
$runner->run_all();
