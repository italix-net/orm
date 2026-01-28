<?php

/**
 * ActiveRow Test Suite
 *
 * Tests for the ActiveRow system including:
 * - Base ActiveRow functionality
 * - ArrayAccess implementation
 * - Dirty tracking
 * - Traits (Persistable, HasTimestamps, SoftDeletes, HasSlug, CanBeAuthor)
 * - Wrapping and unwrapping
 */

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../src/ActiveRow/functions.php';

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;
use Italix\Orm\ActiveRow\Traits\SoftDeletes;
use Italix\Orm\ActiveRow\Traits\HasSlug;
use Italix\Orm\ActiveRow\Traits\CanBeAuthor;

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, boolean};
use function Italix\Orm\Operators\eq;

// Test row classes
class TestUserRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    public function full_name(): string
    {
        return trim($this['first_name'] . ' ' . $this['last_name']);
    }

    public function is_admin(): bool
    {
        return $this['role'] === 'admin';
    }
}

class TestPostRow extends ActiveRow
{
    use Persistable, HasTimestamps, SoftDeletes, HasSlug;

    protected function get_slug_source(): string
    {
        return 'title';
    }

    public function word_count(): int
    {
        return str_word_count($this['content'] ?? '');
    }
}

class TestPersonRow extends ActiveRow
{
    use CanBeAuthor;

    public function display_name(): string
    {
        return trim($this['given_name'] . ' ' . $this['family_name']);
    }

    public function citation_name(): string
    {
        return $this['family_name'] . ', ' . substr($this['given_name'], 0, 1) . '.';
    }
}

class TestOrganizationRow extends ActiveRow
{
    use CanBeAuthor;

    public function display_name(): string
    {
        return $this['name'];
    }
}

// Test counters
$tests_passed = 0;
$tests_failed = 0;

function test($name, $condition)
{
    global $tests_passed, $tests_failed;
    if ($condition) {
        echo "  [PASS] $name\n";
        $tests_passed++;
    } else {
        echo "  [FAIL] $name\n";
        $tests_failed++;
    }
}

function section($name)
{
    echo "\n=== $name ===\n";
}

// ============================================
// TESTS
// ============================================

echo "ActiveRow Test Suite\n";
echo str_repeat('=', 50) . "\n";

// ============================================
section("Basic ActiveRow");
// ============================================

// Test wrap
$data = ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe', 'role' => 'admin'];
$user = TestUserRow::wrap($data);

test("wrap() creates instance", $user instanceof TestUserRow);
test("wrap() preserves data", $user['id'] === 1);
test("Array access works", $user['first_name'] === 'John');
test("Custom method works", $user->full_name() === 'John Doe');
test("Boolean method works", $user->is_admin() === true);

// Test wrap_many
$users = TestUserRow::wrap_many([
    ['id' => 1, 'first_name' => 'A'],
    ['id' => 2, 'first_name' => 'B'],
    ['id' => 3, 'first_name' => 'C'],
]);
test("wrap_many() returns array", is_array($users));
test("wrap_many() count correct", count($users) === 3);
test("wrap_many() items are ActiveRow", $users[0] instanceof TestUserRow);

// Test make
$newUser = TestUserRow::make(['first_name' => 'New']);
test("make() creates instance", $newUser instanceof TestUserRow);
test("make() sets data", $newUser['first_name'] === 'New');
test("make() marks as new", $newUser->is_new() === true);

// ============================================
section("ArrayAccess Implementation");
// ============================================

$row = TestUserRow::wrap(['id' => 1, 'name' => 'Test']);

test("offsetExists returns true for existing key", isset($row['id']));
test("offsetExists returns false for missing key", !isset($row['missing']));
test("offsetGet returns value", $row['name'] === 'Test');
test("offsetGet returns null for missing", $row['missing'] === null);

$row['email'] = 'test@example.com';
test("offsetSet works", $row['email'] === 'test@example.com');

unset($row['email']);
test("offsetUnset works", !isset($row['email']));

// ============================================
section("Unwrap Methods");
// ============================================

$data = ['id' => 1, 'name' => 'Test'];
$row = TestUserRow::wrap($data);

test("to_array() returns array", is_array($row->to_array()));
test("to_array() contains data", $row->to_array()['name'] === 'Test');
test("unwrap() is alias", $row->unwrap() === $row->to_array());
test("data property access works", $row->data['name'] === 'Test');
test("jsonSerialize() works", $row->jsonSerialize() === $data);
test("json_encode() works", json_encode($row) === json_encode($data));

// ============================================
section("Dirty Tracking");
// ============================================

$row = TestUserRow::wrap(['id' => 1, 'name' => 'Original']);

test("Initial state is clean", $row->is_clean());
test("Initial state not dirty", !$row->is_dirty());

$row['name'] = 'Changed';
test("After change is dirty", $row->is_dirty());
test("Specific field is dirty", $row->is_dirty('name'));
test("Other field not dirty", !$row->is_dirty('id'));
test("get_dirty() returns changes", $row->get_dirty() === ['name' => 'Changed']);
test("get_original() returns original", $row->get_original('name') === 'Original');

$row->sync_original();
test("sync_original() clears dirty", $row->is_clean());

// ============================================
section("State Methods");
// ============================================

$existing = TestUserRow::wrap(['id' => 1, 'name' => 'Test']);
$new = TestUserRow::make(['name' => 'New']);

test("exists() true for existing", $existing->exists());
test("exists() false for new", !$new->exists());
test("is_new() false for existing", !$existing->is_new());
test("is_new() true for new", $new->is_new());
test("get_key() returns ID", $existing->get_key() === 1);
test("get_key() returns null for new", $new->get_key() === null);
test("get_key_name() returns 'id'", TestUserRow::get_key_name() === 'id');

// ============================================
section("Utility Methods");
// ============================================

$row = TestUserRow::wrap(['id' => 1, 'name' => 'Test', 'email' => 'test@example.com']);

test("has() returns true for existing", $row->has('name'));
test("has() returns false for null", !$row->has('missing'));
test("get() returns value", $row->get('name') === 'Test');
test("get() returns default", $row->get('missing', 'default') === 'default');
test("only() filters keys", $row->only(['name']) === ['name' => 'Test']);
test("except() excludes keys", !isset($row->except(['id'])['id']));
test("count() returns field count", count($row) === 3);

$row->fill(['extra' => 'value']);
test("fill() adds data", $row['extra'] === 'value');

$clone = $row->with(['new' => 'data']);
test("with() returns clone", $clone !== $row);
test("with() adds data to clone", $clone['new'] === 'data');
test("with() doesn't modify original", !isset($row['new']));

// ============================================
section("Iteration");
// ============================================

$row = TestUserRow::wrap(['a' => 1, 'b' => 2, 'c' => 3]);
$keys = [];
foreach ($row as $key => $value) {
    $keys[] = $key;
}
test("foreach iteration works", $keys === ['a', 'b', 'c']);

// ============================================
section("Persistable Trait");
// ============================================

$db = sqlite_memory();
$users_table = sqlite_table('test_users', [
    'id' => integer()->primary_key()->auto_increment(),
    'first_name' => varchar(100),
    'last_name' => varchar(100),
    'role' => varchar(50)->default('user'),
    'created_at' => text(),
    'updated_at' => text(),
]);
$db->create_tables($users_table);

TestUserRow::set_persistence($db, $users_table);

test("has_persistence() returns true", TestUserRow::has_persistence());

// Create
$user = TestUserRow::create([
    'first_name' => 'Test',
    'last_name' => 'User',
    'role' => 'admin',
]);
test("create() returns instance", $user instanceof TestUserRow);
test("create() assigns ID", $user['id'] !== null);
test("create() persists data", $user->exists());

// Find
$found = TestUserRow::find($user['id']);
test("find() returns instance", $found instanceof TestUserRow);
test("find() returns correct data", $found['first_name'] === 'Test');

$notFound = TestUserRow::find(9999);
test("find() returns null for missing", $notFound === null);

// Update
$user['first_name'] = 'Updated';
$user->save();
$reloaded = TestUserRow::find($user['id']);
test("save() persists updates", $reloaded['first_name'] === 'Updated');

// Update via update()
$user->update(['last_name' => 'Changed']);
$reloaded = TestUserRow::find($user['id']);
test("update() method works", $reloaded['last_name'] === 'Changed');

// find_all
TestUserRow::create(['first_name' => 'Another', 'last_name' => 'User']);
$all = TestUserRow::find_all();
test("find_all() returns all", count($all) >= 2);
test("find_all() returns ActiveRows", $all[0] instanceof TestUserRow);

// find_one
$one = TestUserRow::find_one(['where' => eq($users_table->first_name, 'Updated')]);
test("find_one() returns single", $one instanceof TestUserRow);
test("find_one() filters correctly", $one['first_name'] === 'Updated');

// Delete
$idToDelete = $user['id'];
$user->delete();
$deleted = TestUserRow::find($idToDelete);
test("delete() removes record", $deleted === null);

// ============================================
section("HasTimestamps Trait");
// ============================================

$user = TestUserRow::create([
    'first_name' => 'Timestamp',
    'last_name' => 'Test',
]);

test("created_at is set", $user['created_at'] !== null);
test("updated_at is set", $user['updated_at'] !== null);
test("get_created_at() works", $user->get_created_at() !== null);
test("get_updated_at() works", $user->get_updated_at() !== null);

$oldUpdatedAt = $user['updated_at'];
sleep(1);
$user->touch()->save();
test("touch() updates timestamp", $user['updated_at'] !== $oldUpdatedAt);

test("was_recently_created() works", $user->was_recently_created(60));

// ============================================
section("SoftDeletes Trait");
// ============================================

$posts_table = sqlite_table('test_posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'title' => varchar(255)->not_null(),
    'slug' => varchar(255),
    'content' => text(),
    'created_at' => text(),
    'updated_at' => text(),
    'deleted_at' => text(),
]);
$db->create_tables($posts_table);

TestPostRow::set_persistence($db, $posts_table);

$post = TestPostRow::create([
    'title' => 'Test Post',
    'content' => 'This is the content of the test post.',
]);

test("New post is_deleted() false", !$post->is_deleted());
test("New post is_active() true", $post->is_active());

$post->soft_delete();
test("After soft_delete() is_deleted() true", $post->is_deleted());
test("After soft_delete() deleted_at is set", $post['deleted_at'] !== null);

$post->restore();
test("After restore() is_deleted() false", !$post->is_deleted());
test("After restore() deleted_at is null", $post['deleted_at'] === null);

// ============================================
section("HasSlug Trait");
// ============================================

$post = TestPostRow::create([
    'title' => 'Hello World! This is a Test.',
    'content' => 'Content here.',
]);

test("Slug is auto-generated", $post['slug'] !== null);
test("Slug is lowercase", $post['slug'] === strtolower($post['slug']));
test("Slug has no special chars", preg_match('/^[a-z0-9-]+$/', $post['slug']) === 1);
test("get_slug() works", $post->get_slug() === $post['slug']);

$post2 = TestPostRow::make(['title' => 'Café Résumé']);
$slug = $post2->generate_slug($post2['title']);
test("Transliteration works", strpos($slug, 'cafe') !== false);

// ============================================
section("CanBeAuthor Trait");
// ============================================

$person = TestPersonRow::wrap([
    'given_name' => 'John',
    'family_name' => 'Smith',
]);

$org = TestOrganizationRow::wrap([
    'name' => 'World Health Organization',
]);

test("Person display_name() works", $person->display_name() === 'John Smith');
test("Person author_type() works", $person->author_type() === 'testperson');
test("Person citation_name() works", $person->citation_name() === 'Smith, J.');
test("Person author_label() works", $person->author_label() === 'John Smith');
test("Person initials() works", $person->initials() === 'JS');
test("is_person() default false", !$person->is_person()); // Type is 'testperson', not 'person'

test("Org display_name() works", $org->display_name() === 'World Health Organization');
test("Org author_type() works", $org->author_type() === 'testorganization');

$meta = $person->author_meta();
test("author_meta() returns array", is_array($meta));
test("author_meta() has type", isset($meta['type']));
test("author_meta() has name", isset($meta['name']));

// ============================================
section("Custom Methods");
// ============================================

$post = TestPostRow::wrap([
    'title' => 'Test',
    'content' => 'One two three four five six seven eight nine ten.',
]);

test("word_count() works", $post->word_count() === 10);

// ============================================
// Summary
// ============================================

echo "\n" . str_repeat('=', 50) . "\n";
echo "Tests passed: $tests_passed\n";
echo "Tests failed: $tests_failed\n";
echo str_repeat('=', 50) . "\n";

exit($tests_failed > 0 ? 1 : 0);
