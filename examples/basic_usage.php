<?php
/**
 * Italix ORM - Basic Usage Example
 * 
 * Run with: php examples/basic_usage.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// If running without Composer autoload, use this instead:
// require_once __DIR__ . '/../src/functions.php';
// ... (you would need to manually require all files)

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, text, varchar, boolean, timestamp};
use function Italix\Orm\Operators\{eq, ne, gt, gte, lt, lte, and_, or_, like, desc, asc};

echo "===========================================\n";
echo "  Italix ORM - Basic Usage Example\n";
echo "===========================================\n\n";

// ============================================
// 1. Create Database Connection
// ============================================

echo "1. Creating SQLite in-memory database...\n";
$db = sqlite_memory();
echo "   ✓ Database connection established\n\n";

// ============================================
// 2. Define Table Schema
// ============================================

echo "2. Defining table schema...\n";

$users = sqlite_table('users', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(100)->not_null(),
    'email'      => varchar(255)->not_null()->unique(),
    'age'        => integer(),
    'is_active'  => boolean()->default(true),
    'created_at' => text(),
]);

echo "   ✓ Schema defined: users (id, name, email, age, is_active, created_at)\n\n";

// ============================================
// 3. Create Table
// ============================================

echo "3. Creating table...\n";
$db->create_tables($users);
echo "   ✓ Table 'users' created\n\n";

// ============================================
// 4. Insert Records
// ============================================

echo "4. Inserting records...\n";

// Single insert
$db->insert($users)->values([
    'name'       => 'Alice Johnson',
    'email'      => 'alice@example.com',
    'age'        => 28,
    'is_active'  => true,
    'created_at' => date('Y-m-d H:i:s'),
])->execute();
echo "   ✓ Inserted: Alice Johnson\n";

// Multiple inserts
$db->insert($users)->values([
    [
        'name'       => 'Bob Smith',
        'email'      => 'bob@example.com',
        'age'        => 35,
        'is_active'  => true,
        'created_at' => date('Y-m-d H:i:s'),
    ],
    [
        'name'       => 'Charlie Brown',
        'email'      => 'charlie@example.com',
        'age'        => 22,
        'is_active'  => false,
        'created_at' => date('Y-m-d H:i:s'),
    ],
    [
        'name'       => 'Diana Prince',
        'email'      => 'diana@example.com',
        'age'        => 30,
        'is_active'  => true,
        'created_at' => date('Y-m-d H:i:s'),
    ],
])->execute();
echo "   ✓ Inserted: Bob, Charlie, Diana\n\n";

// ============================================
// 5. Select All Records
// ============================================

echo "5. Selecting all records...\n";
$all_users = $db->select()->from($users)->execute();
echo "   Found " . count($all_users) . " users:\n";
foreach ($all_users as $user) {
    echo "   - [{$user['id']}] {$user['name']} ({$user['email']})\n";
}
echo "\n";

// ============================================
// 6. Select with WHERE
// ============================================

echo "6. Selecting active users over 25...\n";
$filtered = $db->select()
    ->from($users)
    ->where(and_(
        eq($users->is_active, 1),
        gt($users->age, 25)
    ))
    ->execute();
echo "   Found " . count($filtered) . " users:\n";
foreach ($filtered as $user) {
    echo "   - {$user['name']} (age: {$user['age']})\n";
}
echo "\n";

// ============================================
// 7. Select with ORDER BY and LIMIT
// ============================================

echo "7. Selecting top 2 users by age (descending)...\n";
$top_users = $db->select()
    ->from($users)
    ->order_by(desc($users->age))
    ->limit(2)
    ->execute();
foreach ($top_users as $user) {
    echo "   - {$user['name']} (age: {$user['age']})\n";
}
echo "\n";

// ============================================
// 8. Update Record
// ============================================

echo "8. Updating Charlie's status to active...\n";
$db->update($users)
    ->set(['is_active' => true, 'age' => 23])
    ->where(eq($users->name, 'Charlie Brown'))
    ->execute();

$charlie = $db->select()
    ->from($users)
    ->where(eq($users->name, 'Charlie Brown'))
    ->execute()[0];
echo "   ✓ Updated: {$charlie['name']} is now active (age: {$charlie['age']})\n\n";

// ============================================
// 9. Transaction Example
// ============================================

echo "9. Transaction example...\n";
$result = $db->transaction(function($db) use ($users) {
    $db->insert($users)->values([
        'name'       => 'Eve Wilson',
        'email'      => 'eve@example.com',
        'age'        => 27,
        'is_active'  => true,
        'created_at' => date('Y-m-d H:i:s'),
    ])->execute();
    
    return $db->last_insert_id();
});
echo "   ✓ Transaction committed. New user ID: {$result}\n\n";

// ============================================
// 10. Delete Record
// ============================================

echo "10. Deleting Eve...\n";
$db->delete($users)
    ->where(eq($users->email, 'eve@example.com'))
    ->execute();
echo "    ✓ Deleted user with email: eve@example.com\n\n";

// ============================================
// 11. Final Count
// ============================================

echo "11. Final user count...\n";
$final_users = $db->select()->from($users)->execute();
echo "    Total users: " . count($final_users) . "\n\n";

// ============================================
// 12. LIKE Query
// ============================================

echo "12. Searching users with '@example.com' email...\n";
$example_users = $db->select()
    ->from($users)
    ->where(like($users->email, '%@example.com'))
    ->execute();
echo "    Found " . count($example_users) . " users with @example.com email\n\n";

echo "===========================================\n";
echo "  ✅ All examples completed successfully!\n";
echo "===========================================\n";
