# Delegated Types Guide

The Delegated Types pattern enables sophisticated type hierarchies where a base class delegates type-specific behavior and attributes to specialized classes stored in separate tables. This pattern is ideal for implementing Schema.org-style hierarchies, content management systems, and any domain requiring polymorphic entities with efficient querying.

## Table of Contents

- [Overview](#overview)
- [When to Use Delegated Types](#when-to-use-delegated-types)
- [Core Concepts](#core-concepts)
- [Implementation Guide](#implementation-guide)
- [API Reference](#api-reference)
- [Serialization and Reconstruction](#serialization-and-reconstruction)
- [N-Level Chained Delegation](#n-level-chained-delegation)
- [Schema.org Example](#schemaorg-example)
- [Eager Loading](#eager-loading)
- [Polymorphic Contributions](#polymorphic-contributions)
- [Best Practices](#best-practices)
- [Comparison with Other Patterns](#comparison-with-other-patterns)

## Overview

The Delegated Types pattern (inspired by Ruby on Rails) solves the challenge of modeling class hierarchies in relational databases. Instead of using Single Table Inheritance (STI) or Class Table Inheritance (CTI), delegated types use a hybrid approach:

- A **base table** stores shared attributes and type information
- **Delegate tables** store type-specific attributes
- The base class **delegates** behavior to type-specific classes

```
┌─────────────────────────────────────────────────────────────────┐
│                        things table                              │
├─────────────────────────────────────────────────────────────────┤
│ id │ type    │ name          │ is_creative_work │ is_agent     │
├────┼─────────┼───────────────┼──────────────────┼──────────────┤
│ 1  │ Book    │ Design Pat... │ true             │ false        │
│ 2  │ Person  │ Erich Gamma   │ false            │ true         │
│ 3  │ Movie   │ The Matrix    │ true             │ false        │
└────┴─────────┴───────────────┴──────────────────┴──────────────┘

┌─────────────────────────────────┐   ┌──────────────────────────┐
│        books table              │   │     persons table        │
├─────────────────────────────────┤   ├──────────────────────────┤
│ id │ thing_id │ isbn  │ pages  │   │ id │ thing_id │ given_name│
├────┼──────────┼───────┼────────┤   ├────┼──────────┼───────────┤
│ 1  │ 1        │ 978...│ 416    │   │ 1  │ 2        │ Erich     │
└────┴──────────┴───────┴────────┘   └────┴──────────┴───────────┘
```

## When to Use Delegated Types

### Ideal Use Cases

1. **Schema.org-style hierarchies**: Thing → CreativeWork → Book, Thing → Agent → Person
2. **Content management systems**: Entry → Message | Comment | Photo | Video
3. **E-commerce product catalogs**: Product → PhysicalProduct | DigitalProduct | Subscription
4. **User account types**: Account → PersonalAccount | BusinessAccount | EnterpriseAccount
5. **Financial instruments**: Asset → Stock | Bond | RealEstate | Cryptocurrency

### Benefits

| Benefit | Description |
|---------|-------------|
| **Efficient queries** | Query all types at once using the base table |
| **Type safety** | Each delegate class has properly typed attributes |
| **Flexibility** | Add new types without schema changes to existing tables |
| **Clean separation** | Type-specific logic stays in delegate classes |
| **No NULL columns** | Unlike STI, delegate tables only have relevant columns |

### When NOT to Use

- Simple polymorphism with few shared attributes → Use polymorphic relations
- Types share 90%+ of attributes → Consider Single Table Inheritance
- No need to query across types → Use separate, unrelated tables

## Core Concepts

### The Base Class (Thing)

The base class uses the `DelegatedTypes` trait and defines the type mapping:

```php
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\{Persistable, DelegatedTypes};

class Thing extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'Book'   => Book::class,
            'Movie'  => Movie::class,
            'Person' => Person::class,
        ];
    }
}
```

### Delegate Classes

Delegate classes are standard ActiveRow classes that reference their parent Thing:

```php
class Book extends ActiveRow
{
    use Persistable;

    public function thing(): Thing
    {
        return Thing::find($this['thing_id']);
    }

    public function formatted_isbn(): ?string
    {
        return $this['isbn'] ? 'ISBN: ' . $this['isbn'] : null;
    }

    public function pages(): ?int
    {
        return $this['number_of_pages'] ? (int) $this['number_of_pages'] : null;
    }
}
```

### Type Hierarchy Path

The `type_path` column enables hierarchy queries:

```php
// thing_path stores: "Thing/CreativeWork/Book"
$thing->is_in_hierarchy('CreativeWork');  // true for Books, Movies, Articles
$thing->is_in_hierarchy('Agent');         // true for Persons, Organizations
```

## Implementation Guide

### Step 1: Define the Database Schema

```php
use Italix\Orm\Schema\Table;
use function Italix\Orm\Schema\{bigint, varchar, text, integer, boolean, timestamp};

// Base table for all entities
$things = new Table('things', [
    'id'               => bigint()->primary_key()->auto_increment(),
    'uuid'             => varchar(36)->unique(),
    'type'             => varchar(50)->not_null(),
    'type_path'        => varchar(200)->not_null(),
    'name'             => varchar(500)->not_null(),
    'description'      => text(),
    'is_creative_work' => boolean()->default(false),
    'is_agent'         => boolean()->default(false),
    'created_at'       => timestamp(),
    'updated_at'       => timestamp(),
], 'sqlite');

// Delegate table for books
$books = new Table('books', [
    'id'              => bigint()->primary_key()->auto_increment(),
    'thing_id'        => bigint()->not_null()->unique(),
    'isbn'            => varchar(20),
    'number_of_pages' => integer(),
    'date_published'  => date(),
], 'sqlite');

// Delegate table for persons
$persons = new Table('persons', [
    'id'          => bigint()->primary_key()->auto_increment(),
    'thing_id'    => bigint()->not_null()->unique(),
    'given_name'  => varchar(200),
    'family_name' => varchar(200),
    'birth_date'  => date(),
], 'sqlite');
```

### Step 2: Create the Base Class

```php
class Thing extends ActiveRow
{
    use Persistable, HasTimestamps, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'Book'   => Book::class,
            'Movie'  => Movie::class,
            'Person' => Person::class,
        ];
    }

    // Optional: customize column names
    protected function get_type_column(): string
    {
        return 'type';  // default
    }

    protected function get_type_path_column(): ?string
    {
        return 'type_path';  // default, set null to disable
    }

    protected function get_delegate_foreign_key(): string
    {
        return 'thing_id';  // default
    }

    // Convenience query methods
    public static function find_creative_works(array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = eq($table->is_creative_work, 1);
        return static::find_with_delegates($options);
    }

    public static function find_by_type(string $type, array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = eq($table->type, $type);
        return static::find_with_delegates($options);
    }
}
```

### Step 3: Create Delegate Classes

```php
class Book extends ActiveRow
{
    use Persistable;

    protected ?Thing $thing_cache = null;

    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find($this['thing_id']);
        }
        return $this->thing_cache;
    }

    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    // Book-specific methods
    public function pages(): ?int
    {
        return $this['number_of_pages'] ? (int) $this['number_of_pages'] : null;
    }

    public function formatted_isbn(): ?string
    {
        $isbn = $this['isbn'];
        if (!$isbn) return null;
        // Format as ISBN-13
        return substr($isbn, 0, 3) . '-' . substr($isbn, 3);
    }
}
```

### Step 4: Initialize Persistence

```php
use function Italix\Orm\sqlite_memory;

$db = sqlite_memory();
$schema = new Schema('sqlite');

// Create tables
$db->create_tables(...$schema->get_tables());

// Set up persistence for each class
Thing::set_persistence($db, $schema->things);
Book::set_persistence($db, $schema->books);
Person::set_persistence($db, $schema->persons);
```

## API Reference

### DelegatedTypes Trait Methods

#### Configuration Methods (Override in Subclass)

| Method | Returns | Description |
|--------|---------|-------------|
| `get_delegated_types()` | `array<string, class-string>` | Map of type names to delegate classes |
| `get_type_column()` | `string` | Column storing type name (default: `'type'`) |
| `get_type_path_column()` | `string\|null` | Column storing hierarchy path (default: `'type_path'`) |
| `get_delegate_foreign_key()` | `string` | FK column in delegate tables (default: `'thing_id'`) |

#### Delegate Access

| Method | Returns | Description |
|--------|---------|-------------|
| `delegate()` | `ActiveRow\|null` | Get the delegate object (lazy-loaded, cached) |
| `has_delegate()` | `bool` | Check if delegate class exists for this type |
| `delegate_class()` | `string\|null` | Get the delegate class name |
| `set_delegate($delegate)` | `static` | Set pre-loaded delegate (for eager loading) |
| `clear_delegate_cache()` | `static` | Clear the cached delegate |

#### Type Checking

| Method | Returns | Description |
|--------|---------|-------------|
| `is_type($type)` | `bool` | Check if thing is of specific type |
| `is_in_hierarchy($ancestor)` | `bool` | Check if type path contains ancestor |
| `type_name()` | `string` | Get the type name |
| `type_path()` | `string` | Get the full type path |

#### Magic Methods

The trait provides dynamic `is_*()` and `as_*()` methods:

```php
$thing->is_book();    // Equivalent to $thing->is_type('Book')
$thing->is_movie();   // Equivalent to $thing->is_type('Movie')
$thing->as_book();    // Returns delegate if type is Book, null otherwise

// Method delegation - calls are forwarded to delegate
$thing->pages();          // Calls $thing->delegate()->pages()
$thing->formatted_isbn(); // Calls $thing->delegate()->formatted_isbn()
```

#### Creation Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `create_with_delegate($type, $base_data, $delegate_data)` | `static` | Create thing + delegate atomically |
| `update_with_delegate($base_data, $delegate_data)` | `static` | Update both atomically |
| `delete_with_delegate()` | `static` | Delete both atomically |

#### Eager Loading

| Method | Returns | Description |
|--------|---------|-------------|
| `eager_load_delegates($things)` | `array` | Batch load delegates for collection |
| `eager_load_delegates_recursive($items, $depth)` | `array` | Recursively load all delegate levels |
| `find_with_delegates($options)` | `array` | Find things with delegates pre-loaded (recursive) |
| `find_with_delegate($id, $depth)` | `static\|null` | Find one thing with full chain pre-loaded |

#### N-Level Chain Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `create_chain($chain)` | `static` | Create entity with N levels atomically |
| `update_chain($chain)` | `static` | Update all levels atomically |
| `delete_chain()` | `static` | Delete all levels atomically |
| `get_chain()` | `array<ActiveRow>` | Get full delegation chain as array |
| `leaf()` | `ActiveRow` | Get the deepest delegate |
| `get_from_chain($key)` | `mixed` | Get value from any level |
| `chain_depth()` | `int` | Get number of levels |
| `delegate_method_call($method, $args, &$found)` | `mixed` | Recursively search for method in chain |
| `to_array_with_delegates($include_transient)` | `array` | Serialize entity with full delegate chain |
| `to_json_with_delegates($include_transient, $flags)` | `string` | Convert to JSON with delegates |
| `from_serialized($data)` | `static\|null` | Reconstruct entity from serialized data |
| `from_json($json)` | `static\|null` | Reconstruct entity from JSON string |

## Serialization and Reconstruction

When you need to transfer entities across servers, cache them, or store them in session data, simple `json_encode()` won't include the delegate chain. DelegatedTypes provides methods for full serialization and reconstruction.

### The Problem with Standard JSON

```php
$book = Thing::find_with_delegate(1);

// Standard JSON only includes base entity data
$json = json_encode($book);
// {"id":1,"type":"Book","name":"Design Patterns",...}
// Missing: delegate data (ISBN, pages, etc.)
```

### Full Serialization with Delegates

```php
// Serialize the entire chain
$data = $book->to_array_with_delegates();
// Returns:
// [
//     '_type' => 'Book',
//     '_class' => 'App\Models\Thing',
//     '_data' => ['id' => 1, 'name' => 'Design Patterns', 'type' => 'Book', ...],
//     '_delegate' => [
//         '_type' => 'Book',
//         '_class' => 'App\Models\Book',
//         '_data' => ['id' => 1, 'isbn' => '978-0201633610', 'pages' => 416, ...],
//         '_delegate' => null
//     ]
// ]

// Or as JSON string
$json = $book->to_json_with_delegates();
```

### Reconstruction on Another Server

```php
// Server A: Serialize
$json = $thing->to_json_with_delegates();
send_to_queue($json);

// Server B: Reconstruct
$data = json_decode(receive_from_queue(), true);
$thing = Thing::from_serialized($data);

// Or directly from JSON
$thing = Thing::from_json($json);

// The object is fully usable
echo $thing['name'];                    // Works
echo $thing->delegate()['isbn'];        // Works
echo $thing->formatted_isbn();          // Method delegation works
```

### N-Level Chain Serialization

Serialization works with any depth of delegation:

```php
// Three-level chain
$textbook = Thing::create_chain([
    'Thing'    => ['name' => 'Calculus'],
    'Book'     => ['isbn' => '978-1234567890'],
    'TextBook' => ['edition' => '5th'],
]);

$data = $textbook->to_array_with_delegates();
// [
//     '_type' => 'TextBook',
//     '_class' => 'App\Models\Thing',
//     '_data' => [...],
//     '_delegate' => [
//         '_type' => 'Book',
//         '_class' => 'App\Models\Book',
//         '_data' => [...],
//         '_delegate' => [
//             '_type' => 'TextBook',
//             '_class' => 'App\Models\TextBook',
//             '_data' => ['edition' => '5th', ...],
//             '_delegate' => null
//         ]
//     ]
// ]

// Reconstruct with full chain
$reconstructed = Thing::from_serialized($data);
echo $reconstructed->edition();  // '5th' - deep delegation works
```

### Transient Attributes

By default, transient (dot-prefixed) attributes are excluded from serialization:

```php
$book['.cached_score'] = 95;  // Transient attribute

// Default: exclude transient
$data = $book->to_array_with_delegates();
// '.cached_score' not included

// Include transient
$data = $book->to_array_with_delegates(true);
// '.cached_score' is included
```

### Security Considerations

When reconstructing from external data:

1. **Class validation**: `from_serialized()` validates that classes exist and extend `ActiveRow`
2. **No auto-save**: Reconstructed objects are in-memory only; call `save()` explicitly if needed
3. **Untrusted sources**: Be cautious with data from untrusted sources; validate before use

```php
// Safe: Class is validated
$thing = Thing::from_json($untrusted_json);
if ($thing === null) {
    // Invalid data
}

// Reconstructed object has exists() based on presence of 'id'
if ($thing->exists()) {
    // Has an ID, but NOT guaranteed to exist in YOUR database
}
```

## N-Level Chained Delegation

The DelegatedTypes trait supports arbitrary nesting depth. Each level can use `DelegatedTypes` to delegate to deeper levels.

### Three-Level Example: Thing → Book → TextBook

```
┌─────────────────────────────────────────────────────────────────────────┐
│                            things table                                  │
├─────────────────────────────────────────────────────────────────────────┤
│ id │ type      │ type_path                        │ name                │
├────┼───────────┼──────────────────────────────────┼─────────────────────┤
│ 1  │ TextBook  │ Thing/Book/TextBook              │ Calculus 101        │
│ 2  │ AudioBook │ Thing/Book/AudioBook             │ The Hobbit          │
└────┴───────────┴──────────────────────────────────┴─────────────────────┘

┌───────────────────────────────────────────────┐
│                 books table                    │
├───────────────────────────────────────────────┤
│ id │ thing_id │ isbn       │ pages │ type     │
├────┼──────────┼────────────┼───────┼──────────┤
│ 1  │ 1        │ 978-0...   │ 450   │ TextBook │
│ 2  │ 2        │ 978-1...   │ 310   │ AudioBook│
└────┴──────────┴────────────┴───────┴──────────┘

┌─────────────────────────────────┐  ┌─────────────────────────────────┐
│       textbooks table           │  │       audiobooks table          │
├─────────────────────────────────┤  ├─────────────────────────────────┤
│ id │ book_id │ edition │ grade │  │ id │ book_id │ duration │ narrator│
├────┼─────────┼─────────┼───────┤  ├────┼─────────┼──────────┼─────────┤
│ 1  │ 1       │ 5th     │ college│ │ 1  │ 2       │ 683      │ Andy S. │
└────┴─────────┴─────────┴───────┘  └────┴─────────┴──────────┴─────────┘
```

### Setting Up N-Level Classes

Each level uses the `DelegatedTypes` trait and defines only its **direct** children:

```php
// Level 1: Thing delegates to Book
class Thing extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'Book'      => Book::class,
            'TextBook'  => Book::class,  // Leaf types map to intermediate
            'AudioBook' => Book::class,
        ];
    }

    protected function get_delegate_foreign_key(): string
    {
        return 'thing_id';  // FK that Book uses
    }
}

// Level 2: Book delegates to TextBook/AudioBook
class Book extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'TextBook'  => TextBook::class,
            'AudioBook' => AudioBook::class,
        ];
    }

    protected function get_delegate_foreign_key(): string
    {
        return 'book_id';  // FK that TextBook/AudioBook uses
    }

    public function formatted_isbn(): string
    {
        return 'ISBN: ' . $this['isbn'];
    }
}

// Level 3: Leaf nodes don't need DelegatedTypes
class TextBook extends ActiveRow
{
    use Persistable;

    public function edition(): string
    {
        return $this['edition'] ?? '1st';
    }

    public function is_college_level(): bool
    {
        return $this['grade_level'] === 'college';
    }
}
```

### Creating N-Level Entities with create_chain()

The `create_chain()` method creates all levels atomically in a transaction:

```php
// Create a 3-level entity
$textbook = Thing::create_chain([
    'Thing'    => ['name' => 'Calculus: Early Transcendentals'],
    'Book'     => ['isbn' => '978-1285741550', 'pages' => 1344],
    'TextBook' => ['edition' => '8th', 'grade_level' => 'college'],
]);

// Creates:
// 1. Thing with type='TextBook', type_path='Thing/Book/TextBook'
// 2. Book with thing_id pointing to Thing
// 3. TextBook with book_id pointing to Book

// Works for any depth - 2 levels:
$person = Thing::create_chain([
    'Thing'  => ['name' => 'John Doe'],
    'Person' => ['given_name' => 'John', 'family_name' => 'Doe'],
]);

// Or 4+ levels if needed:
$item = Thing::create_chain([
    'Thing'      => [...],
    'Book'       => [...],
    'TextBook'   => [...],
    'MathBook'   => [...],  // If you had a 4th level
]);
```

### Chain Traversal

Navigate through the delegation chain easily:

```php
$thing = Thing::find_with_delegate(1);

// Get the full chain
$chain = $thing->get_chain();
// Returns: [Thing, Book, TextBook]

// Get the leaf (deepest delegate)
$leaf = $thing->leaf();
// Returns: TextBook instance

// Get depth
echo $thing->chain_depth();  // 3

// Get value from anywhere in chain
$thing->get_from_chain('name');      // From Thing
$thing->get_from_chain('isbn');      // From Book
$thing->get_from_chain('edition');   // From TextBook
```

### Deep Method Delegation

Methods are automatically delegated through the entire chain:

```php
$thing = Thing::find_with_delegate(1);  // A TextBook

// Methods are searched through the chain:
$thing->formatted_isbn();     // → Book::formatted_isbn()
$thing->edition();            // → TextBook::edition()
$thing->is_college_level();   // → TextBook::is_college_level()

// Magic type checks still work:
$thing->is_type('TextBook');        // true
$thing->is_in_hierarchy('Book');    // true (via type_path)
```

### Recursive Eager Loading

Load all levels with a single call:

```php
// Loads all levels automatically (default max_depth=10)
$things = Thing::find_with_delegates();

foreach ($things as $thing) {
    // All levels pre-loaded - no N+1 queries
    $chain = $thing->get_chain();  // Already loaded
}

// Control depth:
$things = Thing::find_with_delegates(['max_depth' => 1]);  // Thing → Book only
$things = Thing::find_with_delegates(['max_depth' => 2]);  // Thing → Book → TextBook

// Query efficiency:
// For 100 TextBooks: 4 queries total
// 1. SELECT * FROM things
// 2. SELECT * FROM books WHERE thing_id IN (...)
// 3. SELECT * FROM textbooks WHERE book_id IN (...)
// 4. SELECT * FROM audiobooks WHERE book_id IN (...)
```

### Updating and Deleting Chains

```php
// Update all levels atomically
$thing->update_chain([
    'Thing'    => ['name' => 'Calculus (9th Edition)'],
    'Book'     => ['pages' => 1400],
    'TextBook' => ['edition' => '9th'],
]);

// Delete all levels (deletes from leaf to root)
$thing->delete_chain();
```

### When to Use N-Level Delegation

| Scenario | Recommendation |
|----------|----------------|
| Schema.org-style deep hierarchies | Use N-level |
| Intermediate levels have many shared attributes | Use N-level |
| Need to query intermediate levels specifically | Use N-level |
| Only need 2 levels | Use simpler `create_with_delegate()` |
| Intermediate levels are purely conceptual | Use 2-level with traits for shared behavior |

## Schema.org Example

The `examples/DelegatedTypes/` directory contains a complete Schema.org-inspired implementation:

### Directory Structure

```
examples/DelegatedTypes/
├── Schema.php              # Database schema definition
├── Models/
│   ├── Thing.php          # Base class with DelegatedTypes
│   ├── Book.php           # Book delegate (CreativeWork)
│   ├── Movie.php          # Movie delegate (CreativeWork)
│   ├── Article.php        # Article delegate (CreativeWork)
│   ├── Person.php         # Person delegate (Agent)
│   ├── Organization.php   # Organization delegate (Agent)
│   └── Contribution.php   # Polymorphic author relations
├── Traits/
│   ├── CreativeWorkBehavior.php  # Shared behavior for creative works
│   └── AgentBehavior.php         # Shared behavior for agents
├── delegated_types_example.php   # Usage examples
└── delegated_types_test.php      # Test suite (48 tests)
```

### Usage Example

```php
// Create entities
$book = Thing::create_book(
    ['name' => 'Design Patterns', 'description' => 'A classic...'],
    ['isbn' => '978-0201633610', 'number_of_pages' => 416]
);

$author = Thing::create_person(
    ['name' => 'Erich Gamma'],
    ['given_name' => 'Erich', 'family_name' => 'Gamma']
);

// Type checking
$book->is_book();           // true
$book->is_creative_work();  // true
$book->is_agent();          // false

// Access delegate
$delegate = $book->delegate();  // Returns Book instance
echo $delegate->pages();        // 416
echo $delegate->formatted_isbn(); // 978-0-201-63361-0

// Method delegation (automatic)
echo $book->pages();            // Works directly on Thing

// Query by type
$books = Thing::find_by_type('Book');
$creative_works = Thing::find_creative_works();
$agents = Thing::find_agents();
```

## Eager Loading

Eager loading prevents the N+1 query problem when loading multiple things with their delegates.

### Without Eager Loading (N+1 Problem)

```php
// BAD: 1 query for things + N queries for delegates
$things = Thing::find_all();
foreach ($things as $thing) {
    echo $thing->delegate()->pages();  // Each triggers a query!
}
```

### With Eager Loading

```php
// GOOD: 1 query for things + 1 query per type
$things = Thing::find_with_delegates();
foreach ($things as $thing) {
    echo $thing->delegate()->pages();  // Already loaded!
}

// Or manually eager load
$things = Thing::find_all();
$things = Thing::eager_load_delegates($things);
```

### How It Works

The `eager_load_delegates()` method:
1. Groups things by type
2. Executes one query per type using `IN (...)` clause
3. Attaches delegates to their parent things

```php
// For 100 things (50 Books, 30 Movies, 20 Persons):
// Only 4 queries total: 1 for things + 1 for books + 1 for movies + 1 for persons
```

## Polymorphic Contributions

The Schema.org example includes a `contributions` table for author/creator relationships:

```php
// Contributions table links Agents to CreativeWorks
$contributions = new Table('contributions', [
    'id'       => bigint()->primary_key()->auto_increment(),
    'work_id'  => bigint()->not_null(),  // FK to things (CreativeWork)
    'agent_id' => bigint()->not_null(),  // FK to things (Person/Org)
    'role'     => varchar(50)->not_null(),
    'position' => integer()->default(0),
]);
```

### Adding Authors to a Book

```php
// In CreativeWorkBehavior trait
public function add_author(Thing $agent, int $position = 0): Contribution
{
    return Contribution::create([
        'work_id'  => $this->thing_id(),
        'agent_id' => $agent['id'],
        'role'     => 'author',
        'position' => $position,
    ]);
}

// Usage
$book->add_author($author1, 0);
$book->add_author($author2, 1);
$book->add_author($publisher, 2);  // Organization as author

// Get authors
$authors = $book->authors();  // Returns array of Thing (Person/Organization)
echo $book->authors_string(); // "Erich Gamma, Richard Helm, Addison-Wesley"
```

### Finding Works by Author

```php
// In AgentBehavior trait
public function authored_works(): array
{
    return Contribution::find_works_by_agent($this->thing_id(), 'author');
}

// Usage
$author = Thing::find_by_type('Person')[0];
$works = $author->delegate()->authored_works();
```

## Best Practices

### 1. Use Denormalized Flags for Fast Queries

```php
// Instead of joining type tables, use flags
$things = new Table('things', [
    // ...
    'is_creative_work' => boolean()->default(false),
    'is_agent'         => boolean()->default(false),
]);

// Fast queries without joins
$db->query_table($things)->where(eq($things->is_creative_work, 1))->find_many();
```

### 2. Use Type Path for Hierarchy Queries

```php
// Store: "Thing/CreativeWork/Book"
$thing->is_in_hierarchy('CreativeWork');  // true for Books, Movies, Articles
```

### 3. Create Factory Methods

```php
class Thing extends ActiveRow
{
    public static function create_book(array $thing, array $book = []): static
    {
        return static::create_with_delegate('Book', $thing, $book);
    }

    public static function create_person(array $thing, array $person = []): static
    {
        return static::create_with_delegate('Person', $thing, $person);
    }
}
```

### 4. Use Shared Behavior Traits

```php
// CreativeWorkBehavior.php - shared by Book, Movie, Article
trait CreativeWorkBehavior
{
    public function authors(): array { /* ... */ }
    public function add_author(Thing $agent): Contribution { /* ... */ }
    public function authors_string(): string { /* ... */ }
}

// Book, Movie, Article all use this trait
class Book extends ActiveRow
{
    use Persistable, CreativeWorkBehavior;
}
```

### 5. Index Important Columns

```php
$things->add_index('idx_things_type', ['type']);
$things->add_index('idx_things_type_path', ['type_path']);
$things->add_index('idx_things_is_creative_work', ['is_creative_work']);
$books->add_index('idx_books_thing_id', ['thing_id']);
```

## Comparison with Other Patterns

### vs. Single Table Inheritance (STI)

| Aspect | Delegated Types | STI |
|--------|-----------------|-----|
| Schema | Multiple tables | Single table |
| NULL columns | None | Many (unused by most types) |
| Type-specific constraints | Supported | Difficult |
| Query performance | May require joins | Fast single-table |
| Adding new types | Add table | Add columns |
| Disk space | Efficient | Wasted on NULLs |

**Choose Delegated Types when**: Types have many unique attributes
**Choose STI when**: Types share 90%+ of attributes

### vs. Class Table Inheritance (CTI)

| Aspect | Delegated Types | CTI |
|--------|-----------------|-----|
| Base table | Contains shared attrs + type | Contains shared attrs |
| Delegate FK | `thing_id` in delegate | `id` matches parent |
| Query flexibility | Query base table easily | Must join for full data |
| Identity | Separate IDs | Shared ID |

**Choose Delegated Types when**: You need to query across types frequently
**Choose CTI when**: Types are mostly accessed independently

### vs. Polymorphic Relations

| Aspect | Delegated Types | Polymorphic Relations |
|--------|-----------------|----------------------|
| Purpose | Model type hierarchies | Model "belongs to many types" |
| FK location | Delegate has FK to base | Polymorphic table has type + FK |
| Querying | Easy across types | Complex across types |

**Choose Delegated Types when**: Modeling "is-a" hierarchies (Book IS A CreativeWork)
**Choose Polymorphic when**: Modeling "belongs-to" relationships (Comment BELONGS TO Post OR Video)

## Running the Example

```bash
# Run the complete test suite (48 tests)
php examples/DelegatedTypes/delegated_types_test.php

# Run the usage examples
php examples/DelegatedTypes/delegated_types_example.php
```

## See Also

- [ActiveRow API Reference](REFERENCE_MANUAL.md#activerow-lightweight-active-record) - ActiveRow documentation
- [Multi-Database Guide](MULTI_DATABASE_GUIDE.md) - Writing portable schemas
- [Relations Documentation](../README.md#relations-drizzle-style) - Drizzle-style relations
