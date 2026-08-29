# ActiveRow Guide

ActiveRow provides a lightweight active record pattern for PHP. Row objects behave as both arrays and objects, with support for custom methods, dirty tracking, and transient attributes.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Wrapping and Unwrapping](#wrapping-and-unwrapping)
- [Array Access](#array-access)
- [set() and get() Methods](#set-and-get-methods)
- [Dirty Tracking](#dirty-tracking)
- [Transient Attributes](#transient-attributes)
- [Custom Methods](#custom-methods)
- [Persistence](#persistence)
  - [Composite primary keys](#composite-primary-keys)
- [Available Traits](#available-traits)
- [JSON Serialization](#json-serialization)

## Basic Usage

```php
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class UserRow extends ActiveRow
{
    use Persistable;

    public function full_name(): string
    {
        return $this['first_name'] . ' ' . $this['last_name'];
    }
}

// Setup persistence (once at bootstrap)
UserRow::set_persistence($dm, $users_table);

// Create a new row
$user = UserRow::make([
    'first_name' => 'Andrea',
    'last_name' => 'Rossi',
    'email' => 'andrea@example.com',
]);

// Access data
echo $user['email'];           // Array access
echo $user->full_name();       // Custom method

// Save to database
$user->save();
```

## Wrapping and Unwrapping

ActiveRow objects wrap plain arrays, allowing you to add behavior to data fetched from the database.

```php
// Wrap a single array
$user = UserRow::wrap(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

// Wrap multiple arrays
$users = UserRow::wrap_many([
    ['id' => 1, 'name' => 'John'],
    ['id' => 2, 'name' => 'Jane'],
]);

// Make a new row (not yet in database)
$user = UserRow::make(['name' => 'New User']);

// Unwrap back to plain array
$array = $user->to_array();     // All data
$array = $user->unwrap();       // Alias
$array = $user->data;           // Direct access
```

## Array Access

ActiveRow implements `ArrayAccess`, allowing you to treat it like an array:

```php
$user = UserRow::make();

// Set values
$user['first_name'] = 'Andrea';
$user['last_name'] = 'Rossi';

// Get values
echo $user['first_name'];        // 'Andrea'

// Check if key exists
isset($user['email']);           // false

// Unset a key
unset($user['temporary_field']);

// Iterate
foreach ($user as $key => $value) {
    echo "$key: $value\n";
}
```

## set() and get() Methods

For a more fluent API, use `set()` and `get()`:

```php
$user = UserRow::make();

// set() returns $this for chaining
$user->set('first_name', 'Andrea')
     ->set('last_name', 'Rossi')
     ->set('email', 'andrea@example.com');

// get() retrieves values with optional default
$name = $user->get('first_name');              // 'Andrea'
$role = $user->get('role', 'user');            // 'user' (default)
$missing = $user->get('nonexistent');          // null
```

### Equivalence

| set/get | Array Access |
|---------|--------------|
| `$row->set('key', $value)` | `$row['key'] = $value` |
| `$row->get('key')` | `$row['key']` |
| `$row->get('key', $default)` | `$row['key'] ?? $default` |

## Dirty Tracking

ActiveRow tracks which fields have been modified since wrapping or saving.

```php
$user = UserRow::wrap(['id' => 1, 'name' => 'Original', 'email' => 'test@example.com']);

// Check if any field changed
$user->is_dirty();               // false

// Modify a field
$user['name'] = 'Changed';

// Now it's dirty
$user->is_dirty();               // true
$user->is_dirty('name');         // true
$user->is_dirty('email');        // false

// Get all dirty fields
$dirty = $user->get_dirty();     // ['name' => 'Changed']

// Get original value
$user->get_original('name');     // 'Original'

// Save updates only dirty fields
$user->save();                   // UPDATE ... SET name = 'Changed' WHERE id = 1

// Now it's clean again
$user->is_dirty();               // false

// Reset dirty tracking manually
$user->sync_original();
```

## Transient Attributes

Transient attributes are temporary, in-memory values that are **not persisted to the database**. They're identified by a dot (`.`) prefix.

### Use Cases

- **Calculated values**: Store computed results to avoid recalculation
- **Request context**: Track request-specific metadata
- **Caching**: Cache expensive lookups at the row level
- **UI state**: Store temporary display-related data

### Setting Transient Attributes

```php
$user = UserRow::find(1);

// Via set() method
$user->set('.last_accessed_at', time());
$user->set('.computed_score', calculate_score($user));

// Via array access
$user['.session_id'] = session_id();
$user['.permissions'] = ['read', 'write', 'admin'];
```

### Getting Transient Attributes

```php
// Via get() method
$score = $user->get('.computed_score');
$score = $user->get('.computed_score', 0);  // With default

// Via array access
$session = $user['.session_id'];

// Check if exists
if (isset($user['.cached_data'])) {
    // ...
}
```

### Transient vs Persistent Data

```php
$user = UserRow::make([
    'first_name' => 'Andrea',
    'last_name' => 'Rossi',
    'email' => 'andrea@example.com',
]);

// Add transient data
$user['.temp_token'] = 'abc123';
$user['.request_count'] = 5;

// Get only persistent data (for saving)
$persistent = $user->get_persistent_data();
// ['first_name' => 'Andrea', 'last_name' => 'Rossi', 'email' => 'andrea@example.com']

// Get only transient data
$transient = $user->get_transient_data();
// ['.temp_token' => 'abc123', '.request_count' => 5]

// to_array() includes everything by default
$all = $user->to_array();
// Includes both persistent and transient

// to_array(false) excludes transient
$persistentOnly = $user->to_array(false);
// Only persistent data
```

### Transient Attributes Are NOT Saved

```php
$user = UserRow::make([
    'first_name' => 'Carlo',
    'email' => 'carlo@example.com',
]);

// Set some transient data
$user['.temporary_token'] = 'secret123';
$user['.api_request_id'] = 'req-456';

// Save the user
$user->save();

// Later, when loaded from database
$loaded = UserRow::find($user['id']);

// Transient data is NOT loaded (it was never saved)
$loaded['.temporary_token'];   // null - not in database
$loaded['first_name'];         // 'Carlo' - was saved
```

### Transient Attributes and Dirty Tracking

Transient attributes do **not** affect dirty tracking:

```php
$user = UserRow::wrap(['id' => 1, 'name' => 'Test']);

// User is clean
$user->is_clean();              // true

// Adding transient data doesn't make it dirty
$user['.temp'] = 'value';
$user->is_clean();              // still true

// Only persistent data changes make it dirty
$user['name'] = 'Changed';
$user->is_dirty();              // true
$user->get_dirty();             // ['name' => 'Changed'] - no transient keys
```

### Detecting Transient Keys

```php
// Static helper method
UserRow::is_transient_key('.temp_value');     // true
UserRow::is_transient_key('first_name');      // false
UserRow::is_transient_key('some.value');      // false (dot not at start)
UserRow::is_transient_key('.');               // true
```

## Custom Methods

Add domain-specific methods to your ActiveRow classes:

```php
class UserRow extends ActiveRow
{
    use Persistable;

    public function full_name(): string
    {
        return trim($this['first_name'] . ' ' . $this['last_name']);
    }

    public function is_admin(): bool
    {
        return $this['role'] === 'admin';
    }

    public function age(): ?int
    {
        if (!$this['birth_date']) {
            return null;
        }
        return (new DateTime($this['birth_date']))->diff(new DateTime())->y;
    }

    public function gravatar_url(int $size = 80): string
    {
        $hash = md5(strtolower(trim($this['email'])));
        return "https://www.gravatar.com/avatar/{$hash}?s={$size}";
    }
}

// Usage
$user = UserRow::find(1);
echo $user->full_name();        // 'Andrea Rossi'
echo $user->gravatar_url(120);  // 'https://www.gravatar.com/avatar/...?s=120'
```

## Persistence

The `Persistable` trait adds database operations:

```php
class UserRow extends ActiveRow
{
    use Persistable;
}

// Setup (once at bootstrap)
UserRow::set_persistence($dm, $users_table);

// Create
$user = UserRow::create(['name' => 'New User', 'email' => 'new@example.com']);

// Find
$user = UserRow::find(1);                      // By primary key
$user = UserRow::find_one(['where' => ...]);   // First matching
$users = UserRow::find_all(['where' => ...]);  // All matching

// Update
$user['name'] = 'Updated';
$user->save();

// Or update with array
$user->update(['name' => 'Updated', 'email' => 'updated@example.com']);

// Delete
$user->delete();

// Refresh from database
$user->refresh();

// Upsert (insert or update)
$user = UserRow::upsert(
    ['email' => 'unique@example.com'],         // Match on these
    ['name' => 'Upserted User']                // Set these values
);
```

### Fluent queries with `query()`

`find_all()`/`find_one()` above take every condition as one options array. `query()` is the same
`TableQuery` (`$dm->query_table($table)`) exposed as a chain instead, ending in `ActiveRow` instances:

```php
$admins = UserRow::query()
    ->where(eq($users->role, 'admin'))
    ->order_by(desc($users->id))
    ->with(['posts' => true])
    ->find_many();
```

`find_many()`/`find_all()`/`all()` are the same call under three names (`TableQuery`'s own
`find_many()`, and the two conventions this package's finders elsewhere use); `find_first()`/
`find_one()`/`first()`/`one()` are the same four-way choice for a single row. `find($id)` looks up by
primary key. None of `find_all()`/`find()`/`find_one()` above change — `query()` is a second way to
reach the same query, not a replacement.

`where()` called more than once replaces rather than combines — build one expression with
`and_()`/`or_()`/`not_()` from `Italix\Orm\Operators` and pass it to a single `where()` call when more
than one condition is needed. `order_by()` is the exception: repeated calls accumulate.

### Relation classes are derived, not only declared

```php
class UserRow extends ActiveRow
{
    use Persistable;
    // No $relation_classes override needed for this to work:
}
```

A relation eager-loaded with `with(['posts' => true])` wraps into `PostRow` automatically **if**
`PostRow::set_persistence($dm, $posts_table)` was called somewhere — `RelationsRegistry` already
knows `posts` targets the `$posts_table` declared in `define_relations()`, and `ActiveRowRegistry`
(built for this) says which `ActiveRow` class is registered for that table. Declaring
`protected static $relation_classes = ['posts' => PostRow::class]` still works and still wins when
present; it is optional now rather than required for every relation.

Not attempted for a `one_polymorphic` relation, where a row's parent can genuinely be more than one
type (a `commentable` that is a `Post` *or* a `Video`) — there `$relation_classes`, or the explicit
`$class` argument to `relation($name, $class)`, is still the only way to say which class to wrap it
in. `many_polymorphic` (a single concrete target type) is derived like any ordinary relation.

### Composite primary keys

`static::$primary_key` accepts an array as well as the ordinary string — `save()`, `delete()`,
`refresh()`, `get_key()` and `SoftDeletes::force_delete()` all follow it correctly, ANDing every
column together wherever a `WHERE` is built:

```php
class OrderItemRow extends ActiveRow
{
    use Persistable;

    protected static $primary_key = ['tenant_id', 'order_id'];
}

$item = OrderItemRow::create(['tenant_id' => 1, 'order_id' => 100, 'sku' => 'WIDGET']);
$item['sku'] = 'GADGET';
$item->save();               // UPDATE … WHERE tenant_id = 1 AND order_id = 100 — both columns, ANDed
$item->get_key();            // ['tenant_id' => 1, 'order_id' => 100]

OrderItemRow::find(['tenant_id' => 1, 'order_id' => 100]);   // same array shape find() already took
```

A composite key has no auto-increment id — every column of it must already be present in the data a
caller gives `create()`/`make()`. That is also why `exists()` cannot rely on "does the primary key
column have a value" the way a single auto-increment key's absence-until-INSERT already implied:
`exists()` additionally requires `$original` to be non-empty (the same "nothing has persisted since
this instance was made" signal `get_dirty()` already uses), so a freshly `make()`'d row is never
mistaken for an existing one just because it already carries its key.

`get_key_name(): string` is unchanged for the ordinary single-column case, and raises clearly on a
composite one — there is no single name to return — pointing at `get_key_names(): array`, which works
either way (`['id']` for a single-column key, every column for a composite one, in declared order).

Composes with `Table::optimistic_locking()` (see the main README) the same way a single-column key
does — `expect_version()`'s `WHERE` gets every composite column ANDed in alongside the version check.

## Available Traits

| Trait | Description |
|-------|-------------|
| `Persistable` | Adds `save()`, `delete()`, `refresh()`, and static finders |
| `HasTimestamps` | Auto-manages `created_at` and `updated_at` fields |
| `SoftDeletes` | Adds `soft_delete()`, `restore()`, `is_deleted()` |
| `HasSlug` | Auto-generates URL slugs from a source field |
| `CanBeAuthor` | Interface for entities that can be authors |
| `HasDisplayName` | Standard interface for displayable names |
| `DelegatedTypes` | Schema.org-style type hierarchies |

## JSON Serialization

ActiveRow implements `JsonSerializable`:

```php
$user = UserRow::make([
    'first_name' => 'Andrea',
    'email' => 'andrea@example.com',
]);
$user['.secret_token'] = 'abc123';

// JSON excludes transient attributes by default
$json = json_encode($user);
// {"first_name":"Andrea","email":"andrea@example.com"}

// The .secret_token is NOT included (transient)
```

### Including Transient in JSON

If you need transient attributes in JSON, override in your class:

```php
class UserRow extends ActiveRow
{
    protected static $json_include_transient = true;
}
```

Or use `to_array()`:

```php
$json = json_encode($user->to_array(true));  // Includes transient
```

## Best Practices

### 1. Use Transient for Computed Values

```php
class OrderRow extends ActiveRow
{
    public function total(): float
    {
        // Cache expensive calculation
        if (!isset($this['.calculated_total'])) {
            $this['.calculated_total'] = $this->calculate_total();
        }
        return $this['.calculated_total'];
    }
}
```

### 2. Use set() for Chained Initialization

```php
$user = UserRow::make()
    ->set('first_name', $request->get('first_name'))
    ->set('last_name', $request->get('last_name'))
    ->set('email', $request->get('email'))
    ->set('.ip_address', $request->ip())
    ->set('.user_agent', $request->userAgent());

$user->save();  // Saves only persistent data
```

### 3. Clear Naming for Transient Keys

Use descriptive dot-prefixed names that indicate their temporary nature:

```php
// Good - clear purpose
$user['.cached_permissions'] = $perms;
$user['.request_metadata'] = $meta;
$user['.computed_score'] = $score;

// Avoid - unclear
$user['.x'] = $value;
$user['.temp'] = $data;
```

### 4. Don't Store Sensitive Data in Transient

While transient attributes won't be saved to the database, they're still in memory:

```php
// Be careful with sensitive data
$user['.session_token'] = $token;  // OK for short-lived requests
// But don't rely on this for security - clear when done
unset($user['.session_token']);
```
