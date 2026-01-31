<?php
/**
 * N-Level Delegated Types Test Suite
 *
 * Tests the generalized N-level delegation features:
 * - create_chain() for atomic N-level creation
 * - eager_load_delegates_recursive() for recursive loading
 * - Deep method delegation through __call
 * - Chain traversal helpers (get_chain, leaf, get_from_chain)
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';

use Italix\Orm\Schema\Table;
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\DelegatedTypes;
use Italix\Orm\Dialects\Driver;
use Italix\Orm\IxOrm;

use function Italix\Orm\Schema\bigint;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Operators\eq;

// ============================================================
// SCHEMA: Three-level hierarchy
// Thing → Book → TextBook/AudioBook
// ============================================================

$things = new Table('things', [
    'id'        => bigint()->primary_key()->auto_increment(),
    'type'      => varchar(50)->not_null(),
    'type_path' => varchar(200)->not_null(),
    'name'      => varchar(500)->not_null(),
], 'sqlite');

$books = new Table('books', [
    'id'       => bigint()->primary_key()->auto_increment(),
    'thing_id' => bigint()->not_null(),
    'type'     => varchar(50),
    'isbn'     => varchar(20),
    'pages'    => integer(),
], 'sqlite');

$textbooks = new Table('textbooks', [
    'id'          => bigint()->primary_key()->auto_increment(),
    'book_id'     => bigint()->not_null(),
    'edition'     => varchar(50),
    'grade_level' => varchar(50),
], 'sqlite');

$audiobooks = new Table('audiobooks', [
    'id'       => bigint()->primary_key()->auto_increment(),
    'book_id'  => bigint()->not_null(),
    'duration' => integer(),
    'narrator' => varchar(200),
], 'sqlite');

// ============================================================
// MODELS: Three-level hierarchy
// ============================================================

class NThing extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'Book'      => NBook::class,
            'TextBook'  => NBook::class,  // Leaf types map to intermediate
            'AudioBook' => NBook::class,
        ];
    }

    public function get_type_column(): string
    {
        return 'type';
    }

    public function get_type_path_column(): ?string
    {
        return 'type_path';
    }

    public function get_delegate_foreign_key(): string
    {
        return 'thing_id';
    }
}

class NBook extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'TextBook'  => NTextBook::class,
            'AudioBook' => NAudioBook::class,
        ];
    }

    public function get_type_column(): string
    {
        return 'type';
    }

    public function get_type_path_column(): ?string
    {
        return null; // Books don't need type_path
    }

    public function get_delegate_foreign_key(): string
    {
        return 'book_id';
    }

    public function formatted_isbn(): string
    {
        return 'ISBN: ' . ($this['isbn'] ?? 'N/A');
    }

    public function pages(): int
    {
        return (int) ($this['pages'] ?? 0);
    }
}

class NTextBook extends ActiveRow
{
    use Persistable;

    public function edition(): string
    {
        return $this['edition'] ?? '1st';
    }

    public function grade_level(): string
    {
        return $this['grade_level'] ?? 'general';
    }

    public function is_college_level(): bool
    {
        return $this['grade_level'] === 'college';
    }

    public function full_description(): string
    {
        return "Edition: {$this->edition()}, Level: {$this->grade_level()}";
    }
}

class NAudioBook extends ActiveRow
{
    use Persistable;

    public function duration(): int
    {
        return (int) ($this['duration'] ?? 0);
    }

    public function narrator(): string
    {
        return $this['narrator'] ?? 'Unknown';
    }

    public function duration_formatted(): string
    {
        $hours = floor($this->duration() / 60);
        $mins = $this->duration() % 60;
        return "{$hours}h {$mins}m";
    }
}

// ============================================================
// TEST RUNNER
// ============================================================

$passed = 0;
$failed = 0;

function test(string $description, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$description}\n";
        $passed++;
    } else {
        echo "  ✗ {$description}\n";
        $failed++;
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('-', 30) . "\n";
}

// ============================================================
// SETUP
// ============================================================

$driver = Driver::sqlite_memory();
$db = new IxOrm($driver);
$db->create_tables($things, $books, $textbooks, $audiobooks);

NThing::set_persistence($db, $things);
NBook::set_persistence($db, $books);
NTextBook::set_persistence($db, $textbooks);
NAudioBook::set_persistence($db, $audiobooks);

echo "N-Level Delegated Types Test Suite\n";
echo str_repeat('=', 50) . "\n";

// ============================================================
// TEST: create_chain() for 3-level creation
// ============================================================

section('create_chain() - Three Levels');

$textbook = NThing::create_chain([
    'Thing'    => ['name' => 'Calculus: Early Transcendentals'],
    'Book'     => ['isbn' => '978-1285741550', 'pages' => 1344],
    'TextBook' => ['edition' => '8th', 'grade_level' => 'college'],
]);

test('create_chain() returns NThing instance', $textbook instanceof NThing);
test('NThing has correct type', $textbook['type'] === 'TextBook');
test('NThing has correct type_path', $textbook['type_path'] === 'Thing/Book/TextBook');
test('NThing has correct name', $textbook['name'] === 'Calculus: Early Transcendentals');

$book = $textbook->delegate();
test('delegate() returns NBook instance', $book instanceof NBook);
test('NBook has correct ISBN', $book['isbn'] === '978-1285741550');
test('NBook has correct pages', $book['pages'] == 1344);

$tb = $book->delegate();
test('NBook->delegate() returns NTextBook instance', $tb instanceof NTextBook);
test('NTextBook has correct edition', $tb['edition'] === '8th');
test('NTextBook has correct grade_level', $tb['grade_level'] === 'college');

// Create an AudioBook too
$audiobook = NThing::create_chain([
    'Thing'     => ['name' => 'The Hobbit'],
    'Book'      => ['isbn' => '978-0618260300', 'pages' => 310],
    'AudioBook' => ['duration' => 683, 'narrator' => 'Andy Serkis'],
]);

test('AudioBook create_chain() works', $audiobook instanceof NThing);
test('AudioBook has correct type', $audiobook['type'] === 'AudioBook');

$ab = $audiobook->delegate()->delegate();
test('AudioBook leaf has correct narrator', $ab['narrator'] === 'Andy Serkis');

// ============================================================
// TEST: Chain traversal helpers
// ============================================================

section('Chain Traversal Helpers');

$chain = $textbook->get_chain();
test('get_chain() returns array', is_array($chain));
test('get_chain() has 3 elements', count($chain) === 3);
test('get_chain()[0] is NThing', $chain[0] instanceof NThing);
test('get_chain()[1] is NBook', $chain[1] instanceof NBook);
test('get_chain()[2] is NTextBook', $chain[2] instanceof NTextBook);

$leaf = $textbook->leaf();
test('leaf() returns NTextBook', $leaf instanceof NTextBook);
test('leaf() has correct edition', $leaf->edition() === '8th');

test('chain_depth() returns 3', $textbook->chain_depth() === 3);

// get_from_chain()
test('get_from_chain(name) returns Thing value', $textbook->get_from_chain('name') === 'Calculus: Early Transcendentals');
test('get_from_chain(isbn) returns Book value', $textbook->get_from_chain('isbn') === '978-1285741550');
test('get_from_chain(edition) returns TextBook value', $textbook->get_from_chain('edition') === '8th');
test('get_from_chain(nonexistent) returns null', $textbook->get_from_chain('nonexistent') === null);

// ============================================================
// TEST: Deep method delegation
// ============================================================

section('Deep Method Delegation');

// Methods on Book (level 2)
test('formatted_isbn() delegates to Book', $textbook->formatted_isbn() === 'ISBN: 978-1285741550');
test('pages() delegates to Book', $textbook->pages() === 1344);

// Methods on TextBook (level 3)
test('edition() delegates to TextBook', $textbook->edition() === '8th');
test('grade_level() delegates to TextBook', $textbook->grade_level() === 'college');
test('is_college_level() delegates to TextBook', $textbook->is_college_level() === true);
test('full_description() delegates to TextBook', str_contains($textbook->full_description(), 'Edition: 8th'));

// AudioBook methods
test('duration() delegates to AudioBook', $audiobook->duration() === 683);
test('narrator() delegates to AudioBook', $audiobook->narrator() === 'Andy Serkis');
test('duration_formatted() delegates to AudioBook', $audiobook->duration_formatted() === '11h 23m');

// ============================================================
// TEST: Recursive eager loading
// ============================================================

section('Recursive Eager Loading');

// Clear delegate caches
$textbook->clear_delegate_cache();
$audiobook->clear_delegate_cache();

// Load all with recursive eager loading
$all = NThing::find_with_delegates();

test('find_with_delegates() returns all things', count($all) === 2);

// Check that delegates are pre-loaded at all levels
$thing1 = $all[0];
$thing1_book = $thing1->delegate();
test('First level delegate is loaded', $thing1_book !== null);

$thing1_leaf = $thing1_book->delegate();
test('Second level delegate is loaded', $thing1_leaf !== null);

// Test with max_depth limit
$textbook->clear_delegate_cache();
$audiobook->clear_delegate_cache();

$shallow = NThing::find_with_delegates(['max_depth' => 1]);
test('max_depth=1 loads first delegate', $shallow[0]->delegate() !== null);

// ============================================================
// TEST: update_chain()
// ============================================================

section('update_chain()');

$textbook->update_chain([
    'Thing'    => ['name' => 'Calculus (9th Edition)'],
    'Book'     => ['pages' => 1400],
    'TextBook' => ['edition' => '9th'],
]);

// Refresh from DB
$refreshed = NThing::find_with_delegate($textbook['id']);
test('update_chain() updates Thing', $refreshed['name'] === 'Calculus (9th Edition)');
test('update_chain() updates Book', $refreshed->delegate()['pages'] == 1400);
test('update_chain() updates TextBook', $refreshed->delegate()->delegate()['edition'] === '9th');

// ============================================================
// TEST: delete_chain()
// ============================================================

section('delete_chain()');

// Create a new one to delete
$to_delete = NThing::create_chain([
    'Thing'    => ['name' => 'To Be Deleted'],
    'Book'     => ['isbn' => '000-0000000000', 'pages' => 100],
    'TextBook' => ['edition' => '1st', 'grade_level' => 'test'],
]);

$delete_id = $to_delete['id'];
$book_id = $to_delete->delegate()['id'];
$textbook_id = $to_delete->delegate()->delegate()['id'];

$to_delete->delete_chain();

// Verify all are deleted
$check_thing = NThing::find($delete_id);
$check_book = NBook::find($book_id);
$check_textbook = NTextBook::find($textbook_id);

test('delete_chain() removes Thing', $check_thing === null);
test('delete_chain() removes Book', $check_book === null);
test('delete_chain() removes TextBook', $check_textbook === null);

// ============================================================
// TEST: Error handling
// ============================================================

section('Error Handling');

$caught_empty = false;
try {
    NThing::create_chain([]);
} catch (\InvalidArgumentException $e) {
    $caught_empty = true;
}
test('create_chain([]) throws exception', $caught_empty);

$caught_single = false;
try {
    NThing::create_chain(['Thing' => ['name' => 'Only one level']]);
} catch (\InvalidArgumentException $e) {
    $caught_single = true;
}
test('create_chain() with 1 level throws exception', $caught_single);

$caught_bad_method = false;
try {
    $textbook->nonexistent_method();
} catch (\BadMethodCallException $e) {
    $caught_bad_method = true;
}
test('Calling nonexistent method throws exception', $caught_bad_method);

// ============================================================
// SUMMARY
// ============================================================

echo "\n" . str_repeat('=', 50) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 50) . "\n\n";

if ($failed === 0) {
    echo "✓ Total: {$passed} passed, {$failed} failed\n\n";
    echo "✓ All tests passed!\n";
} else {
    echo "✗ Total: {$passed} passed, {$failed} failed\n\n";
    echo "✗ Some tests failed!\n";
    exit(1);
}
