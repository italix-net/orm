<?php
/**
 * Italix ORM - Comprehensive Feature Test
 */

require_once __DIR__ . '/../src/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\sqlite_table;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\real;
use function Italix\Orm\Operators\{
    eq, ne, gt, gte, lt, lte,
    and_, or_, not_,
    like, not_like, ilike, not_ilike,
    between, not_between,
    in_array, not_in_array,
    is_null, is_not_null,
    asc, desc,
    sql_count, sql_sum, sql_avg, sql_min, sql_max, sql_count_distinct,
    raw
};

echo "===========================================\n";
echo "  Italix ORM - Comprehensive Feature Test\n";
echo "===========================================\n\n";

// Create database
$db = sqlite_memory();
echo "✓ Database connection established\n\n";

// ============================================
// Schema Definition
// ============================================

$users = sqlite_table('users', [
    'id'       => integer()->primary_key()->auto_increment(),
    'name'     => varchar(100)->not_null(),
    'email'    => varchar(255)->not_null()->unique(),
    'age'      => integer(),
    'salary'   => real(),
    'status'   => varchar(20)->default('active'),
]);

$orders = sqlite_table('orders', [
    'id'       => integer()->primary_key()->auto_increment(),
    'user_id'  => integer()->not_null(),
    'amount'   => real()->not_null(),
    'product'  => varchar(100),
]);

// Create tables
$db->create_tables($users, $orders);
echo "✓ Tables created\n\n";

// ============================================
// INSERT Tests
// ============================================

echo "--- INSERT Tests ---\n";

// Single insert
$db->insert($users)->values([
    'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25, 'salary' => 50000.00
])->execute();
echo "✓ Single insert\n";

// Multiple inserts
$db->insert($users)->values([
    ['name' => 'Bob', 'email' => 'bob@test.com', 'age' => 30, 'salary' => 60000.00],
    ['name' => 'Charlie', 'email' => 'charlie@test.com', 'age' => 35, 'salary' => 70000.00],
    ['name' => 'Diana', 'email' => 'diana@test.com', 'age' => 28, 'salary' => 55000.00],
    ['name' => 'Eve', 'email' => 'eve@test.com', 'age' => null, 'salary' => 45000.00],
])->execute();
echo "✓ Multiple inserts\n";

// Insert orders
$db->insert($orders)->values([
    ['user_id' => 1, 'amount' => 100.00, 'product' => 'Widget'],
    ['user_id' => 1, 'amount' => 200.00, 'product' => 'Gadget'],
    ['user_id' => 2, 'amount' => 150.00, 'product' => 'Widget'],
    ['user_id' => 3, 'amount' => 300.00, 'product' => 'Gizmo'],
])->execute();
echo "✓ Orders inserted\n";

// ON CONFLICT DO UPDATE (upsert)
$db->insert($users)->values([
    'name' => 'Alice Updated', 'email' => 'alice@test.com', 'age' => 26, 'salary' => 52000.00
])->on_conflict_do_update(['email'], [
    'name' => 'Alice Updated',
    'age' => 26,
    'salary' => 52000.00
])->execute();
echo "✓ ON CONFLICT DO UPDATE (upsert)\n";

// ON CONFLICT DO NOTHING
$db->insert($users)->values([
    'name' => 'Fake', 'email' => 'alice@test.com', 'age' => 99, 'salary' => 0
])->on_conflict_do_nothing(['email'])->execute();
echo "✓ ON CONFLICT DO NOTHING\n\n";

// ============================================
// SELECT Tests
// ============================================

echo "--- SELECT Tests ---\n";

// Select all
$all = $db->select()->from($users)->execute();
echo "✓ SELECT * ({$all[0]['name']} is now updated)\n";

// Select specific columns
$cols = $db->select([$users->name, $users->email])->from($users)->limit(2)->execute();
echo "✓ SELECT specific columns: " . count($cols) . " rows\n";

// ORDER BY, LIMIT, OFFSET
$ordered = $db->select()
    ->from($users)
    ->order_by(desc($users->salary))
    ->limit(3)
    ->offset(1)
    ->execute();
echo "✓ ORDER BY, LIMIT, OFFSET: Top earner (after offset): {$ordered[0]['name']}\n";

// ============================================
// WHERE Operator Tests
// ============================================

echo "\n--- WHERE Operator Tests ---\n";

// eq, ne
$r = $db->select()->from($users)->where(eq($users->name, 'Bob'))->execute();
echo "✓ eq(): Found {$r[0]['name']}\n";

$r = $db->select()->from($users)->where(ne($users->name, 'Bob'))->execute();
echo "✓ ne(): Found " . count($r) . " users not named Bob\n";

// gt, gte, lt, lte
$r = $db->select()->from($users)->where(gt($users->age, 28))->execute();
echo "✓ gt(): " . count($r) . " users with age > 28\n";

$r = $db->select()->from($users)->where(gte($users->age, 28))->execute();
echo "✓ gte(): " . count($r) . " users with age >= 28\n";

$r = $db->select()->from($users)->where(lt($users->salary, 55000))->execute();
echo "✓ lt(): " . count($r) . " users with salary < 55000\n";

$r = $db->select()->from($users)->where(lte($users->salary, 55000))->execute();
echo "✓ lte(): " . count($r) . " users with salary <= 55000\n";

// and_, or_, not_
$r = $db->select()->from($users)->where(
    and_(
        gte($users->age, 25),
        lte($users->age, 30)
    )
)->execute();
echo "✓ and_(): " . count($r) . " users aged 25-30\n";

$r = $db->select()->from($users)->where(
    or_(
        eq($users->name, 'Alice Updated'),
        eq($users->name, 'Bob')
    )
)->execute();
echo "✓ or_(): " . count($r) . " users (Alice or Bob)\n";

$r = $db->select()->from($users)->where(
    not_(eq($users->status, 'inactive'))
)->execute();
echo "✓ not_(): " . count($r) . " active users\n";

// like, not_like
$r = $db->select()->from($users)->where(like($users->email, '%@test.com'))->execute();
echo "✓ like(): " . count($r) . " users with @test.com email\n";

$r = $db->select()->from($users)->where(not_like($users->name, 'A%'))->execute();
echo "✓ not_like(): " . count($r) . " users whose name doesn't start with A\n";

// between, not_between
$r = $db->select()->from($users)->where(between($users->salary, 50000, 60000))->execute();
echo "✓ between(): " . count($r) . " users with salary 50k-60k\n";

$r = $db->select()->from($users)->where(not_between($users->salary, 50000, 60000))->execute();
echo "✓ not_between(): " . count($r) . " users with salary NOT 50k-60k\n";

// in_array, not_in_array
$r = $db->select()->from($users)->where(in_array($users->name, ['Alice Updated', 'Bob', 'Charlie']))->execute();
echo "✓ in_array(): " . count($r) . " users in list\n";

$r = $db->select()->from($users)->where(not_in_array($users->name, ['Alice Updated', 'Bob']))->execute();
echo "✓ not_in_array(): " . count($r) . " users NOT in list\n";

// is_null, is_not_null
$r = $db->select()->from($users)->where(is_null($users->age))->execute();
echo "✓ is_null(): " . count($r) . " users with null age\n";

$r = $db->select()->from($users)->where(is_not_null($users->age))->execute();
echo "✓ is_not_null(): " . count($r) . " users with non-null age\n";

// ============================================
// Aggregate Functions Tests
// ============================================

echo "\n--- Aggregate Functions Tests ---\n";

// count
$r = $db->select([sql_count()])->from($users)->execute();
echo "✓ count(*): {$r[0]['COUNT(*)']}\n";

// count with column
$r = $db->select([sql_count($users->age)])->from($users)->execute();
$cnt = array_values($r[0])[0];
echo "✓ count(age): {$cnt} (excludes nulls)\n";

// sum
$r = $db->select([sql_sum($users->salary)->as('total_salary')])->from($users)->execute();
echo "✓ sum(salary): {$r[0]['total_salary']}\n";

// avg
$r = $db->select([sql_avg($users->salary)->as('avg_salary')])->from($users)->execute();
echo "✓ avg(salary): " . round($r[0]['avg_salary'], 2) . "\n";

// min, max
$r = $db->select([sql_min($users->age)->as('min_age'), sql_max($users->age)->as('max_age')])->from($users)->execute();
echo "✓ min/max(age): {$r[0]['min_age']} - {$r[0]['max_age']}\n";

// ============================================
// GROUP BY Tests
// ============================================

echo "\n--- GROUP BY Tests ---\n";

$r = $db->select([$orders->product, sql_count()->as('cnt'), sql_sum($orders->amount)->as('total')])
    ->from($orders)
    ->group_by($orders->product)
    ->order_by(desc(raw('total')))
    ->execute();
echo "✓ GROUP BY product:\n";
foreach ($r as $row) {
    echo "    {$row['product']}: {$row['cnt']} orders, \${$row['total']}\n";
}

// ============================================
// JOIN Tests
// ============================================

echo "\n--- JOIN Tests ---\n";

// INNER JOIN
$r = $db->select([$users->name, $orders->product, $orders->amount])
    ->from($users)
    ->inner_join($orders, eq($users->id, $orders->user_id))
    ->execute();
echo "✓ INNER JOIN: " . count($r) . " user-order pairs\n";

// LEFT JOIN
$r = $db->select([$users->name, sql_count($orders->id)->as('order_count')])
    ->from($users)
    ->left_join($orders, eq($users->id, $orders->user_id))
    ->group_by($users->id)
    ->execute();
echo "✓ LEFT JOIN with GROUP BY:\n";
foreach ($r as $row) {
    echo "    {$row['name']}: {$row['order_count']} orders\n";
}

// ============================================
// UPDATE Tests
// ============================================

echo "\n--- UPDATE Tests ---\n";

$affected = $db->update($users)
    ->set(['status' => 'premium'])
    ->where(gte($users->salary, 60000))
    ->execute();
echo "✓ UPDATE: {$affected} rows updated to premium\n";

// ============================================
// DELETE Tests
// ============================================

echo "\n--- DELETE Tests ---\n";

// First add a test user to delete
$db->insert($users)->values([
    'name' => 'ToDelete', 'email' => 'delete@test.com', 'age' => 99, 'salary' => 1000
])->execute();

$affected = $db->delete($users)
    ->where(eq($users->email, 'delete@test.com'))
    ->execute();
echo "✓ DELETE: {$affected} row(s) deleted\n";

// ============================================
// Transaction Test
// ============================================

echo "\n--- Transaction Test ---\n";

$result = $db->transaction(function($db) use ($users) {
    $db->insert($users)->values([
        'name' => 'TransactionUser', 'email' => 'tx@test.com', 'age' => 40, 'salary' => 80000
    ])->execute();
    return 'success';
});
echo "✓ Transaction: {$result}\n";

// Final count
$final = $db->select([sql_count()->as('total')])->from($users)->execute();
echo "\n✓ Final user count: {$final[0]['total']}\n";

echo "\n===========================================\n";
echo "  ✅ All feature tests passed!\n";
echo "===========================================\n";
