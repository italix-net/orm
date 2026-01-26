# Italix ORM - Schema Migration Design Document

## Overview

Schema migrations allow developers to version and evolve their database schema over time. This document outlines a proposed design for adding migration support to Italix ORM.

## Design Goals

1. **Version Control Friendly**: Migrations should be stored as files that can be committed to version control
2. **Safe**: Support for rollback (down migrations) when possible
3. **Dialect Aware**: Generate correct SQL for each database dialect
4. **Developer Friendly**: Simple API for common operations
5. **Flexible**: Support for raw SQL when needed
6. **Production Ready**: Track applied migrations, prevent re-running

## Proposed Architecture

### Migration File Structure

```
project/
├── src/
├── migrations/
│   ├── 2024_01_15_000001_create_users_table.php
│   ├── 2024_01_15_000002_create_posts_table.php
│   ├── 2024_01_16_000001_add_email_to_users.php
│   └── 2024_01_20_000001_create_comments_table.php
└── ...
```

### Migration Class Structure

```php
<?php
// migrations/2024_01_15_000001_create_users_table.php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class CreateUsersTable extends Migration
{
    /**
     * Run the migration (apply changes)
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();                           // bigint primary key auto_increment
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();                   // created_at, updated_at
        });
    }

    /**
     * Reverse the migration (rollback changes)
     */
    public function down(): void
    {
        Schema::drop_if_exists('users');
    }
}
```

### Schema Modification Migration

```php
<?php
// migrations/2024_01_16_000001_add_email_to_users.php

class AddProfileToUsers extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url', 500)->nullable()->after('email');
            $table->text('bio')->nullable();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->drop_column('avatar_url');
            $table->drop_column('bio');
            $table->drop_index('idx_users_email');
        });
    }
}
```

## Core Components

### 1. Migration Base Class

```php
<?php
namespace Italix\Orm\Migration;

abstract class Migration
{
    protected IxOrm $db;
    
    abstract public function up(): void;
    abstract public function down(): void;
    
    /**
     * Run raw SQL (for complex migrations)
     */
    protected function sql(string $query, array $params = []): void
    {
        $this->db->sql($query, $params)->execute();
    }
    
    /**
     * Run different SQL per dialect
     */
    protected function dialect(array $queries): void
    {
        $dialect = $this->db->get_driver()->get_dialect_name();
        if (isset($queries[$dialect])) {
            $this->sql($queries[$dialect]);
        } elseif (isset($queries['default'])) {
            $this->sql($queries['default']);
        }
    }
}
```

### 2. Blueprint Class (Table Builder)

```php
<?php
namespace Italix\Orm\Migration;

class Blueprint
{
    protected string $table;
    protected string $dialect;
    protected array $columns = [];
    protected array $indexes = [];
    protected array $foreign_keys = [];
    protected array $drops = [];
    
    // Column types
    public function id(string $name = 'id'): ColumnDefinition;
    public function uuid(string $name): ColumnDefinition;
    public function string(string $name, int $length = 255): ColumnDefinition;
    public function text(string $name): ColumnDefinition;
    public function integer(string $name): ColumnDefinition;
    public function bigint(string $name): ColumnDefinition;
    public function boolean(string $name): ColumnDefinition;
    public function decimal(string $name, int $precision, int $scale): ColumnDefinition;
    public function timestamp(string $name): ColumnDefinition;
    public function datetime(string $name): ColumnDefinition;
    public function date(string $name): ColumnDefinition;
    public function json(string $name): ColumnDefinition;
    
    // Shortcuts
    public function timestamps(): void;  // created_at, updated_at
    public function soft_deletes(): void; // deleted_at
    
    // Indexes
    public function primary(string|array $columns): self;
    public function unique(string|array $columns, ?string $name = null): self;
    public function index(string|array $columns, ?string $name = null): self;
    
    // Foreign keys
    public function foreign(string $column): ForeignKeyDefinition;
    
    // Drops (for modifications)
    public function drop_column(string $name): self;
    public function drop_index(string $name): self;
    public function drop_foreign(string $name): self;
    
    // Modifications
    public function rename_column(string $from, string $to): self;
    public function change(string $column): ColumnDefinition; // Modify column
}
```

### 3. Column Definition (Fluent Builder)

```php
<?php
namespace Italix\Orm\Migration;

class ColumnDefinition
{
    public function nullable(): self;
    public function not_null(): self;
    public function default(mixed $value): self;
    public function unique(): self;
    public function primary(): self;
    public function auto_increment(): self;
    public function unsigned(): self;
    public function after(string $column): self;  // MySQL only
    public function first(): self;                // MySQL only
    public function comment(string $text): self;
    public function charset(string $charset): self;
    public function collation(string $collation): self;
}
```

### 4. Foreign Key Definition

```php
<?php
namespace Italix\Orm\Migration;

class ForeignKeyDefinition
{
    public function references(string $column): self;
    public function on(string $table): self;
    public function on_delete(string $action): self; // CASCADE, SET NULL, RESTRICT, NO ACTION
    public function on_update(string $action): self;
    
    // Shortcut
    public function constrained(string $table = null, string $column = 'id'): self;
}
```

### 5. Schema Facade

```php
<?php
namespace Italix\Orm\Migration;

class Schema
{
    protected static IxOrm $db;
    
    public static function create(string $table, callable $callback): void;
    public static function table(string $table, callable $callback): void;
    public static function drop(string $table): void;
    public static function drop_if_exists(string $table): void;
    public static function rename(string $from, string $to): void;
    public static function has_table(string $table): bool;
    public static function has_column(string $table, string $column): bool;
}
```

### 6. Migrator Class

```php
<?php
namespace Italix\Orm\Migration;

class Migrator
{
    protected IxOrm $db;
    protected string $migrations_path;
    protected string $migrations_table = 'ix_migrations';
    
    /**
     * Run all pending migrations
     */
    public function migrate(): array;
    
    /**
     * Rollback the last batch of migrations
     */
    public function rollback(int $steps = 1): array;
    
    /**
     * Rollback all migrations
     */
    public function reset(): array;
    
    /**
     * Rollback all and re-run
     */
    public function refresh(): array;
    
    /**
     * Get migration status
     */
    public function status(): array;
    
    /**
     * Create a new migration file
     */
    public function create(string $name): string;
    
    /**
     * Get pending migrations
     */
    public function pending(): array;
    
    /**
     * Get applied migrations
     */
    public function applied(): array;
}
```

## Migration Tracking Table

```sql
CREATE TABLE ix_migrations (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    migration VARCHAR(255) NOT NULL UNIQUE,
    batch INTEGER NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## CLI Commands (Suggested)

```bash
# Run all pending migrations
php italix migrate

# Rollback last batch
php italix migrate:rollback

# Rollback specific number of batches
php italix migrate:rollback --steps=3

# Rollback all migrations
php italix migrate:reset

# Rollback and re-run all
php italix migrate:refresh

# Show migration status
php italix migrate:status

# Create new migration
php italix make:migration create_users_table
php italix make:migration add_email_to_users --table=users
```

## Example Usage

### Creating the Migrator

```php
<?php
use Italix\Orm\Migration\Migrator;
use function Italix\Orm\mysql;

$db = mysql([
    'host' => 'localhost',
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
]);

$migrator = new Migrator($db, __DIR__ . '/migrations');

// Run migrations
$applied = $migrator->migrate();
echo "Applied: " . implode(', ', $applied) . "\n";

// Rollback
$rolled_back = $migrator->rollback();
echo "Rolled back: " . implode(', ', $rolled_back) . "\n";

// Status
foreach ($migrator->status() as $migration) {
    echo "{$migration['name']}: {$migration['status']}\n";
}
```

### Dialect-Specific Migrations

```php
<?php
class AddFullTextIndex extends Migration
{
    public function up(): void
    {
        $this->dialect([
            'mysql' => 'ALTER TABLE posts ADD FULLTEXT INDEX idx_content (title, content)',
            'postgresql' => 'CREATE INDEX idx_content ON posts USING gin(to_tsvector(\'english\', title || \' \' || content))',
            // SQLite doesn't support full-text the same way, might use FTS5
        ]);
    }
    
    public function down(): void
    {
        $this->dialect([
            'mysql' => 'ALTER TABLE posts DROP INDEX idx_content',
            'postgresql' => 'DROP INDEX idx_content',
        ]);
    }
}
```

## Safe Migration Practices

### 1. Transactional Migrations

```php
class CreateTables extends Migration
{
    // If true, wrap entire migration in transaction (when supported)
    protected bool $transactional = true;
    
    public function up(): void
    {
        // All operations atomic
    }
}
```

### 2. Check Before Apply

```php
public function up(): void
{
    if (!Schema::has_table('users')) {
        Schema::create('users', function (Blueprint $table) {
            // ...
        });
    }
    
    if (!Schema::has_column('users', 'avatar')) {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable();
        });
    }
}
```

### 3. Data Migrations

```php
class MigrateUserStatuses extends Migration
{
    public function up(): void
    {
        // First, add new column
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status_new', ['active', 'inactive', 'banned'])->default('active');
        });
        
        // Migrate data
        $this->sql("UPDATE users SET status_new = CASE 
            WHEN is_active = 1 THEN 'active' 
            WHEN is_banned = 1 THEN 'banned'
            ELSE 'inactive' 
        END");
        
        // Remove old columns
        Schema::table('users', function (Blueprint $table) {
            $table->drop_column('is_active');
            $table->drop_column('is_banned');
            $table->rename_column('status_new', 'status');
        });
    }
}
```

## Comparison with Existing Schema Definition

| Current Schema | Migration Equivalent |
|----------------|---------------------|
| `integer()->primary_key()->auto_increment()` | `$table->id()` |
| `varchar(100)->not_null()` | `$table->string('name', 100)` |
| `text()` | `$table->text('content')` |
| `boolean()->default(true)` | `$table->boolean('active')->default(true)` |
| `timestamp()->default('CURRENT_TIMESTAMP')` | `$table->timestamps()` |

## Implementation Phases

### Phase 1: Core Infrastructure
- Migration base class
- Blueprint and ColumnDefinition classes
- Schema facade
- Migrator with migrate/rollback

### Phase 2: CLI Tools
- Migration generator
- migrate commands
- status command

### Phase 3: Advanced Features
- Transactional migrations
- Squashing migrations
- Migration testing utilities

### Phase 4: IDE Support
- PHPDoc annotations
- Auto-completion for Blueprint methods

## Estimated Effort

| Phase | Estimated Time |
|-------|---------------|
| Phase 1 | 2-3 days |
| Phase 2 | 1-2 days |
| Phase 3 | 2-3 days |
| Phase 4 | 1 day |
| Testing | 2-3 days |
| Documentation | 1-2 days |
| **Total** | **9-14 days** |

## Alternatives Considered

1. **Doctrine Migrations**: Too heavy, requires Doctrine DBAL
2. **Phinx**: Good but separate library with different conventions
3. **Laravel Migrations**: Tied to Laravel framework

The proposed design takes inspiration from Laravel's elegant API while keeping Italix ORM's lightweight philosophy and adding full multi-dialect support.

## Conclusion

This migration system would provide Italix ORM users with a robust, developer-friendly way to manage database schema changes while maintaining compatibility across MySQL, PostgreSQL, SQLite, and Supabase. The design prioritizes safety, clarity, and the existing Italix ORM conventions.
