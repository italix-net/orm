<?php
/**
 * Italix ORM - Relations System Test
 *
 * Comprehensive test of the relations system including:
 * - One-to-one relations
 * - One-to-many relations
 * - Many-to-many relations
 * - Polymorphic relations
 * - Eager loading with 'with'
 * - Nested relations
 * - Relation aliases
 */

require_once __DIR__ . '/../src/autoload.php';

use Italix\Orm\Relations\RelationsRegistry;
use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, boolean};
use function Italix\Orm\Relations\{define_relations, get_relations, get_relation};
use function Italix\Orm\Operators\{eq, desc};

echo "============================================\n";
echo "  Italix ORM - Relations System Test\n";
echo "============================================\n\n";

$passed = 0;
$failed = 0;

function test($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: {$name}\n";
        $passed++;
    } else {
        echo "✗ FAIL: {$name}\n";
        if ($details) {
            echo "        Details: {$details}\n";
        }
        $failed++;
    }
}

// Reset registry for clean testing
RelationsRegistry::reset();

// ============================================
// Define Tables
// ============================================

echo "Setting up test tables...\n\n";

$users = sqlite_table('users', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255)->not_null(),
]);

$profiles = sqlite_table('profiles', [
    'id' => integer()->primary_key()->auto_increment(),
    'user_id' => integer()->not_null(),
    'bio' => text(),
]);

$posts = sqlite_table('posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'author_id' => integer()->not_null(),
    'title' => varchar(255)->not_null(),
    'published' => boolean()->default(false),
]);

$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'post_id' => integer()->not_null(),
    'user_id' => integer()->not_null(),
    'content' => text()->not_null(),
]);

$tags = sqlite_table('tags', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);

$post_tags = sqlite_table('post_tags', [
    'post_id' => integer()->not_null(),
    'tag_id' => integer()->not_null(),
]);

// Polymorphic tables
$media = sqlite_table('media', [
    'id' => integer()->primary_key()->auto_increment(),
    'mediable_type' => varchar(50)->not_null(),
    'mediable_id' => integer()->not_null(),
    'url' => varchar(500)->not_null(),
]);

// ============================================
// Test 1: Define Relations
// ============================================

echo "--- Test Group: Defining Relations ---\n";

$users_relations = define_relations($users, function($r) use ($users, $profiles, $posts, $comments) {
    return [
        // One-to-one: users.id -> profiles.user_id
        'profile' => $r->one($profiles, [
            'fields' => [$users->id],           // Source column (PK)
            'references' => [$profiles->user_id], // Target column (FK)
        ]),
        // One-to-many: users.id -> posts.author_id
        'posts' => $r->many($posts, [
            'fields' => [$users->id],
            'references' => [$posts->author_id],
        ]),
        // One-to-many: users.id -> comments.user_id
        'comments' => $r->many($comments, [
            'fields' => [$users->id],
            'references' => [$comments->user_id],
        ]),
    ];
});

test('define_relations returns TableRelations', $users_relations !== null);
test('TableRelations has profile relation', $users_relations->has('profile'));
test('TableRelations has posts relation', $users_relations->has('posts'));
test('TableRelations has comments relation', $users_relations->has('comments'));

$profiles_relations = define_relations($profiles, function($r) use ($users, $profiles) {
    return [
        // Many-to-one: profiles.user_id -> users.id
        'user' => $r->one($users, [
            'fields' => [$profiles->user_id],   // Source FK
            'references' => [$users->id],       // Target PK
        ]),
    ];
});

test('Profile has user relation', $profiles_relations->has('user'));

$posts_relations = define_relations($posts, function($r) use ($users, $posts, $comments, $tags, $post_tags, $media) {
    return [
        // Many-to-one: posts.author_id -> users.id
        'author' => $r->one($users, [
            'fields' => [$posts->author_id],    // Source FK
            'references' => [$users->id],       // Target PK
        ]),
        // One-to-many: posts.id -> comments.post_id
        'comments' => $r->many($comments, [
            'fields' => [$posts->id],           // Source PK
            'references' => [$comments->post_id], // Target FK
        ]),
        // Many-to-many: posts <-> tags through post_tags
        'tags' => $r->many($tags, [
            'fields' => [$posts->id],
            'through' => $post_tags,
            'through_fields' => [$post_tags->post_id],
            'target_fields' => [$post_tags->tag_id],
            'target_references' => [$tags->id],
        ]),
        // Polymorphic many: posts.id -> media where type='post'
        'media' => $r->many_polymorphic($media, [
            'type_column' => $media->mediable_type,
            'id_column' => $media->mediable_id,
            'type_value' => 'post',
            'references' => [$posts->id],
        ]),
    ];
});

test('Post has author relation', $posts_relations->has('author'));
test('Post has comments relation', $posts_relations->has('comments'));
test('Post has tags relation (many-to-many)', $posts_relations->has('tags'));
test('Post has media relation (polymorphic)', $posts_relations->has('media'));

echo "\n";

// ============================================
// Test 2: Relation Types
// ============================================

echo "--- Test Group: Relation Types ---\n";

$profile_rel = $users_relations->get('profile');
test('Profile relation is One type', $profile_rel->get_type() === 'one');
test('Profile relation is_one() returns true', $profile_rel->is_one());
test('Profile relation is_many() returns false', !$profile_rel->is_many());

$posts_rel = $users_relations->get('posts');
test('Posts relation is Many type', $posts_rel->get_type() === 'many');
test('Posts relation is_many() returns true', $posts_rel->is_many());
test('Posts relation is_one() returns false', !$posts_rel->is_one());

$tags_rel = $posts_relations->get('tags');
test('Tags relation is many-to-many', $tags_rel->is_many_to_many());
test('Tags relation has through table', $tags_rel->get_through_table() !== null);

$media_rel = $posts_relations->get('media');
test('Media relation is polymorphic_many type', $media_rel->get_type() === 'polymorphic_many');

echo "\n";

// ============================================
// Test 3: Global Registry
// ============================================

echo "--- Test Group: Relations Registry ---\n";

$registry = RelationsRegistry::get_instance();
test('Registry has users relations', $registry->has($users));
test('Registry has profiles relations', $registry->has($profiles));
test('Registry has posts relations', $registry->has($posts));

$retrieved = get_relations($users);
test('get_relations() retrieves relations', $retrieved !== null);
test('get_relations() returns correct table', $retrieved->get_table() === $users);

$profile_rel = get_relation($users, 'profile');
test('get_relation() retrieves specific relation', $profile_rel !== null);
test('get_relation() returns correct relation', $profile_rel->get_name() === 'profile');

$non_existent = get_relation($users, 'nonexistent');
test('get_relation() returns null for non-existent', $non_existent === null);

echo "\n";

// ============================================
// Test 4: Query Execution
// ============================================

echo "--- Test Group: Query Execution ---\n";

$db = sqlite_memory();
$db->create_tables($users, $profiles, $posts, $comments, $tags, $post_tags, $media);

// Insert test data
$db->insert($users)->values([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
])->execute();

$db->insert($profiles)->values([
    ['user_id' => 1, 'bio' => 'Alice bio'],
    ['user_id' => 2, 'bio' => 'Bob bio'],
])->execute();

$db->insert($posts)->values([
    ['author_id' => 1, 'title' => 'Post 1', 'published' => true],
    ['author_id' => 1, 'title' => 'Post 2', 'published' => false],
    ['author_id' => 2, 'title' => 'Post 3', 'published' => true],
])->execute();

$db->insert($comments)->values([
    ['post_id' => 1, 'user_id' => 2, 'content' => 'Comment 1'],
    ['post_id' => 1, 'user_id' => 1, 'content' => 'Comment 2'],
    ['post_id' => 2, 'user_id' => 2, 'content' => 'Comment 3'],
])->execute();

$db->insert($tags)->values([
    ['name' => 'php'],
    ['name' => 'orm'],
])->execute();

$db->insert($post_tags)->values([
    ['post_id' => 1, 'tag_id' => 1],
    ['post_id' => 1, 'tag_id' => 2],
    ['post_id' => 2, 'tag_id' => 1],
])->execute();

$db->insert($media)->values([
    ['mediable_type' => 'post', 'mediable_id' => 1, 'url' => '/img/post1.jpg'],
    ['mediable_type' => 'post', 'mediable_id' => 1, 'url' => '/img/post1b.jpg'],
])->execute();

// Test find_many
$all_users = $db->query_table($users)->find_many();
test('find_many() returns all users', count($all_users) === 2);

// Test find_first
$first_user = $db->query_table($users)->find_first();
test('find_first() returns one user', $first_user !== null);
test('find_first() returns array', is_array($first_user));

// Test find_one (alias)
$one_user = $db->query_table($users)->find_one();
test('find_one() works as alias', $one_user !== null);

// Test where
$alice = $db->query_table($users)->where(eq($users->name, 'Alice'))->find_first();
test('where() filters correctly', $alice['name'] === 'Alice');

// Test limit/offset
$limited = $db->query_table($users)->limit(1)->find_many();
test('limit() works', count($limited) === 1);

echo "\n";

// ============================================
// Test 5: Eager Loading (with)
// ============================================

echo "--- Test Group: Eager Loading ---\n";

// One-to-one eager loading
$users_with_profile = $db->query_table($users)
    ->with(['profile' => true])
    ->find_many();

test('Eager loads one-to-one relation', isset($users_with_profile[0]['profile']));
test('One-to-one relation is array', is_array($users_with_profile[0]['profile']));
test('One-to-one relation has data', $users_with_profile[0]['profile']['bio'] === 'Alice bio');

// One-to-many eager loading
$users_with_posts = $db->query_table($users)
    ->with(['posts' => true])
    ->where(eq($users->id, 1))
    ->find_first();

test('Eager loads one-to-many relation', isset($users_with_posts['posts']));
test('One-to-many relation is array of arrays', is_array($users_with_posts['posts']));
test('One-to-many returns correct count', count($users_with_posts['posts']) === 2);

// Many-to-one eager loading
$posts_with_author = $db->query_table($posts)
    ->with(['author' => true])
    ->find_many();

test('Eager loads many-to-one relation', isset($posts_with_author[0]['author']));
test('Many-to-one relation has correct data', $posts_with_author[0]['author']['name'] === 'Alice');

// Many-to-many eager loading
$posts_with_tags = $db->query_table($posts)
    ->with(['tags' => true])
    ->where(eq($posts->id, 1))
    ->find_first();

test('Eager loads many-to-many relation', isset($posts_with_tags['tags']));
test('Many-to-many returns correct count', count($posts_with_tags['tags']) === 2);

// Polymorphic many eager loading
$posts_with_media = $db->query_table($posts)
    ->with(['media' => true])
    ->where(eq($posts->id, 1))
    ->find_first();

test('Eager loads polymorphic many relation', isset($posts_with_media['media']));
test('Polymorphic many returns correct count', count($posts_with_media['media']) === 2);

echo "\n";

// ============================================
// Test 6: Nested Relations
// ============================================

echo "--- Test Group: Nested Relations ---\n";

$users_nested = $db->query_table($users)
    ->with([
        'posts' => [
            'with' => ['comments' => true]
        ]
    ])
    ->where(eq($users->id, 1))
    ->find_first();

test('Loads nested relations', isset($users_nested['posts'][0]['comments']));
test('Nested relation has data', count($users_nested['posts'][0]['comments']) === 2);

echo "\n";

// ============================================
// Test 7: Relation Aliases
// ============================================

echo "--- Test Group: Relation Aliases ---\n";

$posts_aliased = $db->query_table($posts)
    ->with([
        'writer:author' => true  // Alias 'author' as 'writer'
    ])
    ->find_first();

test('Alias works in with clause', isset($posts_aliased['writer']));
test('Alias contains correct data', $posts_aliased['writer']['name'] === 'Alice');

echo "\n";

// ============================================
// Test 8: Shorthand Methods
// ============================================

echo "--- Test Group: Shorthand Methods ---\n";

$found = $db->find_many($users, [
    'with' => ['profile' => true],
    'limit' => 10,
]);

test('find_many shorthand works', count($found) === 2);
test('find_many shorthand loads relations', isset($found[0]['profile']));

$first = $db->find_first($users, [
    'with' => ['posts' => true],
    'where' => eq($users->id, 1),
]);

test('find_first shorthand works', $first !== null);
test('find_first shorthand applies where', $first['id'] == 1);
test('find_first shorthand loads relations', isset($first['posts']));

echo "\n";

// ============================================
// Test 9: Filtered Relations
// ============================================

echo "--- Test Group: Filtered Relations ---\n";

$users_published = $db->query_table($users)
    ->with([
        'posts' => [
            'where' => eq($posts->published, true),
        ]
    ])
    ->where(eq($users->id, 1))
    ->find_first();

test('Filtered relation applies where', count($users_published['posts']) === 1);
test('Filtered relation has correct data', $users_published['posts'][0]['title'] === 'Post 1');

echo "\n";

// ============================================
// Test 10: Multi-Dialect Compatibility
// ============================================

echo "--- Test Group: Multi-Dialect Compatibility ---\n";

// Test SQL generation for different dialects
use Italix\Orm\Relations\TableQuery;
use Italix\Orm\Relations\RelationalQueryBuilder;

// Create mock connections for testing SQL generation
// We test that queries are built correctly for each dialect

// MySQL dialect test
$mysql_builder = new RelationalQueryBuilder($db->get_connection(), 'mysql');
$mysql_query = $mysql_builder->query($users)->where(eq($users->id, 1));

// Get SQL by reflection (we can't execute on wrong dialect, but can verify SQL format)
$reflection = new ReflectionClass($mysql_query);
$method = $reflection->getMethod('build_sql');
$method->setAccessible(true);
$params = [];
$mysql_sql = $method->invoke($mysql_query, $params);
test('MySQL uses backtick quoting', strpos($mysql_sql, '`users`') !== false);
test('MySQL uses ? placeholders', strpos($mysql_sql, '?') !== false);

// PostgreSQL dialect test
$pg_builder = new RelationalQueryBuilder($db->get_connection(), 'postgresql');
$pg_query = $pg_builder->query($users)->where(eq($users->id, 1));
$params = [];
$pg_sql = $method->invoke($pg_query, $params);
test('PostgreSQL uses double-quote quoting', strpos($pg_sql, '"users"') !== false);
test('PostgreSQL uses $1 placeholders', strpos($pg_sql, '$1') !== false);

// SQLite dialect test
$sqlite_builder = new RelationalQueryBuilder($db->get_connection(), 'sqlite');
$sqlite_query = $sqlite_builder->query($users)->where(eq($users->id, 1));
$params = [];
$sqlite_sql = $method->invoke($sqlite_query, $params);
test('SQLite uses double-quote quoting', strpos($sqlite_sql, '"users"') !== false);
test('SQLite uses ? placeholders', strpos($sqlite_sql, '?') !== false);

// Supabase dialect test
$supabase_builder = new RelationalQueryBuilder($db->get_connection(), 'supabase');
$supabase_query = $supabase_builder->query($users)->where(eq($users->id, 1));
$params = [];
$supabase_sql = $method->invoke($supabase_query, $params);
test('Supabase uses double-quote quoting', strpos($supabase_sql, '"users"') !== false);
test('Supabase uses $1 placeholders', strpos($supabase_sql, '$1') !== false);

echo "\n";

// ============================================
// Summary
// ============================================

echo "============================================\n";
echo "  Test Summary\n";
echo "============================================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total:  " . ($passed + $failed) . "\n";
echo "============================================\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
