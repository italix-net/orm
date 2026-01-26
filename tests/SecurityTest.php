<?php
/**
 * Italix ORM - Security Test Suite
 * 
 * Tests for SQL injection vulnerabilities and query accuracy.
 */

require_once __DIR__ . '/../src/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\sql;
use function Italix\Orm\Schema\sqlite_table;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\text;
use function Italix\Orm\Operators\{
    eq, ne, gt, gte, lt, lte,
    and_, or_, not_,
    like, not_like, ilike,
    between, not_between,
    in_array, not_in_array,
    is_null, is_not_null,
    asc, desc,
    sql_count, sql_sum, sql_avg, sql_min, sql_max,
    raw
};

echo "===========================================\n";
echo "  Italix ORM - Security Test Suite\n";
echo "===========================================\n\n";

$passed = 0;
$failed = 0;

function test($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: {$name}\n";
        $passed++;
    } else {
        echo "✗ FAIL: {$name}\n";
        if ($details) {
            echo "        Details: {$details}\n";
        }
        $failed++;
    }
}

// Create database
$db = sqlite_memory();

// ============================================
// SECTION 1: SQL Injection Prevention Tests
// ============================================

echo "\n--- SQL Injection Prevention Tests ---\n";

// Test 1: Malicious table name
$malicious_table_name = 'users"; DROP TABLE users; --';
$table = sqlite_table($malicious_table_name, [
    'id' => integer()->primary_key(),
    'name' => varchar(100),
]);
$create_sql = $table->to_create_sql();
// The quotes should be escaped (doubled)
test(
    'Malicious table name is properly escaped',
    strpos($create_sql, '""') !== false || strpos($create_sql, 'DROP') === false,
    "SQL: " . substr($create_sql, 0, 100)
);

// Test 2: Malicious column name
$malicious_col_name = 'name"; DROP TABLE users; --';
$table2 = sqlite_table('safe_table', [
    'id' => integer()->primary_key(),
    $malicious_col_name => varchar(100),
]);
$create_sql2 = $table2->to_create_sql();
test(
    'Malicious column name is properly escaped',
    strpos($create_sql2, '""') !== false,
    "SQL: " . substr($create_sql2, 0, 100)
);

// Test 3: Create a safe table for further tests
$users = sqlite_table('test_users', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255),
    'status' => varchar(20)->default('active'),
]);
$db->create_tables($users);

// Test 4: SQL injection via value in INSERT
$malicious_value = "'; DROP TABLE test_users; --";
try {
    $db->insert($users)->values([
        'name' => $malicious_value,
        'email' => 'test@test.com'
    ])->execute();
    
    // Table should still exist
    $count = $db->sql('SELECT COUNT(*) as cnt FROM test_users')->one();
    test(
        'INSERT value injection prevented',
        $count !== null && $count['cnt'] >= 1,
        "Table still accessible after malicious insert"
    );
} catch (\Exception $e) {
    test('INSERT value injection prevented', false, $e->getMessage());
}

// Test 5: SQL injection via WHERE condition value
$malicious_where = "1 OR 1=1; --";
try {
    $result = $db->select()
        ->from($users)
        ->where(eq($users->name, $malicious_where))
        ->execute();
    test(
        'WHERE condition injection prevented',
        count($result) === 0, // Should not return all rows
        "Query returned " . count($result) . " rows"
    );
} catch (\Exception $e) {
    test('WHERE condition injection prevented', true, "Exception caught safely");
}

// Test 6: SQL injection via LIKE pattern
$malicious_like = "%'; DELETE FROM test_users; --";
try {
    $result = $db->select()
        ->from($users)
        ->where(like($users->name, $malicious_like))
        ->execute();
    
    // Table should still have data
    $count = $db->sql('SELECT COUNT(*) as cnt FROM test_users')->one();
    test(
        'LIKE pattern injection prevented',
        $count['cnt'] >= 1,
        "Table still has data"
    );
} catch (\Exception $e) {
    test('LIKE pattern injection prevented', true, "Exception caught safely");
}

// Test 7: SQL injection via IN array values
$malicious_in = ["admin", "'); DROP TABLE test_users; --"];
try {
    $result = $db->select()
        ->from($users)
        ->where(in_array($users->name, $malicious_in))
        ->execute();
    
    $count = $db->sql('SELECT COUNT(*) as cnt FROM test_users')->one();
    test(
        'IN array injection prevented',
        $count['cnt'] >= 1,
        "Table still has data"
    );
} catch (\Exception $e) {
    test('IN array injection prevented', true, "Exception caught safely");
}

// Test 8: SQL injection via sql() builder with identifier
$malicious_identifier = 'users"; DROP TABLE test_users; --';
$sql_obj = $db->sql()
    ->append('SELECT * FROM ')
    ->identifier($malicious_identifier);
$query_str = $sql_obj->get_query();
test(
    'sql() identifier injection prevented',
    strpos($query_str, '""') !== false,
    "Query: {$query_str}"
);

// Test 9: SQL injection via sql() with parameterized value
try {
    $result = $db->sql(
        'SELECT * FROM test_users WHERE name = ?',
        [$malicious_value]
    )->all();
    
    $count = $db->sql('SELECT COUNT(*) as cnt FROM test_users')->one();
    test(
        'sql() parameterized value injection prevented',
        $count['cnt'] >= 1,
        "Table still has data"
    );
} catch (\Exception $e) {
    test('sql() parameterized value injection prevented', true, "Exception caught safely");
}

// Test 10: SQL injection via UPDATE
try {
    $db->update($users)
        ->set(['status' => "'; DROP TABLE test_users; --"])
        ->where(eq($users->id, 1))
        ->execute();
    
    $count = $db->sql('SELECT COUNT(*) as cnt FROM test_users')->one();
    test(
        'UPDATE value injection prevented',
        $count['cnt'] >= 1,
        "Table still has data"
    );
} catch (\Exception $e) {
    test('UPDATE value injection prevented', true, "Exception caught safely");
}

// ============================================
// SECTION 2: Query Accuracy Tests
// ============================================

echo "\n--- Query Accuracy Tests ---\n";

// Drop and recreate table to reset IDs
$db->drop_tables($users);
$db->create_tables($users);

// Repopulate test data with fresh IDs starting from 1
$db->insert($users)->values([
    ['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active'],
    ['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'active'],
    ['name' => 'Charlie', 'email' => 'charlie@test.com', 'status' => 'inactive'],
    ['name' => 'Diana', 'email' => 'diana@test.com', 'status' => 'active'],
])->execute();

// Test 11: eq() accuracy
$result = $db->select()->from($users)->where(eq($users->name, 'Alice'))->execute();
test(
    'eq() returns exact match',
    count($result) === 1 && $result[0]['name'] === 'Alice',
    "Found " . count($result) . " results"
);

// Test 12: ne() accuracy
$result = $db->select()->from($users)->where(ne($users->name, 'Alice'))->execute();
test(
    'ne() excludes exact match',
    count($result) === 3 && !\in_array('Alice', array_column($result, 'name')),
    "Found " . count($result) . " results"
);

// Test 13: gt() accuracy
$result = $db->select()->from($users)->where(gt($users->id, 2))->execute();
test(
    'gt() returns correct results',
    count($result) === 2,
    "Found " . count($result) . " results with id > 2"
);

// Test 14: gte() accuracy
$result = $db->select()->from($users)->where(gte($users->id, 2))->execute();
test(
    'gte() returns correct results',
    count($result) === 3,
    "Found " . count($result) . " results with id >= 2"
);

// Test 15: lt() accuracy
$result = $db->select()->from($users)->where(lt($users->id, 3))->execute();
test(
    'lt() returns correct results',
    count($result) === 2,
    "Found " . count($result) . " results with id < 3"
);

// Test 16: lte() accuracy
$result = $db->select()->from($users)->where(lte($users->id, 3))->execute();
test(
    'lte() returns correct results',
    count($result) === 3,
    "Found " . count($result) . " results with id <= 3"
);

// Test 17: and_() accuracy
$result = $db->select()->from($users)->where(
    and_(
        eq($users->status, 'active'),
        gt($users->id, 1)
    )
)->execute();
test(
    'and_() combines conditions correctly',
    count($result) === 2, // Bob and Diana
    "Found " . count($result) . " active users with id > 1"
);

// Test 18: or_() accuracy
$result = $db->select()->from($users)->where(
    or_(
        eq($users->name, 'Alice'),
        eq($users->name, 'Bob')
    )
)->execute();
test(
    'or_() combines conditions correctly',
    count($result) === 2,
    "Found " . count($result) . " users named Alice or Bob"
);

// Test 19: not_() accuracy
$result = $db->select()->from($users)->where(
    not_(eq($users->status, 'active'))
)->execute();
test(
    'not_() negates condition correctly',
    count($result) === 1 && $result[0]['name'] === 'Charlie',
    "Found " . count($result) . " inactive users"
);

// Test 20: like() accuracy
$result = $db->select()->from($users)->where(like($users->name, 'A%'))->execute();
test(
    'like() matches pattern correctly',
    count($result) === 1 && $result[0]['name'] === 'Alice',
    "Found " . count($result) . " users starting with A"
);

// Test 21: not_like() accuracy
$result = $db->select()->from($users)->where(not_like($users->name, 'A%'))->execute();
test(
    'not_like() excludes pattern correctly',
    count($result) === 3,
    "Found " . count($result) . " users not starting with A"
);

// Test 22: in_array() accuracy
$result = $db->select()->from($users)->where(
    in_array($users->name, ['Alice', 'Bob', 'Eve'])
)->execute();
test(
    'in_array() matches list correctly',
    count($result) === 2,
    "Found " . count($result) . " users in list"
);

// Test 23: not_in_array() accuracy
$result = $db->select()->from($users)->where(
    not_in_array($users->name, ['Alice', 'Bob'])
)->execute();
test(
    'not_in_array() excludes list correctly',
    count($result) === 2,
    "Found " . count($result) . " users not in list"
);

// Test 24: between() accuracy
$result = $db->select()->from($users)->where(between($users->id, 2, 3))->execute();
test(
    'between() includes range correctly',
    count($result) === 2,
    "Found " . count($result) . " users with id between 2 and 3"
);

// Test 25: not_between() accuracy
$result = $db->select()->from($users)->where(not_between($users->id, 2, 3))->execute();
test(
    'not_between() excludes range correctly',
    count($result) === 2,
    "Found " . count($result) . " users with id not between 2 and 3"
);

// Test 26: ORDER BY accuracy
$result = $db->select()->from($users)->order_by(desc($users->id))->execute();
test(
    'ORDER BY DESC works correctly',
    $result[0]['id'] > $result[count($result)-1]['id'],
    "First id: {$result[0]['id']}, Last id: {$result[count($result)-1]['id']}"
);

// Test 27: LIMIT accuracy
$result = $db->select()->from($users)->limit(2)->execute();
test(
    'LIMIT works correctly',
    count($result) === 2,
    "Found " . count($result) . " results with limit 2"
);

// Test 28: OFFSET accuracy
$result = $db->select()->from($users)->order_by(asc($users->id))->limit(2)->offset(1)->execute();
test(
    'OFFSET works correctly',
    count($result) === 2 && $result[0]['id'] == 2,
    "First result id: {$result[0]['id']}"
);

// Test 29: SELECT specific columns
$result = $db->select([$users->name, $users->email])->from($users)->limit(1)->execute();
test(
    'SELECT specific columns works correctly',
    isset($result[0]['name']) && isset($result[0]['email']) && !isset($result[0]['status']),
    "Columns returned: " . implode(', ', array_keys($result[0]))
);

// Test 30: Aggregate functions
$result = $db->select([sql_count()->as('total')])->from($users)->execute();
test(
    'COUNT(*) works correctly',
    $result[0]['total'] == 4,
    "Count: {$result[0]['total']}"
);

// ============================================
// SECTION 3: Complex Query Tests
// ============================================

echo "\n--- Complex Query Tests ---\n";

// Test 31: Complex WHERE with mixed operators
$result = $db->select()->from($users)->where(
    and_(
        eq($users->status, 'active'),
        or_(
            like($users->name, 'A%'),
            like($users->name, 'D%')
        )
    )
)->execute();
test(
    'Complex nested AND/OR works correctly',
    count($result) === 2, // Alice and Diana
    "Found " . count($result) . " active users starting with A or D"
);

// Test 32: UPDATE with WHERE
$db->update($users)
    ->set(['status' => 'premium'])
    ->where(eq($users->name, 'Alice'))
    ->execute();
$result = $db->select()->from($users)->where(eq($users->name, 'Alice'))->execute();
test(
    'UPDATE with WHERE works correctly',
    $result[0]['status'] === 'premium',
    "Alice status: {$result[0]['status']}"
);

// Test 33: DELETE with WHERE
$db->insert($users)->values(['name' => 'ToDelete', 'email' => 'delete@test.com'])->execute();
$db->delete($users)->where(eq($users->name, 'ToDelete'))->execute();
$result = $db->select()->from($users)->where(eq($users->name, 'ToDelete'))->execute();
test(
    'DELETE with WHERE works correctly',
    count($result) === 0,
    "Found " . count($result) . " ToDelete users"
);

// Test 34: Transaction commit
$result = $db->transaction(function($db) use ($users) {
    $db->insert($users)->values(['name' => 'TxUser', 'email' => 'tx@test.com'])->execute();
    return 'committed';
});
$found = $db->select()->from($users)->where(eq($users->name, 'TxUser'))->execute();
test(
    'Transaction COMMIT works correctly',
    count($found) === 1,
    "Found " . count($found) . " TxUser records"
);

// ============================================
// SUMMARY
// ============================================

echo "\n===========================================\n";
echo "  Test Results: {$passed} passed, {$failed} failed\n";
echo "===========================================\n";

if ($failed > 0) {
    echo "  ⚠️  SECURITY OR ACCURACY ISSUES DETECTED!\n";
    exit(1);
} else {
    echo "  ✅ All security and accuracy tests passed!\n";
    exit(0);
}
