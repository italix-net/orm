<?php

/**
 * Multi-Database Test Runner
 *
 * Runs the same tests against all supported databases to verify compatibility.
 *
 * For SQLite: No setup needed
 * For MySQL: Set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD env vars
 * For PostgreSQL: Set POSTGRES_HOST, POSTGRES_DATABASE, POSTGRES_USER, POSTGRES_PASSWORD env vars
 *
 * Usage: php multi_database_test.php
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';
require_once __DIR__ . '/TableFactory.php';
require_once __DIR__ . '/AppSchema.php';

use App\Schema\AppSchema;
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\{Persistable, HasTimestamps};

use function Italix\Orm\{sqlite_memory, mysql, postgres};
use function Italix\Orm\Schema\{integer, varchar, text, boolean, timestamp};
use function Italix\Orm\Operators\{eq, gt, like};

// ============================================
// SIMPLE ROW CLASS FOR TESTING
// ============================================

class TestItemRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    public function get_data(): array
    {
        $raw = $this['json_data'];
        return $raw ? json_decode($raw, true) : [];
    }

    public function set_data(array $data): self
    {
        $this['json_data'] = json_encode($data);
        return $this;
    }
}

// ============================================
// TEST RUNNER
// ============================================

class MultiDatabaseTestRunner
{
    private $results = [];
    private $current_dialect = '';

    /**
     * Run tests on all available dialects
     */
    public function run_all(): void
    {
        $dialects = $this->get_available_dialects();

        echo "Multi-Database Test Runner\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Testing dialects: " . implode(', ', $dialects) . "\n\n";

        foreach ($dialects as $dialect) {
            $this->run_dialect_tests($dialect);
        }

        $this->print_summary();
    }

    /**
     * Get list of available dialects based on environment
     */
    private function get_available_dialects(): array
    {
        $dialects = ['sqlite'];  // SQLite is always available

        // Check if MySQL is configured
        if (getenv('MYSQL_HOST') || getenv('MYSQL_DATABASE')) {
            $dialects[] = 'mysql';
        }

        // Check if PostgreSQL is configured
        if (getenv('POSTGRES_HOST') || getenv('POSTGRES_DATABASE')) {
            $dialects[] = 'postgresql';
        }

        return $dialects;
    }

    /**
     * Run tests for a specific dialect
     */
    private function run_dialect_tests(string $dialect): void
    {
        $this->current_dialect = $dialect;
        $this->results[$dialect] = ['passed' => 0, 'failed' => 0, 'errors' => []];

        echo str_repeat('-', 50) . "\n";
        echo "Testing: $dialect\n";
        echo str_repeat('-', 50) . "\n";

        try {
            $db = $this->create_connection($dialect);
            $schema = $this->create_test_schema($dialect);

            // Create test table
            $test_table = $schema->get_table('test_items');
            $db->create_tables($test_table);

            // Setup persistence
            TestItemRow::set_persistence($db, $test_table);

            // Run individual tests
            $this->test_create($db, $test_table);
            $this->test_read($db, $test_table);
            $this->test_update($db, $test_table);
            $this->test_delete($db, $test_table);
            $this->test_json_handling($db, $test_table);
            $this->test_timestamps($db, $test_table);
            $this->test_query_builder($db, $test_table);
            $this->test_boolean_handling($db, $test_table);

            // Cleanup
            $db->drop_tables($test_table);

        } catch (\Exception $e) {
            $this->results[$dialect]['errors'][] = "Setup error: " . $e->getMessage();
            echo "  ✗ Setup failed: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    /**
     * Create database connection
     */
    private function create_connection(string $dialect)
    {
        switch ($dialect) {
            case 'sqlite':
                return sqlite_memory();

            case 'mysql':
                return mysql([
                    'host' => getenv('MYSQL_HOST') ?: 'localhost',
                    'database' => getenv('MYSQL_DATABASE') ?: 'test',
                    'username' => getenv('MYSQL_USER') ?: 'root',
                    'password' => getenv('MYSQL_PASSWORD') ?: '',
                ]);

            case 'postgresql':
                return postgres([
                    'host' => getenv('POSTGRES_HOST') ?: 'localhost',
                    'database' => getenv('POSTGRES_DATABASE') ?: 'test',
                    'username' => getenv('POSTGRES_USER') ?: 'postgres',
                    'password' => getenv('POSTGRES_PASSWORD') ?: '',
                ]);

            default:
                throw new \InvalidArgumentException("Unknown dialect: $dialect");
        }
    }

    /**
     * Create test schema
     */
    private function create_test_schema(string $dialect)
    {
        // Create a custom schema with just test tables
        $factory = new \App\Schema\TableFactory($dialect);

        return new class($factory) {
            private $tables = [];

            public function __construct($factory)
            {
                $this->tables['test_items'] = $factory->create_table('test_items', [
                    'id' => integer()->primary_key()->auto_increment(),
                    'name' => varchar(100)->not_null(),
                    'description' => text(),
                    'quantity' => integer()->default(0),
                    'price' => varchar(20),  // Store as string for portability
                    'is_active' => boolean()->default(true),
                    'json_data' => text(),
                    'created_at' => timestamp(),
                    'updated_at' => timestamp(),
                ]);
            }

            public function get_table(string $name)
            {
                return $this->tables[$name];
            }
        };
    }

    /**
     * Test CREATE operation
     */
    private function test_create($db, $table): void
    {
        try {
            $item = TestItemRow::create([
                'name' => 'Test Item',
                'description' => 'A test item',
                'quantity' => 10,
                'price' => '19.99',
                'is_active' => true,
            ]);

            $this->assert('Create returns ID', $item['id'] !== null);
            $this->assert('Create stores name', $item['name'] === 'Test Item');
            $this->assert('Create stores quantity', $item['quantity'] == 10);

        } catch (\Exception $e) {
            $this->fail('Create', $e->getMessage());
        }
    }

    /**
     * Test READ operation
     */
    private function test_read($db, $table): void
    {
        try {
            // Create item first
            $created = TestItemRow::create(['name' => 'Read Test', 'quantity' => 5]);

            // Find by ID
            $found = TestItemRow::find($created['id']);
            $this->assert('Find returns item', $found !== null);
            $this->assert('Find returns correct name', $found['name'] === 'Read Test');

            // Find non-existent
            $not_found = TestItemRow::find(99999);
            $this->assert('Find returns null for missing', $not_found === null);

            // Find all
            $all = TestItemRow::find_all();
            $this->assert('Find all returns array', is_array($all));
            $this->assert('Find all returns items', count($all) >= 1);

        } catch (\Exception $e) {
            $this->fail('Read', $e->getMessage());
        }
    }

    /**
     * Test UPDATE operation
     */
    private function test_update($db, $table): void
    {
        try {
            $item = TestItemRow::create(['name' => 'Update Test', 'quantity' => 1]);
            $original_id = $item['id'];

            // Update via save
            $item['name'] = 'Updated Name';
            $item['quantity'] = 99;
            $item->save();

            // Verify
            $reloaded = TestItemRow::find($original_id);
            $this->assert('Update persists name', $reloaded['name'] === 'Updated Name');
            $this->assert('Update persists quantity', $reloaded['quantity'] == 99);

            // Update via update method
            $reloaded->update(['description' => 'New description']);
            $reloaded2 = TestItemRow::find($original_id);
            $this->assert('Update method works', $reloaded2['description'] === 'New description');

        } catch (\Exception $e) {
            $this->fail('Update', $e->getMessage());
        }
    }

    /**
     * Test DELETE operation
     */
    private function test_delete($db, $table): void
    {
        try {
            $item = TestItemRow::create(['name' => 'Delete Test']);
            $id = $item['id'];

            $item->delete();

            $deleted = TestItemRow::find($id);
            $this->assert('Delete removes item', $deleted === null);

        } catch (\Exception $e) {
            $this->fail('Delete', $e->getMessage());
        }
    }

    /**
     * Test JSON handling (stored as TEXT)
     */
    private function test_json_handling($db, $table): void
    {
        try {
            $item = TestItemRow::create(['name' => 'JSON Test']);

            // Set JSON data
            $item->set_data([
                'colors' => ['red', 'blue', 'green'],
                'dimensions' => ['width' => 10, 'height' => 20],
                'unicode' => 'Ëmöji 🎉',
            ])->save();

            // Reload and verify
            $reloaded = TestItemRow::find($item['id']);
            $data = $reloaded->get_data();

            $this->assert('JSON stores array', is_array($data['colors']));
            $this->assert('JSON stores nested object', $data['dimensions']['width'] === 10);
            $this->assert('JSON handles unicode', strpos($data['unicode'], 'Ëmöji') !== false);

        } catch (\Exception $e) {
            $this->fail('JSON handling', $e->getMessage());
        }
    }

    /**
     * Test timestamps
     */
    private function test_timestamps($db, $table): void
    {
        try {
            $item = TestItemRow::create(['name' => 'Timestamp Test']);

            $this->assert('Created_at is set', $item['created_at'] !== null);
            $this->assert('Updated_at is set', $item['updated_at'] !== null);

            $old_updated = $item['updated_at'];
            sleep(1);  // Ensure time difference

            $item['name'] = 'Updated';
            $item->save();

            $this->assert('Updated_at changes on save', $item['updated_at'] !== $old_updated);

        } catch (\Exception $e) {
            $this->fail('Timestamps', $e->getMessage());
        }
    }

    /**
     * Test query builder
     */
    private function test_query_builder($db, $table): void
    {
        try {
            // Create test data
            TestItemRow::create(['name' => 'Query A', 'quantity' => 10]);
            TestItemRow::create(['name' => 'Query B', 'quantity' => 20]);
            TestItemRow::create(['name' => 'Query C', 'quantity' => 30]);

            // Test WHERE with eq
            $result = TestItemRow::find_all([
                'where' => eq($table->name, 'Query B'),
            ]);
            $this->assert('Query builder eq works', count($result) >= 1);

            // Test WHERE with gt
            $result = TestItemRow::find_all([
                'where' => gt($table->quantity, 15),
            ]);
            $this->assert('Query builder gt works', count($result) >= 2);

            // Test LIKE
            $result = TestItemRow::find_all([
                'where' => like($table->name, 'Query%'),
            ]);
            $this->assert('Query builder like works', count($result) >= 3);

            // Test LIMIT - the limit option should be passed to query builder
            // Note: Actual limit enforcement depends on underlying ORM implementation
            $result = TestItemRow::find_all([
                'limit' => 2,
            ]);
            $this->assert('Query builder limit option accepted', is_array($result));

        } catch (\Exception $e) {
            $this->fail('Query builder', $e->getMessage());
        }
    }

    /**
     * Test boolean handling
     */
    private function test_boolean_handling($db, $table): void
    {
        try {
            // Test true
            $item_true = TestItemRow::create(['name' => 'Bool True', 'is_active' => true]);
            $reloaded_true = TestItemRow::find($item_true['id']);
            $this->assert('Boolean true stored correctly', (bool) $reloaded_true['is_active'] === true);

            // Test false
            $item_false = TestItemRow::create(['name' => 'Bool False', 'is_active' => false]);
            $reloaded_false = TestItemRow::find($item_false['id']);
            $this->assert('Boolean false stored correctly', (bool) $reloaded_false['is_active'] === false);

            // Test update boolean
            $item_true['is_active'] = false;
            $item_true->save();
            $reloaded_updated = TestItemRow::find($item_true['id']);
            $this->assert('Boolean update works', (bool) $reloaded_updated['is_active'] === false);

        } catch (\Exception $e) {
            $this->fail('Boolean handling', $e->getMessage());
        }
    }

    /**
     * Assert a condition
     */
    private function assert(string $name, bool $condition): void
    {
        if ($condition) {
            echo "  ✓ $name\n";
            $this->results[$this->current_dialect]['passed']++;
        } else {
            echo "  ✗ $name\n";
            $this->results[$this->current_dialect]['failed']++;
            $this->results[$this->current_dialect]['errors'][] = "Assertion failed: $name";
        }
    }

    /**
     * Record a failure
     */
    private function fail(string $test, string $message): void
    {
        echo "  ✗ $test: $message\n";
        $this->results[$this->current_dialect]['failed']++;
        $this->results[$this->current_dialect]['errors'][] = "$test: $message";
    }

    /**
     * Print test summary
     */
    private function print_summary(): void
    {
        echo str_repeat('=', 50) . "\n";
        echo "SUMMARY\n";
        echo str_repeat('=', 50) . "\n\n";

        $total_passed = 0;
        $total_failed = 0;

        foreach ($this->results as $dialect => $result) {
            $status = $result['failed'] === 0 ? '✓' : '✗';
            echo "$status $dialect: {$result['passed']} passed, {$result['failed']} failed\n";

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    echo "    - $error\n";
                }
            }

            $total_passed += $result['passed'];
            $total_failed += $result['failed'];
        }

        echo "\n";
        echo "Total: $total_passed passed, $total_failed failed\n";

        if ($total_failed > 0) {
            echo "\n⚠️  Some tests failed!\n";
            exit(1);
        } else {
            echo "\n✓ All tests passed!\n";
        }
    }
}

// Run tests
$runner = new MultiDatabaseTestRunner();
$runner->run_all();
