# Italix ORM - Reference Manual

## Version 1.0.0

**A lightweight, type-safe PHP ORM with support for MySQL, PostgreSQL, SQLite, and Supabase.**

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Installation](#2-installation)
3. [Quick Start](#3-quick-start)
4. [Database Connections](#4-database-connections)
5. [Schema Definition](#5-schema-definition)
6. [Relations](#6-relations)
7. [Query Builder](#7-query-builder)
8. [Operators Reference](#8-operators-reference)
9. [Aggregate Functions](#9-aggregate-functions)
10. [Custom SQL with sql()](#10-custom-sql-with-sql)
11. [Transactions](#11-transactions)
12. [Security Considerations](#12-security-considerations)
13. [API Reference](#13-api-reference)
14. [Examples](#14-examples)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Introduction

Italix ORM is a lightweight Object-Relational Mapping library for PHP 7.4+ that provides:

- **Type Safety**: Full PHP type declarations for better IDE support
- **Multi-Database Support**: MySQL, PostgreSQL, SQLite, and Supabase
- **Query Builder**: Fluent, chainable API for building queries
- **SQL Injection Protection**: All user input is properly parameterized
- **PSR-4 Autoloading**: Compatible with Composer

### Design Philosophy

- **Convention over Configuration**: sensible defaults with snake_case naming
- **Explicit over Implicit**: clear, readable code
- **Safety First**: parameterized queries prevent SQL injection
- **Lightweight**: minimal dependencies, fast execution

---

## 2. Installation

### Via Composer (Recommended)

```bash
composer require italix/orm
```

### Manual Installation

1. Download the package
2. Include the autoloader:

```php
require_once 'path/to/italix-orm/src/autoload.php';
```

### Requirements

- PHP 7.4 or higher
- PDO extension
- Database-specific PDO driver (pdo_mysql, pdo_pgsql, pdo_sqlite)

---

## 3. Quick Start

```php
<?php
require 'vendor/autoload.php';

use function Italix\Orm\sqlite;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar};
use function Italix\Orm\Operators\{eq, desc};

// 1. Create connection
$db = sqlite(['database' => 'app.db']);

// 2. Define schema
$users = sqlite_table('users', [
    'id'    => integer()->primary_key()->auto_increment(),
    'name'  => varchar(100)->not_null(),
    'email' => varchar(255)->unique(),
]);

// 3. Create table
$db->create_tables($users);

// 4. Insert data
$db->insert($users)->values([
    'name' => 'Alice',
    'email' => 'alice@example.com'
])->execute();

// 5. Query data
$results = $db->select()
    ->from($users)
    ->where(eq($users->name, 'Alice'))
    ->execute();

// 6. Update data
$db->update($users)
    ->set(['name' => 'Alice Smith'])
    ->where(eq($users->id, 1))
    ->execute();

// 7. Delete data
$db->delete($users)
    ->where(eq($users->id, 1))
    ->execute();
```

---

## 4. Database Connections

### MySQL

```php
use function Italix\Orm\mysql;

$db = mysql([
    'host'     => 'localhost',
    'port'     => 3306,           // Optional, default: 3306
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'charset'  => 'utf8mb4',      // Optional, default: utf8mb4
]);
```

### PostgreSQL

```php
use function Italix\Orm\postgres;

$db = postgres([
    'host'     => 'localhost',
    'port'     => 5432,           // Optional, default: 5432
    'database' => 'myapp',
    'username' => 'postgres',
    'password' => 'secret',
]);
```

### SQLite

```php
use function Italix\Orm\{sqlite, sqlite_memory};

// File-based database
$db = sqlite(['database' => '/path/to/database.db']);

// In-memory database (useful for testing)
$db = sqlite_memory();
```

### Supabase

```php
use function Italix\Orm\{supabase, supabase_from_credentials};

// From credentials (recommended)
$db = supabase_from_credentials(
    'your-project-ref',     // Project reference
    'your-db-password',     // Database password
    'postgres',             // Database name (default: postgres)
    'us-east-1',            // Region (default: us-east-1)
    true                    // Use connection pooling (default: true)
);

// Or with full config
$db = supabase([
    'project_ref' => 'your-project-ref',
    'password'    => 'your-password',
    'database'    => 'postgres',
    'region'      => 'us-east-1',
    'pooling'     => true,
]);
```

### Connection String

```php
use function Italix\Orm\from_connection_string;

$db = from_connection_string('mysql://user:pass@localhost:3306/myapp');
$db = from_connection_string('postgres://user:pass@localhost:5432/myapp');
$db = from_connection_string('sqlite:///path/to/database.db');
```

---

## 5. Schema Definition

### Column Types

| Function | SQL Type | Description |
|----------|----------|-------------|
| `integer()` | INTEGER | Standard integer |
| `bigint()` | BIGINT | Large integer |
| `smallint()` | SMALLINT | Small integer |
| `serial()` | SERIAL | Auto-incrementing (PostgreSQL) |
| `bigserial()` | BIGSERIAL | Large auto-incrementing |
| `text()` | TEXT | Unlimited text |
| `varchar($len)` | VARCHAR(n) | Variable-length string |
| `char($len)` | CHAR(n) | Fixed-length string |
| `boolean()` | BOOLEAN | True/false |
| `timestamp()` | TIMESTAMP | Date and time |
| `datetime()` | DATETIME | Date and time |
| `date()` | DATE | Date only |
| `time()` | TIME | Time only |
| `json()` | JSON | JSON data |
| `jsonb()` | JSONB | Binary JSON (PostgreSQL) |
| `uuid()` | UUID | Universally unique identifier |
| `real()` | REAL | Single-precision float |
| `double_precision()` | DOUBLE | Double-precision float |
| `decimal($p, $s)` | DECIMAL(p,s) | Exact decimal |
| `numeric($p, $s)` | NUMERIC(p,s) | Exact numeric |
| `blob()` | BLOB | Binary data |

### Column Modifiers

| Method | Description | Example |
|--------|-------------|---------|
| `primary_key()` | Set as primary key | `integer()->primary_key()` |
| `auto_increment()` | Auto-increment (MySQL/SQLite) | `integer()->auto_increment()` |
| `not_null()` | Disallow NULL values | `varchar(100)->not_null()` |
| `unique()` | Unique constraint | `varchar(255)->unique()` |
| `default($value)` | Default value | `boolean()->default(true)` |
| `references($table, $col)` | Foreign key reference | `integer()->references('users', 'id')` |

### Table Definition

```php
use function Italix\Orm\Schema\{mysql_table, pg_table, sqlite_table};
use function Italix\Orm\Schema\{integer, varchar, text, timestamp, boolean};

// MySQL
$users = mysql_table('users', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(100)->not_null(),
    'email'      => varchar(255)->not_null()->unique(),
    'bio'        => text(),
    'is_active'  => boolean()->default(true),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// PostgreSQL
$posts = pg_table('posts', [
    'id'        => serial(),  // Auto-incrementing primary key
    'title'     => varchar(200)->not_null(),
    'content'   => text(),
    'author_id' => integer()->not_null()->references('users', 'id'),
]);

// SQLite
$logs = sqlite_table('logs', [
    'id'      => integer()->primary_key()->auto_increment(),
    'message' => text()->not_null(),
    'level'   => varchar(20)->default('info'),
]);
```

### Creating and Dropping Tables

```php
// Create tables
$db->create_tables($users, $posts, $logs);

// Drop tables
$db->drop_tables($logs, $posts, $users);

// Check if table exists
if (!$db->table_exists('users')) {
    $db->create_tables($users);
}
```

---

## 6. Relations

Italix ORM features a Drizzle-inspired relation system with explicit relation definitions, eager loading, and polymorphic support.

### Import Relations Functions

```php
use function Italix\Orm\Relations\define_relations;
```

### 6.1 Defining Relations

Relations are defined separately from schema using `define_relations()`. This follows Drizzle ORM's pattern of explicit relation definitions.

```php
// Define tables first
$users = sqlite_table('users', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
]);

$profiles = sqlite_table('profiles', [
    'id' => integer()->primary_key()->auto_increment(),
    'user_id' => integer()->not_null()->unique(),
    'bio' => text(),
]);

$posts = sqlite_table('posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'author_id' => integer()->not_null(),
    'title' => varchar(255)->not_null(),
]);

// Define relations
$users_relations = define_relations($users, function($r) use ($users, $profiles, $posts) {
    return [
        // One-to-one: users.id -> profiles.user_id
        'profile' => $r->one($profiles, [
            'fields' => [$users->id],           // Source column (PK)
            'references' => [$profiles->user_id], // Target column (FK)
        ]),

        // One-to-many: users.id -> posts.author_id
        'posts' => $r->many($posts, [
            'fields' => [$users->id],
            'references' => [$posts->author_id],
        ]),
    ];
});
```

### 6.2 Relation Types

| Method | Type | Description |
|--------|------|-------------|
| `$r->one($table, $config)` | One-to-One / Many-to-One | Single related record |
| `$r->many($table, $config)` | One-to-Many | Multiple related records |
| `$r->one_polymorphic($config)` | Polymorphic Belongs-To | Single record from multiple possible tables |
| `$r->many_polymorphic($table, $config)` | Polymorphic Has-Many | Multiple records with type discrimination |

### 6.3 Query Methods

#### query_table()

Creates a relational query builder for a table:

```php
$query = $db->query_table($users);
```

#### find_many()

Get multiple records, optionally with relations:

```php
// All users
$users = $db->query_table($users)->find_many();

// With conditions and relations
$users = $db->query_table($users)
    ->where(eq($users->is_active, true))
    ->with(['posts' => true])
    ->order_by(desc($users->created_at))
    ->limit(10)
    ->find_many();
```

#### find_first()

Get first matching record:

```php
$user = $db->query_table($users)
    ->where(eq($users->email, 'john@example.com'))
    ->with(['profile' => true])
    ->find_first();
```

#### find_one()

Alias for `find_first()`.

#### find($id)

Get record by primary key:

```php
$user = $db->query_table($users)
    ->with(['posts' => true])
    ->find(1);
```

### 6.4 Eager Loading with `with`

The `with` clause loads related data efficiently, avoiding N+1 query problems.

#### Basic Eager Loading

```php
// Load single relation
$users = $db->query_table($users)
    ->with(['posts' => true])
    ->find_many();

// Load multiple relations
$users = $db->query_table($users)
    ->with([
        'profile' => true,
        'posts' => true,
        'comments' => true,
    ])
    ->find_many();
```

#### Nested Relations

```php
$users = $db->query_table($users)
    ->with([
        'posts' => [
            'with' => [
                'comments' => true,  // Load comments for each post
                'tags' => true,      // Load tags for each post
            ]
        ]
    ])
    ->find_many();

// Result structure:
// [
//     'id' => 1,
//     'name' => 'John',
//     'posts' => [
//         [
//             'id' => 1,
//             'title' => 'Hello World',
//             'comments' => [...],
//             'tags' => [...]
//         ]
//     ]
// ]
```

#### Filtered Relations

```php
$users = $db->query_table($users)
    ->with([
        'posts' => [
            'where' => eq($posts->published, true),
            'order_by' => [desc($posts->created_at)],
            'limit' => 5,
        ]
    ])
    ->find_many();
```

#### Relation Aliases

```php
// Load 'author' relation but name it 'writer' in results
$posts = $db->query_table($posts)
    ->with([
        'writer:author' => true,
    ])
    ->find_many();

// Access: $post['writer']['name'] instead of $post['author']['name']
```

### 6.5 Many-to-Many Relations

Many-to-many relations use a junction/pivot table.

```php
$tags = sqlite_table('tags', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);

$post_tags = sqlite_table('post_tags', [
    'post_id' => integer()->not_null(),
    'tag_id' => integer()->not_null(),
]);

$posts_relations = define_relations($posts, function($r) use ($posts, $tags, $post_tags) {
    return [
        'tags' => $r->many($tags, [
            'fields' => [$posts->id],
            'through' => $post_tags,              // Junction table
            'through_fields' => [$post_tags->post_id],  // FK to source
            'target_fields' => [$post_tags->tag_id],    // FK to target
            'target_references' => [$tags->id],         // Target PK
        ]),
    ];
});

// Inverse relation: tags -> posts
$tags_relations = define_relations($tags, function($r) use ($posts, $tags, $post_tags) {
    return [
        'posts' => $r->many($posts, [
            'fields' => [$tags->id],
            'through' => $post_tags,
            'through_fields' => [$post_tags->tag_id],
            'target_fields' => [$post_tags->post_id],
            'target_references' => [$posts->id],
        ]),
    ];
});

// Query
$posts = $db->query_table($posts)->with(['tags' => true])->find_many();
```

### 6.6 Polymorphic Relations

Polymorphic relations allow a model to belong to multiple other model types.

#### Polymorphic Belongs-To (`one_polymorphic`)

When a record can belong to one of several different types:

```php
// Comments can belong to Posts OR Videos
$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'commentable_type' => varchar(50)->not_null(),  // 'post' or 'video'
    'commentable_id' => integer()->not_null(),
    'content' => text()->not_null(),
]);

$comments_relations = define_relations($comments, function($r) use ($comments, $posts, $videos) {
    return [
        'commentable' => $r->one_polymorphic([
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'targets' => [
                'post' => $posts,
                'video' => $videos,
            ],
        ]),
    ];
});

// Query: Get comments with their parent (post or video)
$comments = $db->query_table($comments)
    ->with(['commentable' => true])
    ->find_many();

// Result:
// [
//     'id' => 1,
//     'commentable_type' => 'post',
//     'commentable_id' => 5,
//     'content' => 'Great post!',
//     'commentable' => ['id' => 5, 'title' => 'My Post', ...]
// ]
```

#### Polymorphic Has-Many (`many_polymorphic`)

When a record has many polymorphic children:

```php
$posts_relations = define_relations($posts, function($r) use ($posts, $comments) {
    return [
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'type_value' => 'post',  // Filter: commentable_type = 'post'
            'references' => [$posts->id],
        ]),
    ];
});

$videos_relations = define_relations($videos, function($r) use ($videos, $comments) {
    return [
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'type_value' => 'video',  // Filter: commentable_type = 'video'
            'references' => [$videos->id],
        ]),
    ];
});

// Query posts with their comments
$posts = $db->query_table($posts)->with(['comments' => true])->find_many();
```

### 6.7 Shorthand Query Methods

For convenience, you can use shorthand methods on the database object:

```php
// find_many with options
$users = $db->find_many($users, [
    'where' => eq($users->is_active, true),
    'with' => ['posts' => true, 'profile' => true],
    'order_by' => desc($users->created_at),
    'limit' => 20,
    'offset' => 0,
]);

// find_first with options
$user = $db->find_first($users, [
    'where' => eq($users->id, 1),
    'with' => ['posts' => ['with' => ['comments' => true]]],
]);

// find_one (alias for find_first)
$user = $db->find_one($users, [
    'where' => eq($users->email, 'test@example.com'),
]);
```

### 6.8 Relation Configuration Reference

#### One / Many Relation Config

| Key | Type | Description |
|-----|------|-------------|
| `fields` | `array` | Source table columns (usually PK or FK) |
| `references` | `array` | Target table columns to match against |
| `through` | `Table` | (Many-to-Many) Junction table |
| `through_fields` | `array` | (Many-to-Many) Junction table FK to source |
| `target_fields` | `array` | (Many-to-Many) Junction table FK to target |
| `target_references` | `array` | (Many-to-Many) Target table PK |

#### Polymorphic One Config

| Key | Type | Description |
|-----|------|-------------|
| `type_column` | `Column` | Column storing the type discriminator |
| `id_column` | `Column` | Column storing the foreign key |
| `targets` | `array` | Map of type values to table objects |

#### Polymorphic Many Config

| Key | Type | Description |
|-----|------|-------------|
| `type_column` | `Column` | Column storing the type discriminator |
| `id_column` | `Column` | Column storing the foreign key |
| `type_value` | `string` | Type discriminator value for this relation |
| `references` | `array` | Source table columns to match against id_column |

### 6.9 With Clause Options

| Key | Type | Description |
|-----|------|-------------|
| `true` | `bool` | Load relation with defaults |
| `where` | `Condition` | Filter condition for related records |
| `order_by` | `array` | Order by clauses |
| `limit` | `int` | Maximum records to load |
| `with` | `array` | Nested relations to load |

---

## 7. Query Builder

### SELECT

```php
// Select all columns
$results = $db->select()->from($users)->execute();

// Select specific columns
$results = $db->select([$users->name, $users->email])
    ->from($users)
    ->execute();

// With all clauses
$results = $db->select([$users->id, $users->name])
    ->from($users)
    ->where(eq($users->is_active, true))
    ->order_by(desc($users->created_at))
    ->limit(10)
    ->offset(20)
    ->execute();
```

### INSERT

```php
// Single record
$db->insert($users)->values([
    'name'  => 'Alice',
    'email' => 'alice@example.com'
])->execute();

// Multiple records
$db->insert($users)->values([
    ['name' => 'Bob', 'email' => 'bob@example.com'],
    ['name' => 'Charlie', 'email' => 'charlie@example.com'],
])->execute();

// With RETURNING (PostgreSQL/SQLite)
$inserted = $db->insert($users)
    ->values(['name' => 'Dave', 'email' => 'dave@example.com'])
    ->returning($users->id)
    ->execute();
```

### INSERT with ON CONFLICT (Upsert)

```php
// ON CONFLICT DO UPDATE
$db->insert($users)
    ->values(['name' => 'Alice', 'email' => 'alice@example.com'])
    ->on_conflict_do_update(['email'], [
        'name' => 'Alice Updated'
    ])
    ->execute();

// ON CONFLICT DO NOTHING
$db->insert($users)
    ->values(['name' => 'Alice', 'email' => 'alice@example.com'])
    ->on_conflict_do_nothing(['email'])
    ->execute();
```

### UPDATE

```php
$db->update($users)
    ->set([
        'name' => 'New Name',
        'is_active' => false
    ])
    ->where(eq($users->id, 1))
    ->execute();
```

### DELETE

```php
$db->delete($users)
    ->where(eq($users->id, 1))
    ->execute();
```

### JOINs

```php
// INNER JOIN
$results = $db->select([$users->name, $posts->title])
    ->from($users)
    ->inner_join($posts, eq($users->id, $posts->author_id))
    ->execute();

// LEFT JOIN
$results = $db->select([$users->name, $posts->title])
    ->from($users)
    ->left_join($posts, eq($users->id, $posts->author_id))
    ->execute();

// RIGHT JOIN
$results = $db->select([$users->name, $posts->title])
    ->from($posts)
    ->right_join($users, eq($users->id, $posts->author_id))
    ->execute();

// FULL OUTER JOIN
$results = $db->select()
    ->from($users)
    ->full_join($posts, eq($users->id, $posts->author_id))
    ->execute();

// CROSS JOIN
$results = $db->select()
    ->from($products)
    ->cross_join($colors)
    ->execute();
```

### GROUP BY and HAVING

```php
use function Italix\Orm\Operators\{sql_count, sql_sum, gte, raw};

$results = $db->select([
        $orders->product,
        sql_count()->as('order_count'),
        sql_sum($orders->amount)->as('total')
    ])
    ->from($orders)
    ->group_by($orders->product)
    ->having(gte(raw('total'), 1000))
    ->execute();
```

---

## 8. Operators Reference

### Import Operators

```php
use function Italix\Orm\Operators\{
    // Comparison
    eq, ne, gt, gte, lt, lte,
    // Logical
    and_, or_, not_,
    // Pattern matching
    like, not_like, ilike, not_ilike,
    // Range
    between, not_between,
    // Set membership
    in_array, not_in_array,
    // NULL checks
    is_null, is_not_null,
    // Ordering
    asc, desc,
    // Raw SQL
    raw
};
```

### Comparison Operators

| Function | SQL | Example |
|----------|-----|---------|
| `eq($col, $val)` | `=` | `eq($users->id, 1)` |
| `ne($col, $val)` | `<>` | `ne($users->status, 'deleted')` |
| `gt($col, $val)` | `>` | `gt($users->age, 18)` |
| `gte($col, $val)` | `>=` | `gte($users->score, 80)` |
| `lt($col, $val)` | `<` | `lt($users->age, 65)` |
| `lte($col, $val)` | `<=` | `lte($users->attempts, 3)` |

### Logical Operators

| Function | SQL | Example |
|----------|-----|---------|
| `and_(...$conds)` | `AND` | `and_(eq($a, 1), eq($b, 2))` |
| `or_(...$conds)` | `OR` | `or_(eq($a, 1), eq($a, 2))` |
| `not_($cond)` | `NOT` | `not_(eq($status, 'banned'))` |

**Example: Complex nested conditions**

```php
$db->select()->from($users)->where(
    and_(
        eq($users->status, 'active'),
        or_(
            gte($users->age, 18),
            eq($users->guardian_approved, true)
        ),
        not_(eq($users->role, 'banned'))
    )
)->execute();
// Generates: WHERE (status = ?) AND ((age >= ?) OR (guardian_approved = ?)) AND (NOT (role = ?))
```

### Pattern Matching Operators

| Function | SQL | Description |
|----------|-----|-------------|
| `like($col, $pattern)` | `LIKE` | Case-sensitive pattern match |
| `not_like($col, $pattern)` | `NOT LIKE` | Negated case-sensitive match |
| `ilike($col, $pattern)` | `ILIKE` | Case-insensitive match |
| `not_ilike($col, $pattern)` | `NOT ILIKE` | Negated case-insensitive match |

**Pattern wildcards:**
- `%` - Matches any sequence of characters
- `_` - Matches any single character

```php
// Names starting with 'A'
like($users->name, 'A%')

// Emails from gmail
like($users->email, '%@gmail.com')

// Case-insensitive search
ilike($users->name, 'alice')
```

### Range Operators

| Function | SQL | Example |
|----------|-----|---------|
| `between($col, $min, $max)` | `BETWEEN` | `between($users->age, 18, 65)` |
| `not_between($col, $min, $max)` | `NOT BETWEEN` | `not_between($price, 0, 10)` |
| `in_array($col, $values)` | `IN` | `in_array($status, ['a', 'b'])` |
| `not_in_array($col, $values)` | `NOT IN` | `not_in_array($role, ['x', 'y'])` |

### NULL Operators

| Function | SQL | Example |
|----------|-----|---------|
| `is_null($col)` | `IS NULL` | `is_null($users->deleted_at)` |
| `is_not_null($col)` | `IS NOT NULL` | `is_not_null($users->email)` |

### Ordering

| Function | SQL | Example |
|----------|-----|---------|
| `asc($col)` | `ASC` | `order_by(asc($users->name))` |
| `desc($col)` | `DESC` | `order_by(desc($users->created_at))` |

### Raw Expressions

```php
// Raw SQL in WHERE
$db->select()->from($users)
    ->where(raw('YEAR(created_at) = ?', [2024]))
    ->execute();

// Raw in SELECT
$db->select([raw('COUNT(*) as total')])
    ->from($users)
    ->execute();
```

---

## 9. Aggregate Functions

```php
use function Italix\Orm\Operators\{
    sql_count, sql_sum, sql_avg, sql_min, sql_max, sql_count_distinct
};
```

| Function | SQL | Example |
|----------|-----|---------|
| `sql_count()` | `COUNT(*)` | `sql_count()` |
| `sql_count($col)` | `COUNT(col)` | `sql_count($users->age)` |
| `sql_count_distinct($col)` | `COUNT(DISTINCT col)` | `sql_count_distinct($users->country)` |
| `sql_sum($col)` | `SUM(col)` | `sql_sum($orders->amount)` |
| `sql_avg($col)` | `AVG(col)` | `sql_avg($users->age)` |
| `sql_min($col)` | `MIN(col)` | `sql_min($products->price)` |
| `sql_max($col)` | `MAX(col)` | `sql_max($products->price)` |

### Using Aliases

```php
$db->select([
    sql_count()->as('total_users'),
    sql_avg($users->age)->as('average_age'),
    sql_min($users->created_at)->as('first_signup')
])->from($users)->execute();
```

---

## 10. Custom SQL with sql()

The `sql()` method provides a safe way to write custom SQL with proper parameter binding.

### Basic Usage

```php
// Simple parameterized query
$results = $db->sql('SELECT * FROM users WHERE id = ?', [$userId])->all();

// Multiple parameters
$results = $db->sql(
    'SELECT * FROM users WHERE status = ? AND age > ?',
    ['active', 18]
)->all();
```

### Execution Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `execute()` | `PDOStatement` | Execute and return statement |
| `all()` | `array` | Fetch all rows |
| `one()` | `array\|null` | Fetch single row |
| `scalar($col)` | `mixed` | Fetch single column value |
| `row_count()` | `int` | Get affected row count |

### Fluent Builder

```php
$results = $db->sql()
    ->append('SELECT * FROM ')
    ->identifier('users')         // Safely quoted: "users" or `users`
    ->append(' WHERE ')
    ->identifier('status')
    ->append(' = ')
    ->value('active')             // Parameterized: ?
    ->append(' AND ')
    ->identifier('age')
    ->append(' > ')
    ->value(18)
    ->all();
```

### Helper Methods

| Method | Description | Example |
|--------|-------------|---------|
| `append($sql)` | Add raw SQL | `->append('SELECT * FROM ')` |
| `identifier($name)` | Add quoted identifier | `->identifier('users')` |
| `value($val)` | Add parameterized value | `->value('active')` |
| `values($arr)` | Add multiple values | `->values(['a', 'b', 'c'])` |
| `in($arr)` | Add IN clause | `->in(['x', 'y', 'z'])` |
| `when($cond, $sql, $params)` | Conditional SQL | `->when($hasFilter, 'AND x = ?', [$x])` |
| `merge($sqlObj)` | Merge Sql objects | `->merge($otherSql)` |

### Composing SQL Fragments

```php
use function Italix\Orm\sql;

// Create reusable fragments
$baseQuery = sql('SELECT * FROM users');
$activeFilter = sql(' WHERE status = ?', ['active']);
$orderClause = sql(' ORDER BY name ASC');

// Combine fragments
$results = $db->sql()
    ->merge($baseQuery)
    ->merge($activeFilter)
    ->merge($orderClause)
    ->all();
```

### Conditional Queries

```php
$minAge = $_GET['min_age'] ?? null;
$status = $_GET['status'] ?? null;

$results = $db->sql()
    ->append('SELECT * FROM users WHERE 1=1')
    ->when($minAge !== null, ' AND age >= ?', [$minAge])
    ->when($status !== null, ' AND status = ?', [$status])
    ->all();
```

---

## 11. Transactions

### Manual Transactions

```php
$db->begin_transaction();

try {
    $db->insert($users)->values(['name' => 'Alice'])->execute();
    $db->insert($orders)->values(['user_id' => 1, 'amount' => 100])->execute();
    
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Using Callbacks

```php
$result = $db->transaction(function($db) use ($users, $orders) {
    $db->insert($users)->values(['name' => 'Alice'])->execute();
    $userId = $db->last_insert_id();
    
    $db->insert($orders)->values([
        'user_id' => $userId,
        'amount' => 100
    ])->execute();
    
    return $userId;
});
```

---

## 12. Security Considerations

### SQL Injection Protection

Italix ORM protects against SQL injection in several ways:

1. **Parameterized Queries**: All values are bound as parameters, never interpolated into SQL strings.

2. **Identifier Quoting**: Table and column names are properly quoted with escape sequences:
   - MySQL: `` `name` `` with backticks doubled for escaping
   - PostgreSQL/SQLite: `"name"` with double quotes doubled for escaping

3. **Type Safety**: PHP type declarations prevent passing unexpected data types.

### Safe Practices

```php
// ✅ SAFE: Using query builder with operators
$db->select()->from($users)
    ->where(eq($users->name, $userInput))  // $userInput is parameterized
    ->execute();

// ✅ SAFE: Using sql() with placeholders
$db->sql('SELECT * FROM users WHERE name = ?', [$userInput])->all();

// ✅ SAFE: Using identifier() for dynamic table/column names
$db->sql()
    ->append('SELECT * FROM ')
    ->identifier($tableName)  // Properly quoted
    ->all();

// ⚠️ CAUTION: Raw append() - only use with trusted input
$db->sql()
    ->append('SELECT * FROM users')
    ->append(' WHERE status = ')
    ->append("'active'")  // Only if 'active' is hardcoded, not user input!
    ->all();
```

### What Italix ORM Protects Against

- SQL injection via VALUES in INSERT/UPDATE
- SQL injection via WHERE condition values
- SQL injection via LIKE patterns
- SQL injection via IN array values
- Identifier injection via table/column names

### Security Test Suite

The library includes a comprehensive security test suite. Run it with:

```bash
php tests/SecurityTest.php
```

---

## 13. API Reference

### IxOrm Class

| Method | Return | Description |
|--------|--------|-------------|
| `select($columns = null)` | `QueryBuilder` | Start SELECT query |
| `insert($table)` | `QueryBuilder` | Start INSERT query |
| `update($table)` | `QueryBuilder` | Start UPDATE query |
| `delete($table)` | `QueryBuilder` | Start DELETE query |
| `sql($query, $params)` | `Sql` | Create custom SQL query |
| `create_tables(...$tables)` | `void` | Create tables |
| `drop_tables(...$tables)` | `void` | Drop tables |
| `table_exists($name)` | `bool` | Check if table exists |
| `execute($sql, $params)` | `PDOStatement` | Execute raw SQL |
| `query($sql, $params)` | `array` | Query and fetch all |
| `query_one($sql, $params)` | `array\|null` | Query and fetch one |
| `begin_transaction()` | `bool` | Start transaction |
| `commit()` | `bool` | Commit transaction |
| `rollback()` | `bool` | Rollback transaction |
| `transaction($callback)` | `mixed` | Execute in transaction |
| `last_insert_id($name)` | `string` | Get last insert ID |
| `get_connection()` | `PDO` | Get PDO connection |
| `get_dialect()` | `DialectInterface` | Get dialect |
| `query_table($table)` | `TableQuery` | Create relational query builder |
| `find_many($table, $options)` | `array` | Shorthand: find multiple records |
| `find_first($table, $options)` | `array\|null` | Shorthand: find first record |
| `find_one($table, $options)` | `array\|null` | Alias for find_first |

### TableQuery Class (Relations)

| Method | Return | Description |
|--------|--------|-------------|
| `where($condition)` | `self` | Add WHERE condition |
| `order_by(...$cols)` | `self` | Add ORDER BY |
| `limit($n)` | `self` | Set LIMIT |
| `offset($n)` | `self` | Set OFFSET |
| `with($relations)` | `self` | Configure eager loading |
| `find_many()` | `array` | Execute and fetch all |
| `find_first()` | `array\|null` | Execute and fetch first |
| `find_one()` | `array\|null` | Alias for find_first |
| `find($id)` | `array\|null` | Find by primary key |

### RelationBuilder Methods

| Method | Return | Description |
|--------|--------|-------------|
| `$r->one($table, $config)` | `One` | Define one-to-one/many-to-one |
| `$r->many($table, $config)` | `Many` | Define one-to-many/many-to-many |
| `$r->one_polymorphic($config)` | `PolymorphicOne` | Define polymorphic belongs-to |
| `$r->many_polymorphic($table, $config)` | `PolymorphicMany` | Define polymorphic has-many |

### QueryBuilder Class

| Method | Return | Description |
|--------|--------|-------------|
| `select($columns)` | `self` | Set SELECT columns |
| `from($table)` | `self` | Set FROM table |
| `where($condition)` | `self` | Add WHERE condition |
| `order_by(...$cols)` | `self` | Add ORDER BY |
| `limit($n)` | `self` | Set LIMIT |
| `offset($n)` | `self` | Set OFFSET |
| `group_by(...$cols)` | `self` | Add GROUP BY |
| `having($condition)` | `self` | Add HAVING condition |
| `inner_join($table, $cond)` | `self` | Add INNER JOIN |
| `left_join($table, $cond)` | `self` | Add LEFT JOIN |
| `right_join($table, $cond)` | `self` | Add RIGHT JOIN |
| `full_join($table, $cond)` | `self` | Add FULL OUTER JOIN |
| `cross_join($table)` | `self` | Add CROSS JOIN |
| `insert($table)` | `self` | Start INSERT |
| `values($values)` | `self` | Set INSERT values |
| `on_conflict_do_update($target, $update)` | `self` | Add upsert |
| `on_conflict_do_nothing($target)` | `self` | Add ignore conflict |
| `update($table)` | `self` | Start UPDATE |
| `set($values)` | `self` | Set UPDATE values |
| `delete($table)` | `self` | Start DELETE |
| `returning(...$cols)` | `self` | Add RETURNING clause |
| `execute()` | `mixed` | Execute query |
| `to_sql(&$params)` | `string` | Get SQL string |

---

## 14. Examples

### User Authentication

```php
$users = mysql_table('users', [
    'id'            => integer()->primary_key()->auto_increment(),
    'email'         => varchar(255)->not_null()->unique(),
    'password_hash' => varchar(255)->not_null(),
    'is_verified'   => boolean()->default(false),
    'created_at'    => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Register user
$db->insert($users)->values([
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
])->execute();

// Find user by email
$user = $db->select()
    ->from($users)
    ->where(eq($users->email, $email))
    ->execute();

if ($user && password_verify($password, $user[0]['password_hash'])) {
    // Login successful
}
```

### E-commerce Order

```php
$products = mysql_table('products', [
    'id'    => integer()->primary_key()->auto_increment(),
    'name'  => varchar(200)->not_null(),
    'price' => decimal(10, 2)->not_null(),
    'stock' => integer()->default(0),
]);

$orders = mysql_table('orders', [
    'id'         => integer()->primary_key()->auto_increment(),
    'user_id'    => integer()->not_null(),
    'total'      => decimal(10, 2)->not_null(),
    'status'     => varchar(20)->default('pending'),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

$order_items = mysql_table('order_items', [
    'id'         => integer()->primary_key()->auto_increment(),
    'order_id'   => integer()->not_null(),
    'product_id' => integer()->not_null(),
    'quantity'   => integer()->not_null(),
    'price'      => decimal(10, 2)->not_null(),
]);

// Create order with transaction
$orderId = $db->transaction(function($db) use ($orders, $order_items, $products, $cart, $userId) {
    // Calculate total
    $total = array_sum(array_map(fn($item) => $item['price'] * $item['qty'], $cart));
    
    // Create order
    $db->insert($orders)->values([
        'user_id' => $userId,
        'total' => $total,
    ])->execute();
    $orderId = $db->last_insert_id();
    
    // Add items and update stock
    foreach ($cart as $item) {
        $db->insert($order_items)->values([
            'order_id' => $orderId,
            'product_id' => $item['id'],
            'quantity' => $item['qty'],
            'price' => $item['price'],
        ])->execute();
        
        // Decrease stock
        $db->sql(
            'UPDATE products SET stock = stock - ? WHERE id = ?',
            [$item['qty'], $item['id']]
        )->execute();
    }
    
    return $orderId;
});
```

### Reporting with Aggregates

```php
// Sales by product
$results = $db->select([
        $products->name,
        sql_count()->as('times_ordered'),
        sql_sum($order_items->quantity)->as('total_units'),
        sql_sum(raw('quantity * price'))->as('revenue')
    ])
    ->from($order_items)
    ->inner_join($products, eq($products->id, $order_items->product_id))
    ->group_by($products->id)
    ->order_by(desc(raw('revenue')))
    ->limit(10)
    ->execute();
```

---

## 15. Troubleshooting

### Common Errors

**Error: "No table specified for SELECT"**
```php
// Wrong
$db->select()->where(eq($users->id, 1))->execute();

// Correct
$db->select()->from($users)->where(eq($users->id, 1))->execute();
```

**Error: "No values provided for INSERT"**
```php
// Wrong
$db->insert($users)->execute();

// Correct
$db->insert($users)->values(['name' => 'Test'])->execute();
```

**Error: Naming conflict with PHP's built-in functions**
```php
// If using `in_array` from Italix\Orm\Operators, it may conflict with PHP's in_array()
// Solution: Use fully qualified name or alias

use function Italix\Orm\Operators\in_array as sql_in_array;

$db->select()->from($users)->where(sql_in_array($users->status, ['a', 'b']))->execute();
```

### Debug Tips

**View generated SQL:**
```php
$builder = $db->select()->from($users)->where(eq($users->id, 1));
$params = [];
echo $builder->to_sql($params);
print_r($params);
```

**View Sql object query:**
```php
$sql = $db->sql()
    ->append('SELECT * FROM users WHERE id = ')
    ->value(1);
echo $sql->get_query();      // SELECT * FROM users WHERE id = ?
print_r($sql->get_params()); // [1]
```

---

## License

Apache License 2.0

## Credits

Inspired by [Drizzle ORM](https://orm.drizzle.team/) for TypeScript.

---

*Italix ORM - Safe, Simple, Powerful.*
