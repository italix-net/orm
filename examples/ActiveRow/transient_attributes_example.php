<?php
/**
 * Transient Attributes Example
 *
 * This example demonstrates how to use transient (dot-prefixed) attributes
 * in ActiveRow for temporary, non-persisted data.
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
use function Italix\Orm\Schema\timestamp;

// ============================================================
// SCHEMA DEFINITION
// ============================================================

$users = new Table('users', [
    'id'         => bigint()->primary_key()->auto_increment(),
    'first_name' => varchar(100)->not_null(),
    'last_name'  => varchar(100)->not_null(),
    'email'      => varchar(200)->not_null(),
    'role'       => varchar(50)->default('user'),
    'created_at' => timestamp(),
], 'sqlite');

// ============================================================
// MODEL DEFINITION
// ============================================================

class User extends ActiveRow
{
    use Persistable;

    /**
     * Get user's full name
     */
    public function full_name(): string
    {
        return trim($this['first_name'] . ' ' . $this['last_name']);
    }

    /**
     * Check if user is admin
     */
    public function is_admin(): bool
    {
        return $this['role'] === 'admin';
    }

    /**
     * Get cached permissions (uses transient storage)
     */
    public function permissions(): array
    {
        // Cache permissions in transient attribute
        if (!isset($this['.cached_permissions'])) {
            $this['.cached_permissions'] = $this->load_permissions();
        }
        return $this['.cached_permissions'];
    }

    /**
     * Simulate loading permissions (would normally query database)
     */
    private function load_permissions(): array
    {
        return match($this['role']) {
            'admin' => ['read', 'write', 'delete', 'admin'],
            'editor' => ['read', 'write'],
            default => ['read'],
        };
    }

    /**
     * Check if user has a specific permission
     */
    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}

// ============================================================
// SETUP DATABASE
// ============================================================

$driver = Driver::sqlite_memory();
$db = new IxOrm($driver);
$db->create_tables($users);

User::set_persistence($db, $users);

echo "=== Transient Attributes Example ===\n\n";

// ============================================================
// EXAMPLE 1: Basic set() and get()
// ============================================================

echo "1. Basic set() and get() Methods\n";
echo str_repeat('-', 40) . "\n";

// Create user with fluent set() chaining
$user = User::make()
    ->set('first_name', 'Andrea')
    ->set('last_name', 'Rossi')
    ->set('email', 'andrea@example.com')
    ->set('role', 'admin');

echo "Created user: {$user->full_name()}\n";
echo "Email: {$user->get('email')}\n";
echo "Role: {$user->get('role', 'guest')}\n";  // with default
echo "Missing field: {$user->get('phone', 'N/A')}\n\n";  // default used

// ============================================================
// EXAMPLE 2: Transient Attributes
// ============================================================

echo "2. Transient Attributes (Dot-Prefixed)\n";
echo str_repeat('-', 40) . "\n";

// Set transient attributes - these won't be saved to database
$user['.request_id'] = 'req-' . uniqid();
$user['.ip_address'] = '192.168.1.100';
$user->set('.login_timestamp', time());

echo "Request ID (transient): {$user['.request_id']}\n";
echo "IP Address (transient): {$user->get('.ip_address')}\n";
echo "Login Time (transient): " . date('Y-m-d H:i:s', $user['.login_timestamp']) . "\n\n";

// ============================================================
// EXAMPLE 3: Persistent vs Transient Data
// ============================================================

echo "3. Persistent vs Transient Data\n";
echo str_repeat('-', 40) . "\n";

echo "All data (to_array):\n";
print_r($user->to_array());

echo "\nPersistent only (get_persistent_data):\n";
print_r($user->get_persistent_data());

echo "\nTransient only (get_transient_data):\n";
print_r($user->get_transient_data());

// ============================================================
// EXAMPLE 4: Saving - Transient Data Not Persisted
// ============================================================

echo "\n4. Saving - Transient Data Not Persisted\n";
echo str_repeat('-', 40) . "\n";

$user->save();
echo "User saved with ID: {$user['id']}\n";

// Reload from database
$loaded = User::find($user['id']);

echo "\nLoaded user from database:\n";
echo "  Name: {$loaded->full_name()}\n";
echo "  Email: {$loaded['email']}\n";
echo "  Has .request_id? " . (isset($loaded['.request_id']) ? 'Yes' : 'No') . "\n";
echo "  Has .ip_address? " . (isset($loaded['.ip_address']) ? 'Yes' : 'No') . "\n";

// ============================================================
// EXAMPLE 5: Dirty Tracking with Transient
// ============================================================

echo "\n5. Dirty Tracking (Transient Excluded)\n";
echo str_repeat('-', 40) . "\n";

$user2 = User::wrap([
    'id' => 999,
    'first_name' => 'Mario',
    'last_name' => 'Bianchi',
    'email' => 'mario@example.com',
    'role' => 'user',
]);

echo "Initial state - is_dirty: " . ($user2->is_dirty() ? 'Yes' : 'No') . "\n";

// Add transient - doesn't affect dirty state
$user2['.session_token'] = 'tok_abc123';
echo "After adding transient - is_dirty: " . ($user2->is_dirty() ? 'Yes' : 'No') . "\n";

// Modify persistent field - now dirty
$user2['first_name'] = 'Marco';
echo "After modifying name - is_dirty: " . ($user2->is_dirty() ? 'Yes' : 'No') . "\n";

echo "\nDirty fields (transient excluded):\n";
print_r($user2->get_dirty());

// ============================================================
// EXAMPLE 6: Caching with Transient Attributes
// ============================================================

echo "\n6. Caching Computed Values with Transient\n";
echo str_repeat('-', 40) . "\n";

$admin = User::make([
    'first_name' => 'Super',
    'last_name' => 'Admin',
    'email' => 'admin@example.com',
    'role' => 'admin',
]);

// First call computes and caches
echo "Admin permissions: " . implode(', ', $admin->permissions()) . "\n";
echo "Can delete? " . ($admin->can('delete') ? 'Yes' : 'No') . "\n";
echo "Can admin? " . ($admin->can('admin') ? 'Yes' : 'No') . "\n";

// Check the cached value
echo "\nCached in transient:\n";
print_r($admin->get_transient_data());

$editor = User::make([
    'first_name' => 'Content',
    'last_name' => 'Editor',
    'email' => 'editor@example.com',
    'role' => 'editor',
]);

echo "\nEditor permissions: " . implode(', ', $editor->permissions()) . "\n";
echo "Can write? " . ($editor->can('write') ? 'Yes' : 'No') . "\n";
echo "Can delete? " . ($editor->can('delete') ? 'Yes' : 'No') . "\n";

// ============================================================
// EXAMPLE 7: JSON Serialization
// ============================================================

echo "\n7. JSON Serialization (Transient Excluded)\n";
echo str_repeat('-', 40) . "\n";

$user3 = User::make([
    'first_name' => 'JSON',
    'last_name' => 'Test',
    'email' => 'json@example.com',
]);
$user3['.secret_token'] = 'should_not_appear_in_json';
$user3['.internal_flag'] = true;

$json = json_encode($user3, JSON_PRETTY_PRINT);
echo "JSON output (transient excluded by default):\n";
echo $json . "\n";

// ============================================================
// EXAMPLE 8: Practical Use Case - Request Context
// ============================================================

echo "\n8. Practical Use Case - Request Context\n";
echo str_repeat('-', 40) . "\n";

function process_api_request(User $user, array $request_context): array
{
    // Attach request context as transient data
    $user->set('.request_id', $request_context['request_id'])
         ->set('.client_ip', $request_context['ip'])
         ->set('.timestamp', $request_context['timestamp']);

    // Simulate some processing that uses the context
    $log_entry = sprintf(
        "[%s] User %s (%s) accessed from %s",
        date('Y-m-d H:i:s', $user['.timestamp']),
        $user->full_name(),
        $user['email'],
        $user['.client_ip']
    );

    return [
        'request_id' => $user['.request_id'],
        'user' => $user->to_array(false),  // Persistent data only for response
        'log' => $log_entry,
    ];
}

$api_user = User::make([
    'first_name' => 'API',
    'last_name' => 'User',
    'email' => 'api@example.com',
]);

$result = process_api_request($api_user, [
    'request_id' => 'req-' . uniqid(),
    'ip' => '10.0.0.1',
    'timestamp' => time(),
]);

echo "Request ID: {$result['request_id']}\n";
echo "Log: {$result['log']}\n";
echo "\nResponse user data (no transient):\n";
print_r($result['user']);

echo "\n=== Example Complete ===\n";
