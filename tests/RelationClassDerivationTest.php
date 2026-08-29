<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — ActiveRow's $relation_classes derived from RelationsRegistry
 *
 * Before this, which class wraps a relation's rows was named twice: once
 * implicitly, by `define_relations()` (what `with()` fetches), and again by
 * hand in `protected static $relation_classes = [...]` on the ActiveRow
 * subclass — nothing checked the two agreed, and a class that forgot to
 * list a relation there simply never wrapped it, silently.
 *
 * `resolved_relation_classes()` now derives an entry for any relation
 * `$relation_classes` did not already name, by asking `RelationsRegistry`
 * what the relation targets and `ActiveRowRegistry` which class is bound to
 * that target table.
 *
 * Asserted end to end (array access AND the explicit relation() call, both
 * of which read the same resolved map now) rather than only at the map
 * level — a map with the right entry and a wrapping path still reading the
 * old $relation_classes would look identical from outside offsetGet().
 *
 * One-to-many, many-to-one and `many_polymorphic` are all derived, because
 * all three have exactly one real target table. `one_polymorphic` is the
 * one excluded — a row's polymorphic parent can genuinely be more than one
 * type, so there is no single class to guess. That distinction was found
 * empirically while writing this suite, not designed in advance: an early
 * version of this test used `many_polymorphic` for the "must not be
 * derived" case, and it failed — correctly, because `many_polymorphic` is
 * safe to derive and the test's expectation was the part that was wrong.
 *
 * Run: php src/Libs/Italix/Orm/tests/RelationClassDerivationTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - ActiveRow relation class derivation');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$authors = sqlite_table('authors', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
]);
$books = sqlite_table('books', [
    'id'        => integer()->primary_key()->auto_increment(),
    'author_id' => integer()->not_null(),
    'title'     => varchar(255)->not_null(),
]);
$notes = sqlite_table('notes', [
    'id'           => integer()->primary_key()->auto_increment(),
    'notable_type' => varchar(20)->not_null(),
    'notable_id'   => integer()->not_null(),
    'body'         => varchar(255)->not_null(),
]);
// A row of this can belong to a Book OR an Author — the genuinely ambiguous
// case, where deriving a single class would be a guess.
$likes = sqlite_table('likes', [
    'id'            => integer()->primary_key()->auto_increment(),
    'likeable_type' => varchar(20)->not_null(),
    'likeable_id'   => integer()->not_null(),
]);

define_relations($authors, function ($r) use ($authors, $books) {
    return [
        'books' => $r->many($books, [
            'fields'     => [$authors->id],
            'references' => [$books->author_id],
        ]),
    ];
});
// One define_relations() call per table — a second call for the same table
// replaces the first rather than merging with it, so every relation for a
// table has to be declared together.
define_relations($books, function ($r) use ($books, $authors, $notes, $likes) {
    return [
        'author' => $r->one($authors, [
            'fields'     => [$books->author_id],
            'references' => [$authors->id],
        ]),
        // Single concrete target ('book') — not ambiguous, derivable.
        'notes' => $r->many_polymorphic($notes, [
            'type_column' => $notes->notable_type,
            'id_column'   => $notes->notable_id,
            'type_value'  => 'book',
            'references'  => [$books->id],
        ]),
        // Reverse side of the genuinely ambiguous relation below, so a Book
        // can ask for its own likes too — unused by the assertions, kept
        // only so define_relations() has a symmetric, realistic shape.
        'likes' => $r->many_polymorphic($likes, [
            'type_column' => $likes->likeable_type,
            'id_column'   => $likes->likeable_id,
            'type_value'  => 'book',
            'references'  => [$books->id],
        ]),
    ];
});
define_relations($likes, function ($r) use ($likes, $books, $authors) {
    return [
        'likeable' => $r->one_polymorphic([
            'type_column' => $likes->likeable_type,
            'id_column'   => $likes->likeable_id,
            'targets'     => ['book' => $books, 'author' => $authors],
        ]),
    ];
});

class AuthorRow extends ActiveRow
{
    use Persistable;
    // No $relation_classes override — this is exactly what is being tested.
    protected static $auto_wrap_relations = true;
}

class BookRow extends ActiveRow
{
    use Persistable;

    protected static $relation_classes = ['notes' => 'NonExistentNoteRow'];
}

class NoteRow extends ActiveRow
{
    use Persistable;
}

class LikeRow extends ActiveRow
{
    use Persistable;
}

$dm = sqlite_memory();
$dm->create_tables($authors, $books, $notes, $likes);

AuthorRow::set_persistence($dm, $authors);
BookRow::set_persistence($dm, $books);
NoteRow::set_persistence($dm, $notes);
LikeRow::set_persistence($dm, $likes);

$author = AuthorRow::create(['name' => 'Ursula K. Le Guin']);
BookRow::create(['author_id' => $author['id'], 'title' => 'The Left Hand of Darkness']);
BookRow::create(['author_id' => $author['id'], 'title' => 'The Dispossessed']);

// -----------------------------------------------------------------------------
section('a one-to-many relation with no $relation_classes entry is still wrapped');

$loaded = $dm->query_table($authors)->with(['books' => true])->find($author['id']);
$wrapped_author = AuthorRow::wrap($loaded);

$wrapped_books = $wrapped_author->relation('books');
test('relation(\'books\') returns an array', is_array($wrapped_books));
test('…of two rows', count($wrapped_books) === 2);
test('…each one a real BookRow, not a plain array', $wrapped_books[0] instanceof BookRow);
test('…with the right data', $wrapped_books[0]['title'] === 'The Left Hand of Darkness');

// Array access goes through offsetGet(), a different path than relation() —
// asserted separately since offsetGet() has its own gate to derive through.
test('array access ($author[\'books\']) is wrapped identically', $wrapped_author['books'][0] instanceof BookRow);

// -----------------------------------------------------------------------------
section('a many-to-one relation is derived the same way, the other direction');

$book_loaded = $dm->query_table($books)->with(['author' => true])->find_first();
$wrapped_book = BookRow::wrap($book_loaded);

$wrapped_book_author = $wrapped_book->relation('author');
test('relation(\'author\') is wrapped', $wrapped_book_author instanceof AuthorRow);
test('…with the right data', $wrapped_book_author['name'] === 'Ursula K. Le Guin');

// -----------------------------------------------------------------------------
section('an explicit $relation_classes entry still wins over derivation');

// BookRow explicitly (and wrongly, on purpose) names 'NonExistentNoteRow'
// for 'notes' — a real class is never even looked up for it.
$reflect = new ReflectionMethod(BookRow::class, 'resolved_relation_classes');
$reflect->setAccessible(true);
$resolved = $reflect->invoke(null);

test('the explicit override is what resolved_relation_classes() reports', $resolved['notes'] === 'NonExistentNoteRow');
test('…and "author" was still derived, since nothing overrides it', $resolved['author'] === AuthorRow::class);

// -----------------------------------------------------------------------------
section('many_polymorphic has one real target and is safely derived');

class PlainBookRow extends ActiveRow
{
    use Persistable;
    // No override at all this time — a class that never mentions 'notes'.
}
PlainBookRow::set_persistence($dm, $books);

$plain_reflect = new ReflectionMethod(PlainBookRow::class, 'resolved_relation_classes');
$plain_reflect->setAccessible(true);
$plain_resolved = $plain_reflect->invoke(null);

test('"notes" (many_polymorphic, one concrete target) is derived to NoteRow', ($plain_resolved['notes'] ?? null) === NoteRow::class);
test('"author" (ordinary) is still derived on this class', $plain_resolved['author'] === AuthorRow::class);

// -----------------------------------------------------------------------------
section('one_polymorphic is the genuinely ambiguous case, and is never auto-derived');

$like_reflect = new ReflectionMethod(LikeRow::class, 'resolved_relation_classes');
$like_reflect->setAccessible(true);
$like_resolved = $like_reflect->invoke(null);

test(
    '"likeable" (one_polymorphic — could be a BookRow or an AuthorRow) has no derived entry',
    !isset($like_resolved['likeable'])
);

exit(summary());
