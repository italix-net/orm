# Italix ORM

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

<img width="320" height="1024" alt="immagine" src="https://github.com/user-attachments/assets/024ccd7b-cdf2-4870-9300-0a5a3fcc293e" />


A lightweight, type-safe ORM for PHP with support 

for MySQL, PostgreSQL, SQLite, and Supabase.

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
- 🎭 **ActiveRow** - Lightweight active record pattern with array access and custom methods
- 🏛️ **Delegated Types** - Schema.org-style type hierarchies with efficient querying

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
$dm = sqlite(['database' => 'app.db']);

// Define a table schema
$users = sqlite_table('users', [
    'id'    => integer()->primary_key()->auto_increment(),
    'name'  => varchar(100)->not_null(),
    'email' => varchar(255)->not_null()->unique(),
]);

// Create the table
$dm->create_tables($users);

// Insert a record
$dm->insert($users)->values([
    'name'  => 'John Doe',
    'email' => 'john@example.com',
])->execute();

// Query records
$results = $dm->select()
    ->from($users)
    ->where(eq($users->email, 'john@example.com'))
    ->execute();

// Update a record
$dm->update($users)
    ->set(['name' => 'Jane Doe'])
    ->where(eq($users->id, 1))
    ->execute();

// Delete a record
$dm->delete($users)
    ->where(eq($users->id, 1))
    ->execute();
```

## Database Connections

### MySQL

```php
use function Italix\Orm\mysql;

$dm = mysql([
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

$dm = postgres([
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
$dm = sqlite(['database' => '/path/to/database.db']);

// In-memory
$dm = sqlite_memory();
```

### Supabase

```php
use function Italix\Orm\{supabase, supabase_from_credentials};

// From credentials
$dm = supabase_from_credentials(
    'your-project-ref',
    'your-password',
    'postgres',
    'us-east-1',
    true // Use connection pooling
);

// Or with full config
$dm = supabase([
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

$dm = from_connection_string('mysql://user:pass@localhost:3306/myapp');
$dm = from_connection_string('postgres://user:pass@localhost:5432/myapp');
$dm = from_connection_string('sqlite:///path/to/database.db');
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

$dm = mysql([/* config */]);
$migrator = new Migrator($dm, './migrations');

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
$users = $dm->query_table($users)
    ->with(['posts' => true])
    ->find_many();

// find_first() - Get first matching record
$user = $dm->query_table($users)
    ->where(eq($users->id, 1))
    ->with(['posts' => true, 'comments' => true])
    ->find_first();

// find() - Get by primary key
$user = $dm->query_table($users)
    ->with(['posts' => true])
    ->find(1);

// Nested relations
$users = $dm->query_table($users)
    ->with([
        'posts' => [
            'with' => ['comments' => true]  // Load comments for each post
        ]
    ])
    ->find_many();
```

### Filtered and Ordered Relations

```php
$users = $dm->query_table($users)
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
$posts = $dm->query_table($posts)
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
$posts = $dm->query_table($posts)->with(['tags' => true])->find_many();
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
$comments = $dm->query_table($comments)
    ->with(['commentable' => true])
    ->find_many();
```

### Shorthand Query Methods

```php
// Shorthand for common patterns
$users = $dm->find_many($users, [
    'where' => eq($users->is_active, true),
    'with' => ['posts' => true],
    'order_by' => desc($users->id),
    'limit' => 20,
]);

$user = $dm->find_first($users, [
    'where' => eq($users->id, 1),
    'with' => ['profile' => true, 'posts' => true],
]);
```

### Eager Loading vs Lazy Loading

Understanding when to use eager loading vs lazy loading is crucial for application performance.

#### The N+1 Query Problem

Without eager loading, accessing related data in a loop causes the "N+1 problem":

```php
// BAD: N+1 queries (1 query for users + N queries for posts)
$users = $dm->query_table($users)->find_many();  // 1 query
foreach ($users as $user) {
    // Each iteration triggers a separate query!
    $posts = $dm->query_table($posts)
        ->where(eq($posts->author_id, $user['id']))
        ->find_many();  // N queries
}
```

#### Solution: Eager Loading

Eager loading fetches all related data in optimized batch queries:

```php
// GOOD: 2 queries total (1 for users + 1 for all their posts)
$users = $dm->query_table($users)
    ->with(['posts' => true])
    ->find_many();

foreach ($users as $user) {
    // Posts already loaded - no additional queries
    foreach ($user['posts'] as $post) {
        echo $post['title'];
    }
}
```

#### When to Use Eager Loading

| Scenario | Recommendation |
|----------|----------------|
| Displaying lists with related data | **Use eager loading** |
| API responses including nested resources | **Use eager loading** |
| Reports aggregating data across relations | **Use eager loading** |
| Loading data you know you'll need | **Use eager loading** |

```php
// Example: Blog posts list with authors and comment counts
$posts = $dm->query_table($posts)
    ->with([
        'author' => true,
        'comments' => true,
        'tags' => true,
    ])
    ->order_by(desc($posts->created_at))
    ->limit(20)
    ->find_many();
```

#### When to Use Lazy Loading (Manual Queries)

| Scenario | Recommendation |
|----------|----------------|
| Conditionally accessing relations | Consider lazy loading |
| Single record detail views | Either approach works |
| Relations rarely accessed | Consider lazy loading |
| Very large related datasets | Load on demand with pagination |

```php
// Example: Only load comments if user wants to see them
$post = $dm->query_table($posts)->find(1);

if ($showComments) {
    // Load comments only when needed
    $comments = $dm->query_table($comments)
        ->where(eq($comments->post_id, $post['id']))
        ->order_by(desc($comments->created_at))
        ->find_many();
}
```

#### Performance Tips

1. **Don't over-eager**: Only load relations you actually need
   ```php
   // BAD: Loading everything "just in case"
   ->with(['posts' => true, 'comments' => true, 'likes' => true, 'followers' => true])

   // GOOD: Load only what the view needs
   ->with(['posts' => true])
   ```

2. **Use filtered relations** for large datasets:
   ```php
   // Load only recent posts, not entire history
   ->with([
       'posts' => [
           'where' => gte($posts->created_at, '2024-01-01'),
           'limit' => 10,
           'order_by' => [desc($posts->created_at)],
       ]
   ])
   ```

3. **Paginate parent records** when dealing with many items:
   ```php
   // Process users in batches
   $page = 0;
   do {
       $users = $dm->query_table($users)
           ->with(['profile' => true])
           ->limit(100)
           ->offset($page * 100)
           ->find_many();

       // Process batch...
       $page++;
   } while (count($users) === 100);
   ```

## ActiveRow (Lightweight Active Record)

ActiveRow provides a lightweight active record pattern where row objects behave as both arrays and objects with custom methods.

### Key Features

- **Array Access**: Use `$row['field']` syntax for data access
- **Custom Methods**: Add domain logic to row classes
- **Dirty Tracking**: Track changed fields for efficient updates
- **Traits for Composition**: Add behaviors via traits instead of inheritance
- **Wrap/Unwrap**: Easy conversion between arrays and objects

### Basic Usage

```php
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\{Persistable, HasTimestamps};

class UserRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    public function full_name(): string
    {
        return $this['first_name'] . ' ' . $this['last_name'];
    }

    public function is_admin(): bool
    {
        return $this['role'] === 'admin';
    }
}

// Setup persistence (once at bootstrap)
UserRow::set_persistence($dm, $users_table);

// Wrap query results
$users = UserRow::wrap_many($dm->select()->from($users)->execute());

// Or use static finders
$user = UserRow::find(1);
$admins = UserRow::find_all(['where' => eq($users->role, 'admin')]);

// Array access + custom methods
echo $user['email'];           // Array access
echo $user->full_name();       // Custom method
$user['role'] = 'admin';       // Modify
$user->save();                 // Persist

// Convert back to array
$array = $user->to_array();
json_encode($user);            // Works directly
```

### Available Traits

| Trait | Description |
|-------|-------------|
| `Persistable` | Adds `save()`, `delete()`, `refresh()`, static finders |
| `HasTimestamps` | Auto-manages `created_at` and `updated_at` |
| `SoftDeletes` | Adds `soft_delete()`, `restore()`, `is_deleted()` |
| `HasSlug` | Auto-generates URL slugs from a source field |
| `CanBeAuthor` | Shared interface for entities that can be authors |
| `HasDisplayName` | Standard interface for displayable names |

### Wrapping and Unwrapping

```php
// Wrap an array into ActiveRow
$user = UserRow::wrap(['id' => 1, 'name' => 'John']);

// Wrap multiple arrays
$users = UserRow::wrap_many($arrayOfRows);

// Unwrap back to plain array
$array = $user->to_array();   // or ->unwrap() or ->data
```

### Dirty Tracking

```php
$user = UserRow::wrap(['id' => 1, 'name' => 'Original']);

$user['name'] = 'Changed';

$user->is_dirty();              // true
$user->is_dirty('name');        // true
$user->get_dirty();             // ['name' => 'Changed']
$user->get_original('name');    // 'Original'

$user->save();                  // Only updates dirty fields
$user->is_dirty();              // false (now clean)
```

### set() and get() Methods

For a fluent API, use `set()` and `get()`:

```php
// Chained setting
$user = UserRow::make()
    ->set('first_name', 'Andrea')
    ->set('last_name', 'Rossi')
    ->set('email', 'andrea@example.com');

// Get with optional default
$name = $user->get('first_name');           // 'Andrea'
$role = $user->get('role', 'guest');        // 'guest' (default)
```

### Transient Attributes (Dot-Prefixed)

Transient attributes are temporary, in-memory values that are **not persisted** to the database. They're identified by a dot (`.`) prefix.

```php
$user = UserRow::find(1);

// Set transient data (won't be saved to database)
$user['.session_id'] = session_id();
$user['.cached_permissions'] = ['read', 'write'];
$user->set('.request_timestamp', time());

// Access transient data
echo $user['.session_id'];
echo $user->get('.cached_permissions');

// Transient data is excluded from:
// - Database saves (INSERT/UPDATE)
// - Dirty tracking
// - JSON serialization (by default)

$user->save();  // Only saves persistent fields

// Get data subsets
$user->get_persistent_data();   // Only database fields
$user->get_transient_data();    // Only dot-prefixed fields
$user->to_array(false);         // Exclude transient from output
```

Use cases for transient attributes:
- Caching computed values
- Storing request-specific context
- Temporary UI state
- Avoiding repeated expensive calculations

For complete documentation, see the [ActiveRow Guide](docs/ACTIVE_ROW_GUIDE.md).

### Polymorphic Authors Example

```php
// Both Person and Organization can be authors using the CanBeAuthor trait

class PersonRow extends ActiveRow
{
    use CanBeAuthor;

    public function display_name(): string
    {
        return $this['given_name'] . ' ' . $this['family_name'];
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

// In CreativeWorkRow
public function authors(): array
{
    return array_map(function($authorship) {
        $type = $authorship['author_type'];
        return $type === 'person'
            ? PersonRow::wrap($authorship['author'])
            : OrganizationRow::wrap($authorship['author']);
    }, $this['authorships'] ?? []);
}

// Usage
foreach ($work->authors() as $author) {
    echo $author->display_name();     // Works for both Person and Organization
    echo $author->author_type();      // "person" or "organization"
}
```

## Delegated Types (Schema.org-style Hierarchies)

The Delegated Types pattern enables sophisticated type hierarchies where a base class delegates behavior to specialized classes stored in separate tables. Ideal for Schema.org-style hierarchies (Thing → CreativeWork → Book), content management systems, and polymorphic entity modeling.

### How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│                        things table                              │
├─────────────────────────────────────────────────────────────────┤
│ id │ type    │ name          │ is_creative_work │ is_agent     │
├────┼─────────┼───────────────┼──────────────────┼──────────────┤
│ 1  │ Book    │ Design Pat... │ true             │ false        │
│ 2  │ Person  │ Erich Gamma   │ false            │ true         │
└────┴─────────┴───────────────┴──────────────────┴──────────────┘

┌─────────────────────────────────┐   ┌──────────────────────────┐
│        books table              │   │     persons table        │
├─────────────────────────────────┤   ├──────────────────────────┤
│ id │ thing_id │ isbn  │ pages  │   │ id │ thing_id │ given_name│
├────┼──────────┼───────┼────────┤   ├────┼──────────┼───────────┤
│ 1  │ 1        │ 978...│ 416    │   │ 1  │ 2        │ Erich     │
└────┴──────────┴───────┴────────┘   └────┴──────────┴───────────┘
```

### Basic Usage

```php
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\{Persistable, DelegatedTypes};

class Thing extends ActiveRow
{
    use Persistable, DelegatedTypes;

    protected function get_delegated_types(): array
    {
        return [
            'Book'   => Book::class,
            'Movie'  => Movie::class,
            'Person' => Person::class,
        ];
    }
}

// Create entities atomically (thing + delegate in transaction)
$book = Thing::create_with_delegate('Book',
    ['name' => 'Design Patterns'],
    ['isbn' => '978-0201633610', 'number_of_pages' => 416]
);

$author = Thing::create_with_delegate('Person',
    ['name' => 'Erich Gamma'],
    ['given_name' => 'Erich', 'family_name' => 'Gamma']
);

// Type checking
$book->is_book();           // true
$book->is_type('Book');     // true
$book->is_creative_work();  // true (via hierarchy flag)

// Access delegate
$delegate = $book->delegate();
echo $delegate->pages();        // 416
echo $delegate->formatted_isbn(); // 978-0-201-63361-0

// Method delegation (automatic forwarding)
echo $book->pages();            // Works directly - delegates to Book::pages()
```

### Eager Loading

```php
// Load all things with their delegates pre-loaded (prevents N+1 queries)
$things = Thing::find_with_delegates();

foreach ($things as $thing) {
    // Delegates already loaded - no additional queries
    echo $thing->delegate()->specific_method();
}

// Query by type
$books = Thing::find_by_type('Book');
$creative_works = Thing::find_creative_works();
$agents = Thing::find_agents();
```

### Atomic Operations

```php
// Update thing and delegate together
$book->update_with_delegate(
    ['name' => 'Design Patterns (2nd Ed)'],
    ['number_of_pages' => 450]
);

// Delete thing and delegate together
$book->delete_with_delegate();
```

### Dynamic Type Methods

The `DelegatedTypes` trait provides magic methods for type checking and access:

```php
$thing->is_book();    // Dynamic: checks if type === 'Book'
$thing->is_movie();   // Dynamic: checks if type === 'Movie'
$thing->as_book();    // Returns delegate if Book, null otherwise
```

### N-Level Chained Delegation

For deeper hierarchies (Thing → Book → TextBook), use `create_chain()`:

```php
// Create 3-level entity atomically
$textbook = Thing::create_chain([
    'Thing'    => ['name' => 'Calculus'],
    'Book'     => ['isbn' => '978-1285741550', 'pages' => 1344],
    'TextBook' => ['edition' => '8th', 'grade_level' => 'college'],
]);

// Chain traversal
$textbook->get_chain();      // [Thing, Book, TextBook]
$textbook->leaf();           // TextBook instance
$textbook->chain_depth();    // 3

// Methods delegate through entire chain
$textbook->formatted_isbn(); // → Book::formatted_isbn()
$textbook->edition();        // → TextBook::edition()

// Recursive eager loading
$all = Thing::find_with_delegates();  // Loads all levels
```

For complete documentation including Schema.org examples, polymorphic contributions, and best practices, see the [Delegated Types Guide](docs/DELEGATED_TYPES_GUIDE.md).

## Query Builder

### SELECT

```php
// Select all columns
$results = $dm->select()->from($users)->execute();

// Select specific columns
$results = $dm->select([$users->id, $users->name])->from($users)->execute();

// With WHERE, ORDER BY, LIMIT, OFFSET
$results = $dm->select()
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
$dm->insert($users)->values([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
])->execute();

// Multiple records
$dm->insert($users)->values([
    ['name' => 'Bob', 'email' => 'bob@example.com'],
    ['name' => 'Charlie', 'email' => 'charlie@example.com'],
])->execute();

// With RETURNING (PostgreSQL/SQLite)
$inserted = $dm->insert($users)
    ->values(['name' => 'Dave', 'email' => 'dave@example.com'])
    ->returning($users->id)
    ->execute();
```

### INSERT with ON CONFLICT (Upsert)

```php
// ON CONFLICT DO UPDATE (PostgreSQL/SQLite)
$dm->insert($users)
    ->values(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30])
    ->on_conflict_do_update(['email'], [
        'name' => 'Alice Updated',
        'age' => 31
    ])
    ->execute();

// ON CONFLICT DO NOTHING
$dm->insert($users)
    ->values(['name' => 'Alice', 'email' => 'alice@example.com'])
    ->on_conflict_do_nothing(['email'])
    ->execute();

// MySQL uses ON DUPLICATE KEY UPDATE automatically
```

### UPDATE

```php
$dm->update($users)
    ->set(['name' => 'Updated Name', 'is_active' => false])
    ->where(eq($users->id, 1))
    ->execute();
```

### DELETE

```php
$dm->delete($users)
    ->where(eq($users->id, 1))
    ->execute();
```

## Operators

### Comparison Operators

```php
use function Italix\Orm\Operators\{eq, ne, gt, gte, lt, lte};

// Equal (=)
$dm->select()->from($users)->where(eq($users->name, 'Alice'))->execute();

// Not equal (<>)
$dm->select()->from($users)->where(ne($users->status, 'inactive'))->execute();

// Greater than (>)
$dm->select()->from($users)->where(gt($users->age, 18))->execute();

// Greater than or equal (>=)
$dm->select()->from($users)->where(gte($users->salary, 50000))->execute();

// Less than (<)
$dm->select()->from($users)->where(lt($users->age, 65))->execute();

// Less than or equal (<=)
$dm->select()->from($users)->where(lte($users->attempts, 3))->execute();
```

### Logical Operators

```php
use function Italix\Orm\Operators\{and_, or_, not_};

// AND
$dm->select()->from($users)->where(
    and_(
        gte($users->age, 18),
        eq($users->is_active, true)
    )
)->execute();

// OR
$dm->select()->from($users)->where(
    or_(
        eq($users->role, 'admin'),
        eq($users->role, 'moderator')
    )
)->execute();

// NOT
$dm->select()->from($users)->where(
    not_(eq($users->status, 'banned'))
)->execute();

// Complex combinations
$dm->select()->from($users)->where(
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
$dm->select()->from($users)->where(like($users->name, 'A%'))->execute();

// NOT LIKE
$dm->select()->from($users)->where(not_like($users->email, '%@spam.com'))->execute();

// ILIKE (case-insensitive, PostgreSQL native, emulated on others)
$dm->select()->from($users)->where(ilike($users->name, 'alice'))->execute();

// NOT ILIKE
$dm->select()->from($users)->where(not_ilike($users->name, 'bob'))->execute();
```

### Range Operators

```php
use function Italix\Orm\Operators\{between, not_between, in_array, not_in_array};

// BETWEEN
$dm->select()->from($users)->where(between($users->age, 18, 65))->execute();

// NOT BETWEEN
$dm->select()->from($users)->where(not_between($users->salary, 0, 30000))->execute();

// IN
$dm->select()->from($users)->where(in_array($users->status, ['active', 'pending']))->execute();

// NOT IN
$dm->select()->from($users)->where(not_in_array($users->role, ['banned', 'suspended']))->execute();
```

### NULL Operators

```php
use function Italix\Orm\Operators\{is_null, is_not_null};

// IS NULL
$dm->select()->from($users)->where(is_null($users->deleted_at))->execute();

// IS NOT NULL
$dm->select()->from($users)->where(is_not_null($users->email_verified_at))->execute();
```

### Ordering

```php
use function Italix\Orm\Operators\{asc, desc, raw};

// ASC
$dm->select()->from($users)->order_by(asc($users->name))->execute();

// DESC
$dm->select()->from($users)->order_by(desc($users->created_at))->execute();

// Multiple columns
$dm->select()->from($users)->order_by(
    desc($users->is_premium),
    asc($users->name)
)->execute();

// Order by expression
$dm->select()->from($users)->order_by(desc(raw('total')))->execute();
```

## Aggregate Functions

```php
use function Italix\Orm\Operators\{sql_count, sql_sum, sql_avg, sql_min, sql_max, sql_count_distinct};

// COUNT(*)
$dm->select([sql_count()])->from($users)->execute();

// COUNT with column (excludes nulls)
$dm->select([sql_count($users->age)])->from($users)->execute();

// COUNT DISTINCT
$dm->select([sql_count_distinct($users->country)])->from($users)->execute();

// SUM
$dm->select([sql_sum($users->salary)->as('total_salary')])->from($users)->execute();

// AVG
$dm->select([sql_avg($users->age)->as('average_age')])->from($users)->execute();

// MIN / MAX
$dm->select([
    sql_min($users->age)->as('youngest'),
    sql_max($users->age)->as('oldest')
])->from($users)->execute();
```

## GROUP BY and HAVING

```php
// GROUP BY
$dm->select([$orders->product, sql_count()->as('cnt'), sql_sum($orders->amount)->as('total')])
    ->from($orders)
    ->group_by($orders->product)
    ->execute();

// GROUP BY with HAVING
$dm->select([$orders->product, sql_sum($orders->amount)->as('total')])
    ->from($orders)
    ->group_by($orders->product)
    ->having(gte(raw('total'), 1000))
    ->execute();
```

## JOINs

```php
// INNER JOIN
$dm->select([$users->name, $orders->product])
    ->from($users)
    ->inner_join($orders, eq($users->id, $orders->user_id))
    ->execute();

// LEFT JOIN
$dm->select([$users->name, sql_count($orders->id)->as('order_count')])
    ->from($users)
    ->left_join($orders, eq($users->id, $orders->user_id))
    ->group_by($users->id)
    ->execute();

// RIGHT JOIN
$dm->select([$users->name, $orders->product])
    ->from($orders)
    ->right_join($users, eq($users->id, $orders->user_id))
    ->execute();

// FULL OUTER JOIN
$dm->select([$users->name, $orders->product])
    ->from($users)
    ->full_join($orders, eq($users->id, $orders->user_id))
    ->execute();

// CROSS JOIN
$dm->select([$products->name, $colors->name])
    ->from($products)
    ->cross_join($colors)
    ->execute();
```

## Transactions

```php
// Manual transaction
$dm->begin_transaction();
try {
    $dm->insert($users)->values(['name' => 'Test'])->execute();
    $dm->commit();
} catch (Exception $e) {
    $dm->rollback();
    throw $e;
}

// Using callback
$result = $dm->transaction(function($dm) use ($users) {
    $dm->insert($users)->values(['name' => 'Test'])->execute();
    return $dm->last_insert_id();
});
```

## Raw Queries

```php
use function Italix\Orm\Operators\raw;

// Execute raw SQL
$dm->execute('UPDATE users SET status = ? WHERE id = ?', ['active', 1]);

// Query with results
$results = $dm->query('SELECT * FROM users WHERE status = ?', ['active']);

// Single result
$user = $dm->query_one('SELECT * FROM users WHERE id = ?', [1]);

// Raw expressions in queries
$dm->select([raw('COUNT(*) as total')])->from($users)->execute();
```

## Custom SQL with sql() Builder

The `sql()` method provides a powerful way to write custom SQL while maintaining full protection against SQL injection. Similar to Drizzle's `sql` template tag.

### Basic Usage

```php
// Simple parameterized query
$users = $dm->sql('SELECT * FROM users WHERE id = ?', [$userId])->all();

// Multiple parameters
$users = $dm->sql(
    'SELECT * FROM users WHERE status = ? AND age > ?',
    ['active', 18]
)->all();

// Get single result
$user = $dm->sql('SELECT * FROM users WHERE email = ?', [$email])->one();

// Get scalar value
$count = $dm->sql('SELECT COUNT(*) FROM users')->scalar();

// Execute and get affected rows
$affected = $dm->sql('UPDATE users SET status = ? WHERE id = ?', ['active', $id])->row_count();
```

### Fluent Builder

```php
// Build SQL piece by piece with safe identifier quoting
$users = $dm->sql()
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
$users = $dm->sql()
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
$dm->sql()
    ->append('INSERT INTO users (name, email, age) VALUES (')
    ->values(['Alice', 'alice@test.com', 25])  // Creates: ?, ?, ?
    ->append(')')
    ->execute();

// IN clause
$users = $dm->sql()
    ->append('SELECT * FROM users WHERE status ')
    ->in(['active', 'pending', 'verified'])    // Creates: IN (?, ?, ?)
    ->all();

// Conditional SQL (only adds if condition is true)
$minAge = 18;
$maxAge = null;

$users = $dm->sql()
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
$results = $dm->sql()
    ->merge($selectPart)
    ->merge($fromPart)
    ->merge($wherePart)
    ->merge($orderPart)
    ->all();

// Join multiple parts
$dm->sql()
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
$query = $dm->sql()
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
