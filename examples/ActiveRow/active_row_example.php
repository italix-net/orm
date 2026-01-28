<?php

/**
 * ActiveRow Example
 *
 * This example demonstrates the ActiveRow system with a blog-like schema.
 * It shows how to:
 * - Define row classes with traits
 * - Wrap query results as ActiveRow instances
 * - Use array access and custom methods together
 * - Work with relations
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;
use Italix\Orm\ActiveRow\Traits\SoftDeletes;
use Italix\Orm\ActiveRow\Traits\HasSlug;

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, boolean};
use function Italix\Orm\Operators\{eq, desc};

// ============================================
// ROW CLASSES
// ============================================

/**
 * User row class
 */
class UserRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    protected static $relation_classes = [
        'posts' => PostRow::class,
        'profile' => ProfileRow::class,
    ];

    /**
     * Get full name
     */
    public function full_name(): string
    {
        return trim(($this['first_name'] ?? '') . ' ' . ($this['last_name'] ?? ''));
    }

    /**
     * Get display name (first name or email)
     */
    public function display_name(): string
    {
        $fullName = $this->full_name();
        return !empty($fullName) ? $fullName : ($this['email'] ?? 'Unknown');
    }

    /**
     * Check if user is admin
     */
    public function is_admin(): bool
    {
        return ($this['role'] ?? '') === 'admin';
    }

    /**
     * Get posts as PostRow instances
     */
    public function posts(): array
    {
        return $this->relation('posts') ?? [];
    }

    /**
     * Get profile as ProfileRow instance
     */
    public function profile(): ?ProfileRow
    {
        return $this->relation('profile');
    }
}

/**
 * Profile row class
 */
class ProfileRow extends ActiveRow
{
    use Persistable;

    protected static $relation_classes = [
        'user' => UserRow::class,
    ];

    /**
     * Get bio excerpt
     */
    public function bio_excerpt(int $length = 100): string
    {
        $bio = $this['bio'] ?? '';
        if (strlen($bio) <= $length) {
            return $bio;
        }
        return substr($bio, 0, $length) . '...';
    }

    /**
     * Get website domain
     */
    public function website_domain(): ?string
    {
        $website = $this['website'] ?? '';
        if (empty($website)) {
            return null;
        }
        $parsed = parse_url($website);
        return $parsed['host'] ?? null;
    }
}

/**
 * Post row class
 */
class PostRow extends ActiveRow
{
    use Persistable, HasTimestamps, SoftDeletes, HasSlug;

    protected static $slug_source = 'title';

    protected static $relation_classes = [
        'author' => UserRow::class,
        'comments' => CommentRow::class,
    ];

    /**
     * Get word count
     */
    public function word_count(): int
    {
        return str_word_count($this['content'] ?? '');
    }

    /**
     * Get estimated reading time in minutes
     */
    public function reading_time(): int
    {
        return (int) ceil($this->word_count() / 200);
    }

    /**
     * Check if post is published
     */
    public function is_published(): bool
    {
        return (bool) ($this['published'] ?? false);
    }

    /**
     * Get author as UserRow
     */
    public function author(): ?UserRow
    {
        return $this->relation('author');
    }

    /**
     * Get comments as CommentRow instances
     */
    public function comments(): array
    {
        return $this->relation('comments') ?? [];
    }

    /**
     * Get excerpt
     */
    public function excerpt(int $length = 200): string
    {
        $content = strip_tags($this['content'] ?? '');
        if (strlen($content) <= $length) {
            return $content;
        }
        return substr($content, 0, $length) . '...';
    }
}

/**
 * Comment row class
 */
class CommentRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    protected static $relation_classes = [
        'author' => UserRow::class,
        'post' => PostRow::class,
    ];

    /**
     * Get author
     */
    public function author(): ?UserRow
    {
        return $this->relation('author');
    }

    /**
     * Get parent post
     */
    public function post(): ?PostRow
    {
        return $this->relation('post');
    }

    /**
     * Get formatted date
     */
    public function formatted_date(): string
    {
        $date = $this['created_at'] ?? null;
        if (!$date) {
            return 'Unknown';
        }
        return date('M j, Y', strtotime($date));
    }
}

// ============================================
// EXAMPLE USAGE
// ============================================

echo "=== ActiveRow Example ===\n\n";

// Create in-memory database
$db = sqlite_memory();

// Define tables
$users = sqlite_table('users', [
    'id' => integer()->primary_key()->auto_increment(),
    'email' => varchar(255)->not_null()->unique(),
    'first_name' => varchar(100),
    'last_name' => varchar(100),
    'role' => varchar(50)->default('user'),
    'created_at' => text(),
    'updated_at' => text(),
]);

$profiles = sqlite_table('profiles', [
    'id' => integer()->primary_key()->auto_increment(),
    'user_id' => integer()->not_null(),
    'bio' => text(),
    'website' => varchar(255),
]);

$posts = sqlite_table('posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'author_id' => integer()->not_null(),
    'title' => varchar(255)->not_null(),
    'slug' => varchar(255),
    'content' => text(),
    'published' => boolean()->default(false),
    'created_at' => text(),
    'updated_at' => text(),
    'deleted_at' => text(),
]);

$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'post_id' => integer()->not_null(),
    'author_id' => integer()->not_null(),
    'content' => text()->not_null(),
    'created_at' => text(),
    'updated_at' => text(),
]);

// Create tables
$db->create_tables($users, $profiles, $posts, $comments);

// Set up persistence for row classes
UserRow::set_persistence($db, $users);
ProfileRow::set_persistence($db, $profiles);
PostRow::set_persistence($db, $posts);
CommentRow::set_persistence($db, $comments);

// ============================================
// 1. Creating records with ActiveRow::create()
// ============================================

echo "1. Creating records with ActiveRow::create()\n";
echo str_repeat('-', 50) . "\n";

$user = UserRow::create([
    'email' => 'john@example.com',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'role' => 'admin',
]);

echo "Created user: " . $user->display_name() . " (ID: {$user['id']})\n";
echo "Is admin: " . ($user->is_admin() ? 'Yes' : 'No') . "\n";
echo "Created at: " . $user['created_at'] . "\n\n";

// ============================================
// 2. Array access + methods
// ============================================

echo "2. Array access + methods\n";
echo str_repeat('-', 50) . "\n";

// Array access works
echo "Email (array access): " . $user['email'] . "\n";
echo "Role (array access): " . $user['role'] . "\n";

// Methods also work
echo "Full name (method): " . $user->full_name() . "\n";

// Modify via array access
$user['first_name'] = 'Jonathan';
echo "Updated name: " . $user->full_name() . "\n";

// Check dirty tracking
echo "Is dirty: " . ($user->is_dirty() ? 'Yes' : 'No') . "\n";
echo "Dirty fields: " . json_encode($user->get_dirty()) . "\n";

// Save changes
$user->save();
echo "After save, is dirty: " . ($user->is_dirty() ? 'Yes' : 'No') . "\n\n";

// ============================================
// 3. Creating posts with auto-slug
// ============================================

echo "3. Creating posts with auto-slug\n";
echo str_repeat('-', 50) . "\n";

$post1 = PostRow::create([
    'author_id' => $user['id'],
    'title' => 'Hello World! This is my first post.',
    'content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ' .
                 'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. ' .
                 'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris.',
    'published' => true,
]);

echo "Post title: " . $post1['title'] . "\n";
echo "Auto-generated slug: " . $post1['slug'] . "\n";
echo "Word count: " . $post1->word_count() . "\n";
echo "Reading time: " . $post1->reading_time() . " min\n";
echo "Excerpt: " . $post1->excerpt(50) . "\n\n";

// ============================================
// 4. Wrap query results
// ============================================

echo "4. Wrap query results\n";
echo str_repeat('-', 50) . "\n";

// Create more users and posts
UserRow::create(['email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Smith']);
UserRow::create(['email' => 'bob@example.com', 'first_name' => 'Bob', 'last_name' => 'Wilson']);

// Query as plain arrays, then wrap
$rawUsers = $db->select()->from($users)->execute();
$userRows = UserRow::wrap_many($rawUsers);

echo "Users in database:\n";
foreach ($userRows as $u) {
    echo "  - {$u->display_name()} ({$u['email']})" .
         ($u->is_admin() ? ' [ADMIN]' : '') . "\n";
}
echo "\n";

// ============================================
// 5. Static finder methods
// ============================================

echo "5. Static finder methods\n";
echo str_repeat('-', 50) . "\n";

// Find by ID
$foundUser = UserRow::find(1);
echo "Find by ID 1: " . ($foundUser ? $foundUser->display_name() : 'Not found') . "\n";

// Find with options
$admins = UserRow::find_all([
    'where' => eq($users->role, 'admin'),
]);
echo "Admin users: " . count($admins) . "\n";

// Find one
$firstUser = UserRow::find_one([
    'order_by' => $users->id,
]);
echo "First user: " . ($firstUser ? $firstUser->display_name() : 'None') . "\n\n";

// ============================================
// 6. Working with relations
// ============================================

echo "6. Working with relations\n";
echo str_repeat('-', 50) . "\n";

// Create a comment
$comment = CommentRow::create([
    'post_id' => $post1['id'],
    'author_id' => 2,  // Jane
    'content' => 'Great post! Thanks for sharing.',
]);

echo "Created comment by user ID " . $comment['author_id'] . "\n";
echo "Comment date: " . $comment->formatted_date() . "\n\n";

// ============================================
// 7. Soft delete
// ============================================

echo "7. Soft delete\n";
echo str_repeat('-', 50) . "\n";

echo "Post is_deleted before: " . ($post1->is_deleted() ? 'Yes' : 'No') . "\n";

$post1->soft_delete();
echo "Post is_deleted after soft_delete(): " . ($post1->is_deleted() ? 'Yes' : 'No') . "\n";
echo "Deleted at: " . $post1['deleted_at'] . "\n";

$post1->restore();
echo "Post is_deleted after restore(): " . ($post1->is_deleted() ? 'Yes' : 'No') . "\n\n";

// ============================================
// 8. Utility methods
// ============================================

echo "8. Utility methods\n";
echo str_repeat('-', 50) . "\n";

echo "Only email and role: " . json_encode($user->only(['email', 'role'])) . "\n";
echo "Except timestamps: " . json_encode(array_keys($user->except(['created_at', 'updated_at']))) . "\n";
echo "Has 'email': " . ($user->has('email') ? 'Yes' : 'No') . "\n";
echo "Has 'nonexistent': " . ($user->has('nonexistent') ? 'Yes' : 'No') . "\n";
echo "Get with default: " . $user->get('missing', 'default_value') . "\n\n";

// ============================================
// 9. JSON serialization
// ============================================

echo "9. JSON serialization\n";
echo str_repeat('-', 50) . "\n";

echo "JSON: " . json_encode($user) . "\n\n";

// ============================================
// 10. Iteration
// ============================================

echo "10. Iteration (foreach)\n";
echo str_repeat('-', 50) . "\n";

echo "User fields:\n";
foreach ($user as $key => $value) {
    if ($value !== null) {
        echo "  $key: $value\n";
    }
}

echo "\n=== Example Complete ===\n";
