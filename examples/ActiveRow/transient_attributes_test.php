<?php
/**
 * Transient Attributes Test Suite
 *
 * Tests the transient (dot-prefixed) attribute feature:
 * - Transient keys (.key) are stored in memory but not persisted
 * - set() and get() methods work with both regular and transient keys
 * - get_dirty() excludes transient keys
 * - get_persistent_data() returns only non-transient data
 * - save() does not persist transient attributes to database
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';

use Italix\Orm\Schema\Table;
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\Dialects\Driver;
use Italix\Orm\IxOrm;

use function Italix\Orm\Schema\bigint;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\integer;

// ============================================================
// SCHEMA
// ============================================================

$users = new Table('users', [
    'id'         => bigint()->primary_key()->auto_increment(),
    'first_name' => varchar(100)->not_null(),
    'last_name'  => varchar(100)->not_null(),
    'email'      => varchar(200)->not_null(),
    'age'        => integer(),
], 'sqlite');

// ============================================================
// MODEL
// ============================================================

class TestUser extends ActiveRow
{
    use Persistable;

    public function full_name(): string
    {
        return $this['first_name'] . ' ' . $this['last_name'];
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
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('-', 40) . "\n";
}

// ============================================================
// SETUP
// ============================================================

$driver = Driver::sqlite_memory();
$db = new IxOrm($driver);
$db->create_tables($users);

TestUser::set_persistence($db, $users);

echo "Transient Attributes Test Suite\n";
echo str_repeat('=', 50) . "\n";

// ============================================================
// TEST: set() and get() methods
// ============================================================

section('set() and get() Methods');

$user = TestUser::make();
$user->set('first_name', 'Andrea');
$user->set('last_name', 'Rossi');
$user->set('email', 'andrea@example.com');

test('set() stores value', $user['first_name'] === 'Andrea');
test('get() retrieves value', $user->get('first_name') === 'Andrea');
test('get() with default returns value when key exists', $user->get('first_name', 'Default') === 'Andrea');
test('get() with default returns default when key missing', $user->get('nonexistent', 'Default') === 'Default');
test('set() returns $this for chaining', $user->set('age', 30) === $user);

// Test chaining
$user2 = TestUser::make()
    ->set('first_name', 'Mario')
    ->set('last_name', 'Bianchi')
    ->set('email', 'mario@example.com');

test('set() chaining works', $user2['first_name'] === 'Mario' && $user2['last_name'] === 'Bianchi');

// ============================================================
// TEST: is_transient_key()
// ============================================================

section('is_transient_key() Detection');

test('Regular key is not transient', TestUser::is_transient_key('first_name') === false);
test('Empty key is not transient', TestUser::is_transient_key('') === false);
test('Dot-prefixed key is transient', TestUser::is_transient_key('.temp_value') === true);
test('Dot in middle is not transient', TestUser::is_transient_key('some.value') === false);
test('Single dot is transient', TestUser::is_transient_key('.') === true);
test('Key starting with . is transient', TestUser::is_transient_key('.last_accessed_at') === true);

// ============================================================
// TEST: Transient attributes via set() and array access
// ============================================================

section('Transient Attribute Storage');

$user3 = TestUser::make([
    'first_name' => 'Luigi',
    'last_name' => 'Verdi',
    'email' => 'luigi@example.com',
]);

// Set transient via set()
$user3->set('.last_call_at', '2024-01-15 10:30:00');
$user3->set('.cached_score', 95);

test('Transient set via set() is stored', $user3['.last_call_at'] === '2024-01-15 10:30:00');
test('Transient set via set() is retrievable with get()', $user3->get('.cached_score') === 95);

// Set transient via array access
$user3['.session_id'] = 'abc123';
test('Transient set via array access is stored', $user3['.session_id'] === 'abc123');

// ============================================================
// TEST: get_dirty() excludes transient keys
// ============================================================

section('Dirty Tracking (Excludes Transient)');

$user4 = TestUser::wrap([
    'id' => 1,
    'first_name' => 'Test',
    'last_name' => 'User',
    'email' => 'test@example.com',
]);

// Modify regular field
$user4['first_name'] = 'Modified';
$dirty = $user4->get_dirty();
test('Regular dirty field is tracked', isset($dirty['first_name']) && $dirty['first_name'] === 'Modified');

// Add transient field
$user4['.temp_flag'] = true;
$dirty2 = $user4->get_dirty();
test('Transient field is NOT in dirty', !isset($dirty2['.temp_flag']));
test('Dirty still contains only regular modified field', count($dirty2) === 1 && isset($dirty2['first_name']));

// ============================================================
// TEST: get_persistent_data() and get_transient_data()
// ============================================================

section('get_persistent_data() and get_transient_data()');

$user5 = TestUser::make([
    'first_name' => 'Anna',
    'last_name' => 'Neri',
    'email' => 'anna@example.com',
]);
$user5['.calculated_age'] = 28;
$user5['.permissions'] = ['read', 'write'];

$persistent = $user5->get_persistent_data();
$transient = $user5->get_transient_data();

test('get_persistent_data() excludes transient keys', !isset($persistent['.calculated_age']));
test('get_persistent_data() includes regular keys', isset($persistent['first_name']) && $persistent['first_name'] === 'Anna');
test('get_transient_data() includes only transient keys', isset($transient['.calculated_age']) && $transient['.calculated_age'] === 28);
test('get_transient_data() excludes regular keys', !isset($transient['first_name']));
test('Persistent + transient = all data', count($persistent) + count($transient) === count($user5->to_array()));

// ============================================================
// TEST: to_array() with include_transient parameter
// ============================================================

section('to_array() with Transient Option');

$user6 = TestUser::make(['first_name' => 'Test', 'last_name' => 'Test', 'email' => 'test@test.com']);
$user6['.metadata'] = ['source' => 'api'];

$all_data = $user6->to_array(true);
$persistent_only = $user6->to_array(false);

test('to_array(true) includes transient', isset($all_data['.metadata']));
test('to_array(false) excludes transient', !isset($persistent_only['.metadata']));
test('to_array() default includes transient', isset($user6->to_array()['.metadata']));

// ============================================================
// TEST: save() does not persist transient attributes
// ============================================================

section('Persistence (Transient NOT Saved to DB)');

$user7 = TestUser::make([
    'first_name' => 'Carlo',
    'last_name' => 'Blu',
    'email' => 'carlo@example.com',
]);
$user7['.temporary_token'] = 'secret123';
$user7['.request_count'] = 5;

$user7->save();

test('User saved successfully', $user7['id'] !== null);

// Fetch from database to verify transient wasn't saved
$loaded = TestUser::find($user7['id']);
test('Loaded user does not have transient .temporary_token', !isset($loaded['.temporary_token']));
test('Loaded user does not have transient .request_count', !isset($loaded['.request_count']));
test('Loaded user has regular first_name', $loaded['first_name'] === 'Carlo');

// ============================================================
// TEST: Update with transient attributes
// ============================================================

section('Update with Transient Attributes');

$user7['.another_temp'] = 'temp_value';
$user7['first_name'] = 'Carlo Updated';
$user7->save();

$loaded2 = TestUser::find($user7['id']);
test('Update saved regular field', $loaded2['first_name'] === 'Carlo Updated');
test('Update did not persist transient', !isset($loaded2['.another_temp']));

// ============================================================
// TEST: JSON serialization
// ============================================================

section('JSON Serialization');

$user8 = TestUser::make([
    'first_name' => 'JSON',
    'last_name' => 'Test',
    'email' => 'json@example.com',
]);
$user8['.secret'] = 'should_not_serialize';

$json = json_encode($user8);
$decoded = json_decode($json, true);

test('JSON excludes transient by default', !isset($decoded['.secret']));
test('JSON includes regular fields', isset($decoded['first_name']) && $decoded['first_name'] === 'JSON');

// ============================================================
// TEST: sync_original() only syncs persistent data
// ============================================================

section('sync_original() Behavior');

$user9 = TestUser::wrap([
    'id' => 999,
    'first_name' => 'Sync',
    'last_name' => 'Test',
    'email' => 'sync@example.com',
]);

$user9['first_name'] = 'Changed';
$user9['.temp'] = 'temporary';

test('User is dirty before sync', $user9->is_dirty());
$user9->sync_original();
test('User is clean after sync', $user9->is_clean());

// Transient modification should not make it dirty
$user9['.another_temp'] = 'value';
test('Adding transient does not make user dirty', $user9->is_clean());

// Regular modification should make it dirty again
$user9['first_name'] = 'Changed Again';
test('Regular modification makes user dirty', $user9->is_dirty());

// ============================================================
// SUMMARY
// ============================================================

echo "\n" . str_repeat('=', 50) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 50) . "\n\n";

if ($failed === 0) {
    echo "[PASS] Total: {$passed} passed, {$failed} failed\n\n";
    echo "[PASS] All tests passed!\n";
} else {
    echo "[FAIL] Total: {$passed} passed, {$failed} failed\n\n";
    echo "[FAIL] Some tests failed!\n";
    exit(1);
}
