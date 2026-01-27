# Italix ORM: The Best of Laravel and Drizzle Worlds

## Executive Summary

Italix ORM combines the **best ideas from Laravel** (migrations, Blueprint API) and **Drizzle ORM** (relations, eager loading, type-safe queries) into a cohesive PHP 7.4+ ORM.

| Feature | Inspired By | Status |
|---------|-------------|--------|
| **Migration System** | Laravel | ✅ Implemented |
| **Blueprint API** | Laravel | ✅ Implemented |
| **Schema Introspection (Pull)** | Drizzle | ✅ Implemented |
| **Schema Push** | Drizzle | ✅ Implemented |
| **Schema Diff** | Drizzle | ✅ Implemented |
| **Relations with `define_relations()`** | Drizzle | ✅ Implemented |
| **Eager Loading with `with`** | Drizzle | ✅ Implemented |
| **Polymorphic Relations** | Laravel + Drizzle | ✅ Implemented |
| **Multi-Dialect Support** | Both | ✅ MySQL, PostgreSQL, SQLite, Supabase |

---

## 1. Migration System (Laravel-Inspired)

### Why Laravel's Approach?

Laravel's migration system is battle-tested and provides:
- **Full rollback support** with `up()` and `down()` methods
- **Data migrations** as first-class citizens
- **Team-friendly** file-based migrations
- **Explicit control** over every SQL statement

### Implementation

```php
use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::drop_if_exists('users');
    }
}
```

### CLI Commands

```bash
# Run pending migrations
php ix migrate

# Rollback last batch
php ix migrate:rollback

# Rollback N steps
php ix migrate:rollback --steps=3

# Reset all migrations
php ix migrate:reset

# Refresh (reset + migrate)
php ix migrate:refresh

# Check migration status
php ix migrate:status
```

---

## 2. Drizzle-Inspired Features

### 2.1 Schema Push (Direct to Database)

Like Drizzle's `drizzle-kit push`, Italix supports pushing schema changes directly to the database without migration files - perfect for rapid prototyping.

```bash
# Push schema directly to database
php ix db:push

# Push with confirmation
php ix db:push --force
```

### 2.2 Schema Pull (Database Introspection)

Like Drizzle's `drizzle-kit pull`, Italix can introspect an existing database and generate schema definitions.

```bash
# Pull schema from database
php ix db:pull

# Generate migration from existing database
php ix db:pull --migration
```

### 2.3 Schema Diff (Auto-Suggest Migrations)

Compare your schema definition with the database and auto-generate migration suggestions.

```bash
# Show differences
php ix db:diff

# Generate migration from diff
php ix db:diff --generate
```

---

## 3. Relations System (Drizzle-Inspired)

### Why Drizzle's Approach?

Drizzle's relation system offers:
- **Explicit relation definitions** separate from schema
- **Powerful eager loading** with the `with` clause
- **Nested relations** support
- **Relation aliases** for flexibility

### 3.1 Defining Relations

```php
use function Italix\Orm\Relations\define_relations;

// Define tables
$users = sqlite_table('users', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(100)->not_null(),
    'email' => varchar(255)->not_null(),
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

// Define relations (Drizzle-style)
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

### 3.2 Eager Loading with `with`

```php
// Find users with their posts and comments
$users = $db->query_table($users)
    ->with([
        'posts' => [
            'with' => ['comments' => true]  // Nested relations
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
//             'comments' => [
//                 ['id' => 1, 'content' => 'Great post!'],
//                 ['id' => 2, 'content' => 'Thanks!'],
//             ]
//         ]
//     ]
// ]
```

### 3.3 Filtered and Ordered Relations

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

### 3.4 Relation Aliases

```php
$posts = $db->query_table($posts)
    ->with([
        'writer:author' => true,  // Load 'author' relation as 'writer'
    ])
    ->find_many();

// Access via alias: $post['writer']['name']
```

---

## 4. Many-to-Many Relations

### Through Junction Tables

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

// Query
$posts = $db->query_table($posts)
    ->with(['tags' => true])
    ->find_many();
```

---

## 5. Polymorphic Relations

Italix supports polymorphic relations following patterns from both Laravel and Drizzle.

### 5.1 Polymorphic Belongs-To (one_polymorphic)

When a model can belong to multiple different model types:

```php
// Comments can belong to Posts OR Videos
$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'commentable_type' => varchar(50)->not_null(),  // 'post' or 'video'
    'commentable_id' => integer()->not_null(),
    'content' => text()->not_null(),
]);

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

// Query comments with their parent (regardless of type)
$comments = $db->query_table($comments)
    ->with(['commentable' => true])
    ->find_many();
```

### 5.2 Polymorphic Has-Many (many_polymorphic)

When a model has many of a polymorphic child:

```php
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

// Query posts with their polymorphic comments
$posts = $db->query_table($posts)
    ->with(['comments' => true])
    ->find_many();
```

### 5.3 Schema.org Pattern: Multiple Polymorphic Authors

For complex scenarios like schema.org's CreativeWork (multiple authors, each can be Person or Organization):

```php
// Junction table for polymorphic many-to-many with roles
$creative_work_contributors = sqlite_table('creative_work_contributors', [
    'id' => integer()->primary_key()->auto_increment(),
    'work_id' => integer()->not_null(),
    'contributor_type' => varchar(50)->not_null(),  // 'person' or 'organization'
    'contributor_id' => integer()->not_null(),
    'role' => varchar(50)->not_null(),              // 'author', 'creator', 'editor'
    'position' => integer()->default(0),             // For ordering
]);

// Relations
$works_relations = define_relations($creative_works, function($r) use ($creative_works, $contributors) {
    return [
        'contributor_records' => $r->many($contributors, [
            'fields' => [$creative_works->id],
            'references' => [$contributors->work_id],
        ]),
    ];
});

$contributors_relations = define_relations($contributors, function($r) use ($contributors, $persons, $organizations) {
    return [
        'contributor' => $r->one_polymorphic([
            'type_column' => $contributors->contributor_type,
            'id_column' => $contributors->contributor_id,
            'targets' => [
                'person' => $persons,
                'organization' => $organizations,
            ],
        ]),
    ];
});

// Query with all contributors resolved
$works = $db->query_table($creative_works)
    ->with([
        'contributor_records' => [
            'with' => ['contributor' => true],
            'where' => eq($contributors->role, 'author'),
            'order_by' => [$contributors->position],
        ]
    ])
    ->find_many();
```

---

## 6. Query Methods

### Drizzle-Style Query API

```php
// find_many() - Get multiple records
$users = $db->query_table($users)
    ->where(eq($users->is_active, true))
    ->order_by(desc($users->created_at))
    ->limit(10)
    ->with(['posts' => true])
    ->find_many();

// find_first() / find_one() - Get single record
$user = $db->query_table($users)
    ->where(eq($users->email, 'john@example.com'))
    ->with(['profile' => true])
    ->find_first();

// find() - Get by primary key
$user = $db->query_table($users)
    ->with(['posts' => true])
    ->find(1);
```

### Shorthand Methods

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

---

## 7. Multi-Dialect Support

All features work across all supported databases:

| Database | Identifier Quoting | Placeholders | RETURNING |
|----------|-------------------|--------------|-----------|
| MySQL | `` `table` `` | `?` | ❌ |
| PostgreSQL | `"table"` | `$1, $2...` | ✅ |
| SQLite | `"table"` | `?` | ✅ |
| Supabase | `"table"` | `$1, $2...` | ✅ |

```php
// Same code works on all databases
$db = mysql(['host' => 'localhost', 'database' => 'myapp', ...]);
$db = postgresql(['host' => 'localhost', 'database' => 'myapp', ...]);
$db = sqlite('/path/to/db.sqlite');
$db = supabase(['url' => '...', 'key' => '...']);
```

---

## 8. Comparison Summary

### What We Took from Laravel

| Feature | Description |
|---------|-------------|
| **Migration files** | Timestamped PHP files with `up()` and `down()` |
| **Blueprint API** | Fluent table builder (`$table->string('name')`) |
| **Rollback support** | Full up/down migration support |
| **Data migrations** | SQL and data transformations in same file |
| **Batch tracking** | Track migration batches for rollback |

### What We Took from Drizzle

| Feature | Description |
|---------|-------------|
| **`define_relations()`** | Explicit relation definitions separate from schema |
| **`with` clause** | Declarative eager loading |
| **Nested relations** | Load relations of relations |
| **Relation aliases** | Rename relations in queries |
| **`find_many()` / `find_first()`** | Drizzle-style query methods |
| **Push/Pull/Diff** | Schema sync workflows |

### What We Added

| Feature | Description |
|---------|-------------|
| **Polymorphic relations** | `one_polymorphic()`, `many_polymorphic()` |
| **Junction table relations** | Many-to-many with `through` |
| **Multi-dialect SQL** | Single codebase, multiple databases |
| **Filtered relations** | `where`, `order_by`, `limit` on relations |

---

## 9. Conclusion

Italix ORM successfully combines:

1. **Laravel's robust migration system** for production-safe schema management
2. **Drizzle's elegant relation system** for type-safe, declarative data access
3. **Polymorphic relations** from both worlds for complex domain models
4. **Multi-dialect support** for flexibility across database platforms

This combination provides the **developer experience of Drizzle** with the **production safety of Laravel**, all in a lightweight PHP 7.4+ package.

```
┌─────────────────────────────────────────────────────────────────┐
│                      ITALIX ORM                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   ┌─────────────────┐         ┌─────────────────┐              │
│   │    LARAVEL      │         │    DRIZZLE      │              │
│   │                 │         │                 │              │
│   │  • Migrations   │         │  • Relations    │              │
│   │  • Blueprint    │         │  • Eager Load   │              │
│   │  • Rollbacks    │    +    │  • with clause  │              │
│   │  • Data Migrate │         │  • Push/Pull    │              │
│   │  • Batch Track  │         │  • find_many()  │              │
│   └────────┬────────┘         └────────┬────────┘              │
│            │                           │                        │
│            └───────────┬───────────────┘                        │
│                        ▼                                        │
│            ┌───────────────────────┐                           │
│            │   ITALIX ORM          │                           │
│            │                       │                           │
│            │  Best of Both Worlds  │                           │
│            │  + Polymorphic Rels   │                           │
│            │  + Multi-Dialect      │                           │
│            └───────────────────────┘                           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```
