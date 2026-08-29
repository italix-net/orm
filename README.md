# Italix ORM

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

<img width="320" height="1024" alt="immagine" src="https://github.com/user-attachments/assets/024ccd7b-cdf2-4870-9300-0a5a3fcc293e" />


A lightweight, type-safe ORM for PHP with support 

for MySQL, PostgreSQL, SQLite, and Supabase.

## Features

- 🚀 **Lightweight** - Minimal dependencies, fast and efficient
- 🔒 **Type-safe** - Full PHP 8.1+ type declarations
- 🗃️ **Multi-database** - MySQL, PostgreSQL, SQLite, Supabase
- 🔧 **Query Builder** - Fluent, chainable API
- 🪆 **Subqueries** - in `WHERE`, as a scalar, as a derived table, plus `EXISTS` / `NOT EXISTS`
- 🧱 **CTEs** - `WITH`, including `WITH RECURSIVE` for trees in one statement
- ➕ **Set operations** - `UNION`, `UNION ALL`, `INTERSECT`, `EXCEPT`
- 🪟 **Window functions** - `ROW_NUMBER`, `RANK`, `LAG`/`LEAD`, any aggregate `OVER (…)`, with frames
- 🌊 **Large results** - `cursor()`, `each()`, and keyset paging with `chunk_by()`
- 🧩 **Bridges** - validation rules derived from the schema, and a query cache that lets go on writes
- 📚 **Bulk writes** - `insert_many()`, chunked and transactional
- 🪞 **Read replicas** - reads routed to a replica, and back to the primary the moment correctness needs it
- 🔎 **`db:pull`** - the live schema as PHP, round-tripped on three dialects
- 🧭 **`db:diff`** - what drifted, and a migration that would close it
- 🔗 **Foreign keys** - declared on the column, emitted in the DDL, read back by `db:pull`
- 🧾 **JSON** - one path syntax, rendered for SQLite, MySQL and PostgreSQL
- ⏱️ **Profiling** - a query log that counts before it flags, and `explain()` on any query
- 📦 **Schema Definition** - Define tables in PHP code
- 🔄 **Transactions** - Nested via savepoints, so a helper can open one without asking its caller
- 👓 **Views** - Declared in PHP, read like a table, refused as a write target — materialized ones on PostgreSQL
- 🎯 **PSR-4** - Composer autoloading
- 📋 **Migrations** - Laravel-style migrations with full rollback support
- ⚡ **CLI Tool (`ix`)** - Powerful command-line interface for migrations
- 🔗 **Relations** - Drizzle-style relations with eager loading and polymorphic support
- 🎭 **ActiveRow** - Lightweight active record pattern with array access and custom methods
- 🏛️ **Delegated Types** - Schema.org-style type hierarchies with efficient querying
- 🔑 **Composite primary keys** - declared on the schema, matched by `find()`, and now by `ActiveRow` too
- 🎨 **Attribute casting** - `cast_as('array'|'datetime'|'bool'|'int'|'float')`, plus native `BackedEnum` hydration
- 🪝 **Lifecycle hooks** - `before_insert`/`after_insert`/…/`after_delete`, and transaction-scoped `on_commit()`/`on_rollback()`
- 🎯 **Global scopes** - a named condition ANDed onto every read, until a caller opts out
- 🔐 **Optimistic locking** - a version column bumped on every `UPDATE`, with a guard that raises on a lost race
- 🔎 **Full-text search** - `MATCH()`/`AGAINST()` on MySQL, `tsvector`/`GIN` on PostgreSQL, a real FTS5 virtual table on SQLite
- 🏭 **Factories & seeders** - `definition()`/`state()`/`count()` fake-data recipes, and `ix db:seed`

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
    $table->fulltext('content');                 // Full-text index — see "Full-text search" below
    
    // Foreign Keys
    $table->foreign('user_id')
        ->references('id')
        ->on('users')
        ->on_delete('CASCADE');
    
    // Shorthand for foreign key (auto-detects: user_id -> users.id)
    $table->foreign('user_id')->constrained();
});
```

#### `CHECK` constraints

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->integer('total_cents')->unsigned()->check('total_cents >= 0');   // column-level
    $table->timestamp('placed_dt');
    $table->timestamp('shipped_dt');
    $table->check('placed_dt < shipped_dt', 'valid_shipping');               // table-level, named
});
```

Column-level `check()` can be called more than once — each call adds a clause rather than replacing
the one before it, so `->check('n >= 0')->check('n <= 100')` is two rules, not the second one alone.
Table-level `check()` is for a rule spanning more than one column, where no single column's own
`check()` could say it.

Rendered inline in `CREATE TABLE` on all three dialects. **Refused on `ALTER TABLE` for SQLite**
specifically — not a version gap the way `DROP COLUMN` is (SQLite 3.35 added that), but a categorical
absence: SQLite has no `ALTER TABLE … ADD CONSTRAINT` at all, so adding a check to an existing table
needs a rebuild (`CREATE` the new shape, copy the rows, drop the old table, rename) rather than SQL
this package can emit for you. MySQL below 8.0.16 **parses but silently ignores** `CHECK` — a fact
about the server this package cannot detect while a migration file is merely being rendered to text,
so it is written down here instead of guarded against.

The expression is schema text, not a bound value — trusted at the same level as the table name beside
it, the same way a view's `->as_query()` definition already is. Runtime values still belong in a
query's `WHERE`, bound as usual.

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

### Foreign keys

Two ways to say it, and they end up in the same `CREATE TABLE`:

```php
// On the column, which is where it reads best
'team_id'   => integer()->unsigned()->not_null()->references('teams', 'id'),
'parent_id' => integer()->unsigned()->references('categories', 'id'),    // NULL is a root

// On the table, when the constraint needs a name or a rule other than the default
$orders->add_foreign_key('fk_orders_team', 'team_id', 'teams', 'id', 'RESTRICT', 'RESTRICT');
```

The column form emits an unnamed `FOREIGN KEY` clause and the table form a named `CONSTRAINT`.
`db:pull` reads either back as `->references(…)`, so a schema pulled from a database carries its
relationships and not just its columns.

> `references()` used to be **stored and never emitted**: the reference resolved relations and was
> dropped from the DDL, so the constraint a developer wrote did not exist in the database and nothing
> said so. Found by the round trip in `IntrospectionTest`, not by reading the code.

**What the rule should be** is a decision this package does not make for you, and the default —
`CASCADE` on `add_foreign_key()` — is the one to think about hardest:

| | |
|---|---|
| `RESTRICT` | the parent cannot be deleted while children exist. The right answer for a tenant key: `CASCADE` there erases a customer's data because somebody removed their account row. |
| `CASCADE` | children go with the parent. Right when the child *exists because of* the parent — the lines of an order, the subtree of a category. |
| `SET NULL` | the child stays and forgets. Right for authorship: deleting an account should not delete what it wrote. Needs a nullable column. |

**Adding one to a live database finds out whether it was ever true.** Every candidate should be
checked for orphans first — `SELECT … LEFT JOIN parent … WHERE parent.id IS NULL` — because a
constraint that has never been enforced usually has exceptions, and they are the interesting part.

### What `db:diff` does not compare

Foreign keys. `diff()` reports columns, types, lengths, nullability, uniqueness and indexes; the
`add_foreign_keys` and `drop_foreign_keys` keys in its result are **always empty**. A constraint
added or dropped by hand is invisible to it.

Said here because a tool that is silent is indistinguishable from a tool that found nothing, and
knowing which is which is the whole value of the tool.

### `unsigned()`

```php
'id'        => serial()->unsigned(),
'agency_id' => integer()->unsigned()->not_null(),
```

Refuses negative values and doubles the positive range — the ordinary way to say "this is an
identifier, not a quantity that can go below zero".

**It is not portable, and that is the whole thing to know.** MySQL and SQLite take the word;
**PostgreSQL has no unsigned integer type at all**, so there it is dropped rather than approximated.
A `CHECK (col >= 0)` would enforce the same rule, and this package does not add one on your behalf: a
constraint nobody wrote is a constraint nobody expects to find — and one `db:pull` would then report
as drift forever.

For the same reason `db:diff` compares it on MySQL and SQLite and **not** on PostgreSQL, where the
difference is real, permanent, and closeable by no migration.

On SQLite an auto-increment primary key stays plain `INTEGER`: only that exact spelling is a rowid
alias, and `INTEGER UNSIGNED PRIMARY KEY AUTOINCREMENT` is not.

### `check()` and `enum()`

The same two things as the migration `Blueprint::check()`/`enum()` above, on `Column`/`Table` —
because a model queried the Data Mapper way (`$dm->query_table($table)`) needs to know its own
constraints too, not only the migration that first created them:

```php
use function Italix\Orm\Schema\{integer, enum, mysql_table};

$orders = mysql_table('orders', [
    'id'          => integer()->primary_key()->auto_increment(),
    'total_cents' => integer()->unsigned()->not_null()->check('total_cents >= 0'),
    'status'      => enum(['draft', 'placed', 'shipped', 'cancelled'])->not_null(),
]);

// A rule spanning more than one column — no single column's own check() could say this:
$orders->add_check('valid_shipping', 'placed_dt < shipped_dt');
```

`enum()` is native `ENUM(...)` on MySQL. PostgreSQL and SQLite have no equivalent column type, so
there it becomes `VARCHAR(255)` plus a `CHECK (col IN (...))` carrying the same values — built on
`check()` rather than a second mechanism, and enforced identically to a hand-written one.

**`enum()` also accepts a native PHP `BackedEnum` class name in place of the array**, reading the
allowed values from `::cases()` once instead of typing them a second time where the DDL is declared:

```php
enum OrderStatus: string
{
    case Draft   = 'draft';
    case Placed  = 'placed';
    case Shipped = 'shipped';
}

$orders = mysql_table('orders', [
    'status' => enum(OrderStatus::class)->not_null(),
]);
```

A column declared this way also **hydrates on read**: `$dm->select()->from($orders)->execute()` gives
back `OrderStatus` instances, not raw strings, and `$dm->insert($orders)->values(['status' =>
OrderStatus::Draft])->execute()` accepts the enum case directly on the way in. A plain `enum([...])`
is unaffected and still returns bare strings — there is no PHP type to hydrate into. A value already
outside the declared cases throws on read (`BackedEnum::from()`'s own behaviour) rather than silently
handing back the raw string; `enum()` given anything other than a backed enum raises
`\InvalidArgumentException` immediately.

### Attribute casting

`Column::cast_as()` turns a raw driver value into a PHP one on the way out, and back on the way in —
Eloquent's `$casts`, Rails' Attributes API. Without it, a JSON column is the text PDO handed back, a
boolean is whatever `0`/`1`/`"0"`/`"1"` the driver used, and every reader writes its own
`json_decode()`/`new DateTime()`/`(bool)` at the call site, with the inverse conversion written a
second time, separately, on the way back in — the two directions drifting out of sync is exactly the
bug class this closes.

```php
$articles = mysql_table('articles', [
    'metadata'   => text()->cast_as('array'),        // JSON string <-> PHP array
    'expires_dt' => varchar(30)->cast_as('datetime'), // string <-> DateTimeImmutable
    'is_active'  => integer()->cast_as('bool'),       // 0/1 <-> real bool
    'views_n'    => text()->cast_as('int'),           // stored as text, read back as int
    'rating'     => text()->cast_as('float'),
]);

$dm->insert($articles)->values(['metadata' => ['tags' => ['php', 'orm']]])->execute();
$row = $dm->select()->from($articles)->execute()[0];
$row['metadata']; // ['tags' => ['php', 'orm']] — a real PHP array, not a JSON string
```

Applies through both query engines — `$dm->select()->from(...)` and `$dm->query_table(...)` alike —
and to `ActiveRow` too, since both compile through the same read path. A `raw()`/`SQLExpression` value
is passed through untouched in either direction: encoding it would corrupt the fragment.
`enum(SomeBackedEnum::class)` above uses this same machinery — it does not need `cast_as()` of its
own, casting automatically.

> **Two `timestamps()`, two `soft_deletes()`, two `check()`s, two `enum()`s.** `Migration\Blueprint`
> (above) and `Schema\Table`/`Column` (here) are different classes with some identically-named
> methods that do related but different jobs: `Blueprint`'s create the columns a migration writes into
> the database; `Table`/`Column`'s (this section, and "Automatic timestamps and soft deletes" below)
> describe those same columns to the ORM afterwards, so it knows to keep them filled in, enforce them,
> or filter by them. Ordinarily you use both — one to create the column, the other to describe it —
> which is why the names match rather than clash.

### `Table::timestamps()` and `Table::soft_deletes()`

The Data Mapper equivalent of `ActiveRow`'s `HasTimestamps`/`SoftDeletes` traits — declared once on
the schema instead of requiring `ActiveRow` at all:

```php
$orders->timestamps('insert_dt', 'update_dt');   // any column names, not tied to created_at/updated_at
$orders->soft_deletes('deleted_dt');
```

Once declared, `$dm->insert($orders)->values([...])->execute()` fills both timestamp columns with the
same instant, `$dm->update($orders)->set([...])->execute()` refills the update column, and
`$dm->delete($orders)->where(...)->execute()` compiles to an `UPDATE … SET deleted_dt = ?` instead of
a real `DELETE` — **unless the caller already set that column**, checked by key, which always wins.
A genuine delete is still available: `$dm->delete($orders)->force()->where(...)->execute()`.

Reached by `ActiveRow` too, since `Persistable::save()`/`delete()` compile through the same
`insert()`/`update()`/`delete()`. `HasTimestamps` needed no change for this — it already sets both
columns in PHP before `save()` reaches the query builder, so the automatic fill finds them already
present and does nothing further.

### `Table::optimistic_locking()`

A version counter — Rails' `lock_version`, Doctrine's `@Version`, EF Core's concurrency tokens — that
detects a row changed between when a caller read it and when it tries to write it back, instead of
the second write silently overwriting the first:

```php
$accounts->optimistic_locking('version');   // the column itself is declared in the schema, like any other

$dm->insert($accounts)->values(['balance' => 100])->execute();      // version starts at 1

$dm->update($accounts)->set(['balance' => 90])
    ->where(eq($accounts->id, $id))
    ->expect_version(1)                                              // "only if it is still 1"
    ->execute();                                                      // succeeds; version is now 2

// A second writer, still holding the version it read before the first writer committed:
$dm->update($accounts)->set(['balance' => 80])
    ->where(eq($accounts->id, $id))
    ->expect_version(1)
    ->execute();                                                      // throws Locking\OptimisticLockException
```

`SET version = version + 1` compiles into **every** `UPDATE` once declared — unconditionally, the one
place this package does *not* trust a value the caller put under that key in `->set([...])`, unlike
`timestamps()` above. `->expect_version($n)` additionally ANDs `version = ?` onto the `WHERE`;
`execute()` raises when that leaves zero rows affected. `INSERT` defaults the column to `1` unless the
caller already gave it one. `DELETE` is not version-checked — a version counter guards a row's
*content*, and `soft_deletes()` above already covers "keep the row reachable" for a delete.

`ActiveRow`'s `Persistable::save()` calls `expect_version()` automatically whenever
`has_optimistic_locking()` is declared, using the version the instance last read, and keeps its own
in-memory copy in sync with the real increment afterward.

### Composite primary keys

```php
$order_items = mysql_table('order_items', [
    'tenant_id' => integer()->not_null(),
    'order_id'  => integer()->not_null(),
    'sku'       => varchar(30)->not_null(),
])->primary_key(['tenant_id', 'order_id']);
```

A single-column key needs none of this — `id => serial()`, or any column's own `->primary_key()`,
already works. `Table::primary_key([...])` is for the case one column cannot express: it marks every
named column `not_null()` and renders one table-level `PRIMARY KEY (…)` clause, in the order given —
mirrored by `Migration\Blueprint::primary([...])` on the migration side, and read back correctly by
`db:pull`.

`TableQuery::find($id)` (and therefore `Persistable::find()`) takes the composite key as an array
keyed by column name — `$dm->query_table($order_items)->find(['tenant_id' => 1, 'order_id' => 100])`
— and refuses a bare scalar rather than silently matching only the first column.

`ActiveRow` supports a composite key too: declare `static::$primary_key` as an array instead of a
string, and `save()`/`delete()`/`refresh()`/`get_key()` all follow it —

```php
class OrderItemRow extends ActiveRow
{
    use Persistable;

    protected static $primary_key = ['tenant_id', 'order_id'];
}

$item = OrderItemRow::create(['tenant_id' => 1, 'order_id' => 100, 'sku' => 'WIDGET']);
$item['sku'] = 'GADGET';
$item->save();                          // UPDATE … WHERE tenant_id = 1 AND order_id = 100, both ANDed
$item->get_key();                       // ['tenant_id' => 1, 'order_id' => 100]
```

`get_key_name(): string` is unchanged for the ordinary single-column case, and raises clearly on a
composite one — there is no single name to return — pointing at the new `get_key_names(): array`,
which works either way (`['id']` for a single-column key, every column for a composite one).

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
    blob, binary, varbinary,
    // Enum — see "check() and enum()" above
    enum
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

### Fluent, typed queries: `query()`

`find_all(['where' => …, 'with' => …])` above takes every condition as one options array assembled
up front. `query()` is the same underlying query, exposed as a chain instead — where `TableQuery`
(`$dm->query_table($table)`) is already fluent but returns plain arrays, `query()` ends in `ActiveRow`
instances:

```php
UserRow::query()
    ->where(eq($users->role, 'admin'))
    ->order_by(desc($users->id))
    ->with(['posts' => true])
    ->limit(10)
    ->find_many();   // array<UserRow>, not array<array>
```

`find_all()`/`find()`/`find_one()` are unaffected by this — `query()` is a second way to reach the
same `TableQuery`, not a replacement for the first.

**Every finishing call has more than one name**, on purpose — this package's two layers borrowed
their vocabulary from two different query builders (`TableQuery` speaks Drizzle's
`findMany`/`findFirst`; `Persistable`'s own finders speak Eloquent's `all()`/`find_one()`) and rather
than pick a winner, both are offered:

| Every matching row | The first matching row |
|---|---|
| `find_many()` | `find_first()` |
| `find_all()` | `find_one()` |
| `all()` | `first()` |
| | `one()` |

All names in a row call the same code. `find($id)` looks up by primary key and wraps the result, or
returns `null`.

Two behaviours worth knowing, both inherited unchanged from `TableQuery` since `query()` only
forwards to it rather than reimplementing anything: **order among different methods in the chain
never matters** (`->where(a)->limit(1)` and `->limit(1)->where(a)` compile identically — each call
sets its own independent fact), **but repeating the same method is not the same story for every
one.** `where()` called twice replaces the first condition rather than combining it — the second call
simply wins, which is *not* an error and produces valid SQL that answers a different question than
intended. Combine more than one condition with `and_()`/`or_()`/`not_()` (see
["Operators"](#operators) below) and pass the single result to one `where()` call:

```php
->where(and_(eq($users->active, true), or_(eq($users->role, 'admin'), gt($users->score, 100))))
```

`order_by()`, by contrast, *does* accumulate across repeated calls — `->order_by(asc($role))->order_by(desc($name))`
sorts by both, role first.

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

## Lifecycle hooks

`DataManager::on($table, $event, $hook)` — the Data Mapper style's own version of what an `ActiveRow`
subclass overriding a method already had. Six events: `before_insert` (per row), `before_update`
(once, against the whole `SET` clause), `before_delete` (side effects only — a `DELETE` has no values
to hand a hook), and `after_insert`/`after_update`/`after_delete`:

```php
$dm->on($orders, 'before_insert', function (array $row): array {
    $row['reference'] = strtoupper(bin2hex(random_bytes(4)));
    return $row;                              // returning an array replaces the row
});
$dm->on($orders, 'after_insert', function (array $rows, ?int $id): void {
    Log::info("order {$id} created");          // void return — side effects only
});
```

`before_insert`/`before_update` may return a replacement array to change what gets written; anything
else, including no return at all, leaves it as the previous hook — or the caller — set it. Multiple
hooks on the same table/event run in registration order, each seeing the previous one's mutation.
`after_*` fires by which method the caller called, not by which SQL actually ran — a `soft_deletes()`
row (a `DELETE` that compiles to an `UPDATE`) still fires `after_delete`. Since `Persistable::save()`/
`delete()` compile to exactly these same `QueryBuilder` calls, `ActiveRow` reaches these hooks too,
without needing to know they exist. `Table::timestamps()`/`optimistic_locking()`'s own defaults are
applied *after* hooks run — a hook that returns a full replacement row can never accidentally drop
them.

Kept on `DataManager`, not a static registry, so two managers on the same schema never share each
other's hooks — matched by `Table` identity, the same rule relation and delegated-type lookups
already use.

**Transaction-scoped hooks are a separate pair** — see ["Transactions"](#transactions) below for
`on_commit()`/`on_rollback()`, which wait for the surrounding transaction's actual fate rather than
firing the instant a statement runs.

## Global scopes

A named condition ANDed onto every read against a table, until a caller opts out — Eloquent's global
scopes, EF Core's global query filters, Rails' `default_scope`. `Table::soft_deletes()` above is
exactly one hard-coded instance of this same idea; `add_global_scope()` generalises it past the one
case this package used to special-case:

```php
$dm->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, $current_tenant_id));

$dm->select()->from($orders)->execute();                       // only this tenant's rows
$dm->query_table($orders)->find_many();                        // same — both query engines
$dm->select()->from($orders)->without_scopes()->execute();     // every tenant's — the escape hatch
$dm->select()->from($orders)->without_scopes(['tenant'])->execute(); // skip just this one, by name
```

Extends the same `effective_where()` both query engines already use for `soft_deletes()`'s own filter,
ANDing in every active scope's condition alongside it. `without_scopes()` is clone-based like
`with_trashed()`: no arguments disables every scope, an array of names disables only those, and
repeated calls naming different scopes accumulate. Registering the same scope name again for a table
replaces it rather than adding a second one. `TypedQuery::without_scopes()` forwards to it, and
`Persistable::find_all()` takes a matching `'without_scopes'` option key alongside its existing
`'with_trashed'`.

Like `soft_deletes()`'s own filter, this narrows reads only — `UPDATE`/`DELETE` do not go through
`effective_where()`, so a scoped-out row is still reachable to correct or purge by primary key.

## Operators

**Either side of a condition can be an expression.** The operators take a `Column` — the ordinary
case — or an aggregate, a raw fragment, or a scalar subquery, which is what makes `HAVING` on an
aggregate expressible:

```php
->having(gt(sql_sum($orders->total), 1000))          // HAVING SUM(total) > 1000
->where(eq(raw('LOWER(email)'), $typed_email))       // WHERE LOWER(email) = ?
->where(gt(sub($total_spend), 10000))                // WHERE (SELECT …) > ?
->where(in_(raw('LOWER(status)'), ['active', 'trial']))
```

A subquery is parenthesised for you, since that is the only form SQL accepts there. A `raw()`
fragment is emitted exactly as written — parenthesise it yourself if precedence matters — and its
own bindings are appended in the position it occupies, before the value on the right.


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

## Window functions

`GROUP BY` collapses the rows you often still want to see. A window function computes across a set of
rows and keeps every one of them:

```php
use function Italix\Orm\Operators\{desc, lag, row_number, sql_sum};

$dm->select([
    $orders->id,
    row_number()->partition_by($orders->customer_id)->order_by(desc($orders->placed_dt))->as('n'),
    sql_sum($orders->total)->over()->partition_by($orders->customer_id)->as('customer_total'),
    lag($orders->total, 1, 0)->partition_by($orders->customer_id)->order_by($orders->placed_dt)->as('previous'),
])->from($orders)->execute();
```

| | |
|---|---|
| ranking | `row_number()` `rank()` `dense_rank()` `percent_rank()` `cume_dist()` `ntile($n)` |
| neighbours | `lag($col, $offset, $default)` `lead(…)` `first_value($col)` `last_value($col)` `nth_value($col, $n)` |
| any aggregate | `sql_sum($col)->over()`, `sql_count()->over()`, … |
| anything else | `window_call('MEDIAN', $col)` — the name must be an identifier, not a fragment |

Each takes `->partition_by(…)`, `->order_by(…)`, a frame, and `->as('name')`. The window's `ORDER BY`
is what decides "previous", "first" and "running" — it is not the statement's own `ORDER BY`, and
setting one does not set the other.

**Frames** are `->rows_between($start, $end)` and `->range_between($start, $end)`, where each bound is
`'unbounded preceding'`, `'unbounded following'`, `'current row'`, `'N preceding'` or `'N following'`.
Anything else is refused rather than interpolated — a frame is the one place in a window where free
text would let arbitrary SQL through, and there are only five shapes worth having.

> `LAST_VALUE` without a frame is the **current row**, not the last of the partition, because the
> default frame ends at the current row. That surprises people in every SQL dialect; pair it with
> `rows_between('unbounded preceding', 'unbounded following')` when you meant the partition.

### Top N per group

A window function cannot go in `WHERE`: `WHERE` is evaluated before the window is, in every engine.
So the shape is rank inside, filter outside — and this is worth knowing rather than hiding, because
it is the reason the query looks the way it does:

```php
$ranked = sub(
    $dm->select([$orders->id, $orders->customer_id, $orders->total,
        row_number()->partition_by($orders->customer_id)->order_by(desc($orders->total))->as('n'),
    ])->from($orders),
    'ranked'
);

$dm->select()->from($ranked)->where(lte(raw('n'), 3))->execute();   // three per customer
```

**Server versions.** Window functions need SQLite 3.25, MySQL 8.0, MariaDB 10.2, or any PostgreSQL —
a floor this package does not otherwise impose, which is why nothing emits one on your behalf (eager
loading still caps children in PHP). Reach for them when you know your server.

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
    ->having(gt(sql_sum($orders->amount), 1000))
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

## Subqueries

`sub()` wraps a SELECT so it can be used inside another statement. It is an ordinary SQL expression,
so it composes with the operators that already exist rather than needing its own.

```php
use function Italix\Orm\Operators\{sub, in_, not_in_, eq, exists, not_exists, gt};

$big_spenders = sub(
    $dm->select([$orders->customer_id])->from($orders)->where(gt($orders->total, 100))
);

// IN (SELECT …)
$dm->select()->from($customers)->where(in_($customers->id, $big_spenders))->execute();

// a scalar subquery in a comparison
$dm->select()->from($customers)->where(eq($customers->id, $biggest))->execute();

// a derived table — the alias is required
$dm->select()->from(sub($grouped)->alias('totals'))->execute();
```

The value inside is **bound, not pasted**: that is the whole point. Before this the only way to write
`WHERE id IN (SELECT …)` was `raw()`, which takes a string and interpolates nothing, so every value
had to be put into the SQL by hand.

### `exists()` is not a second spelling of `in_()`

```php
$dm->select()->from($customers)->where(exists(
    sub($dm->select([$orders->id])->from($orders)->where(eq($orders->customer_id, $customers->id)))
))->execute();
```

`EXISTS` stops at the first matching row, and it correlates: the inner query mentions a column of the
outer table and nothing has to be told that it did.

The difference that matters is NULL. `NOT IN` against a subquery whose column can be NULL is true for
**no row at all** — correct three-valued logic, and almost never the question anybody was asking.
`not_exists()` answers it. Reach for it whenever the subquery's column is nullable.

---

## DISTINCT

```php
$dm->select([$orders->customer_id])->from($orders)->distinct()->execute();
```

Distinct is over **everything selected**. Adding a column to the list changes which rows count as
duplicates — which is what SQL means and not always what people expect.

```php
// PostgreSQL only: the first row of each group, as decided by ORDER BY
$dm->select()->from($orders)
   ->distinct_on([$orders->customer_id])
   ->order_by($orders->customer_id, desc($orders->placed_dt))
   ->execute();
```

`distinct_on()` is refused on MySQL and SQLite rather than approximated. They have no equivalent; the
rewrite is a window function or a correlated subquery, and which one is right depends on the query.
Emitting plain `DISTINCT` instead would return a different number of rows on a different server.

---

## Common Table Expressions

```php
$dm->select()->from($customers)
   ->with_cte('big', sub($dm->select([$orders->customer_id])->from($orders)
        ->where(gt($orders->total, 800))))
   ->where(in_($customers->id, $big))
   ->execute();
```

Several may be declared; they are emitted in the order given, which is also the order in which one
may refer to another.

The point is not brevity. A query written as three CTEs reads top to bottom in the order the work
happens; the same query as nested subqueries reads inside out, and the version somebody has to change
six months later is the one they can follow.

### Recursive

The form that makes a tree one statement instead of a loop:

```php
$anchor = $dm->select([$nodes->id, $nodes->parent_id, $nodes->name])->from($nodes)
              ->where(eq($nodes->id, $root_id));

$step   = $dm->select([$nodes->id, $nodes->parent_id, $nodes->name])->from($nodes)
              ->inner_join($tree, eq($nodes->parent_id, $tree->id));

$dm->select([$tree->name])->from($tree)
   ->with_recursive('tree', sub($anchor->union_all($step)))
   ->execute();
```

The body you pass is the whole recursion — anchor, `UNION ALL`, step — because the shape of it is the
part only you know. `RECURSIVE` is a property of the whole `WITH` clause, which is why declaring any
recursive term marks it.

---

## Set Operations

```php
$roman->union($neapolitan)->order_by($customers->name)->limit(10)->execute();
$all->intersect($roman)->execute();
$all->except($roman)->execute();
$roman->union_all($everyone)->execute();     // duplicates kept, nothing sorted
```

**`ORDER BY`, `LIMIT` and `OFFSET` belong to the compound, not to a branch**, and putting them on a
branch is refused rather than emitted. In standard SQL they follow the last branch and apply to the
whole thing; a dialect that accepts them inside one either parenthesises (MySQL, PostgreSQL) or
rejects the statement (SQLite). Emitting them and hoping is how a query returns different rows on a
laptop and on the server.

Put them on the query you call `union()` on and they apply to the compound, which is what they mean.

> `INTERSECT` and `EXCEPT` need MySQL 8.0.31 or later. PostgreSQL and SQLite have had them for years.

---

## Worked examples

Ten ordinary questions, the code that asks them, and the SQL that reaches the database. Every one of
these was executed against a real database rather than transcribed, so the statements below are what
the builder actually emits.

The schema is a shop: `customers`, `orders`, `order_items`, `products`, `categories`.

```bash
php examples/worked_examples.php     # builds and runs all ten, printing the SQL
```

They are a runnable file rather than a listing on purpose. An example nobody runs is documentation
that ages in silence — this package had two of those, and running them is what found the two defects
fixed in 2.5.1.

### 1. Customers who have never ordered

```php
$sel($customers, [$customers->name])->where(not_exists(
    sub($sel($orders, [$orders->id])->where(eq($orders->customer_id, $customers->id)))
));
```
```sql
SELECT "customers"."name" FROM "customers"
WHERE NOT EXISTS (SELECT "orders"."id" FROM "orders"
                  WHERE "orders"."customer_id" = "customers"."id")
```

`not_exists()` rather than `not_in_()`, because `customer_id` is nullable — and `NOT IN` with a NULL
among the results is true for no row at all.

### 2. Customers who ordered since a date

```php
$sel($customers, [$customers->name])->where(in_($customers->id,
    sub($sel($orders, [$orders->customer_id])->where(gte($orders->placed_on, $since)))
));
```
```sql
SELECT "customers"."name" FROM "customers"
WHERE "customers"."id" IN (SELECT "orders"."customer_id" FROM "orders"
                           WHERE "orders"."placed_on" >= ?)
-- params: ["2026-08-01"]
```

The date is **bound, not pasted**. Before subqueries existed this was `raw()`, which interpolates
nothing.

### 3. Products priced above the average

```php
$sel($products, [$products->name, $products->price])
    ->where(gt($products->price, sub($sel($products, ['AVG(price)']))));
```
```sql
SELECT "products"."name", "products"."price" FROM "products"
WHERE "products"."price" > (SELECT AVG(price) FROM "products")
```

A scalar subquery. It needed no new operator: `Comparison` already parenthesises an expression.

### 4. The cities we actually ship to

```php
$sel($customers, [$customers->city])->distinct()->order_by(asc($customers->city));
```
```sql
SELECT DISTINCT "customers"."city" FROM "customers" ORDER BY "customers"."city" ASC
```

### 5. Spend per customer, then filtered

```php
$per_customer = sub(
    $sel($orders, [$orders->customer_id, 'SUM(total) AS spent'])->group_by($orders->customer_id)
)->alias('per_customer');

(new QueryBuilder())->select()->from($per_customer)->where(gt($totals->spent, 100));
```
```sql
SELECT * FROM (SELECT "orders"."customer_id", SUM(total) AS spent
               FROM "orders" GROUP BY "orders"."customer_id") AS "per_customer"
WHERE "per_customer"."spent" > ?
```

Aggregate first, then ask questions of the result — which `HAVING` can do only for the grouping it
belongs to.

### 6. Active and archived, in one list

```php
$active->union($archived)->order_by(asc($customers->name));
```
```sql
SELECT … WHERE "customers"."status" = ?
UNION
SELECT … WHERE "customers"."status" = ?
ORDER BY "customers"."name" ASC
```

The `order_by` is on the query you call `union()` on, and applies to the whole compound. Putting it
on a branch is refused — see above.

### 7. In one city, but not among the recent buyers

```php
$in_that_city->except($ordered_recently);
```
```sql
SELECT "customers"."name" FROM "customers" WHERE "customers"."city" = ?
EXCEPT
SELECT "customers"."name" FROM "customers"
WHERE "customers"."id" IN (SELECT "orders"."customer_id" FROM "orders" WHERE "orders"."placed_on" >= ?)
```

A set difference, which reads better than a `NOT IN` carrying two conditions.

### 8. A whole category subtree

```php
$anchor = $sel($categories, [$categories->id, $categories->parent_id, $categories->name])
    ->where(eq($categories->id, $root_id));

$step = $sel($categories, [$categories->id, $categories->parent_id, $categories->name])
    ->inner_join($subtree, eq($categories->parent_id, $subtree->id));

$sel($subtree, [$subtree->name])->with_recursive('subtree', sub($anchor->union_all($step)));
```
```sql
WITH RECURSIVE "subtree" AS (
    SELECT … FROM "categories" WHERE "categories"."id" = ?
    UNION ALL
    SELECT … FROM "categories" INNER JOIN "subtree" ON "categories"."parent_id" = "subtree"."id"
)
SELECT "subtree"."name" FROM "subtree"
```

→ `Electronics / Peripherals / Keyboards` — every level, in **one statement** instead of a query per
level.

### 9. A two-step report

```php
$sel($customers, [$customers->name])
    ->with_cte('big_orders', sub($orders_over_threshold))
    ->where(in_($customers->id, $big_orders))
    ->order_by(asc($customers->name));
```
```sql
WITH "big_orders" AS (SELECT "orders"."customer_id" FROM "orders" WHERE "orders"."total" > ?)
SELECT "customers"."name" FROM "customers" WHERE "customers"."id" IN (…) ORDER BY …
-- params: [200, 200]
```

The CTE binds **first**, because it appears first. Placeholders are positional, so a clause built out
of order produces a statement that runs against the wrong values without complaining.

### 10. Customers who bought a specific product

```php
$sel($customers, [$customers->name])->where(exists(
    sub($sel($orders, [$orders->id])
        ->inner_join($order_items, eq($order_items->order_id, $orders->id))
        ->where(and_(eq($orders->customer_id, $customers->id),
                     eq($order_items->product_id, $product_id))))
));
```
```sql
SELECT "customers"."name" FROM "customers" WHERE EXISTS (
    SELECT "orders"."id" FROM "orders"
    INNER JOIN "order_items" ON "order_items"."order_id" = "orders"."id"
    WHERE ("orders"."customer_id" = "customers"."id") AND ("order_items"."product_id" = ?)
)
```

A subquery with a join inside it, correlated to the outer customer. Still one statement.

---

## Worked examples: relations

The companion to the section above. Those are questions a person could have written SQL for; these
are the thing the builder does *for* you — fetching related rows without an N+1 and attaching them
where they belong.

```bash
php examples/relation_examples.php
```

### A list with its children

```php
$q->query($customers)->columns(['name'])
  ->with(['orders' => ['columns' => ['placed_on', 'total']]])
  ->find_many();
```
```json
[{"name":"Alice","orders":[{"placed_on":"2026-08-01","total":120},
                           {"placed_on":"2026-06-01","total":80}]},
 {"name":"Bob","orders":[{"placed_on":"2026-08-10","total":300}]},
 {"name":"Chen","orders":[]}]
```

**Two queries, not one per customer**: one for the customers, one for all of their orders with an
`IN (…)`. That is the whole point of `with()`.

### A row with its parent

```php
$q->query($orders)->columns(['placed_on', 'total'])
  ->with(['customer' => ['columns' => ['name', 'city']]])
  ->find_first();
```
```json
{"placed_on":"2026-08-01","total":120,"customer":{"name":"Alice","city":"Rome"}}
```

A `one` relation attaches a single row; a `many` attaches a list. Even when the list is empty.

### Filtered, ordered and capped children

```php
->with(['orders' => [
    'columns'  => ['placed_on', 'total'],
    'where'    => gte($orders->placed_on, '2026-08-01'),
    'order_by' => [desc($orders->total)],
    'limit'    => 1,           // the biggest August order — for each customer
]]);
```

All three apply to the **children of each parent**, not to the parents. `limit` in particular means
*this many per parent*: `order_by` decides which ones survive the cap.

### Several levels deep

```php
->with(['orders' => ['columns' => ['placed_on'],
    'with' => ['items' => ['columns' => ['qty'],
        'with' => ['product' => ['columns' => ['name']]]]]]]);
```
```json
{"name":"Alice","orders":[
  {"placed_on":"2026-08-01","items":[{"qty":2,"product":{"name":"Keyboard"}},
                                     {"qty":1,"product":{"name":"Monitor"}}]}]}
```

One query per level, regardless of how many rows each level has.

### Many-to-many, through a junction table

```php
// declared once
'tags' => $r->many($tags, [
    'through'        => $product_tags,
    'through_fields' => [$product_tags->product_id],
    'target_fields'  => [$product_tags->tag_id],
]);

// and never mentioned again
$q->query($products)->columns(['name'])->with(['tags' => ['columns' => ['label']]])->find_many();
```

### A table related to itself

```php
'children' => $r->many($categories, ['fields' => [$categories->id],
                                     'references' => [$categories->parent_id]]),
'parent'   => $r->one($categories,  ['fields' => [$categories->parent_id],
                                     'references' => [$categories->id]]),
```

No special case — the target of the relation happens to be the same table.

### A polymorphic child

One table of notes, several kinds of owner, told apart by a type column:

```php
'notes' => $r->many_polymorphic($notes, [
    'type_column' => $notes->about_type,
    'id_column'   => $notes->about_id,
    'type_value'  => 'customer',        // only the notes about customers
    'references'  => [$customers->id],
]);
```

### Forget the `with()`, and the key is simply absent

```php
$with    = $q->query($customers)->columns(['name'])->with(['orders' => [...]])->find_first();
// {"name":"Alice","orders":[…]}

$without = $q->query($customers)->columns(['name'])->find_first();
// {"name":"Alice"}          ← no "orders" key at all

array_key_exists('orders', $without);   // false
```

**Loading is eager and explicit, and there is no lazy fallback.** Nothing queries the database when
you read a relation — `ActiveRow` wraps rows that are already there and contains no queries at all.

That is deliberate, and it is the same choice Drizzle makes: an N+1 cannot appear by accident,
because a relation you did not ask for is not there to be read. The cost is that if a template needs
a relation the controller did not load, you go back to the controller.

Note the distinction the two cases keep: a parent with **no children** gets `[]`, and a relation that
was **never loaded** has no key. `?? []` collapses them; `array_key_exists()` does not.

---

## Views

A view is a stored `SELECT` you read like a table. Declare it the way you declare a table, and give
it the query it stands for:

```php
use function Italix\Orm\Schema\{pg_view, integer, varchar, numeric};
use function Italix\Orm\Operators\{desc, eq, gt, raw, sql_sum};

$top_customers = pg_view('top_customers', [
    'id'    => integer(),
    'name'  => varchar(120),
    'spend' => numeric(10, 2),
])->as_query(
    $dm->select([$customers->id, $customers->name, sql_sum($orders->total)->as('spend')])
       ->from($customers)
       ->inner_join($orders, eq($orders->customer_id, $customers->id))
       ->where(eq($customers->status, 'active'))
       ->group_by($customers->id, $customers->name)
       ->having(gt(sql_sum($orders->total), 1000))
);
```

Then, in a migration:

```php
Schema::create_or_replace_view($top_customers);   // Schema::create_view() / drop_view() / has_view()
```

And from then on it is just a table:

```php
$rows = $dm->select()->from($top_customers)
           ->where(gt($top_customers->spend, 5000))
           ->order_by(desc($top_customers->spend))
           ->limit(20)
           ->execute();
```

**A view is a `Table`.** Not a parallel type with its own code path — the query builder, the
relation loader and `describe_columns()` take it unchanged, which is also why none of them can drift
from the table behaviour.

**Writing to one is refused here, not at the server.** Whether a view is updatable depends on the
engine, on the `SELECT`, and on MySQL on the algorithm the optimiser picked; the one portable answer
is no. `insert()`, `update()` and `delete()` throw, and the message names the line in *your* code
instead of arriving as a server error about a statement you did not write.

**Replacing takes a different number of statements per dialect.** PostgreSQL, MySQL and MariaDB take
`CREATE OR REPLACE VIEW`; SQLite does not — measured, `near "OR": syntax error` — and has to drop
first. `create_or_replace_view()` runs whatever the dialect needs. `CREATE VIEW IF NOT EXISTS` is
deliberately absent: SQLite and MariaDB accept it, MySQL and PostgreSQL do not, and MariaDB reports
itself as `mysql` — a method that works on your machine and fails on deploy is worse than no method.

**The definition's values are written into the statement.** `CREATE VIEW` is DDL and binds nothing,
so `where(eq($customers->status, 'active'))` has to end up as text. Values are rendered by an
encoder that takes null, booleans, numbers, strings and `DateTimeInterface` and **refuses anything
else** rather than guessing; strings are escaped for the dialect, and placeholders are only replaced
where they are SQL syntax — a `?` inside a string literal in a raw fragment stays a `?`.

> This is not a hole in the builder. A view definition is schema written by the developer, at the
> same trust level as the table name beside it. Runtime values belong in the `WHERE` of the query
> that *reads* the view, where they are bound as usual.

The columns are declared, not discovered — same as a table's, because this package describes a
schema in PHP rather than interrogating the server. A definition that needs SQL this builder cannot
express takes a string instead: `->as_query('SELECT …')`.

### Materialized views (PostgreSQL)

A view runs its query every time you read it. A **materialized** view stores the answer:

```php
use function Italix\Orm\Schema\{pg_materialized_view, numeric, varchar};

$daily_totals = pg_materialized_view('daily_totals', [
    'day'   => varchar(10),
    'total' => numeric(12, 2),
])->as_query($select)
  ->add_unique_index('daily_totals_day', ['day']);

Schema::create_materialized_view($daily_totals);          // creates the view and its indexes
Schema::refresh_materialized_view($daily_totals, true);   // true = CONCURRENTLY
```

That is the point and also the catch: **the rows are as old as the last refresh**, and deciding when
to refresh is a question about your application, not about SQL.

| measured on PostgreSQL 12 | |
|---|---|
| `CREATE OR REPLACE MATERIALIZED VIEW` | does not exist. Replacing is `DROP` then `CREATE`, which `create_or_replace_materialized_view()` does for you — the rows go with it. |
| `CREATE … IF NOT EXISTS` | works, and is offered — this type is PostgreSQL by construction, so there is no dialect ambiguity here. |
| `->with_no_data()` | creates it empty for the first refresh to fill. Reading it before that is an **error**, not an empty result — ask `Schema::is_materialized_view_populated()`. |
| `REFRESH … CONCURRENTLY` | keeps readers unblocked, and needs a unique index on the view plus a populated view. Hence `add_unique_index()`. |
| `DROP VIEW` on one | `"x" is not a view`. They share neither a `DROP` nor a catalogue — matviews are in `pg_matviews`. |

MySQL and SQLite have none, and building one for them is refused where it is built. The substitute
there is a real table plus a job that refills it, which is a different thing and worth writing as one.

## Large results: cursors and chunking

`execute()` calls `fetchAll()`, which builds a PHP array of every row before you can look at the
first one. That is right until it is not — and when it is not, the export dies at the memory limit
having produced nothing. Three ways out, and they are not interchangeable:

```php
// One statement, rows as they arrive. Measured on 50,000 rows: 21.8 MB → 0.0 MB.
foreach ($dm->select()->from($orders)->cursor() as $row) { … }

// The same, as a callback. Return false to stop early.
$dm->select()->from($orders)->each(fn(array $row, int $i) => …);

// Keyset paging: one bounded query per page, nothing held open between them.
$dm->select()->from($orders)->chunk_by($orders->id, 1000, function (array $rows) { … });

// Offset paging. Needs an ORDER BY, and says so if it does not have one.
$dm->select()->from($orders)->order_by($orders->id)->chunk(1000, function (array $rows) { … });
```

`$dm->cursor($sql, $params)` does the same for raw SQL.

**`chunk()` refuses to run without an `ORDER BY`.** Not pedantry: `LIMIT 10 OFFSET 10` against an
unordered query is not an error, but the server may order the pages differently each time, so a page
repeats rows the last one had and never returns others. Nothing reports it, and it looks like data
that went missing on its own.

**Prefer `chunk_by()`.** It asks for `key > <last one seen>` instead of counting rows to skip, so the
last page costs what the first did, and rows inserted or deleted while it runs cannot shift the
window. The difference is not academic — this is `examples/large_result_example.php`, a job that
processes rows and deletes them:

```
chunk_by():  50000 of 50000 rows processed
chunk():      6500 of 50000 rows processed — OFFSET moved under the deletions
```

The key must be unique and ordered — a primary key is the usual one — and must be among the columns
selected, or the paging has nothing to continue from; both are checked.

> What `cursor()` controls is the PHP array. The driver may still buffer the whole result itself —
> PDO's MySQL driver does by default — so it is a saving, not a guarantee of constant memory, and the
> statement stays open for the whole loop. Where even that is too much, page with `chunk_by()`.

## Transactions

```php
$dm->transaction(function ($dm) use ($order) {
    $dm->insert($orders, $order);
    $audit->record($dm, $order);      // opens its own transaction — and need not know
});
```

**Transactions nest.** The first `transaction()` opens a real one; each one inside it opens a
savepoint. That is what lets a method ask for a transaction without first asking whether it is
already in one — an answer that depends on its callers, which it does not know.

The two directions both hold:

| | |
|---|---|
| an inner block fails | its work is undone, **the outer transaction stays open and usable** |
| the outer block fails | everything goes, including work an inner block "committed" |

The first is not a nicety. On PostgreSQL a failed statement aborts the whole transaction — every
later statement errors until something rolls back — and rolling back to a savepoint is the only way
out short of abandoning all of it.

The second is the one a naive implementation gets wrong: releasing a savepoint is **not** a commit.
It discards the marker and leaves the work inside the enclosing transaction, still provisional.
Otherwise a helper could write through its caller's rollback.

`transaction_depth()` reports how many are open. Committing or rolling back with none open throws,
rather than returning quietly and letting a double commit look like success.

> Two things no savepoint can help with. MySQL **implicitly commits** on DDL (`CREATE TABLE`,
> `ALTER TABLE`, …), which ends the transaction underneath you whatever the depth says. And a
> rollback that fails — a connection that went away — is caught so that the original exception
> reaches the caller rather than being replaced by a secondary one.

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

### `on_commit()` / `on_rollback()`

`DataManager::on()` (["Lifecycle hooks"](#lifecycle-hooks) above) fires `after_insert`/`after_update`/
`after_delete` the instant a statement runs, whether or not the transaction around it ever commits —
the wrong moment for a side effect the rest of the world will see (an email, a webhook call) that a
later rollback cannot take back:

```php
$dm->transaction(function ($dm) use ($order) {
    $dm->insert($orders)->values([...])->execute();
    $dm->on_commit(fn () => $mailer->send_confirmation($order));   // waits for durability
});
```

Unlike `on()`'s table-scoped registry, these are registered dynamically during a transaction — there
is no table a "the enclosing transaction succeeded" callback naturally belongs to. `on_commit()` fires
**immediately, synchronously**, right there, when no transaction is open at all — outside
`transaction()`/`begin_transaction()` every statement commits on its own, so there is nothing to wait
for. `on_rollback()` is a no-op in that case.

**Nesting resolves each hook at the level it actually belongs to, not always at the outermost
boundary.** A hook registered inside a nested `transaction()` call that itself rolls back (a
`SAVEPOINT` undone) resolves *immediately* — that specific unit of work is undone, whatever an
enclosing transaction goes on to do, so its `on_rollback()` hooks fire right then and its
`on_commit()` hooks are discarded right then. A hook registered inside a nested call that itself
"commits", conversely, does *not* fire yet: releasing a savepoint is not a real commit (see above —
only the outermost commit writes anything), so any hook queued there simply graduates to the
enclosing level until something outside the whole transaction decides its fate for real. A hook
registered by an enclosing level, before a nested savepoint even opened, is untouched by that nested
savepoint's own rollback.

A **foreign** transaction (this `DataManager` did not open the outermost `BEGIN` — a shared
connection, a test harness wrapping a suite) fires `on_commit()` at this object's own savepoint
release, best-effort: there is no way to observe what the transaction's actual owner does with it
afterward.

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

## Writing many rows

```php
$dm->insert_many($readings, $rows);            // chunks of 500, one transaction
$dm->insert_many($readings, $rows, 1000);      // wider chunks for narrow rows
```

Measured on this project's MariaDB, 5,000 rows:

| | |
|---|---|
| one `INSERT` at a time | **273 s** |
| one at a time, inside one transaction | **1.71 s** |
| `insert_many()`, chunks of 500 | **1.18 s** |

Note where the time went. Batching is the smaller half — 1.5× — and **the transaction is the other
160×**: without one, every row is its own transaction and the database flushes to disk 5,000 times.
`insert_many()` gives you both, which is why it exists rather than being advice in a README. (In
memory, where there is no disk to flush and no round trip, batching alone is about 7×.)

One transaction also means a failure half way leaves **nothing** behind rather than half an import.
Nesting is safe — inside a caller's transaction it opens a savepoint.

Rows that name different columns are refused, not reconciled: a multi-row `INSERT` has one column
list, and filling the gaps with `NULL` would write something nobody asked for. The check runs over
every row before the first statement, so a bad row at the end does not leave the good ones at the
start behind.

The chunk size is a size, not a limit read from the server. What bites in practice is statement and
packet size, not a placeholder count — measured, this MariaDB took 90,000 placeholders in one
statement and this SQLite 150,000.

## Read replicas

```php
$dm->use_replicas(Driver::mysql($replica_config));

$rows = $dm->select()->from($articles)->execute();     // the replica
$dm->update($articles)->set([…])->execute();           // the primary
$rows = $dm->select()->from($articles)->execute();     // the primary, from now on
```

A replica is always a little behind, and how far is not something the application can see. Everything
about this exists to keep that from becoming a bug:

| | |
|---|---|
| **a read after a write** | goes to the primary — and not just the next one: every read until `resume_replica_reads()` |
| **a read inside a transaction** | goes to the primary; the rows it has written exist nowhere else yet |
| **`$dm->execute()`** (raw SQL) | assumed to be a write. It cannot tell from the text, and guessing is how a `WITH … INSERT` ends up on a replica |
| **several replicas** | one is picked at random per read |

Saving a form and rendering the page that shows it is the most common thing an application does, and
a replica that has not caught up shows the value from before the edit — from which the user concludes
the save did not work. That is why the pin is sticky rather than per-query.

```php
$total = $dm->on_primary(fn() => $dm->select()->from($ledger)->execute());   // must be current
$dm->resume_replica_reads();                                                // a worker, between jobs
```

A long-lived worker should call `resume_replica_reads()` between jobs, or every read after its first
write stays on the primary for the life of the process. Nothing here checks whether a replica is
healthy or how far behind it is: that is a decision for the deployment, not for an ORM.

## JSON columns

`json()` and `jsonb()` columns were declarable and unaskable. Now one path syntax — MySQL's and
SQLite's, `$.meta.age`, `$.tags[0]`, `$."odd,key"` — renders for whichever server you are on:

```php
use function Italix\Orm\Operators\{json_text, json_get, json_length, json_has, json_contains};

$dm->select([$orders->id, json_text($orders->doc, '$.customer.name')->as('customer')])
   ->from($orders)
   ->where(eq(json_text($orders->doc, '$.status'), 'paid'))
   ->where(gt(json_length($orders->doc, '$.items'), 1))
   ->order_by(desc(json_text($orders->doc, '$.total')))
   ->execute();
```

| | SQLite | MySQL / MariaDB | PostgreSQL |
|---|---|---|---|
| `json_text()` | `json_extract(c, ?)` | `JSON_UNQUOTE(JSON_EXTRACT(c, ?))` | `c #>> ?::text[]` |
| `json_get()` | `json_extract(c, ?)` | `JSON_EXTRACT(c, ?)` | `c #> ?::text[]` |
| `json_length()` | `json_array_length(c, ?)` | `JSON_LENGTH(c, ?)` | `jsonb_array_length(c #> ?::text[])` |
| `json_has()` / `json_missing()` | `json_type(c, ?) IS [NOT] NULL` | `[NOT] JSON_CONTAINS_PATH(c, 'one', ?)` | `(c #> ?::text[]) IS [NOT] NULL` |
| `json_contains()` | **refused** | `JSON_CONTAINS(c, ?)` | `c @> ?::jsonb` |

Everything above is measured on SQLite 3.31, MariaDB 10.3 and PostgreSQL 12, and the suite runs
against all three.

**`json_text()` and `json_get()` are not the same call.** On MySQL, `JSON_EXTRACT` returns *JSON* —
`"Ada"`, quotes included — so comparing it to `'Ada'` compares a two-character-longer string and
matches nothing. `json_text()` unquotes; `json_get()` deliberately does not, for when you want the
object or the array.

**Containment is refused on SQLite**, which has neither `@>` nor `JSON_CONTAINS()` and no rewrite
that means the same for an arbitrary document — the same choice `distinct_on()` makes. On PostgreSQL
`@>` is a `jsonb` operator: a `json()` column has to be `jsonb()`.

> Two things worth knowing, both found by running this rather than reading about it. PostgreSQL's
> key-exists operator is `?`, which **PDO takes for a placeholder** — `doc ? 'name'` reaches the
> server as `doc $1 'name'`; `json_has()` uses `(doc #> path) IS NOT NULL` instead, which also works
> at any depth rather than only on a top-level key. And the `->` / `->>` operators are not used at
> all: SQLite got them in 3.38 and MariaDB does not have them here, while the function forms work
> everywhere those operators do.

The path is **bound as a parameter** on every dialect, never written into the statement, and on
PostgreSQL every segment is quoted — an unquoted array literal ends at the first comma, so a key
containing one would address a different place in the document. Anything that is not a path (`name`,
`$..name`, `$.tags[x]`) is refused where it is written.

## Full-text search

`Table::fulltext($columns)` declares the index; `Operators\fulltext_match()` searches it. Both render
three different ways, because there is no SQL-standard full-text mechanism the way there almost is for
`CHECK`/`ENUM`:

```php
$articles = mysql_table('articles', [
    'id'    => integer()->primary_key()->auto_increment(),
    'title' => varchar(200)->not_null(),
    'body'  => text()->not_null(),
])->fulltext(['title', 'body']);

$dm->create_tables($articles);   // creates the index/virtual table for whichever dialect this is

$dm->select()->from($articles)
    ->where(fulltext_match($articles, ['title', 'body'], 'quick fox'))
    ->execute();
```

- **MySQL**: a native `FULLTEXT INDEX`; `MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)`, or
  `IN BOOLEAN MODE` (`fulltext_match(..., 'boolean')`) for the engine's own `+word -word "phrase"`
  operator syntax.
- **PostgreSQL/Supabase**: a `GIN` index over `to_tsvector('english', title || ' ' || body)` (not
  required for `@@` to work at all, only for it not to be a sequential scan); `@@ plainto_tsquery(...)`
  for free text, `@@ to_tsquery(...)` in boolean mode (the caller supplies `to_tsquery` syntax directly,
  e.g. `'fox & quick'`).
- **SQLite**, which has no full-text *index* at all, only a completely separate FTS5 *virtual table*:
  an external-content FTS5 table (the indexed text is not duplicated) plus three triggers that keep it
  in sync on `INSERT`/`UPDATE`/`DELETE`. `fulltext_match()` compiles to `pk_col IN (SELECT rowid FROM
  table_fts WHERE table_fts MATCH ?)`, composable with the rest of a `WHERE` the same as any other
  condition. Needs the table to have exactly one, single-column integer primary key — FTS5's
  `content_rowid` needs SQLite's own rowid alias — and both `fulltext()` and `fulltext_match()` raise
  immediately, rather than emitting SQL that would fail, when that is not the case.

`Migration\Blueprint::fulltext()` renders the same three ways, on the migration side.

## What the queries cost

```php
$log = new QueryLog(0.1);          // "slow" means 100 ms here
$dm->use_query_log($log);
…
$log->queries_n();                 // 31
$log->total_seconds();             // 0.412
$log->slow();                      // the ones over the threshold
$log->repeated();                  // the same statement, over and over
```

"The page is slow" is answered by *31 queries* far more often than by any one of them being slow, and
a log that keeps only the slow ones cannot say that. `repeated()` is the same point sharpened: an N+1
is one query in a loop, a hundred times, **none of them slow on its own**. No threshold ever sees it.

> **Bound values are not kept.** A record holds the statement, how many values were bound, and how
> long it took. A query log gets written to a file, shipped to a log service, pasted into a ticket —
> and the bound values are the password being hashed and the tax code. `remember_values()` turns that
> on for a development session, and says so in its name.

`keep_all(false)` counts and times without holding a record per statement. A cache hit is never
counted: it did not reach the server, and counting it would hide what the cache is there to show.

## What the server will do with it

```php
$plan = $dm->select()->from($orders)->where(eq($orders->customer_id, 7))->explain();

$plan->has_full_scan();   // the answer that turns into a missing index
$plan->rows();            // the server's own answer, unedited
echo $plan;               // …as text
```

The plan is **not normalised**: three servers describe their work in three vocabularies, and
flattening them into one would mean inventing a fourth that none of them speaks. What is normalised
is the single question worth asking automatically — measured, "I will read the whole table" is
spelled `SCAN TABLE orders` on SQLite, `type: ALL` on MySQL and `Seq Scan` on PostgreSQL.

`explain(true)` asks for `EXPLAIN ANALYZE`, and is **refused on anything but a `SELECT`**: on
PostgreSQL that form *runs* the statement, so `EXPLAIN ANALYZE DELETE …` deletes. Finding that out
from a production database is not a way to learn it.

## Reading a schema back out (`db:pull`)

```bash
ix db:pull                        # every table, as PHP
ix db:pull users orders           # just these
ix db:pull --output=schema.php
```

```php
$introspector = new SchemaIntrospector($dm);
$code = $introspector->generate_schema_code();      // the same, from code
```

What comes back is a file that **declares the database as it is** — types, lengths, `not_null()`,
`unique()`, defaults, `references()` and the named indexes — for SQLite, MySQL/MariaDB and
PostgreSQL. The point is less "generate models" than "get a second opinion": put it next to what your
models declare and the diff is where the two have drifted. A column added by hand on the server, a
length changed in a migration nobody re-ran, an index that was quietly dropped — none of those
announce themselves.

**How it is tested, because a generator cannot be tested by reading what it wrote:**

```
a real database  →  pulled PHP  →  a second database  →  pulled PHP
```

and the two pulls must be identical, on each dialect. Everything the mapping loses shows up as a
difference. That round trip found, in one sitting: `date` and `datetime` described as `timestamp()`,
`char` as `varchar()`, `json` as `text()`; SQLite's unique constraints skipped along with the indexes
that carry them; foreign keys dropped from the output *and* from `CREATE TABLE`; `double_precision()`
emitted with its underscore into MySQL; `real()` silently widening to `DOUBLE` there; and every
PostgreSQL query using `$1` placeholders, which PDO does not parse — so a pull returned nothing at
all and reported success.

**Views come back as views**, with their columns and the definition the server holds:

```php
$active_customers = pg_view('active_customers', [
    'id'   => integer(),
    'name' => text(),
])->as_query('SELECT customers.id, customers.name FROM customers WHERE (customers.status = …)');
```

PostgreSQL materialized views too, read from `pg_attribute` — they are in neither `pg_tables` nor
`information_schema.columns`, so anything asking the ordinary way finds a view with no columns in it.
The definition is whatever the server rewrote yours into (MySQL normalises and qualifies, PostgreSQL
normalises and re-indents), except that MySQL's qualification is stripped: a definition naming the
database it came from only works in a database with that name.

It does not write your models for you: the output is a starting point and a comparison, not something
to keep regenerating over hand-written code.

## Comparing a schema with the database (`db:diff`)

```bash
ix db:diff                    # what the declarations and the database disagree about
ix db:diff --migration        # …and write a migration that would close it
```

(`--generate` is the older name for the same flag, and still works.)

```php
$differ = new SchemaDiffer($dm);
$diff   = $differ->diff($tables);
$code   = $differ->generate_migration_from_diff($diff, $tables);
```

A difference means the declarations and the database disagree, and **only one of them is right** —
which one is a question about the application, not about SQL. So the generated migration moves the
*database*, and everything that would lose data is left for a person:

| | |
|---|---|
| a missing table | `Schema::create()` with its actual columns |
| a missing column | `$table->…()` with type, nullability and default |
| a column the declaration dropped | **commented out** — it holds data |
| a changed type or length | a comment saying what changed: converting is dialect-specific and can lose data |
| a table nobody declared | a comment; it may belong to a library |

`down()` reverses what `up()` actually did, and cannot reverse what it only proposed — one more
reason those stay comments. The file is written and **not run**: a generated migration is a draft
until somebody has read it.

> Going the other way — rewriting the declarations from the database — is deliberately not offered.
> A model carries relations, delegated types and the comment explaining why a column is what it is,
> and regenerating it from a database destroys exactly the part the database does not know. Use
> `db:pull` to see what the database would say and paste the line you want.

### Applying a diff directly

`SchemaPusher` exists for the case with no migration ledger to keep honest — a scratch database, a
test fixture, a tool. **Its two gates are not one flag:**

```php
$pusher->push($tables);                      // create missing tables, add missing columns
$pusher->push($tables, true);                // …and drop the columns the declarations no longer have
$pusher->push($tables, true, true);          // …and drop the tables they do not mention
```

That third argument used to be part of the second, and it was a trap with teeth: `diff()` calls every
table it was not given a "drop", because it cannot tell a partial declaration from a complete one —
and passing *part* of a schema is the ordinary case. `push($some_tables, true)` therefore meant
"align these, and delete the rest of the database". Changes to an existing column are reported and
never applied, whatever the flags.

## Factories and seeders

`Factories\Factory` — Eloquent's model factories, Rails' FactoryBot — a declared recipe for one row
of a table, producing plain arrays (`Persistable`/`ActiveRow` already wrap an array when a caller
wants one, so a factory has no reason to know either style exists):

```php
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'  => fn () => 'User ' . $this->sequence(),
            'email' => fn () => 'user' . $this->sequence() . '@example.com',
            'role'  => 'member',
        ];
    }
}

UserFactory::new($dm, $users)->count(10)->create();                  // persisted
UserFactory::new($dm, $users)->state(['role' => 'admin'])->make();   // built, not persisted
```

No bundled fake-data generation (names, addresses, lorem text) — a Faker-equivalent is an entire
library's worth of scope this package has had no actual need for; every value in `definition()` is
plain PHP the caller already knows how to write. A value may be a scalar/array or a zero-argument
`Closure`, evaluated once per row at build time — what makes `sequence()` (a per-factory-subclass
counter) give each row of a `count(n)` batch its own value. `state($overrides)` — an array, or a
`callable(array $row): array` that can derive one field from another already-resolved one — layers
over `definition()`; repeated calls accumulate in registration order. `create()` persists inside one
transaction (a failure partway through leaves nothing behind) and merges each row's generated
single-column primary key back into the returned array; a composite or absent key is left exactly as
`definition()`/`state()` built it. `create()` never re-`SELECT`s afterward, so a column
`timestamps()`/`optimistic_locking()` fills in automatically is **not** reflected in the returned
row — only the primary key is; read the row back explicitly if the true post-insert state is needed.

`Seeding\Seeder` — a small base class for declaring what an application's seed data is:

```php
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $this->call(PostSeeder::class);
    }
}
```

```bash
ix db:seed                       # runs DatabaseSeeder
ix db:seed --class=UserSeeder    # runs a specific one
```

## Two bridges: validation and caching

Neither `italix/rules` nor `italix/cache` is a dependency of this package, and neither depends on it.
Both seams are interfaces in `italix/contracts`, which is where a shape two libraries share belongs.

### Rules the schema already implies

```php
use Italix\Orm\Rules\{SchemaRules, DatabaseRules};

$schedule = SchemaRules::for_table($users);       // RuleMeta[], per column
$outcome  = $checker->check_all($schedule, $_POST);

$errors = array_merge(
    $outcome->errors(),
    (new DatabaseRules($dm))->check($schedule, $outcome->normalized(), ['id' => $editing_id])
);
```

`varchar(50)` is already a promise that a longer value will not survive the insert; `not_null()` that
a missing one will not either. Writing those a second time in a form is how the two drift apart.

| schema | rule | | not derived, and why |
|---|---|---|---|
| `not_null()`, no default | `required` | | **primary keys** — the database fills them |
| `varchar(n)` / `char(n)` | `max_length` | | **`text()`, `blob()`** — no length to check against |
| `integer()` family | `integer` | | **`boolean()`, `json()`** — no rule in the shared vocabulary means either |
| `decimal()` family | `numeric` | | **composite unique constraints** — the *pair* is unique; splitting it refuses legal rows |
| `date()` / `datetime()` | `date` | | |
| `uuid()` | `uuid` | | |
| `unique()` | `unique` | | |
| `references()` | `exists` | | |

`DatabaseRules` settles the two a checker deliberately leaves open. `['id' => 42]` is what keeps an
edit form from failing its own uniqueness check when the address is left alone — the row that already
has it is the row being saved. Table and column names arrive as rule parameters and cannot be bound,
so they are checked to be identifiers and quoted; anything else is refused.

A schema cannot know that a `varchar(255)` holds e-mail addresses, or that a `NOT NULL` column is
filled in by the server. The schedule is a starting point to add to:

```php
$schedule = SchemaRules::for_table($users, ['except' => ['created_by_id']]);
$schedule['email'][] = Rule::email();
```

### Answers worth keeping

```php
$dm->use_query_cache(new QueryCache($cache, 300));

$rows = $dm->select()->from($products)->where(eq($products->kind, 'tool'))->cached()->execute();
$dm->insert($products)->values([…])->execute();     // and now it will ask again
```

Caching is easy; knowing when to stop is the problem, and getting it wrong does not raise — it serves
a row the user just changed themselves. So every table carries a **generation**: a random token in
the cache, mixed into the key of every query that reads it. A write through this package replaces the
token, which retires every answer about that table at once, however many there are.

A token rather than a counter on purpose: a counter whose key expired would restart at 1, and entries
written under the previous generation 1 would come back — stale, and by then trusted.

| | |
|---|---|
| a write through this package | retired automatically |
| `$dm->execute('DELETE FROM …')` — raw SQL | **not seen.** `$dm->query_cache()->invalidate('products')` |
| another process, or a trigger | **not seen.** The lifetime is the only bound — which is why there is one |
| a table reached only inside a subquery | **not tracked** unless named: `->cached(300, ['tags'])` |

`from()` and the joins are tracked. With `ArrayCache` the cache lives and dies with the request; with
`FileCache` it is per server, so two servers hold different generations and a write on one does not
retire the other's answers — for more than one machine the store has to be shared (`RedisCache`,
`StoreCache`). That is a property of the cache handed in, not something the ORM can arrange.

## Requirements

- PHP 7.4 or higher
- PDO extension
- Database-specific PDO driver (pdo_mysql, pdo_pgsql, pdo_sqlite)

## License

This project is licensed under the Apache License 2.0 - see the [LICENSE](LICENSE) file for details.

## Credits

Inspired by [Drizzle ORM](https://orm.drizzle.team/) for TypeScript.
