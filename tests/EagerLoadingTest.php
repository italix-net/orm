<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — relations, loaded
 *
 * `RelationsTest` asserts that relations are *declared* correctly: the right
 * type, the right through-table, the right inverse. This asserts that they
 * *load* — which is a different question and was the one with a bug in it.
 *
 * Eager loading works by reading a key out of each parent row and looking
 * children up by it. Narrow the select with `columns()` and that key is not
 * fetched, so nothing matches, every relation comes back empty, and **nothing
 * raises**. A query asking for a name and its posts returns the name and no
 * posts, which reads like missing data rather than a missing column.
 *
 * That is why almost every assertion here narrows the columns. The unnarrowed
 * path already worked.
 *
 * Run: php src/Libs/Italix/Orm/tests/EagerLoadingTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',               // checked out on its own
        __DIR__ . '/../../../../../vendor/autoload.php',   // vendored in a project
        __DIR__ . '/../../../../vendor/autoload.php',      // installed as a package
        __DIR__ . '/../../../autoload.php',                // sibling autoloader
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\Relations\RelationalQueryBuilder;

use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Schema\{integer, serial, sqlite_table, varchar};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - eager loading');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, invited_by INTEGER)');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INTEGER, reviewer_id INTEGER, title TEXT)');
$pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT)');
$pdo->exec('CREATE TABLE post_tags (post_id INTEGER, tag_id INTEGER)');
$pdo->exec("INSERT INTO users VALUES (1,'Anna',NULL),(2,'Bruno',1),(3,'Carla',1)");
$pdo->exec("INSERT INTO posts VALUES (1,1,2,'primo'),(2,1,3,'secondo'),(3,2,1,'terzo')");
$pdo->exec("INSERT INTO tags VALUES (1,'php'),(2,'sql')");
$pdo->exec('INSERT INTO post_tags VALUES (1,1),(1,2),(2,2)');

$users = sqlite_table('users', ['id' => serial(), 'name' => varchar(50), 'invited_by' => integer()]);
$posts = sqlite_table('posts', ['id' => serial(), 'author_id' => integer(),
                                'reviewer_id' => integer(), 'title' => varchar(50)]);
$tags  = sqlite_table('tags', ['id' => serial(), 'label' => varchar(30)]);
$pivot = sqlite_table('post_tags', ['post_id' => integer(), 'tag_id' => integer()]);

$registry = [
    'users' => define_relations($users, static function ($r) use ($users, $posts) {
        return [
            'posts'   => $r->many($posts, ['fields' => [$users->id], 'references' => [$posts->author_id]]),
            // self-referencing: who this user invited
            'invited' => $r->many($users, ['fields' => [$users->id], 'references' => [$users->invited_by]]),
        ];
    }),
    'posts' => define_relations($posts, static function ($r) use ($users, $posts, $tags, $pivot) {
        return [
            // two relations to the same table, told apart by name
            'author'   => $r->one($users, ['fields' => [$posts->author_id], 'references' => [$users->id]]),
            'reviewer' => $r->one($users, ['fields' => [$posts->reviewer_id], 'references' => [$users->id]]),
            'tags'     => $r->many($tags, [
                'through'           => $pivot,
                'through_fields'    => [$pivot->post_id],
                'target_fields'     => [$pivot->tag_id],
                'fields'            => [$posts->id],
                'target_references' => [$tags->id],
            ]),
        ];
    }),
];

$q = new RelationalQueryBuilder($pdo, 'sqlite', $registry);

/** The value of one column across rows, as a comma-joined string. */
$col = static function (array $rows, string $name): string {
    return implode(',', array_column($rows, $name));
};

// -----------------------------------------------------------------------------
section('every relation kind loads');

$rows = $q->query($users)->with(['posts' => []])->find_many();

test('one-to-many attaches children to the right parent',
    count($rows[0]['posts']) === 2 && count($rows[1]['posts']) === 1 && $rows[2]['posts'] === [],
    json_encode(array_map(static function (array $r): int { return count($r['posts']); }, $rows)));

$post = $q->query($posts)->with(['author' => []])->find_first();

test('many-to-one attaches a single row, not a list',
    isset($post['author']['name']) && $post['author']['name'] === 'Anna', json_encode($post['author']));

test('a parent with no children gets an empty list, not null',
    $q->query($users)->with(['posts' => []])->find_many()[2]['posts'] === []);

test('MANY-TO-MANY RESOLVES THROUGH THE JUNCTION TABLE',
    $col($q->query($posts)->with(['tags' => []])->find_first()['tags'], 'label') === 'php,sql');

test('a self-referencing relation works — the table is its own target',
    $col($q->query($users)->with(['invited' => []])->find_first()['invited'], 'name') === 'Bruno,Carla');

$post = $q->query($posts)->with(['author' => [], 'reviewer' => []])->find_first();

test('TWO RELATIONS TO THE SAME TABLE STAY APART',
    $post['author']['name'] === 'Anna' && $post['reviewer']['name'] === 'Bruno',
    'one overwrote the other: ' . json_encode([$post['author'] ?? null, $post['reviewer'] ?? null]));

$nested = $q->query($users)->with(['posts' => ['with' => ['tags' => []]]])->find_first();

test('with() nests to any depth',
    $col($nested['posts'][0]['tags'], 'label') === 'php,sql', json_encode($nested['posts'][0] ?? null));

// -----------------------------------------------------------------------------
section('NARROWING THE COLUMNS MUST NOT EMPTY THE RELATIONS');

// The bug this suite was written for. `columns(['name'])` did not fetch `id`,
// the loader had nothing to match on, and every relation came back empty with
// no error at all.
$rows = $q->query($users)->columns(['name'])->with(['posts' => []])->find_many();

test('a narrowed parent still loads its children',
    count($rows[0]['posts']) === 2,
    'the join key was not selected, so nothing matched — and nothing said so');

test('…and the parent still has only what was asked for',
    array_keys(array_diff_key($rows[0], ['posts' => 1])) === ['name'],
    'the key fetched for matching leaked into the result: ' . json_encode(array_keys($rows[0])));

// The same failure on the child side.
$rows = $q->query($users)->columns(['name'])->with(['posts' => ['columns' => ['title']]])->find_many();

test('A NARROWED CHILD IS STILL ATTACHED',
    $col($rows[0]['posts'], 'title') === 'primo,secondo',
    'the child was fetched without the column it is matched on');

test('…and the child has only what was asked for',
    array_keys($rows[0]['posts'][0]) === ['title'], json_encode(array_keys($rows[0]['posts'][0])));

// Asking for the key explicitly must keep it.
$rows = $q->query($users)->columns(['id', 'name'])
    ->with(['posts' => ['columns' => ['author_id', 'title']]])->find_many();

test('a key the caller did ask for is kept on the parent',
    array_key_exists('id', $rows[0]), json_encode(array_keys($rows[0])));
test('…and on the child', array_key_exists('author_id', $rows[0]['posts'][0]),
    json_encode(array_keys($rows[0]['posts'][0])));

// Every relation kind, narrowed.
test('many-to-one survives narrowing',
    $q->query($posts)->columns(['title'])->with(['author' => ['columns' => ['name']]])
      ->find_first()['author'] === ['name' => 'Anna']);

test('many-to-many survives narrowing',
    $col($q->query($posts)->columns(['title'])->with(['tags' => ['columns' => ['label']]])
        ->find_first()['tags'], 'label') === 'php,sql');

test('a self-reference survives narrowing',
    $col($q->query($users)->columns(['name'])->with(['invited' => ['columns' => ['name']]])
        ->find_first()['invited'], 'name') === 'Bruno,Carla');

test('two relations to one table survive narrowing', (static function () use ($q, $posts): bool {
    $row = $q->query($posts)->columns(['title'])
        ->with(['author' => ['columns' => ['name']], 'reviewer' => ['columns' => ['name']]])
        ->find_first();

    return ($row['author']['name'] ?? null) === 'Anna' && ($row['reviewer']['name'] ?? null) === 'Bruno';
})());

// -----------------------------------------------------------------------------
section('filtering a relation');

$rows = $q->query($users)->columns(['name'])
    ->with(['posts' => ['columns' => ['title'], 'limit' => 1]])->find_many();

test('limit applies to the children of each parent',
    count($rows[0]['posts']) === 1, json_encode($rows[0]['posts']));

// The failure this replaced: a LIMIT on the batched child query caps the whole
// fetch, so the first parent takes the allowance and every other parent gets
// nothing. Alice has two posts and Bob has one; with a global LIMIT 1, Bob's
// list came back empty.
test('EVERY PARENT GETS ITS OWN ALLOWANCE, not a share of one',
    count($rows[0]['posts']) === 1 && count($rows[1]['posts']) === 1,
    'the limit was applied across the whole fetch: ' . json_encode(array_column($rows, 'posts')));

test('…and a parent with fewer children than the limit keeps them all',
    count($q->query($users)->columns(['name'])
        ->with(['posts' => ['columns' => ['title'], 'limit' => 10]])->find_many()[0]['posts']) === 2);

test('…and a parent with none still gets an empty list',
    $q->query($users)->columns(['name'])
        ->with(['posts' => ['columns' => ['title'], 'limit' => 1]])->find_many()[2]['posts'] === []);

// order_by decides *which* ones survive the cap, so the two must work together.
$capped = $q->query($users)->columns(['name'])
    ->with(['posts' => ['columns' => ['title'],
                        'order_by' => [\Italix\Orm\Operators\desc($posts->title)],
                        'limit' => 1]])->find_many();

test('ORDER BY DECIDES WHICH CHILD SURVIVES THE CAP',
    $capped[0]['posts'][0]['title'] === 'secondo',
    'capped before ordering, so the wrong row was kept: ' . json_encode($capped[0]['posts']));

// many-to-many takes the same treatment.
$tagged = $q->query($posts)->columns(['title'])
    ->with(['tags' => ['columns' => ['label'], 'limit' => 1]])->find_many();

test('a many-to-many relation is capped per parent too',
    count($tagged[0]['tags']) === 1 && count($tagged[1]['tags']) === 1,
    json_encode(array_column($tagged, 'tags')));

$rows = $q->query($users)->columns(['name'])
    ->with(['posts' => ['columns' => ['title'],
                        'order_by' => [\Italix\Orm\Operators\desc($posts->title)]]])->find_many();

test('order_by applies within a parent',
    $col($rows[0]['posts'], 'title') === 'secondo,primo', json_encode($rows[0]['posts']));

$rows = $q->query($users)->columns(['name'])
    ->with(['posts' => ['columns' => ['title'],
                        'where' => \Italix\Orm\Operators\eq($posts->title, 'primo')]])->find_many();

test('where narrows the children, not the parents',
    count($rows) === 3 && $col($rows[0]['posts'], 'title') === 'primo',
    json_encode(array_column($rows, 'posts')));

exit(summary());
