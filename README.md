# Italix ORM

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

A lightweight, type-safe ORM for PHP with support for MySQL, PostgreSQL, SQLite, and Supabase.

## Features

- 🚀 **Lightweight** - Minimal dependencies, fast and efficient
- 🔒 **Type-safe** - Full PHP 7.4+ type declarations
- 🗃️ **Multi-database** - MySQL, PostgreSQL, SQLite, Supabase
- 🔧 **Query Builder** - Fluent, chainable API
- 📦 **Schema Definition** - Define tables in PHP code
- 🔄 **Transactions** - Full transaction support
- 🎯 **PSR-4** - Composer autoloading
- 📋 **Migrations** - Laravel-style migrations with full rollback support
- ⚡ **CLI Tool (`ix`)** - Powerful command-line interface for migrations
- 🔗 **Relations** - Drizzle-style relations with eager loading and polymorphic support

## Installation

```bash
composer require italix/orm
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use function Italix\Orm\sqlite;
use function Italix\Orm\Schema\{sqlite_table, integer, text, varchar};
use function Italix\Orm\Operators\{eq, desc};

// Create a SQLite database connection
$db = sqlite(['database' => 'app.db']);

// Define a table schema
$users = sqlite_table('users', [
    'id'    => integer()->primary_key()->auto_increment(),
    'name'  => varchar(100)->not_null(),
    'email' => varchar(255)->not_null()->unique(),
]);

// Create the table
$db->create_tables($users);

// Insert a record
$db->insert($users)->values([
    'name'  => 'John Doe',
    'email' => 'john@example.com',
])->execute();

// Query records
$results = $db->select()
    ->from($users)
    ->where(eq($users->email, 'john@example.com'))
    ->execute();

// Update a record
$db->update($users)
    ->set(['name' => 'Jane Doe'])
    ->where(eq($users->id, 1))
    ->execute();

// Delete a record
$db->delete($users)
    ->where(eq($users->id, 1))
    ->execute();
```

## Database Connections

### MySQL

```php
use function Italix\Orm\mysql;

$db = mysql([
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
]);
```

### PostgreSQL

```php
use function Italix\Orm\postgres;

$db = postgres([
    'host'     => 'localhost',
    'port'     => 5432,
    'database' => 'myapp',
    'username' => 'postgres',
    'password' => 'secret',
]);
```

### SQLite

```php
use function Italix\Orm\{sqlite, sqlite_memory};

// File-based
$db = sqlite(['database' => '/path/to/database.db']);

// In-memory
$db = sqlite_memory();
```

### Supabase

```php
use function Italix\Orm\{supabase, supabase_from_credentials};

// From credentials
$db = supabase_from_credentials(
    'your-project-ref',
    'your-password',
    'postgres',
    'us-east-1',
    true // Use connection pooling
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

## Migrations

Italix ORM includes a powerful Laravel-style migration system with full rollback support, plus modern features inspired by Drizzle ORM.

### CLI Tool (`ix`)

After installing via Composer, the `ix` command is available:

```bash
# Show help
./vendor/bin/ix help

# Migration commands
./vendor/bin/ix migrate              # Run pending migrations
./vendor/bin/ix migrate:rollback     # Rollback last batch
./vendor/bin/ix migrate:reset        # Rollback all migrations
./vendor/bin/ix migrate:refresh      # Reset and re-run all
./vendor/bin/ix migrate:status       # Show migration status
./vendor/bin/ix make:migration       # Create new migration

# Schema management (Drizzle-like features)
./vendor/bin/ix db:pull              # Generate code from existing database
./vendor/bin/ix db:push              # Push schema directly (rapid prototyping)
./vendor/bin/ix db:diff              # Compare & auto-suggest migrations
./vendor/bin/ix db:squash            # Consolidate old migrations
```

### Configuration

Create `ix.config.php` in your project root:

```php
<?php
return [
    'database' => [
        'dialect' => 'mysql',  // mysql, postgresql, sqlite, supabase
        'host' => 'localhost',
        'database' => 'myapp',
        'username' => 'root',
        'password' => '',
    ],
    'migrations_path' => 'migrations',
];
```

### Creating Migrations

```bash
# Create a migration
./vendor/bin/ix make:migration create_users_table

# Auto-detects table name from migration name
./vendor/bin/ix make:migration add_email_to_users --table=users
```

This creates a file like `migrations/2024_01_15_143022_create_users_table.php`:

```php
<?php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();                              // BIGINT AUTO_INCREMENT PRIMARY KEY
            $table->string('name', 100);               // VARCHAR(100)
            $table->string('email')->unique();         // VARCHAR(255) UNIQUE
            $table->boolean('is_active')->default(true);
            $table->timestamps();                      // created_at, updated_at
        });
    }

    public function down(): void
    {
        Schema::drop_if_exists('users');
    }
}
```

### Blueprint Methods

#### Column Types

```php
Schema::create('example', function (Blueprint $table) {
    // Primary Keys
    $table->id();                      // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    $table->uuid('id');                // UUID/CHAR(36) PRIMARY KEY
    
    // Integers
    $table->tiny_integer('level');     // TINYINT
    $table->small_integer('rank');     // SMALLINT
    $table->integer('count');          // INTEGER
    $table->big_integer('views');      // BIGINT
    $table->unsigned_integer('votes'); // INTEGER UNSIGNED
    
    // Strings
    $table->string('name', 100);       // VARCHAR(100)
    $table->char('code', 3);           // CHAR(3)
    $table->text('bio');               // TEXT
    $table->medium_text('content');    // MEDIUMTEXT
    $table->long_text('body');         // LONGTEXT
    
    // Numbers
    $table->decimal('price', 10, 2);   // DECIMAL(10,2)
    $table->float('rating');           // FLOAT
    $table->double('amount');          // DOUBLE
    
    // Boolean
    $table->boolean('active');         // BOOLEAN/TINYINT(1)
    
    // Date/Time
    $table->date('birth_date');        // DATE
    $table->time('start_time');        // TIME
    $table->datetime('scheduled_at');  // DATETIME
    $table->timestamp('created_at');   // TIMESTAMP
    $table->timestamps();              // created_at + updated_at
    $table->soft_deletes();            // deleted_at (nullable timestamp)
    
    // JSON
    $table->json('metadata');          // JSON
    $table->jsonb('data');             // JSONB (PostgreSQL)
    
    // Binary
    $table->binary('data');            // BINARY
    $table->blob('file');              // BLOB
    
    // Enum
    $table->enum('status', ['draft', 'published', 'archived']);
});
```

#### Column Modifiers

```php
$table->string('email')
    ->nullable()                       // Allow NULL
    ->unique()                         // Add UNIQUE constraint
    ->default('default@example.com')   // Set default value
    ->comment('User email address')    // Column comment (MySQL)
    ->after('name')                    // Place after column (MySQL)
    ->first();                         // Place first (MySQL)
```

#### Indexes

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->string('slug');
    $table->text('content');
    $table->foreign_id('user_id');
    $table->timestamps();
    
    // Indexes
    $table->unique('slug');                      // Unique index
    $table->index('title');                      // Regular index
    $table->index(['user_id', 'created_at']);    // Composite index
    $table->fulltext('content');                 // Full-text index (MySQL)
    
    // Foreign Keys
    $table->foreign('user_id')
        ->references('id')
        ->on('users')
        ->on_delete('CASCADE');
    
    // Shorthand for foreign key (auto-detects: user_id -> users.id)
    $table->foreign('user_id')->constrained();
});
```

### Modifying Tables

```php
class AddEmailVerifiedToUsers extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->index('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->drop_index('users_email_verified_at_index');
            $table->drop_column('email_verified_at');
        });
    }
}
```

### Data Migrations

```php
class SeedDefaultCategories extends Migration
{
    public function up(): void
    {
        // Seed data in migrations
        $this->seed('categories', [
            ['name' => 'Technology', 'slug' => 'technology'],
            ['name' => 'Science', 'slug' => 'science'],
            ['name' => 'Art', 'slug' => 'art'],
        ]);
    }

    public function down(): void
    {
        $this->sql("DELETE FROM categories WHERE slug IN ('technology', 'science', 'art')");
    }
}
```

### Running Migrations

```bash
# Run all pending migrations
./vendor/bin/ix migrate

# Rollback last batch
./vendor/bin/ix migrate:rollback

# Rollback multiple batches
./vendor/bin/ix migrate:rollback --steps=3

# Rollback all migrations
./vendor/bin/ix migrate:reset

# Reset and re-run all migrations
./vendor/bin/ix migrate:refresh

# Show migration status
./vendor/bin/ix migrate:status
```

### Pull (Introspect Existing Database)

Generate migration code from an existing database:

```bash
# Generate schema code
./vendor/bin/ix db:pull --output=schema.php

# Generate as migration file
./vendor/bin/ix db:pull --format=migration --output=migrations/initial.php

# Initialize project with existing database
./vendor/bin/ix db:pull --format=migration --init
```

### Push (Rapid Prototyping)

Push schema changes directly without migration files (great for development):

```bash
# Preview changes
./vendor/bin/ix db:push --dry-run --schema=schema.php

# Apply changes
./vendor/bin/ix db:push --schema=schema.php

# Force destructive changes
./vendor/bin/ix db:push --schema=schema.php --force
```

### Diff (Auto-Suggest Migrations)

Compare your schema with the database and generate a migration:

```bash
# Show differences
./vendor/bin/ix db:diff --schema=schema.php

# Generate migration file
./vendor/bin/ix db:diff --schema=schema.php --generate
```

### Squash Migrations

Consolidate old migrations into a single file:

```bash
# Preview what will be squashed
./vendor/bin/ix db:squash

# Squash all migrations
./vendor/bin/ix db:squash --force
```

### Programmatic Usage

```php
use Italix\Orm\Migration\Migrator;
use function Italix\Orm\mysql;

$db = mysql([/* config */]);
$migrator = new Migrator($db, './migrations');

// Run migrations
$applied = $migrator->migrate();

// Rollback
$rolled_back = $migrator->rollback();

// Get status
$status = $migrator->status();

// Create migration file
$filepath = $migrator->create('create_posts_table', 'posts', true);
```

## Schema Definition

### Column Types

```php
use function Italix\Orm\Schema\{
    // Integers
    integer, bigint, smallint, serial, bigserial,
    // Strings
    text, varchar, char,
    // Boolean
    boolean,
    // Date/Time
    timestamp, datetime, date, time,
    // JSON
    json, jsonb,
    // Other
    uuid, real, double_precision, decimal, numeric,
    blob, binary, varbinary
};
```

### Table Definition

```php
use function Italix\Orm\Schema\{mysql_table, pg_table, sqlite_table};

// MySQL
$users = mysql_table('users', [
    'id'         => integer()->primary_key()->auto_increment(),
    'name'       => varchar(100)->not_null(),
    'email'      => varchar(255)->not_null()->unique(),
    'is_active'  => boolean()->default(true),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// PostgreSQL
$posts = pg_table('posts', [
    'id'        => serial(),
    'title'     => varchar(200)->not_null(),
    'content'   => text(),
    'metadata'  => jsonb(),
    'author_id' => integer()->not_null(),
]);

// SQLite
$logs = sqlite_table('logs', [
    'id'         => integer()->primary_key()->auto_increment(),
    'message'    => text()->not_null(),
    'level'      => varchar(20)->default('info'),
    'created_at' => text(),
]);
```

## Relations (Drizzle-style)

Italix ORM features a Drizzle-inspired relation system with explicit relation definitions, eager loading, and polymorphic support.

### Defining Relations

```php
use function Italix\Orm\Relations\define_relations;

// Define tables
$users = sqlite_table('users', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
]);

$posts = sqlite_table('posts', [
    'id' => integer()->primary_key()->auto_increment(),
    'author_id' => integer()->not_null(),
    'title' => varchar(255)->not_null(),
]);

$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'post_id' => integer()->not_null(),
    'user_id' => integer()->not_null(),
    'content' => text()->not_null(),
]);

// Define relations
$users_relations = define_relations($users, function($r) use ($users, $posts, $comments) {
    return [
        // One-to-many: users.id -> posts.author_id
        'posts' => $r->many($posts, [
            'fields' => [$users->id],
            'references' => [$posts->author_id],
        ]),
        // One-to-many: users.id -> comments.user_id
        'comments' => $r->many($comments, [
            'fields' => [$users->id],
            'references' => [$comments->user_id],
        ]),
    ];
});

$posts_relations = define_relations($posts, function($r) use ($users, $posts, $comments) {
    return [
        // Many-to-one: posts.author_id -> users.id
        'author' => $r->one($users, [
            'fields' => [$posts->author_id],
            'references' => [$users->id],
        ]),
        // One-to-many: posts.id -> comments.post_id
        'comments' => $r->many($comments, [
            'fields' => [$posts->id],
            'references' => [$comments->post_id],
        ]),
    ];
});
```

### Query Methods with Eager Loading

```php
// find_many() - Get multiple records with relations
$users = $db->query_table($users)
    ->with(['posts' => true])
    ->find_many();

// find_first() - Get first matching record
$user = $db->query_table($users)
    ->where(eq($users->id, 1))
    ->with(['posts' => true, 'comments' => true])
    ->find_first();

// find() - Get by primary key
$user = $db->query_table($users)
    ->with(['posts' => true])
    ->find(1);

// Nested relations
$users = $db->query_table($users)
    ->with([
        'posts' => [
            'with' => ['comments' => true]  // Load comments for each post
        ]
    ])
    ->find_many();
```

### Filtered and Ordered Relations

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

### Relation Aliases

```php
$posts = $db->query_table($posts)
    ->with([
        'writer:author' => true,  // Load 'author' relation as 'writer'
    ])
    ->find_many();

// Access via alias: $post['writer']['name']
```

### Many-to-Many Relations

```php
$tags = sqlite_table('tags', [...]);
$post_tags = sqlite_table('post_tags', [
    'post_id' => integer()->not_null(),
    'tag_id' => integer()->not_null(),
]);

$posts_relations = define_relations($posts, function($r) use ($posts, $tags, $post_tags) {
    return [
        'tags' => $r->many($tags, [
            'fields' => [$posts->id],
            'through' => $post_tags,
            'through_fields' => [$post_tags->post_id],
            'target_fields' => [$post_tags->tag_id],
            'target_references' => [$tags->id],
        ]),
    ];
});

// Query posts with tags
$posts = $db->query_table($posts)->with(['tags' => true])->find_many();
```

### Polymorphic Relations

```php
// Comments can belong to Posts OR Videos
$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'commentable_type' => varchar(50)->not_null(),  // 'post' or 'video'
    'commentable_id' => integer()->not_null(),
    'content' => text()->not_null(),
]);

// Polymorphic "belongs to" (one_polymorphic)
$comments_relations = define_relations($comments, function($r) use ($posts, $videos) {
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

// Polymorphic "has many" (many_polymorphic)
$posts_relations = define_relations($posts, function($r) use ($posts, $comments) {
    return [
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->commentable_type,
            'id_column' => $comments->commentable_id,
            'type_value' => 'post',
            'references' => [$posts->id],
        ]),
    ];
});

// Query with polymorphic relations
$comments = $db->query_table($comments)
    ->with(['commentable' => true])
    ->find_many();
```

### Shorthand Query Methods

```php
// Shorthand for common patterns
$users = $db->find_many($users, [
    'where' => eq($users->is_active, true),
    'with' => ['posts' => true],
    'order_by' => desc($users->id),
    'limit' => 20,
]);

$user = $db->find_first($users, [
    'where' => eq($users->id, 1),
    'with' => ['profile' => true, 'posts' => true],
]);
```

## Query Builder

### SELECT

```php
// Select all columns
$results = $db->select()->from($users)->execute();

// Select specific columns
$results = $db->select([$users->id, $users->name])->from($users)->execute();

// With WHERE, ORDER BY, LIMIT, OFFSET
$results = $db->select()
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
    'email' => 'alice@example.com',
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
// ON CONFLICT DO UPDATE (PostgreSQL/SQLite)
$db->insert($users)
    ->values(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30])
    ->on_conflict_do_update(['email'], [
        'name' => 'Alice Updated',
        'age' => 31
    ])
    ->execute();

// ON CONFLICT DO NOTHING
$db->insert($users)
    ->values(['name' => 'Alice', 'email' => 'alice@example.com'])
    ->on_conflict_do_nothing(['email'])
    ->execute();

// MySQL uses ON DUPLICATE KEY UPDATE automatically
```

### UPDATE

```php
$db->update($users)
    ->set(['name' => 'Updated Name', 'is_active' => false])
    ->where(eq($users->id, 1))
    ->execute();
```

### DELETE

```php
$db->delete($users)
    ->where(eq($users->id, 1))
    ->execute();
```

## Operators

### Comparison Operators

```php
use function Italix\Orm\Operators\{eq, ne, gt, gte, lt, lte};

// Equal (=)
$db->select()->from($users)->where(eq($users->name, 'Alice'))->execute();

// Not equal (<>)
$db->select()->from($users)->where(ne($users->status, 'inactive'))->execute();

// Greater than (>)
$db->select()->from($users)->where(gt($users->age, 18))->execute();

// Greater than or equal (>=)
$db->select()->from($users)->where(gte($users->salary, 50000))->execute();

// Less than (<)
$db->select()->from($users)->where(lt($users->age, 65))->execute();

// Less than or equal (<=)
$db->select()->from($users)->where(lte($users->attempts, 3))->execute();
```

### Logical Operators

```php
use function Italix\Orm\Operators\{and_, or_, not_};

// AND
$db->select()->from($users)->where(
    and_(
        gte($users->age, 18),
        eq($users->is_active, true)
    )
)->execute();

// OR
$db->select()->from($users)->where(
    or_(
        eq($users->role, 'admin'),
        eq($users->role, 'moderator')
    )
)->execute();

// NOT
$db->select()->from($users)->where(
    not_(eq($users->status, 'banned'))
)->execute();

// Complex combinations
$db->select()->from($users)->where(
    and_(
        gte($users->age, 18),
        or_(
            like($users->email, '%@gmail.com'),
            like($users->email, '%@yahoo.com')
        )
    )
)->execute();
```

### LIKE Operators

```php
use function Italix\Orm\Operators\{like, not_like, ilike, not_ilike};

// LIKE
$db->select()->from($users)->where(like($users->name, 'A%'))->execute();

// NOT LIKE
$db->select()->from($users)->where(not_like($users->email, '%@spam.com'))->execute();

// ILIKE (case-insensitive, PostgreSQL native, emulated on others)
$db->select()->from($users)->where(ilike($users->name, 'alice'))->execute();

// NOT ILIKE
$db->select()->from($users)->where(not_ilike($users->name, 'bob'))->execute();
```

### Range Operators

```php
use function Italix\Orm\Operators\{between, not_between, in_array, not_in_array};

// BETWEEN
$db->select()->from($users)->where(between($users->age, 18, 65))->execute();

// NOT BETWEEN
$db->select()->from($users)->where(not_between($users->salary, 0, 30000))->execute();

// IN
$db->select()->from($users)->where(in_array($users->status, ['active', 'pending']))->execute();

// NOT IN
$db->select()->from($users)->where(not_in_array($users->role, ['banned', 'suspended']))->execute();
```

### NULL Operators

```php
use function Italix\Orm\Operators\{is_null, is_not_null};

// IS NULL
$db->select()->from($users)->where(is_null($users->deleted_at))->execute();

// IS NOT NULL
$db->select()->from($users)->where(is_not_null($users->email_verified_at))->execute();
```

### Ordering

```php
use function Italix\Orm\Operators\{asc, desc, raw};

// ASC
$db->select()->from($users)->order_by(asc($users->name))->execute();

// DESC
$db->select()->from($users)->order_by(desc($users->created_at))->execute();

// Multiple columns
$db->select()->from($users)->order_by(
    desc($users->is_premium),
    asc($users->name)
)->execute();

// Order by expression
$db->select()->from($users)->order_by(desc(raw('total')))->execute();
```

## Aggregate Functions

```php
use function Italix\Orm\Operators\{sql_count, sql_sum, sql_avg, sql_min, sql_max, sql_count_distinct};

// COUNT(*)
$db->select([sql_count()])->from($users)->execute();

// COUNT with column (excludes nulls)
$db->select([sql_count($users->age)])->from($users)->execute();

// COUNT DISTINCT
$db->select([sql_count_distinct($users->country)])->from($users)->execute();

// SUM
$db->select([sql_sum($users->salary)->as('total_salary')])->from($users)->execute();

// AVG
$db->select([sql_avg($users->age)->as('average_age')])->from($users)->execute();

// MIN / MAX
$db->select([
    sql_min($users->age)->as('youngest'),
    sql_max($users->age)->as('oldest')
])->from($users)->execute();
```

## GROUP BY and HAVING

```php
// GROUP BY
$db->select([$orders->product, sql_count()->as('cnt'), sql_sum($orders->amount)->as('total')])
    ->from($orders)
    ->group_by($orders->product)
    ->execute();

// GROUP BY with HAVING
$db->select([$orders->product, sql_sum($orders->amount)->as('total')])
    ->from($orders)
    ->group_by($orders->product)
    ->having(gte(raw('total'), 1000))
    ->execute();
```

## JOINs

```php
// INNER JOIN
$db->select([$users->name, $orders->product])
    ->from($users)
    ->inner_join($orders, eq($users->id, $orders->user_id))
    ->execute();

// LEFT JOIN
$db->select([$users->name, sql_count($orders->id)->as('order_count')])
    ->from($users)
    ->left_join($orders, eq($users->id, $orders->user_id))
    ->group_by($users->id)
    ->execute();

// RIGHT JOIN
$db->select([$users->name, $orders->product])
    ->from($orders)
    ->right_join($users, eq($users->id, $orders->user_id))
    ->execute();

// FULL OUTER JOIN
$db->select([$users->name, $orders->product])
    ->from($users)
    ->full_join($orders, eq($users->id, $orders->user_id))
    ->execute();

// CROSS JOIN
$db->select([$products->name, $colors->name])
    ->from($products)
    ->cross_join($colors)
    ->execute();
```

## Transactions

```php
// Manual transaction
$db->begin_transaction();
try {
    $db->insert($users)->values(['name' => 'Test'])->execute();
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}

// Using callback
$result = $db->transaction(function($db) use ($users) {
    $db->insert($users)->values(['name' => 'Test'])->execute();
    return $db->last_insert_id();
});
```

## Raw Queries

```php
use function Italix\Orm\Operators\raw;

// Execute raw SQL
$db->execute('UPDATE users SET status = ? WHERE id = ?', ['active', 1]);

// Query with results
$results = $db->query('SELECT * FROM users WHERE status = ?', ['active']);

// Single result
$user = $db->query_one('SELECT * FROM users WHERE id = ?', [1]);

// Raw expressions in queries
$db->select([raw('COUNT(*) as total')])->from($users)->execute();
```

## Custom SQL with sql() Builder

The `sql()` method provides a powerful way to write custom SQL while maintaining full protection against SQL injection. Similar to Drizzle's `sql` template tag.

### Basic Usage

```php
// Simple parameterized query
$users = $db->sql('SELECT * FROM users WHERE id = ?', [$userId])->all();

// Multiple parameters
$users = $db->sql(
    'SELECT * FROM users WHERE status = ? AND age > ?',
    ['active', 18]
)->all();

// Get single result
$user = $db->sql('SELECT * FROM users WHERE email = ?', [$email])->one();

// Get scalar value
$count = $db->sql('SELECT COUNT(*) FROM users')->scalar();

// Execute and get affected rows
$affected = $db->sql('UPDATE users SET status = ? WHERE id = ?', ['active', $id])->row_count();
```

### Fluent Builder

```php
// Build SQL piece by piece with safe identifier quoting
$users = $db->sql()
    ->append('SELECT * FROM ')
    ->identifier('users')           // Safely quoted: `users` or "users"
    ->append(' WHERE ')
    ->identifier('status')
    ->append(' = ')
    ->value('active')               // Parameterized: ?
    ->append(' AND ')
    ->identifier('age')
    ->append(' > ')
    ->value(18)
    ->all();

// Using Column and Table objects
$users = $db->sql()
    ->append('SELECT ')
    ->column($users->name)
    ->append(', ')
    ->column($users->email)
    ->append(' FROM ')
    ->table($users)
    ->all();
```

### Helper Methods

```php
// Multiple values at once
$db->sql()
    ->append('INSERT INTO users (name, email, age) VALUES (')
    ->values(['Alice', 'alice@test.com', 25])  // Creates: ?, ?, ?
    ->append(')')
    ->execute();

// IN clause
$users = $db->sql()
    ->append('SELECT * FROM users WHERE status ')
    ->in(['active', 'pending', 'verified'])    // Creates: IN (?, ?, ?)
    ->all();

// Conditional SQL (only adds if condition is true)
$minAge = 18;
$maxAge = null;

$users = $db->sql()
    ->append('SELECT * FROM users WHERE 1=1')
    ->when($minAge !== null, ' AND age >= ?', [$minAge])
    ->when($maxAge !== null, ' AND age <= ?', [$maxAge])
    ->all();
```

### Composing SQL Fragments

```php
use function Italix\Orm\sql;

// Create reusable SQL fragments
$selectPart = sql('SELECT name, email, salary');
$fromPart = sql(' FROM users');
$wherePart = sql(' WHERE salary > ?', [50000]);
$orderPart = sql(' ORDER BY salary DESC');

// Merge fragments together
$results = $db->sql()
    ->merge($selectPart)
    ->merge($fromPart)
    ->merge($wherePart)
    ->merge($orderPart)
    ->all();

// Join multiple parts
$db->sql()
    ->join([
        sql('SELECT * FROM users'),
        sql(' WHERE active = ?', [true]),
        sql(' LIMIT 10')
    ])
    ->all();
```

### Static Factory Methods

```php
use Italix\Orm\Sql;

// Select query
$sql = Sql::select('*')
    ->append(' FROM users WHERE id = ')
    ->value($id);

// Insert query
$sql = Sql::insert_into('users')
    ->append(' (name, email) VALUES (')
    ->values(['Alice', 'alice@test.com'])
    ->append(')');

// Update query
$sql = Sql::update_table('users')
    ->append(' SET status = ')
    ->value('active')
    ->append(' WHERE id = ')
    ->value($id);

// Delete query
$sql = Sql::delete_from('users')
    ->append(' WHERE id = ')
    ->value($id);

// Single parameter
$param = Sql::param($userId);  // Creates: ? with bound value
```

### Inspecting Generated SQL

```php
$query = $db->sql()
    ->append('SELECT * FROM users WHERE status = ')
    ->value('active')
    ->append(' AND age > ')
    ->value(18);

// Get the SQL string
echo $query->get_query();      // SELECT * FROM users WHERE status = ? AND age > ?

// Get the parameters
print_r($query->get_params()); // ['active', 18]

// Convert to string
echo (string)$query;           // SELECT * FROM users WHERE status = ? AND age > ?
```

## Requirements

- PHP 7.4 or higher
- PDO extension
- Database-specific PDO driver (pdo_mysql, pdo_pgsql, pdo_sqlite)

## License

This project is licensed under the Apache License 2.0 - see the [LICENSE](LICENSE) file for details.

## Credits

Inspired by [Drizzle ORM](https://orm.drizzle.team/) for TypeScript.
