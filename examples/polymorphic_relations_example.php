<?php
/**
 * Italix ORM - Polymorphic Relations Example
 *
 * This example demonstrates how to use polymorphic relations
 * following the Drizzle ORM pattern.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, timestamp};
use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Operators\{eq, desc};

// ============================================
// 1. Define Tables for Polymorphic Relations
// ============================================

// Posts table
$posts = sqlite_table('posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'title' => varchar(255)->not_null(),
    'content' => text(),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Videos table
$videos = sqlite_table('videos', [
    'id' => integer()->primary_key()->auto_increment(),
    'title' => varchar(255)->not_null(),
    'url' => varchar(500)->not_null(),
    'duration' => integer(), // seconds
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Comments table - polymorphic (can belong to posts OR videos)
// Uses commentable_type and commentable_id pattern
$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'commentable_type' => varchar(50)->not_null(),  // 'post' or 'video'
    'commentable_id' => integer()->not_null(),       // ID of the post or video
    'author_name' => varchar(100)->not_null(),
    'content' => text()->not_null(),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Likes table - polymorphic (can like posts, videos, or comments)
$likes = sqlite_table('likes', [
    'id' => integer()->primary_key()->auto_increment(),
    'likeable_type' => varchar(50)->not_null(),     // 'post', 'video', or 'comment'
    'likeable_id' => integer()->not_null(),          // ID of the liked item
    'user_name' => varchar(100)->not_null(),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// ============================================
// 2. Define Polymorphic Relations
// ============================================

// Comments relations - polymorphic "belongs to"
// A comment belongs to either a post or a video
$comments_relations = define_relations($comments, function($r) use ($posts, $videos) {
    return [
        // Polymorphic one: Comment belongs to commentable (Post or Video)
        'commentable' => $r->one_polymorphic([
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'targets' => [
                'post' => $posts,
                'video' => $videos,
            ],
        ]),
    ];
});

// Posts relations - polymorphic "has many"
// A post has many comments (where commentable_type = 'post')
$posts_relations = define_relations($posts, function($r) use ($posts, $comments, $likes) {
    return [
        // Polymorphic many: Post has many Comments
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'type_value' => 'post',
            'references' => [$posts->id],
        ]),

        // Polymorphic many: Post has many Likes
        'likes' => $r->many_polymorphic($likes, [
            'type_column' => $likes->likeable_type,
            'id_column' => $likes->likeable_id,
            'type_value' => 'post',
            'references' => [$posts->id],
        ]),
    ];
});

// Videos relations - polymorphic "has many"
// A video has many comments (where commentable_type = 'video')
$videos_relations = define_relations($videos, function($r) use ($videos, $comments, $likes) {
    return [
        // Polymorphic many: Video has many Comments
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'type_value' => 'video',
            'references' => [$videos->id],
        ]),

        // Polymorphic many: Video has many Likes
        'likes' => $r->many_polymorphic($likes, [
            'type_column' => $likes->likeable_type,
            'id_column' => $likes->likeable_id,
            'type_value' => 'video',
            'references' => [$videos->id],
        ]),
    ];
});

// ============================================
// 3. Create Database and Insert Data
// ============================================

$db = sqlite_memory();
$db->create_tables($posts, $videos, $comments, $likes);

// Insert posts
$db->insert($posts)->values([
    ['title' => 'Introduction to PHP', 'content' => 'PHP is a popular language...'],
    ['title' => 'Advanced ORM Patterns', 'content' => 'Learn about relations...'],
])->execute();

// Insert videos
$db->insert($videos)->values([
    ['title' => 'PHP Tutorial Part 1', 'url' => 'https://example.com/video1', 'duration' => 600],
    ['title' => 'Database Design', 'url' => 'https://example.com/video2', 'duration' => 900],
])->execute();

// Insert comments on posts
$db->insert($comments)->values([
    ['commentable_type' => 'post', 'commentable_id' => 1, 'author_name' => 'Alice', 'content' => 'Great article!'],
    ['commentable_type' => 'post', 'commentable_id' => 1, 'author_name' => 'Bob', 'content' => 'Very helpful, thanks!'],
    ['commentable_type' => 'post', 'commentable_id' => 2, 'author_name' => 'Charlie', 'content' => 'Interesting patterns.'],
])->execute();

// Insert comments on videos
$db->insert($comments)->values([
    ['commentable_type' => 'video', 'commentable_id' => 1, 'author_name' => 'Dave', 'content' => 'Nice tutorial!'],
    ['commentable_type' => 'video', 'commentable_id' => 1, 'author_name' => 'Eve', 'content' => 'Please make more!'],
    ['commentable_type' => 'video', 'commentable_id' => 2, 'author_name' => 'Frank', 'content' => 'Clear explanation.'],
])->execute();

// Insert likes
$db->insert($likes)->values([
    ['likeable_type' => 'post', 'likeable_id' => 1, 'user_name' => 'Alice'],
    ['likeable_type' => 'post', 'likeable_id' => 1, 'user_name' => 'Bob'],
    ['likeable_type' => 'video', 'likeable_id' => 1, 'user_name' => 'Charlie'],
    ['likeable_type' => 'video', 'likeable_id' => 1, 'user_name' => 'Dave'],
    ['likeable_type' => 'video', 'likeable_id' => 1, 'user_name' => 'Eve'],
])->execute();

// ============================================
// 4. Query Polymorphic Relations
// ============================================

echo "=== Polymorphic Relations Example ===\n\n";

// Query 1: Posts with polymorphic comments
echo "1. Posts with their comments:\n";
$all_posts = $db->query_table($posts)
    ->with(['comments' => true])
    ->find_many();

foreach ($all_posts as $post) {
    echo "   Post: \"{$post['title']}\"\n";
    foreach ($post['comments'] as $comment) {
        echo "     - {$comment['author_name']}: {$comment['content']}\n";
    }
}
echo "\n";

// Query 2: Videos with polymorphic comments
echo "2. Videos with their comments:\n";
$all_videos = $db->query_table($videos)
    ->with(['comments' => true])
    ->find_many();

foreach ($all_videos as $video) {
    echo "   Video: \"{$video['title']}\"\n";
    foreach ($video['comments'] as $comment) {
        echo "     - {$comment['author_name']}: {$comment['content']}\n";
    }
}
echo "\n";

// Query 3: Comments with their polymorphic parent (commentable)
echo "3. Comments with their parent (polymorphic 'belongs to'):\n";
$all_comments = $db->query_table($comments)
    ->with(['commentable' => true])
    ->find_many();

foreach ($all_comments as $comment) {
    $parent_type = $comment['commentable_type'];
    $parent_title = $comment['commentable']['title'] ?? 'Unknown';
    echo "   \"{$comment['content']}\" on {$parent_type}: \"{$parent_title}\"\n";
}
echo "\n";

// Query 4: Posts and Videos with likes count
echo "4. Posts with likes:\n";
$posts_with_likes = $db->query_table($posts)
    ->with(['likes' => true])
    ->find_many();

foreach ($posts_with_likes as $post) {
    $like_count = count($post['likes']);
    echo "   \"{$post['title']}\" - {$like_count} likes\n";
}
echo "\n";

echo "5. Videos with likes:\n";
$videos_with_likes = $db->query_table($videos)
    ->with(['likes' => true])
    ->find_many();

foreach ($videos_with_likes as $video) {
    $like_count = count($video['likes']);
    echo "   \"{$video['title']}\" - {$like_count} likes\n";
}
echo "\n";

// Query 5: Combined - Posts with comments and likes
echo "6. Posts with both comments and likes:\n";
$posts_full = $db->query_table($posts)
    ->with([
        'comments' => true,
        'likes' => true,
    ])
    ->find_many();

foreach ($posts_full as $post) {
    echo "   \"{$post['title']}\"\n";
    echo "     Comments: " . count($post['comments']) . "\n";
    echo "     Likes: " . count($post['likes']) . "\n";
}
echo "\n";

echo "=== Polymorphic Example Complete ===\n";
