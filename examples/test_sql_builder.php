<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - SQL Builder Test
 */

require_once __DIR__ . '/../src/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\sql;
use function Italix\Orm\Schema\sqlite_table;
use function Italix\Orm\Schema\integer;
use function Italix\Orm\Schema\varchar;
use function Italix\Orm\Schema\real;

echo "===========================================\n";
echo "  Italix ORM - SQL Builder Test\n";
echo "===========================================\n\n";

// Create database
$dm = sqlite_memory();
echo "✓ Database connection established\n\n";

// Create table using raw SQL via sql()
echo "--- Creating table with sql() ---\n";
$dm->sql('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        age INTEGER,
        salary REAL
    )
')->execute();
echo "✓ Table created with raw SQL\n\n";

// ============================================
// INSERT with sql()
// ============================================

echo "--- INSERT with sql() ---\n";

// Simple parameterized insert
$dm->sql(
    'INSERT INTO users (name, email, age, salary) VALUES (?, ?, ?, ?)',
    ['Alice', 'alice@test.com', 25, 50000.00]
)->execute();
echo "✓ Inserted Alice with parameterized query\n";

// Fluent builder insert
$dm->sql()
    ->append('INSERT INTO ')
    ->identifier('users')
    ->append(' (')
    ->identifier('name')
    ->append(', ')
    ->identifier('email')
    ->append(', ')
    ->identifier('age')
    ->append(', ')
    ->identifier('salary')
    ->append(') VALUES (')
    ->value('Bob')
    ->append(', ')
    ->value('bob@test.com')
    ->append(', ')
    ->value(30)
    ->append(', ')
    ->value(60000.00)
    ->append(')')
    ->execute();
echo "✓ Inserted Bob with fluent builder\n";

// Using values() for multiple placeholders
$dm->sql()
    ->append('INSERT INTO users (name, email, age, salary) VALUES (')
    ->values(['Charlie', 'charlie@test.com', 35, 70000.00])
    ->append(')')
    ->execute();
echo "✓ Inserted Charlie with values() helper\n\n";

// ============================================
// SELECT with sql()
// ============================================

echo "--- SELECT with sql() ---\n";

// Simple select all
$users = $dm->sql('SELECT * FROM users')->all();
echo "✓ SELECT *: Found " . count($users) . " users\n";

// Select with parameter
$user = $dm->sql('SELECT * FROM users WHERE id = ?', [1])->one();
echo "✓ SELECT by id: Found {$user['name']}\n";

// Select with multiple parameters
$users = $dm->sql(
    'SELECT * FROM users WHERE age >= ? AND salary <= ?',
    [25, 65000]
)->all();
echo "✓ SELECT with multiple params: Found " . count($users) . " users\n";

// Fluent select
$users = $dm->sql()
    ->append('SELECT ')
    ->identifier('name')
    ->append(', ')
    ->identifier('email')
    ->append(' FROM ')
    ->identifier('users')
    ->append(' WHERE ')
    ->identifier('age')
    ->append(' > ')
    ->value(25)
    ->all();
echo "✓ Fluent SELECT: Found " . count($users) . " users over 25\n";

// Using IN clause
$users = $dm->sql()
    ->append('SELECT * FROM users WHERE name ')
    ->in(['Alice', 'Bob'])
    ->all();
echo "✓ SELECT with IN: Found " . count($users) . " users (Alice, Bob)\n";

// Scalar value
$count = $dm->sql('SELECT COUNT(*) FROM users')->scalar();
echo "✓ Scalar value (COUNT): {$count}\n\n";

// ============================================
// UPDATE with sql()
// ============================================

echo "--- UPDATE with sql() ---\n";

$affected = $dm->sql(
    'UPDATE users SET salary = ? WHERE name = ?',
    [55000.00, 'Alice']
)->row_count();
echo "✓ UPDATE Alice's salary: {$affected} row(s) affected\n";

// Verify update
$alice = $dm->sql('SELECT salary FROM users WHERE name = ?', ['Alice'])->one();
echo "✓ Verified: Alice's salary is now {$alice['salary']}\n\n";

// ============================================
// DELETE with sql()
// ============================================

echo "--- DELETE with sql() ---\n";

// First add someone to delete
$dm->sql(
    'INSERT INTO users (name, email, age, salary) VALUES (?, ?, ?, ?)',
    ['ToDelete', 'delete@test.com', 99, 1000]
)->execute();

$affected = $dm->sql('DELETE FROM users WHERE name = ?', ['ToDelete'])->row_count();
echo "✓ DELETE: {$affected} row(s) deleted\n\n";

// ============================================
// Conditional SQL with when()
// ============================================

echo "--- Conditional SQL with when() ---\n";

$min_age = 30;
$max_salary = null; // Not set

$query = $dm->sql()
    ->append('SELECT * FROM users WHERE 1=1')
    ->when($min_age !== null, ' AND age >= ?', [$min_age])
    ->when($max_salary !== null, ' AND salary <= ?', [$max_salary]);

$users = $query->all();
echo "✓ Conditional query (minAge=$min_age): Found " . count($users) . " users\n\n";

// ============================================
// Complex query building
// ============================================

echo "--- Complex query building ---\n";

// Build a complex query piece by piece
$select_part = sql('SELECT name, email, salary');
$from_part = sql(' FROM users');
$where_part = sql(' WHERE salary > ?', [50000]);
$order_part = sql(' ORDER BY salary DESC');

$complex_query = $dm->sql()
    ->merge($select_part)
    ->merge($from_part)
    ->merge($where_part)
    ->merge($order_part);

$results = $complex_query->all();
echo "✓ Complex merged query: Found " . count($results) . " users with salary > 50k\n";
foreach ($results as $row) {
    echo "    - {$row['name']}: \${$row['salary']}\n";
}
echo "\n";

// ============================================
// Showing the generated SQL
// ============================================

echo "--- Generated SQL inspection ---\n";

$query = sql()
    ->append('SELECT * FROM ')
    ->identifier('users')
    ->append(' WHERE ')
    ->identifier('status')
    ->append(' = ')
    ->value('active')
    ->append(' AND ')
    ->identifier('age')
    ->append(' BETWEEN ')
    ->value(18)
    ->append(' AND ')
    ->value(65);

echo "Generated SQL: " . $query->get_query() . "\n";
echo "Parameters: " . json_encode($query->get_params()) . "\n\n";

// ============================================
// Static factory methods
// ============================================

echo "--- Static factory methods ---\n";

// Using Sql::select()
$select_sql = \Italix\Orm\Sql::select('*')
    ->append(' FROM users WHERE id = ')
    ->value(1);
echo "Sql::select(): " . $select_sql->get_query() . "\n";

// Using Sql::insert_into()
$insert_sql = \Italix\Orm\Sql::insert_into('users')
    ->append(' (name, email) VALUES (')
    ->values(['Test', 'test@test.com'])
    ->append(')');
echo "Sql::insert_into(): " . $insert_sql->get_query() . "\n";

// Using Sql::param()
$param_sql = sql('SELECT * FROM users WHERE id = ')
    ->merge(\Italix\Orm\Sql::param(42));
echo "Sql::param(): " . $param_sql->get_query() . " with params " . json_encode($param_sql->get_params()) . "\n\n";

// Final count
$count = $dm->sql('SELECT COUNT(*) as total FROM users')->one();
echo "✓ Final user count: {$count['total']}\n\n";

echo "===========================================\n";
echo "  ✅ All SQL builder tests passed!\n";
echo "===========================================\n";
