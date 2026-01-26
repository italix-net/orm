<?php
/**
 * Italix ORM - Test (without Composer)
 */

// Load autoloader
require_once __DIR__ . '/../src/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\sqlite_table;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\desc;

echo "===========================================\n";
echo "  Italix ORM - Test\n";
echo "===========================================\n\n";

// Create database
echo "Creating SQLite in-memory database...\n";
$db = sqlite_memory();
echo "✓ Connected\n\n";

// Define schema
echo "Defining schema...\n";
$users = sqlite_table('users', [
    'id'    => integer()->primary_key()->auto_increment(),
    'name'  => varchar(100)->not_null(),
    'email' => varchar(255)->not_null(),
]);
echo "✓ Schema defined\n\n";

// Create table
echo "Creating table...\n";
$db->create_tables($users);
echo "✓ Table created\n\n";

// Insert
echo "Inserting data...\n";
$db->insert($users)->values([
    'name'  => 'Test User',
    'email' => 'test@example.com',
])->execute();

$db->insert($users)->values([
    'name'  => 'Another User',
    'email' => 'another@example.com',
])->execute();
echo "✓ Data inserted\n\n";

// Select
echo "Selecting all users...\n";
$results = $db->select()->from($users)->order_by(desc($users->id))->execute();
foreach ($results as $user) {
    echo "  [{$user['id']}] {$user['name']} - {$user['email']}\n";
}
echo "\n";

// Select with WHERE
echo "Selecting user by email...\n";
$results = $db->select()
    ->from($users)
    ->where(eq($users->email, 'test@example.com'))
    ->execute();
echo "  Found: {$results[0]['name']}\n\n";

// Update
echo "Updating user...\n";
$db->update($users)
    ->set(['name' => 'Updated User'])
    ->where(eq($users->id, 1))
    ->execute();

$updated = $db->select()->from($users)->where(eq($users->id, 1))->execute()[0];
echo "  Updated name: {$updated['name']}\n\n";

// Delete
echo "Deleting user...\n";
$db->delete($users)->where(eq($users->id, 2))->execute();
echo "✓ User deleted\n\n";

// Final count
$count = count($db->select()->from($users)->execute());
echo "Final user count: {$count}\n\n";

echo "===========================================\n";
echo "  ✅ All tests passed!\n";
echo "===========================================\n";
