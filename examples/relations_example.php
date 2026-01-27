<?php
/**
 * Italix ORM - Relations Example
 *
 * This example demonstrates how to use Drizzle-style relations
 * with the Italix ORM.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, timestamp, boolean};
use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Operators\{eq, desc};

// ============================================
// 1. Define Tables
// ============================================

// Users table
$users = sqlite_table('users', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255)->not_null()->unique(),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Profiles table (one-to-one with users)
$profiles = sqlite_table('profiles', [
    'id' => integer()->primary_key()->auto_increment(),
    'user_id' => integer()->not_null()->unique(),
    'bio' => text(),
    'avatar_url' => varchar(255),
]);

// Posts table (many-to-one with users)
$posts = sqlite_table('posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'author_id' => integer()->not_null(),
    'title' => varchar(255)->not_null(),
    'content' => text(),
    'published' => boolean()->default(false),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Comments table (many-to-one with posts and users)
$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'post_id' => integer()->not_null(),
    'user_id' => integer()->not_null(),
    'content' => text()->not_null(),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Tags table (for many-to-many with posts)
$tags = sqlite_table('tags', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null()->unique(),
]);

// Post-Tags junction table (many-to-many)
$post_tags = sqlite_table('post_tags', [
    'post_id' => integer()->not_null(),
    'tag_id' => integer()->not_null(),
]);

// ============================================
// 2. Define Relations (Drizzle-style)
// ============================================

// Users relations
$users_relations = define_relations($users, function($r) use ($users, $profiles, $posts, $comments) {
    return [
        // One-to-one: users.id -> profiles.user_id
        'profile' => $r->one($profiles, [
            'fields' => [$users->id],             // Source PK
            'references' => [$profiles->user_id], // Target FK
        ])->relation_name('user'),

        // One-to-many: users.id -> posts.author_id
        'posts' => $r->many($posts, [
            'fields' => [$users->id],             // Source PK
            'references' => [$posts->author_id],  // Target FK
        ])->relation_name('author'),

        // One-to-many: users.id -> comments.user_id
        'comments' => $r->many($comments, [
            'fields' => [$users->id],
            'references' => [$comments->user_id],
        ]),
    ];
});

// Profiles relations
$profiles_relations = define_relations($profiles, function($r) use ($users, $profiles) {
    return [
        // Many-to-one: profiles.user_id -> users.id
        'user' => $r->one($users, [
            'fields' => [$profiles->user_id],     // Source FK
            'references' => [$users->id],         // Target PK
        ]),
    ];
});

// Posts relations
$posts_relations = define_relations($posts, function($r) use ($users, $posts, $comments, $tags, $post_tags) {
    return [
        // Many-to-one: posts.author_id -> users.id
        'author' => $r->one($users, [
            'fields' => [$posts->author_id],      // Source FK
            'references' => [$users->id],         // Target PK
        ])->relation_name('posts'),

        // One-to-many: posts.id -> comments.post_id
        'comments' => $r->many($comments, [
            'fields' => [$posts->id],             // Source PK
            'references' => [$comments->post_id], // Target FK
        ])->relation_name('post'),

        // Many-to-many: posts <-> tags through post_tags
        'tags' => $r->many($tags, [
            'fields' => [$posts->id],
            'through' => $post_tags,
            'through_fields' => [$post_tags->post_id],
            'target_fields' => [$post_tags->tag_id],
            'target_references' => [$tags->id],
        ]),
    ];
});

// Comments relations
$comments_relations = define_relations($comments, function($r) use ($posts, $users, $comments) {
    return [
        // Many-to-one: comments.post_id -> posts.id
        'post' => $r->one($posts, [
            'fields' => [$comments->post_id],     // Source FK
            'references' => [$posts->id],         // Target PK
        ]),

        // Many-to-one: comments.user_id -> users.id
        'user' => $r->one($users, [
            'fields' => [$comments->user_id],     // Source FK
            'references' => [$users->id],         // Target PK
        ]),
    ];
});

// Tags relations
$tags_relations = define_relations($tags, function($r) use ($posts, $post_tags, $tags) {
    return [
        // Many-to-many: tags <-> posts through post_tags
        'posts' => $r->many($posts, [
            'fields' => [$tags->id],
            'through' => $post_tags,
            'through_fields' => [$post_tags->tag_id],
            'target_fields' => [$post_tags->post_id],
            'target_references' => [$posts->id],
        ]),
    ];
});

// ============================================
// 3. Example Queries (Drizzle-style)
// ============================================

// Create database connection
$db = sqlite_memory();

// Create tables
$db->create_tables($users, $profiles, $posts, $comments, $tags, $post_tags);

// Insert sample data
$db->insert($users)->values([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
])->execute();

$db->insert($profiles)->values([
    ['user_id' => 1, 'bio' => 'Software developer', 'avatar_url' => '/avatars/alice.jpg'],
    ['user_id' => 2, 'bio' => 'Designer', 'avatar_url' => '/avatars/bob.jpg'],
])->execute();

$db->insert($posts)->values([
    ['author_id' => 1, 'title' => 'Hello World', 'content' => 'My first post!', 'published' => true],
    ['author_id' => 1, 'title' => 'PHP Tips', 'content' => 'Some PHP tips...', 'published' => true],
    ['author_id' => 2, 'title' => 'Design Patterns', 'content' => 'About design...', 'published' => false],
])->execute();

$db->insert($comments)->values([
    ['post_id' => 1, 'user_id' => 2, 'content' => 'Great post!'],
    ['post_id' => 1, 'user_id' => 1, 'content' => 'Thanks!'],
    ['post_id' => 2, 'user_id' => 2, 'content' => 'Very helpful!'],
])->execute();

$db->insert($tags)->values([
    ['name' => 'php'],
    ['name' => 'tutorial'],
    ['name' => 'design'],
])->execute();

$db->insert($post_tags)->values([
    ['post_id' => 1, 'tag_id' => 1],
    ['post_id' => 1, 'tag_id' => 2],
    ['post_id' => 2, 'tag_id' => 1],
    ['post_id' => 3, 'tag_id' => 3],
])->execute();

echo "=== Relations Example ===\n\n";

// ============================================
// Query 1: Find all users with their profiles
// ============================================
echo "1. Users with profiles:\n";
$all_users = $db->query_table($users)
    ->with(['profile' => true])
    ->find_many();

foreach ($all_users as $user) {
    echo "   - {$user['name']}: {$user['profile']['bio']}\n";
}
echo "\n";

// ============================================
// Query 2: Find a user with posts and comments
// ============================================
echo "2. User with posts and comments (nested relations):\n";
$user_with_posts = $db->query_table($users)
    ->with([
        'posts' => [
            'with' => [
                'comments' => true
            ]
        ]
    ])
    ->where(eq($users->id, 1))
    ->find_first();

echo "   User: {$user_with_posts['name']}\n";
foreach ($user_with_posts['posts'] as $post) {
    echo "   - Post: {$post['title']}\n";
    foreach ($post['comments'] as $comment) {
        echo "     - Comment: {$comment['content']}\n";
    }
}
echo "\n";

// ============================================
// Query 3: Find posts with author (many-to-one)
// ============================================
echo "3. Posts with authors:\n";
$all_posts = $db->query_table($posts)
    ->with(['author' => true])
    ->order_by(desc($posts->created_at))
    ->find_many();

foreach ($all_posts as $post) {
    echo "   - \"{$post['title']}\" by {$post['author']['name']}\n";
}
echo "\n";

// ============================================
// Query 4: Find posts with tags (many-to-many)
// ============================================
echo "4. Posts with tags (many-to-many):\n";
$posts_with_tags = $db->query_table($posts)
    ->with(['tags' => true])
    ->find_many();

foreach ($posts_with_tags as $post) {
    $tag_names = array_column($post['tags'], 'name');
    echo "   - \"{$post['title']}\": [" . implode(', ', $tag_names) . "]\n";
}
echo "\n";

// ============================================
// Query 5: Using relation aliases
// ============================================
echo "5. Using aliases:\n";
$posts_aliased = $db->query_table($posts)
    ->with([
        'writer:author' => true,  // Alias 'author' relation as 'writer'
    ])
    ->find_many();

foreach ($posts_aliased as $post) {
    echo "   - \"{$post['title']}\" written by {$post['writer']['name']}\n";
}
echo "\n";

// ============================================
// Query 6: Filtered and ordered relations
// ============================================
echo "6. User with only published posts (filtered relation):\n";
$user_published = $db->query_table($users)
    ->with([
        'posts' => [
            'where' => eq($posts->published, true),
            'order_by' => [desc($posts->created_at)],
            'limit' => 5,
        ]
    ])
    ->where(eq($users->id, 1))
    ->find_first();

echo "   User: {$user_published['name']}\n";
echo "   Published posts: " . count($user_published['posts']) . "\n";
foreach ($user_published['posts'] as $post) {
    echo "   - {$post['title']}\n";
}
echo "\n";

// ============================================
// Query 7: Using shorthand methods
// ============================================
echo "7. Using shorthand find_many:\n";
$users_shorthand = $db->find_many($users, [
    'with' => ['profile' => true, 'posts' => true],
    'order_by' => desc($users->id),
    'limit' => 10,
]);

foreach ($users_shorthand as $user) {
    echo "   - {$user['name']}: " . count($user['posts']) . " posts\n";
}
echo "\n";

echo "=== Example Complete ===\n";
