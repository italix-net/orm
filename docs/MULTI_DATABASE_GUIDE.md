# Multi-Database Compatibility Guide

This guide provides best practices for developing applications that work seamlessly across MySQL, PostgreSQL, SQLite, and Supabase using Italix ORM.

## Table of Contents

1. [Overview](#overview)
2. [Things to Avoid](#things-to-avoid)
3. [Recommended Patterns](#recommended-patterns)
4. [Portable Data Types](#portable-data-types)
5. [Schema Definition Patterns](#schema-definition-patterns)
6. [Query Best Practices](#query-best-practices)
7. [Date and Time Handling](#date-and-time-handling)
8. [JSON Data Handling](#json-data-handling)
9. [Testing Multiple Databases](#testing-multiple-databases)
10. [Quick Checklist](#quick-checklist)

---

## Overview

Italix ORM supports four database dialects:

| Dialect | Use Case |
|---------|----------|
| **MySQL** | Traditional web applications, widely supported |
| **PostgreSQL** | Advanced features, JSON support, enterprise |
| **SQLite** | Local development, testing, embedded apps |
| **Supabase** | Cloud PostgreSQL with realtime features |

While Italix ORM abstracts many differences, understanding the underlying variations helps you write truly portable code.

---

## Things to Avoid

### 1. Database-Specific Data Types

```php
// ❌ AVOID: Database-specific types
'id' => serial()                    // PostgreSQL only
'flags' => tinyint()                // MySQL-specific
'data' => jsonb()                   // PostgreSQL only

// ✅ USE: Portable alternatives
'id' => integer()->primary_key()->auto_increment()
'flags' => integer()
'data' => text()->cast_as('array')  // JSON encode/decode handled automatically — see "Attribute casting" in the main README
```

### 2. UNSIGNED Integers

```php
// ❌ AVOID: UNSIGNED (PostgreSQL doesn't support it)
'count' => integer()->unsigned()

// ✅ USE: Regular integers with application validation
'count' => integer()->not_null()->default(0)

// Validate in your ActiveRow class
protected function before_save_validate_count(): void
{
    if ($this['count'] < 0) {
        throw new \InvalidArgumentException('Count cannot be negative');
    }
}
```

### 3. ENUM Types — outdated advice, corrected

This used to recommend avoiding `enum()` and hand-rolling a `VARCHAR` plus application-level
validation instead. That advice predates `enum()` becoming a first-class, dialect-portable schema
type (since 2.23.0) and is now backwards — `enum()` **is** the portable alternative, not the thing to
avoid:

```php
// ✅ USE: enum() — renders correctly on every dialect, enforced by the server itself
'status' => enum(['draft', 'published', 'archived'])->not_null()->default('draft')
```

Native `ENUM(...)` on MySQL; `VARCHAR(255)` plus a `CHECK (col IN (...))` carrying the same values on
PostgreSQL and SQLite, built on `check()` rather than a second mechanism — enforced identically to a
hand-written one, not merely rendered and hoped for (`CheckAndEnumTest.php` proves it by inserting a
value outside the list and watching the server refuse it, on all three dialects). No hand-written
`VALID_STATUSES` array or `before_save_validate_status()` override needed — the database is already
the single source of truth for which values are legal, the same as any other constraint.

**A native PHP `BackedEnum` is even better when the values are also meaningful in PHP code**, not
only in the database:

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';
}

'status' => enum(PostStatus::class)->not_null()->default('draft'),
```

Reading the row back gives a real `PostStatus` instance (`$post['status'] === PostStatus::Published`),
not a bare string to compare by hand — see "Attribute casting" in the main README.

### 4. Reserved Words as Identifiers

```php
// ❌ AVOID: Reserved words
$order = sqlite_table('order', [...]);      // "ORDER" is SQL keyword
$user = sqlite_table('user', [...]);        // Reserved in some databases
$group = sqlite_table('group', [...]);      // "GROUP" is SQL keyword

// ✅ USE: Descriptive, non-reserved names
$orders = sqlite_table('orders', [...]);
$users = sqlite_table('users', [...]);
$user_groups = sqlite_table('user_groups', [...]);
```

### 5. Case-Sensitive Identifiers

```php
// ❌ AVOID: Mixed case (behavior varies by database)
'userName' => varchar(100)
'UserName' => varchar(100)

// ✅ USE: Consistent snake_case
'user_name' => varchar(100)
```

### 6. Raw SQL with Database-Specific Functions

```php
// ❌ AVOID: Database-specific SQL functions
$dm->sql("SELECT DATE_FORMAT(created_at, '%Y-%m') FROM posts")->all();     // MySQL
$dm->sql("SELECT TO_CHAR(created_at, 'YYYY-MM') FROM posts")->all();       // PostgreSQL
$dm->sql("SELECT strftime('%Y-%m', created_at) FROM posts")->all();        // SQLite

// ✅ USE: Query builder and handle formatting in PHP
$posts = $dm->select([$posts->created_at])->from($posts)->execute();
foreach ($posts as $post) {
    $formatted = date('Y-m', strtotime($post['created_at']));
}
```

### 7. LIMIT with OFFSET Syntax Variations

```php
// ❌ AVOID: Raw SQL with LIMIT (syntax varies)
$dm->sql("SELECT * FROM users LIMIT 10, 20")->all();  // MySQL: LIMIT offset, count

// ✅ USE: Query builder (handles syntax per dialect)
$dm->select()->from($users)->limit(20)->offset(10)->execute();
```

---

## Recommended Patterns

### 1. Dialect-Aware Table Factory

Create a factory function that returns the correct table type based on dialect:

```php
<?php
// src/Schema/TableFactory.php

use function Italix\Orm\Schema\{mysql_table, pg_table, sqlite_table};
use function Italix\Orm\Schema\{integer, varchar, text, boolean, timestamp};

class TableFactory
{
    private $dialect;

    public function __construct(string $dialect)
    {
        $this->dialect = $dialect;
    }

    public function create_table(string $name, array $columns)
    {
        switch ($this->dialect) {
            case 'mysql':
                return mysql_table($name, $columns);
            case 'postgresql':
            case 'supabase':
                return pg_table($name, $columns);
            case 'sqlite':
                return sqlite_table($name, $columns);
            default:
                throw new \InvalidArgumentException("Unknown dialect: {$this->dialect}");
        }
    }

    public function get_dialect(): string
    {
        return $this->dialect;
    }
}
```

### 2. Centralized Schema Registry

```php
<?php
// src/Schema/AppSchema.php

class AppSchema
{
    private $factory;
    private $tables = [];

    public function __construct(string $dialect)
    {
        $this->factory = new TableFactory($dialect);
        $this->define_tables();
    }

    private function define_tables(): void
    {
        // Users table
        $this->tables['users'] = $this->factory->create_table('users', [
            'id' => integer()->primary_key()->auto_increment(),
            'email' => varchar(255)->not_null()->unique(),
            'password_hash' => varchar(255)->not_null(),
            'display_name' => varchar(100),
            'is_active' => boolean()->default(true),
            'settings' => text(),  // JSON stored as text
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ]);

        // Posts table
        $this->tables['posts'] = $this->factory->create_table('posts', [
            'id' => integer()->primary_key()->auto_increment(),
            'user_id' => integer()->not_null()->references('users', 'id'),
            'title' => varchar(255)->not_null(),
            'slug' => varchar(255)->unique(),
            'content' => text(),
            'status' => varchar(20)->default('draft'),
            'published_at' => timestamp(),
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
            'deleted_at' => timestamp(),
        ]);

        // Comments table
        $this->tables['comments'] = $this->factory->create_table('comments', [
            'id' => integer()->primary_key()->auto_increment(),
            'post_id' => integer()->not_null()->references('posts', 'id'),
            'user_id' => integer()->not_null()->references('users', 'id'),
            'content' => text()->not_null(),
            'created_at' => timestamp(),
        ]);
    }

    public function get_table(string $name)
    {
        if (!isset($this->tables[$name])) {
            throw new \InvalidArgumentException("Unknown table: $name");
        }
        return $this->tables[$name];
    }

    public function get_all_tables(): array
    {
        return array_values($this->tables);
    }

    public function get_dialect(): string
    {
        return $this->factory->get_dialect();
    }
}
```

### 3. Environment-Based Configuration

```php
<?php
// config/database.php

return [
    'default' => getenv('DB_DIALECT') ?: 'sqlite',

    'connections' => [
        'sqlite' => [
            'dialect' => 'sqlite',
            'database' => getenv('DB_DATABASE') ?: __DIR__ . '/../database.db',
        ],

        'mysql' => [
            'dialect' => 'mysql',
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: 3306,
            'database' => getenv('DB_DATABASE') ?: 'app',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ],

        'postgresql' => [
            'dialect' => 'postgresql',
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: 5432,
            'database' => getenv('DB_DATABASE') ?: 'app',
            'username' => getenv('DB_USERNAME') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: '',
        ],

        'supabase' => [
            'dialect' => 'supabase',
            'project_ref' => getenv('SUPABASE_PROJECT_REF'),
            'password' => getenv('SUPABASE_DB_PASSWORD'),
            'database' => 'postgres',
            'pooling' => true,
        ],
    ],
];
```

---

## Portable Data Types

Use these data types for maximum compatibility:

| Italix Type | MySQL | PostgreSQL | SQLite | Notes |
|-------------|-------|------------|--------|-------|
| `integer()` | INT | INTEGER | INTEGER | Standard integer |
| `bigint()` | BIGINT | BIGINT | INTEGER | Large integers |
| `smallint()` | SMALLINT | SMALLINT | INTEGER | Small integers |
| `varchar(n)` | VARCHAR(n) | VARCHAR(n) | TEXT | Variable string |
| `text()` | TEXT | TEXT | TEXT | Unlimited text |
| `boolean()` | TINYINT(1) | BOOLEAN | INTEGER | True/false |
| `timestamp()` | TIMESTAMP | TIMESTAMP | TEXT | Date and time |
| `date()` | DATE | DATE | TEXT | Date only |
| `decimal(p,s)` | DECIMAL(p,s) | DECIMAL(p,s) | REAL | Exact decimal |
| `real()` | FLOAT | REAL | REAL | Floating point |

### Auto-Increment Primary Keys

```php
// This pattern works on all databases
'id' => integer()->primary_key()->auto_increment()

// Italix translates to:
//   MySQL:      `id` INT PRIMARY KEY AUTO_INCREMENT
//   PostgreSQL: "id" SERIAL PRIMARY KEY
//   SQLite:     "id" INTEGER PRIMARY KEY AUTOINCREMENT
```

---

## Schema Definition Patterns

### Pattern 1: Single Schema File

For smaller projects, define all tables in one file:

```php
<?php
// schema.php

function create_schema(string $dialect): array
{
    $factory = new TableFactory($dialect);

    return [
        'users' => $factory->create_table('users', [
            'id' => integer()->primary_key()->auto_increment(),
            'email' => varchar(255)->not_null()->unique(),
            'name' => varchar(100),
            'created_at' => timestamp(),
        ]),

        'posts' => $factory->create_table('posts', [
            'id' => integer()->primary_key()->auto_increment(),
            'user_id' => integer()->not_null(),
            'title' => varchar(255)->not_null(),
            'content' => text(),
            'created_at' => timestamp(),
        ]),
    ];
}

// Usage
$tables = create_schema('mysql');
$dm->create_tables(...array_values($tables));
```

### Pattern 2: Modular Schema Classes

For larger projects, organize tables by domain:

```php
<?php
// schema/UserSchema.php
class UserSchema
{
    public static function get_tables(TableFactory $factory): array
    {
        return [
            'users' => $factory->create_table('users', [...]),
            'user_profiles' => $factory->create_table('user_profiles', [...]),
            'user_settings' => $factory->create_table('user_settings', [...]),
        ];
    }
}

// schema/ContentSchema.php
class ContentSchema
{
    public static function get_tables(TableFactory $factory): array
    {
        return [
            'posts' => $factory->create_table('posts', [...]),
            'comments' => $factory->create_table('comments', [...]),
            'tags' => $factory->create_table('tags', [...]),
        ];
    }
}

// schema/AppSchema.php
class AppSchema
{
    public static function get_all_tables(string $dialect): array
    {
        $factory = new TableFactory($dialect);

        return array_merge(
            UserSchema::get_tables($factory),
            ContentSchema::get_tables($factory)
        );
    }
}
```

---

## Query Best Practices

### Use Query Builder Instead of Raw SQL

```php
// ❌ Raw SQL - dialect-specific issues
$dm->sql("SELECT * FROM users WHERE email LIKE '%@gmail.com'")->all();

// ✅ Query builder - portable
$dm->select()
    ->from($users)
    ->where(like($users->email, '%@gmail.com'))
    ->execute();
```

### Use Operators for Conditions

```php
use function Italix\Orm\Operators\{eq, ne, gt, gte, lt, lte, like, in_array, is_null};

// Comparison operators
->where(eq($users->status, 'active'))
->where(gte($users->age, 18))
->where(lt($posts->created_at, '2024-01-01'))

// Pattern matching
->where(like($users->email, '%@example.com'))

// Set membership
->where(in_array($posts->status, ['draft', 'published']))

// NULL checks
->where(is_null($users->deleted_at))
```

### Avoid Database-Specific String Functions

```php
// ❌ AVOID: CONCAT (syntax varies)
$dm->sql("SELECT CONCAT(first_name, ' ', last_name) FROM users")->all();

// ✅ USE: Select columns and concatenate in PHP
$users = $dm->select([$users->first_name, $users->last_name])->from($users)->execute();
foreach ($users as $user) {
    $full_name = $user['first_name'] . ' ' . $user['last_name'];
}

// Or use ActiveRow
class UserRow extends ActiveRow
{
    public function full_name(): string
    {
        return trim($this['first_name'] . ' ' . $this['last_name']);
    }
}
```

---

## Date and Time Handling

### Storage

```php
// Use timestamp() for date/time columns
'created_at' => timestamp()
'published_at' => timestamp()
'expires_at' => timestamp()
```

### Format in PHP, Not SQL

```php
// ❌ AVOID: Database-specific date formatting
$dm->sql("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as date FROM posts")->all();

// ✅ USE: Format in PHP
$posts = $dm->select([$posts->created_at])->from($posts)->execute();
foreach ($posts as $post) {
    $formatted = date('Y-m-d', strtotime($post['created_at']));
}
```

### ActiveRow Date Methods

```php
class PostRow extends ActiveRow
{
    use HasTimestamps;

    public function published_date(): ?string
    {
        if (!$this['published_at']) {
            return null;
        }
        return date('F j, Y', strtotime($this['published_at']));
    }

    public function is_published(): bool
    {
        if (!$this['published_at']) {
            return false;
        }
        return strtotime($this['published_at']) <= time();
    }

    public function days_since_published(): ?int
    {
        if (!$this['published_at']) {
            return null;
        }
        $published = new \DateTime($this['published_at']);
        $now = new \DateTime();
        return $now->diff($published)->days;
    }
}
```

---

## JSON Data Handling

### Store as TEXT

```php
// Use TEXT for JSON data (most portable)
'settings' => text()
'metadata' => text()
'preferences' => text()
```

### Encode/Decode in ActiveRow

```php
class UserRow extends ActiveRow
{
    /**
     * Get settings as array
     */
    public function get_settings(): array
    {
        $raw = $this['settings'];
        if (empty($raw)) {
            return [];
        }
        return json_decode($raw, true) ?: [];
    }

    /**
     * Set settings from array
     */
    public function set_settings(array $settings): self
    {
        $this['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /**
     * Get a specific setting with default
     */
    public function get_setting(string $key, $default = null)
    {
        $settings = $this->get_settings();
        return $settings[$key] ?? $default;
    }

    /**
     * Set a specific setting
     */
    public function set_setting(string $key, $value): self
    {
        $settings = $this->get_settings();
        $settings[$key] = $value;
        return $this->set_settings($settings);
    }
}

// Usage
$user = UserRow::find(1);
$user->set_setting('theme', 'dark');
$user->set_setting('notifications', true);
$user->save();

echo $user->get_setting('theme', 'light');  // 'dark'
```

---

## Testing Multiple Databases

### Test Matrix

```php
<?php
// tests/MultiDatabaseTest.php

class MultiDatabaseTest
{
    private static $dialects = ['sqlite', 'mysql', 'postgresql'];

    public static function run_all(): void
    {
        foreach (self::$dialects as $dialect) {
            echo "\n=== Testing on $dialect ===\n";

            try {
                $dm = self::create_connection($dialect);
                $schema = new AppSchema($dialect);

                // Create tables
                $dm->create_tables(...$schema->get_all_tables());

                // Run tests
                self::test_crud($dm, $schema);
                self::test_relations($dm, $schema);
                self::test_queries($dm, $schema);

                // Cleanup
                $dm->drop_tables(...array_reverse($schema->get_all_tables()));

                echo "✓ All tests passed for $dialect\n";
            } catch (\Exception $e) {
                echo "✗ Failed on $dialect: " . $e->getMessage() . "\n";
            }
        }
    }

    private static function create_connection(string $dialect)
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
        }
    }

    private static function test_crud($dm, $schema): void
    {
        // Test CRUD operations...
    }
}
```

### Docker Compose for Testing

```yaml
# docker-compose.test.yml
version: '3.8'

services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: test
      MYSQL_DATABASE: test
    ports:
      - "3306:3306"

  postgres:
    image: postgres:15
    environment:
      POSTGRES_PASSWORD: test
      POSTGRES_DB: test
    ports:
      - "5432:5432"
```

---

## Quick Checklist

Before deploying a multi-database application, verify:

### Schema Design
- [ ] Using `integer()->auto_increment()` for primary keys
- [ ] Using `varchar()` or `text()` for strings
- [ ] Using `boolean()` for true/false values
- [ ] Using `timestamp()` or `text()` for dates
- [ ] Using `text()` for JSON data
- [ ] `enum()` for a fixed value set — safe and portable on all three dialects (native `ENUM` on
      MySQL, `VARCHAR` + `CHECK` elsewhere, enforced identically); no reason to hand-roll it anymore
- [ ] `Table::fulltext()` for full-text search rather than a dialect-specific `MATCH()`/`to_tsvector()`
      written by hand — it already renders correctly per dialect, SQLite's FTS5 included
- [ ] No UNSIGNED integers
- [ ] No reserved words as table/column names
- [ ] Consistent snake_case naming

### Queries
- [ ] Using query builder instead of raw SQL
- [ ] No database-specific SQL functions
- [ ] Date formatting done in PHP
- [ ] String concatenation done in PHP

### Testing
- [ ] Tested on all target databases
- [ ] Automated tests for each dialect
- [ ] Migration scripts work on all dialects

### Code Organization
- [ ] Centralized schema definition
- [ ] Environment-based configuration
- [ ] ActiveRow classes for business logic

---

## See Also

- [Examples: Multi-Database Project](../examples/MultiDatabase/)
- [REFERENCE_MANUAL.md - Schema Definition](REFERENCE_MANUAL.md#5-schema-definition)
- [REFERENCE_MANUAL.md - Query Builder](REFERENCE_MANUAL.md#8-query-builder)
