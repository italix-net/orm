<?php
/**
 * Italix ORM - SQL Dialect Test Suite
 * 
 * Tests SQL generation for all supported dialects:
 * - MySQL
 * - PostgreSQL
 * - SQLite
 * - Supabase (PostgreSQL-compatible)
 */

require_once __DIR__ . '/../src/autoload.php';

use Italix\Orm\QueryBuilder\QueryBuilder;
use Italix\Orm\Schema\Table;
use Italix\Orm\Schema\Column;
use function Italix\Orm\Schema\{mysql_table, pg_table, sqlite_table};
use function Italix\Orm\Schema\{integer, varchar, text, boolean, timestamp, serial};
use function Italix\Orm\Operators\{
    eq, ne, gt, gte, lt, lte,
    and_, or_, not_,
    like, not_like, ilike, not_ilike,
    between, not_between,
    in_array as sql_in_array, not_in_array as sql_not_in_array,
    is_null, is_not_null,
    asc, desc,
    sql_count, sql_sum, raw
};

echo "===========================================\n";
echo "  Italix ORM - SQL Dialect Test Suite\n";
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

function create_test_table(string $dialect): Table {
    switch ($dialect) {
        case 'mysql':
            return mysql_table('users', [
                'id' => integer()->primary_key()->auto_increment(),
                'name' => varchar(100)->not_null(),
                'email' => varchar(255)->unique(),
                'status' => varchar(20)->default('active'),
                'age' => integer(),
            ]);
        case 'postgresql':
        case 'supabase':
            return pg_table('users', [
                'id' => serial(),
                'name' => varchar(100)->not_null(),
                'email' => varchar(255)->unique(),
                'status' => varchar(20)->default('active'),
                'age' => integer(),
            ]);
        case 'sqlite':
            return sqlite_table('users', [
                'id' => integer()->primary_key()->auto_increment(),
                'name' => varchar(100)->not_null(),
                'email' => varchar(255)->unique(),
                'status' => varchar(20)->default('active'),
                'age' => integer(),
            ]);
        default:
            throw new \InvalidArgumentException("Unknown dialect: {$dialect}");
    }
}

// ============================================
// Test each dialect
// ============================================

$dialects = ['mysql', 'postgresql', 'sqlite', 'supabase'];

foreach ($dialects as $dialect) {
    echo "\n--- Testing {$dialect} dialect ---\n";
    
    $users = create_test_table($dialect);
    
    // ============================================
    // Identifier Quoting Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()->from($users)->to_sql($params);
    
    if ($dialect === 'mysql') {
        test(
            "{$dialect}: Identifiers use backticks",
            strpos($sql, '`users`') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: Identifiers use double quotes",
            strpos($sql, '"users"') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // Placeholder Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(eq($users->name, 'Alice'))
        ->to_sql($params);
    
    if (\in_array($dialect, ['postgresql', 'supabase'])) {
        test(
            "{$dialect}: Uses numbered placeholders (\$1)",
            strpos($sql, '$1') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: Uses question mark placeholders",
            strpos($sql, '?') !== false && strpos($sql, '$') === false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // Multiple Placeholder Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(
            and_(
                eq($users->name, 'Alice'),
                gt($users->age, 18),
                eq($users->status, 'active')
            )
        )
        ->to_sql($params);
    
    if (\in_array($dialect, ['postgresql', 'supabase'])) {
        test(
            "{$dialect}: Multiple numbered placeholders (\$1, \$2, \$3)",
            strpos($sql, '$1') !== false && 
            strpos($sql, '$2') !== false && 
            strpos($sql, '$3') !== false,
            "SQL: {$sql}"
        );
    } else {
        $question_count = substr_count($sql, '?');
        test(
            "{$dialect}: Multiple question mark placeholders",
            $question_count === 3,
            "SQL: {$sql}, found {$question_count} placeholders"
        );
    }
    
    test(
        "{$dialect}: Correct number of params",
        count($params) === 3,
        "Params: " . json_encode($params)
    );
    
    // ============================================
    // LIKE/ILIKE Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(like($users->name, 'A%'))
        ->to_sql($params);
    
    test(
        "{$dialect}: LIKE operator",
        strpos($sql, 'LIKE') !== false,
        "SQL: {$sql}"
    );
    
    // ILIKE test
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(ilike($users->name, 'alice'))
        ->to_sql($params);
    
    if (\in_array($dialect, ['postgresql', 'supabase'])) {
        test(
            "{$dialect}: Native ILIKE operator",
            strpos($sql, 'ILIKE') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: ILIKE emulated with LOWER()",
            strpos($sql, 'LOWER(') !== false && strpos($sql, 'LIKE') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // INSERT Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->insert($users)
        ->values(['name' => 'Alice', 'email' => 'alice@test.com'])
        ->to_sql($params);
    
    test(
        "{$dialect}: INSERT statement",
        strpos($sql, 'INSERT INTO') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // INSERT ... ON CONFLICT DO NOTHING Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->insert($users)
        ->values(['name' => 'Alice', 'email' => 'alice@test.com'])
        ->on_conflict_do_nothing(['email'])
        ->to_sql($params);
    
    if ($dialect === 'mysql') {
        test(
            "{$dialect}: INSERT IGNORE for DO NOTHING",
            strpos($sql, 'INSERT IGNORE') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: ON CONFLICT DO NOTHING",
            strpos($sql, 'ON CONFLICT') !== false && strpos($sql, 'DO NOTHING') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // INSERT ... ON CONFLICT DO UPDATE (Upsert) Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->insert($users)
        ->values(['name' => 'Alice', 'email' => 'alice@test.com'])
        ->on_conflict_do_update(['email'], ['name' => 'Alice Updated'])
        ->to_sql($params);
    
    if ($dialect === 'mysql') {
        test(
            "{$dialect}: ON DUPLICATE KEY UPDATE",
            strpos($sql, 'ON DUPLICATE KEY UPDATE') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: ON CONFLICT DO UPDATE",
            strpos($sql, 'ON CONFLICT') !== false && strpos($sql, 'DO UPDATE SET') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // RETURNING Tests
    // ============================================
    
    if ($dialect !== 'mysql') {
        $builder = new QueryBuilder($dialect);
        $params = [];
        $sql = $builder->insert($users)
            ->values(['name' => 'Alice'])
            ->returning($users->id)
            ->to_sql($params);
        
        test(
            "{$dialect}: RETURNING clause",
            strpos($sql, 'RETURNING') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // UPDATE Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->update($users)
        ->set(['name' => 'Bob', 'status' => 'inactive'])
        ->where(eq($users->id, 1))
        ->to_sql($params);
    
    test(
        "{$dialect}: UPDATE statement",
        strpos($sql, 'UPDATE') !== false && strpos($sql, 'SET') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // DELETE Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->delete($users)
        ->where(eq($users->id, 1))
        ->to_sql($params);
    
    test(
        "{$dialect}: DELETE statement",
        strpos($sql, 'DELETE FROM') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // JOIN Tests
    // ============================================
    
    // Create a second table for joins
    $orders = create_test_table($dialect);
    // Hack to rename the table for testing
    $orders_class = new \ReflectionClass($orders);
    $name_prop = $orders_class->getProperty('name');
    $name_prop->setAccessible(true);
    $name_prop->setValue($orders, 'orders');
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select([$users->name])
        ->from($users)
        ->inner_join($orders, eq($users->id, $orders->id))
        ->to_sql($params);
    
    test(
        "{$dialect}: INNER JOIN",
        strpos($sql, 'INNER JOIN') !== false && strpos($sql, 'ON') !== false,
        "SQL: {$sql}"
    );
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->left_join($orders, eq($users->id, $orders->id))
        ->to_sql($params);
    
    test(
        "{$dialect}: LEFT JOIN",
        strpos($sql, 'LEFT JOIN') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // Aggregate Functions Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select([
            sql_count()->as('total'),
            sql_sum($users->age)->as('age_sum')
        ])
        ->from($users)
        ->to_sql($params);
    
    test(
        "{$dialect}: Aggregate functions",
        strpos($sql, 'COUNT(*)') !== false && strpos($sql, 'SUM(') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // GROUP BY and HAVING Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select([
            $users->status,
            sql_count()->as('cnt')
        ])
        ->from($users)
        ->group_by($users->status)
        ->having(raw('COUNT(*) > 5'))
        ->to_sql($params);
    
    test(
        "{$dialect}: GROUP BY with HAVING",
        strpos($sql, 'GROUP BY') !== false && strpos($sql, 'HAVING') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // ORDER BY, LIMIT, OFFSET Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->order_by(desc($users->name))
        ->limit(10)
        ->offset(20)
        ->to_sql($params);
    
    test(
        "{$dialect}: ORDER BY, LIMIT, OFFSET",
        strpos($sql, 'ORDER BY') !== false && 
        strpos($sql, 'LIMIT 10') !== false && 
        strpos($sql, 'OFFSET 20') !== false,
        "SQL: {$sql}"
    );
    
    // ============================================
    // IN and NOT IN Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(sql_in_array($users->status, ['active', 'pending', 'verified']))
        ->to_sql($params);
    
    if (\in_array($dialect, ['postgresql', 'supabase'])) {
        test(
            "{$dialect}: IN with numbered placeholders",
            strpos($sql, 'IN ($1, $2, $3)') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: IN with ? placeholders",
            strpos($sql, 'IN (?, ?, ?)') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // BETWEEN Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(between($users->age, 18, 65))
        ->to_sql($params);
    
    if (\in_array($dialect, ['postgresql', 'supabase'])) {
        test(
            "{$dialect}: BETWEEN with numbered placeholders",
            strpos($sql, 'BETWEEN $1 AND $2') !== false,
            "SQL: {$sql}"
        );
    } else {
        test(
            "{$dialect}: BETWEEN with ? placeholders",
            strpos($sql, 'BETWEEN ? AND ?') !== false,
            "SQL: {$sql}"
        );
    }
    
    // ============================================
    // IS NULL / IS NOT NULL Tests
    // ============================================
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(is_null($users->email))
        ->to_sql($params);
    
    test(
        "{$dialect}: IS NULL",
        strpos($sql, 'IS NULL') !== false,
        "SQL: {$sql}"
    );
    
    $builder = new QueryBuilder($dialect);
    $params = [];
    $sql = $builder->select()
        ->from($users)
        ->where(is_not_null($users->email))
        ->to_sql($params);
    
    test(
        "{$dialect}: IS NOT NULL",
        strpos($sql, 'IS NOT NULL') !== false,
        "SQL: {$sql}"
    );
}

// ============================================
// CREATE TABLE Syntax Tests
// ============================================

echo "\n--- CREATE TABLE Syntax Tests ---\n";

// MySQL
$mysql_table = mysql_table('test_table', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255)->unique(),
    'is_active' => boolean()->default(true),
]);

$sql = $mysql_table->to_create_sql();
test(
    'MySQL: AUTO_INCREMENT keyword',
    strpos($sql, 'AUTO_INCREMENT') !== false,
    "SQL: " . substr($sql, 0, 200)
);

test(
    'MySQL: Backtick quoting',
    strpos($sql, '`test_table`') !== false,
    "SQL: " . substr($sql, 0, 200)
);

test(
    'MySQL: InnoDB engine',
    strpos($sql, 'ENGINE=InnoDB') !== false,
    "SQL: " . substr($sql, 0, 200)
);

// PostgreSQL
$pg_table = pg_table('test_table', [
    'id' => serial(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255)->unique(),
    'is_active' => boolean()->default(true),
]);

$sql = $pg_table->to_create_sql();
test(
    'PostgreSQL: SERIAL type',
    strpos($sql, 'SERIAL') !== false,
    "SQL: " . substr($sql, 0, 200)
);

test(
    'PostgreSQL: Double quote quoting',
    strpos($sql, '"test_table"') !== false,
    "SQL: " . substr($sql, 0, 200)
);

// SQLite
$sqlite_table = sqlite_table('test_table', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255)->unique(),
    'is_active' => boolean()->default(true),
]);

$sql = $sqlite_table->to_create_sql();
test(
    'SQLite: AUTOINCREMENT keyword',
    strpos($sql, 'AUTOINCREMENT') !== false,
    "SQL: " . substr($sql, 0, 200)
);

test(
    'SQLite: Double quote quoting',
    strpos($sql, '"test_table"') !== false,
    "SQL: " . substr($sql, 0, 200)
);

// ============================================
// Supabase-specific Tests
// ============================================

echo "\n--- Supabase-specific Tests ---\n";

$supabase_dialect = new \Italix\Orm\Dialects\SupabaseDialect();

// RLS SQL generation
$rls_sql = $supabase_dialect->get_enable_rls_sql('users');
test(
    'Supabase: Enable RLS SQL',
    strpos($rls_sql, 'ENABLE ROW LEVEL SECURITY') !== false,
    "SQL: {$rls_sql}"
);

$policy_sql = $supabase_dialect->get_create_policy_sql(
    'users',
    'users_select_policy',
    'SELECT',
    'auth.uid() = user_id'
);
test(
    'Supabase: Create policy SQL',
    strpos($policy_sql, 'CREATE POLICY') !== false && strpos($policy_sql, 'FOR SELECT') !== false,
    "SQL: {$policy_sql}"
);

// Connection string building
$config = \Italix\Orm\Dialects\SupabaseDialect::from_credentials(
    'test-project-ref',
    'password123',
    'postgres',
    'us-west-1',
    true
);

test(
    'Supabase: Pooling username format',
    $config['username'] === 'postgres.test-project-ref',
    "Username: {$config['username']}"
);

// ============================================
// Summary
// ============================================

echo "\n===========================================\n";
echo "  Test Results: {$passed} passed, {$failed} failed\n";
echo "===========================================\n";

if ($failed > 0) {
    echo "  ⚠️  DIALECT ISSUES DETECTED!\n";
    exit(1);
} else {
    echo "  ✅ All dialect tests passed!\n";
    exit(0);
}
