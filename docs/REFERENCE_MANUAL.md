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
7. [ActiveRow](#7-activerow)
8. [Query Builder](#8-query-builder)
9. [Operators Reference](#9-operators-reference)
10. [Aggregate Functions](#10-aggregate-functions)
11. [Custom SQL with sql()](#11-custom-sql-with-sql)
12. [Transactions](#12-transactions)
13. [Security Considerations](#13-security-considerations)
14. [API Reference](#14-api-reference)
15. [Examples](#15-examples)
16. [Troubleshooting](#16-troubleshooting)

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

### 6.10 Loading Strategies: Eager vs Lazy

Understanding when to use eager loading versus lazy loading (manual queries) is essential for building performant applications.

#### What is Eager Loading?

**Eager loading** pre-fetches related data in optimized batch queries when you load the parent records. Italix ORM uses the `with` clause for eager loading.

```php
// Eager loading: 2 queries total
$users = $db->query_table($users)
    ->with(['posts' => true, 'profile' => true])
    ->find_many();
// Query 1: SELECT * FROM users
// Query 2: SELECT * FROM posts WHERE author_id IN (1, 2, 3, ...)
// Query 3: SELECT * FROM profiles WHERE user_id IN (1, 2, 3, ...)
```

#### What is Lazy Loading?

**Lazy loading** fetches related data on-demand, when you actually access it. In Italix ORM, this means making separate queries when needed.

```php
// Lazy loading: query relations when needed
$users = $db->query_table($users)->find_many();  // 1 query

// Later, load posts for a specific user
$userPosts = $db->query_table($posts)
    ->where(eq($posts->author_id, $users[0]['id']))
    ->find_many();  // 1 additional query
```

#### The N+1 Query Problem

The most common performance issue with ORMs is the "N+1 problem" - loading N related records requires N+1 database queries:

```php
// PROBLEM: N+1 queries
$users = $db->query_table($users)->find_many();  // 1 query

foreach ($users as $user) {
    // Each iteration = 1 additional query
    $posts = $db->query_table($posts)
        ->where(eq($posts->author_id, $user['id']))
        ->find_many();

    echo count($posts) . " posts for " . $user['name'];
}
// Total: 1 + N queries (if 100 users = 101 queries!)
```

**Solution: Use eager loading**

```php
// SOLUTION: 2 queries total, regardless of user count
$users = $db->query_table($users)
    ->with(['posts' => true])
    ->find_many();

foreach ($users as $user) {
    // No additional queries - posts already loaded
    echo count($user['posts']) . " posts for " . $user['name'];
}
// Total: 2 queries (even with 1000 users)
```

#### When to Use Eager Loading

| Use Case | Why Eager Loading |
|----------|-------------------|
| **List views with related data** | Prevents N+1 when iterating |
| **API responses with nested resources** | Single round-trip for all data |
| **Reports/exports** | Efficient bulk data retrieval |
| **Data you know you'll access** | Reduces total query count |
| **Templates/views iterating over relations** | Avoids query-per-row |

**Example: Blog posts listing**

```php
$posts = $db->query_table($posts)
    ->with([
        'author' => true,           // Author name for byline
        'category' => true,         // Category for filtering/display
        'tags' => true,             // Tags for display
        'comments' => [
            'limit' => 3,           // Just preview, not all
            'order_by' => [desc($comments->created_at)],
        ],
    ])
    ->where(eq($posts->published, true))
    ->order_by(desc($posts->created_at))
    ->limit(20)
    ->find_many();
```

#### When to Use Lazy Loading

| Use Case | Why Lazy Loading |
|----------|------------------|
| **Conditional data access** | Load only if condition met |
| **Single record views** | Overhead difference is minimal |
| **Rarely accessed relations** | Avoid loading unused data |
| **Very large related datasets** | Load with pagination on demand |
| **Complex filtering on related data** | More control over the query |

**Example: Conditional loading**

```php
$user = $db->query_table($users)->find($userId);

// Only load activity log if user is admin viewing their own profile
if ($isAdmin && $viewingOwnProfile) {
    $activityLog = $db->query_table($activity_logs)
        ->where(eq($activity_logs->user_id, $user['id']))
        ->order_by(desc($activity_logs->created_at))
        ->limit(50)
        ->find_many();
}
```

**Example: Paginated related data**

```php
$post = $db->query_table($posts)->find($postId);

// Load comments with pagination (user controls page)
$page = $_GET['comment_page'] ?? 1;
$perPage = 20;

$comments = $db->query_table($comments)
    ->where(eq($comments->post_id, $post['id']))
    ->order_by(desc($comments->created_at))
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->find_many();
```

#### Performance Comparison

| Scenario | Eager Loading | Lazy Loading |
|----------|---------------|--------------|
| 100 users, each with posts | 2 queries | 101 queries |
| 1 user with posts | 2 queries | 2 queries |
| 100 users, 10% need posts | 2 queries | 11 queries |
| 100 users, posts rarely shown | 2 queries (wasteful) | 1 query (efficient) |

#### Best Practices

**1. Default to eager loading for lists**

```php
// When displaying a list, eager load what you'll display
$orders = $db->query_table($orders)
    ->with([
        'customer' => true,
        'items' => ['with' => ['product' => true]],
    ])
    ->find_many();
```

**2. Don't over-eager**

```php
// BAD: Loading everything
$user = $db->query_table($users)
    ->with([
        'posts' => true,
        'comments' => true,
        'likes' => true,
        'followers' => true,
        'following' => true,
        'notifications' => true,
    ])
    ->find($id);

// GOOD: Load only what the current view needs
$user = $db->query_table($users)
    ->with(['profile' => true])  // Only profile for header
    ->find($id);
```

**3. Use filtered/limited relations for large datasets**

```php
// Instead of loading ALL posts (could be thousands)
$user = $db->query_table($users)
    ->with([
        'posts' => [
            'where' => eq($posts->published, true),
            'order_by' => [desc($posts->created_at)],
            'limit' => 5,  // Just recent posts
        ]
    ])
    ->find($id);
```

**4. Batch process with pagination**

```php
// Process large datasets in chunks
$offset = 0;
$batchSize = 100;

do {
    $users = $db->query_table($users)
        ->with(['subscription' => true])
        ->limit($batchSize)
        ->offset($offset)
        ->find_many();

    foreach ($users as $user) {
        processUserSubscription($user);
    }

    $offset += $batchSize;
} while (count($users) === $batchSize);
```

**5. Profile your queries**

Enable query logging to identify N+1 problems:

```php
// Count queries in development
$queryCount = 0;
// ... run your code ...
// If $queryCount is unexpectedly high, you may have N+1 issues
```

#### Decision Flowchart

```
Will you iterate over parent records?
├─ Yes → Will you access related data for each?
│        ├─ Yes (all/most) → USE EAGER LOADING
│        └─ No (few/conditional) → USE LAZY LOADING
└─ No (single record) → Either approach works
                        ├─ Known relations needed → Eager (simpler code)
                        └─ Conditional access → Lazy (more efficient)
```

---

## 7. ActiveRow

ActiveRow provides a lightweight active record pattern where database rows become objects with both array access and custom methods.

### 7.1 Overview

ActiveRow bridges the gap between raw arrays and full ORM entities:

- **Array-like**: Access data with `$row['field']` syntax
- **Object-like**: Call custom methods like `$row->full_name()`
- **Lightweight**: Minimal overhead, no magic methods for data access
- **Composable**: Add behaviors via traits instead of inheritance

### 7.2 Creating Row Classes

```php
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\{Persistable, HasTimestamps, SoftDeletes};

class UserRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    /**
     * Custom method: compute full name
     */
    public function full_name(): string
    {
        return trim($this['first_name'] . ' ' . $this['last_name']);
    }

    /**
     * Custom method: check role
     */
    public function is_admin(): bool
    {
        return $this['role'] === 'admin';
    }
}
```

### 7.3 Wrapping and Unwrapping

#### Wrap Arrays into ActiveRow

```php
// Wrap a single array
$data = ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'];
$user = UserRow::wrap($data);

// Wrap multiple arrays
$rows = $db->select()->from($users)->execute();
$users = UserRow::wrap_many($rows);

// Create new (no original data)
$user = UserRow::make(['name' => 'New User']);
```

#### Unwrap Back to Arrays

```php
// Get as plain array
$array = $user->to_array();

// Alias methods
$array = $user->unwrap();
$array = $user->data;

// JSON serialization (automatic)
json_encode($user);  // Works directly
```

### 7.4 Array Access

ActiveRow implements `ArrayAccess`, allowing array syntax:

```php
$user = UserRow::wrap(['id' => 1, 'name' => 'John']);

// Read
echo $user['name'];          // "John"
echo $user['missing'];       // null (no error)

// Write
$user['email'] = 'john@example.com';

// Check existence
isset($user['name']);        // true
isset($user['missing']);     // false

// Unset
unset($user['email']);

// Iteration
foreach ($user as $key => $value) {
    echo "$key: $value\n";
}
```

### 7.5 Dirty Tracking

Track changes since the row was loaded:

```php
$user = UserRow::wrap(['id' => 1, 'name' => 'Original']);

// Check clean state
$user->is_clean();           // true
$user->is_dirty();           // false

// Make changes
$user['name'] = 'Changed';

// Check dirty state
$user->is_dirty();           // true
$user->is_dirty('name');     // true
$user->is_dirty('id');       // false

// Get changed fields
$user->get_dirty();          // ['name' => 'Changed']

// Get original value
$user->get_original('name'); // 'Original'

// Reset tracking
$user->sync_original();      // Mark current state as clean
```

### 7.6 Persistable Trait

Adds database persistence methods.

#### Setup

```php
// Configure once at application bootstrap
UserRow::set_persistence($db, $users_table);
```

#### CRUD Operations

```php
// Create
$user = UserRow::create([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Read
$user = UserRow::find(1);
$user = UserRow::find(1, ['posts' => true]);  // With relations

// Update
$user['name'] = 'Jane';
$user->save();

// Or update directly
$user->update(['name' => 'Jane', 'email' => 'jane@example.com']);

// Delete
$user->delete();

// Refresh from database
$user->refresh();
```

#### Static Finders

```php
// Find by ID
$user = UserRow::find(1);

// Find all
$users = UserRow::find_all();

// Find with conditions
$admins = UserRow::find_all([
    'where' => eq($users->role, 'admin'),
    'order_by' => desc($users->created_at),
    'limit' => 10,
]);

// Find one
$user = UserRow::find_one([
    'where' => eq($users->email, 'john@example.com'),
]);

// Upsert (update or create)
$user = UserRow::upsert(
    ['email' => 'john@example.com'],  // Match criteria
    ['name' => 'John Doe']            // Values to set
);
```

### 7.7 HasTimestamps Trait

Automatically manages `created_at` and `updated_at` columns.

```php
class PostRow extends ActiveRow
{
    use Persistable, HasTimestamps;
}

$post = PostRow::create(['title' => 'Hello']);
// created_at and updated_at are automatically set

$post['title'] = 'Updated';
$post->save();
// updated_at is automatically updated

// Manual touch
$post->touch()->save();

// Check timing
$post->was_recently_created(60);  // Created in last 60 seconds?
$post->was_recently_updated(60);  // Updated in last 60 seconds?

// Get as DateTime
$post->get_created_at_datetime();
$post->get_updated_at_datetime();
```

### 7.8 SoftDeletes Trait

Soft delete records instead of permanent deletion.

```php
class PostRow extends ActiveRow
{
    use Persistable, SoftDeletes;
}

$post = PostRow::find(1);

// Soft delete
$post->soft_delete();
$post->is_deleted();     // true
$post['deleted_at'];     // timestamp

// Restore
$post->restore();
$post->is_deleted();     // false

// Force delete (permanent)
$post->force_delete();
```

### 7.9 HasSlug Trait

Automatically generate URL-friendly slugs. Override the getter methods to customize behavior.

```php
class PostRow extends ActiveRow
{
    use Persistable, HasSlug;

    // Override to specify the source column (default: 'title')
    protected function get_slug_source(): string
    {
        return 'title';
    }

    // Optional: Override slug column (default: 'slug')
    protected function get_slug_column(): string
    {
        return 'slug';
    }

    // Optional: Regenerate slug on update (default: false)
    protected function get_slug_on_update(): bool
    {
        return false;
    }

    // Optional: Maximum slug length (default: 255)
    protected function get_slug_max_length(): int
    {
        return 255;
    }
}

$post = PostRow::create(['title' => 'Hello World!']);
echo $post['slug'];  // "hello-world"

// Manual slug generation
$post->set_slug('custom-slug');
$post->regenerate_slug();
```

### 7.10 CanBeAuthor Trait

Shared interface for entities that can be authors (Person, Organization).

```php
use Italix\Orm\ActiveRow\Traits\CanBeAuthor;

class PersonRow extends ActiveRow
{
    use CanBeAuthor;

    public function display_name(): string
    {
        return $this['given_name'] . ' ' . $this['family_name'];
    }

    public function citation_name(): string
    {
        return $this['family_name'] . ', ' . substr($this['given_name'], 0, 1) . '.';
    }
}

class OrganizationRow extends ActiveRow
{
    use CanBeAuthor;

    public function display_name(): string
    {
        return $this['name'];
    }
}

// Both can be used uniformly
foreach ($work->authors() as $author) {
    echo $author->display_name();    // Works for both
    echo $author->author_type();     // "person" or "organization"
    echo $author->citation_name();   // Formatted for citations
    echo $author->initials();        // "JS" or "WHO"
}
```

### 7.11 Hook System (AOP)

Traits can hook into lifecycle events using naming conventions:

```php
class PostRow extends ActiveRow
{
    use Persistable;

    // Called before save
    protected function before_save_validate(): void
    {
        if (empty($this['title'])) {
            throw new \InvalidArgumentException('Title is required');
        }
    }

    // Called after save
    protected function after_save_cache(): void
    {
        cache_forget('posts:' . $this['id']);
    }
}
```

Available hooks:
- `before_save_*` / `after_save_*`
- `before_delete_*` / `after_delete_*`
- `after_wrap_*`
- `after_refresh_*`
- `before_soft_delete_*` / `after_soft_delete_*`
- `before_restore_*` / `after_restore_*`

### 7.12 Utility Methods

```php
$user = UserRow::wrap(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

// Check state
$user->exists();             // Has primary key?
$user->is_new();             // No primary key?
$user->get_key();            // Get primary key value
$user->has('name');          // Has non-null value?

// Get with default
$user->get('missing', 'default');

// Filter fields
$user->only(['name', 'email']);      // Only these keys
$user->except(['id', 'created_at']); // All except these

// Bulk assign
$user->fill(['name' => 'Jane', 'role' => 'admin']);

// Clone with modifications
$admin = $user->with(['role' => 'admin']);

// Create copy without ID
$copy = $user->replicate();
```

### 7.13 Working with Relations

Load related data as ActiveRow instances:

```php
class CreativeWorkRow extends ActiveRow
{
    // Map relation names to row classes
    protected static $relation_classes = [
        'authors' => PersonRow::class,
    ];

    // Method to get wrapped authors
    public function authors(): array
    {
        return $this->relation('authors') ?? [];
    }

    // Or manually wrap polymorphic relations
    public function polymorphic_authors(): array
    {
        return array_map(function($authorship) {
            $type = $authorship['author_type'];
            $data = $authorship['author'];

            return $type === 'person'
                ? PersonRow::wrap($data)
                : OrganizationRow::wrap($data);
        }, $this['authorships'] ?? []);
    }
}

// Usage
$work = CreativeWorkRow::wrap($dataWithRelations);
foreach ($work->authors() as $author) {
    echo $author->display_name();  // Author methods available
}
```

---

## 8. Query Builder

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

## 9. Operators Reference

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

## 10. Aggregate Functions

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

## 11. Custom SQL with sql()

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

## 12. Transactions

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

## 13. Security Considerations

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

## 14. API Reference

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

### ActiveRow Class

| Method | Return | Description |
|--------|--------|-------------|
| `wrap($data)` | `static` | Create instance from array (static) |
| `wrap_many($rows)` | `array` | Wrap multiple arrays (static) |
| `make($data)` | `static` | Create new instance (static) |
| `to_array()` | `array` | Get data as plain array |
| `unwrap()` | `array` | Alias for to_array() |
| `exists()` | `bool` | Check if has primary key |
| `is_new()` | `bool` | Check if no primary key |
| `get_key()` | `mixed` | Get primary key value |
| `is_dirty($key)` | `bool` | Check for unsaved changes |
| `is_clean()` | `bool` | Check if no changes |
| `get_dirty()` | `array` | Get changed fields |
| `get_original($key)` | `mixed` | Get original value |
| `sync_original()` | `self` | Mark current state as clean |
| `fill($data)` | `self` | Mass assign data |
| `only($keys)` | `array` | Get specific keys |
| `except($keys)` | `array` | Get all except keys |
| `has($key)` | `bool` | Check if key has value |
| `get($key, $default)` | `mixed` | Get with default |
| `with($data)` | `self` | Clone with new data |
| `replicate()` | `self` | Clone without primary key |
| `relation($name, $class)` | `mixed` | Get wrapped relation |

### Persistable Trait Methods

| Method | Return | Description |
|--------|--------|-------------|
| `set_persistence($db, $table)` | `void` | Configure DB and table (static) |
| `has_persistence()` | `bool` | Check if configured (static) |
| `find($id, $with)` | `static\|null` | Find by primary key (static) |
| `find_all($options)` | `array` | Find multiple (static) |
| `find_one($options)` | `static\|null` | Find first (static) |
| `create($data)` | `static` | Create and save (static) |
| `upsert($attrs, $values)` | `static` | Update or create (static) |
| `save()` | `self` | Persist changes |
| `delete()` | `self` | Remove from database |
| `refresh()` | `self` | Reload from database |
| `update($data)` | `self` | Fill and save |

### HasTimestamps Trait Methods

| Method | Return | Description |
|--------|--------|-------------|
| `touch()` | `self` | Update updated_at |
| `get_created_at()` | `string\|null` | Get created_at value |
| `get_updated_at()` | `string\|null` | Get updated_at value |
| `get_created_at_datetime()` | `DateTime\|null` | Get as DateTime |
| `get_updated_at_datetime()` | `DateTime\|null` | Get as DateTime |
| `was_recently_created($sec)` | `bool` | Created within N seconds |
| `was_recently_updated($sec)` | `bool` | Updated within N seconds |

### SoftDeletes Trait Methods

| Method | Return | Description |
|--------|--------|-------------|
| `soft_delete()` | `self` | Set deleted_at timestamp |
| `restore()` | `self` | Clear deleted_at |
| `is_deleted()` | `bool` | Check if soft deleted |
| `is_active()` | `bool` | Check if not deleted |
| `get_deleted_at()` | `string\|null` | Get deleted_at value |
| `get_deleted_at_datetime()` | `DateTime\|null` | Get as DateTime |
| `force_delete()` | `self` | Permanently delete |

### HasSlug Trait Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get_slug()` | `string\|null` | Get slug value |
| `set_slug($slug)` | `self` | Manually set slug |
| `regenerate_slug()` | `self` | Regenerate from source |
| `generate_slug($text)` | `string` | Generate slug from text |

### CanBeAuthor Trait Methods

| Method | Return | Description |
|--------|--------|-------------|
| `display_name()` | `string` | Get display name (abstract) |
| `author_label()` | `string` | Get author label |
| `author_type()` | `string` | Get type identifier |
| `citation_name()` | `string` | Get citation format |
| `is_person()` | `bool` | Check if type is 'person' |
| `is_organization()` | `bool` | Check if type is 'organization' |
| `initials($count)` | `string` | Get initials |
| `author_meta()` | `array` | Get author metadata |

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

## 15. Examples

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

## 16. Troubleshooting

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
