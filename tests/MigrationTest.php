<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Migration System Test
 * 
 * Comprehensive test of the migration system including:
 * - Creating migrations
 * - Running migrations
 * - Rolling back
 * - Pull (introspection)
 * - Push
 * - Diff/auto-suggest
 * - Squash
 */

// The autoloader, wherever this package happens to sit. Composer first, because
// `Italix\Contracts` is a real dependency and Composer is what resolves it; the
// manual autoloader beside the source is the no-Composer fallback, and it now
// knows about that namespace too.
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

use Italix\Orm\Migration\Migrator;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;
use Italix\Orm\Migration\SchemaIntrospector;
use Italix\Orm\Migration\SchemaDiffer;
use Italix\Orm\Migration\SchemaPusher;
use Italix\Orm\Migration\MigrationSquasher;
use function Italix\Orm\sqlite;

use function Italix\Testing\{suite, test as ix_test, summary};

suite('Italix Orm - migrations');


/**
 * This suite predates the shared runner and calls `test()` a few hundred times
 * with the signature it has always had. The shim leaves every one of those call
 * sites untouched and routes the result to `Italix\Testing\Runner`, so the
 * assertions are counted by `ix test`, appear in the JUnit report, and stop
 * being printed into a summary only a person reads.
 *
 * `(bool)` because the original tested truthiness while the runner takes a bool.
 */
function test($name, $condition, $details = ''): void
{
    ix_test((string) $name, (bool) $condition, (string) $details);
}

// Setup test environment
$test_dir = __DIR__ . '/migration_test_' . time();

// The cleanup at the end of this file only runs when the file gets there. Three
// empty `migration_test_*` directories were sitting in the tree from runs that
// died part way, so it is registered here as well — a test that leaves litter
// behind when it fails leaves it behind exactly when somebody is busy.
register_shutdown_function(static function () use (&$test_dir): void {
    if (is_dir($test_dir)) {
        remove_dir($test_dir);
    }
});
$migrations_dir = $test_dir . '/migrations';
$db_file = $test_dir . '/test.db';

mkdir($test_dir, 0755, true);
mkdir($migrations_dir, 0755, true);

// Connect to SQLite
$dm = sqlite(['database' => $db_file]);
Schema::set_connection($dm);

// ============================================
// Test 1: Migrator Creation
// ============================================

echo "\n--- Migrator Tests ---\n";

$migrator = new Migrator($dm, $migrations_dir);
$migrator->set_output(false);

test('Migrator created', $migrator instanceof Migrator);
test('Migrations table created', $dm->table_exists('ix_migrations'));

// ============================================
// Test 2: Create Migration File
// ============================================

echo "\n--- Make Migration Tests ---\n";

$filepath = $migrator->create('create_users_table', 'users', true);
test('Migration file created', file_exists($filepath));

$content = file_get_contents($filepath);
test('Migration has up method', strpos($content, 'public function up()') !== false);
test('Migration has down method', strpos($content, 'public function down()') !== false);
test('Migration creates users table', strpos($content, "Schema::create('users'") !== false);

// ============================================
// Test 3: Edit Migration and Run
// ============================================

echo "\n--- Run Migration Tests ---\n";

// Replace with actual migration content
$migration_content = <<<'PHP'
<?php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop_if_exists('users');
    }
}
PHP;

file_put_contents($filepath, $migration_content);

// Run migrations
$applied = $migrator->migrate();
test('Migration applied', count($applied) === 1);
test('Users table created', $dm->table_exists('users'));

// Check migrations table
$migrations = $migrator->get_applied_migrations();
test('Migration recorded', count($migrations) === 1);

// ============================================
// Test 4: Status
// ============================================

echo "\n--- Status Tests ---\n";

$status = $migrator->status();
test('Status shows 1 migration', count($status) === 1);
test('Status shows Ran', $status[0]['status'] === 'Ran');

// ============================================
// Test 5: Create Second Migration
// ============================================

echo "\n--- Second Migration Tests ---\n";

$filepath2 = $migrator->create('create_posts_table', 'posts', true);

$migration2_content = <<<'PHP'
<?php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class CreatePostsTable extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreign_id('user_id');
            $table->string('title', 200);
            $table->text('content');
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->on_delete('CASCADE');
        });
    }

    public function down(): void
    {
        Schema::drop_if_exists('posts');
    }
}
PHP;

file_put_contents($filepath2, $migration2_content);

$applied = $migrator->migrate();
test('Second migration applied', count($applied) === 1);
test('Posts table created', $dm->table_exists('posts'));

// ============================================
// Test 6: Rollback
// ============================================

echo "\n--- Rollback Tests ---\n";

$rolled_back = $migrator->rollback();
test('Rollback successful', count($rolled_back) === 1);
test('Posts table dropped', !$dm->table_exists('posts'));
test('Users table still exists', $dm->table_exists('users'));

// Rollback again
$rolled_back = $migrator->rollback();
test('Second rollback successful', count($rolled_back) === 1);
test('Users table dropped', !$dm->table_exists('users'));

// ============================================
// Test 7: Refresh
// ============================================

echo "\n--- Refresh Tests ---\n";

$applied = $migrator->refresh();
test('Refresh applied both migrations', count($applied) === 2);
test('Users table exists after refresh', $dm->table_exists('users'));
test('Posts table exists after refresh', $dm->table_exists('posts'));

// ============================================
// Test 8: Schema Introspector (Pull)
// ============================================

echo "\n--- Pull/Introspection Tests ---\n";

$introspector = new SchemaIntrospector($dm);

$tables = $introspector->get_tables();
test('Introspector finds tables', count($tables) >= 2);

$users_schema = $introspector->get_table_schema('users');
test('Get users schema', !empty($users_schema['columns']));

$columns = array_column($users_schema['columns'], 'name');
test('Users has id column', in_array('id', $columns));
test('Users has name column', in_array('name', $columns));
test('Users has email column', in_array('email', $columns));

// Generate schema code
$schema_code = $introspector->generate_schema_code(['users']);
test('Schema code generated', strpos($schema_code, 'users') !== false);

// Generate migration code
$migration_code = $introspector->generate_migration_code('users');
test('Migration code generated', strpos($migration_code, 'Schema::create') !== false);

// ============================================
// Test 9: Blueprint Tests
// ============================================

echo "\n--- Blueprint Tests ---\n";

$blueprint = new Blueprint('test_table', 'sqlite');
$blueprint->id();
$blueprint->string('name', 100)->nullable();
$blueprint->integer('age')->default(0);
$blueprint->boolean('active')->default(true);
$blueprint->timestamps();

$create_sql = $blueprint->to_create_sql();
test('Blueprint generates CREATE TABLE', strpos($create_sql, 'CREATE TABLE') !== false);
test('Blueprint includes columns', strpos($create_sql, 'name') !== false);

// ============================================
// Test 10: Schema Differ (Auto-suggest)
// ============================================

echo "\n--- Diff/Auto-suggest Tests ---\n";

// Create a schema definition that differs from database
// For this test, we'll use a simple diff check

$differ = new SchemaDiffer($dm);
test('Differ created', $differ instanceof SchemaDiffer);

// ============================================
// Test 11: Reset
// ============================================

echo "\n--- Reset Tests ---\n";

$rolled_back = $migrator->reset();
test('Reset rolled back all', count($rolled_back) === 2);
test('All tables dropped', !$dm->table_exists('users') && !$dm->table_exists('posts'));

// ============================================
// Test 12: Pending Migrations
// ============================================

echo "\n--- Pending Tests ---\n";

$pending = $migrator->pending();
test('Shows 2 pending migrations', count($pending) === 2);

// ============================================
// Cleanup
// ============================================

echo "\n--- Cleanup ---\n";

// Remove test directory
function remove_dir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    remove_dir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

remove_dir($test_dir);
test('Test directory cleaned up', !is_dir($test_dir));

// ============================================
// Summary
// ============================================

exit(summary());
