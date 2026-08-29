# Changelog — italix/orm

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.30.2] — 2026-08-30

### Fixed

- README's `unsigned()` example used `agency_id` — a column name lifted directly from the
  application this library was extracted from, not a neutral example. Replaced with `customer_id`.

## [2.30.1] — 2026-08-28

### Changed

No change to this library's own code. `require-dev`'s `italix/testing` widened to `^2.0` (was
`^1.0`) and `require`'s `italix/contracts` widened to `^2.0` (was `^1.7`), both MAJOR-bumped
elsewhere in this same round for a function-naming convention change (`_c` retired on method
names — see `src/Libs/Italix/CONVENTIONS.md`). This library implements Contracts' `TableMeta`/
`ColumnMeta` family, not `Translator` — the one interface that actually changed — so nothing here
was at risk, only the constraint needed widening.

## [2.30.0] — 2026-08-26

### Added

- **`DataManager::on_commit()` / `on_rollback()` — transaction-boundary lifecycle hooks.** `DataManager::
  on()` (2.28.0) fires `after_insert`/`after_update`/`after_delete` the instant a statement runs,
  whether or not the transaction around it ever commits — the wrong moment for a side effect the rest
  of the world will see (an email, a webhook call) that a later rollback cannot take back. Found by
  asking, of the two new event categories this release's design discussion considered, which one
  closes a correctness gap that exists today: this one does; a `before_validation`/`after_validation`
  pair does not, since this package's write path has no validation concept to hook into in the first
  place, and the application-level equivalent (`prepare_data()` on `BaseAdminAction`, outside this
  package) already covers "transform data once validation has passed, before it is written" — filed as
  a documentation note there instead of a duplicate hook here.

  ```php
  $dm->transaction(function ($dm) use ($order) {
      $dm->insert($orders)->values([...])->execute();
      $dm->on_commit(fn () => $mailer->send_confirmation($order));   // waits for durability
  });
  ```

  Unlike `DataManager::on()`'s table-scoped `HookRegistry`, these are registered dynamically during a
  transaction — there is no table a "the enclosing transaction succeeded" callback is naturally
  attached to. `on_commit()` fires **immediately, synchronously**, right there, when no transaction is
  open at all: outside `transaction()`/`begin_transaction()` every statement commits on its own, so
  there is nothing to wait for. `on_rollback()` is a no-op in that case — nothing could roll back.

  Nesting is where a naive version of this goes wrong, and is the part actually proven by execution
  here rather than assumed: a hook registered inside a nested `transaction()` call that itself rolls
  back (a `SAVEPOINT` undone) resolves **immediately** — that specific unit of work is undone, whatever
  an enclosing transaction goes on to do, so its `on_rollback()` hooks fire right then and its
  `on_commit()` hooks are discarded right then, without waiting for the outer transaction's own fate.
  A hook registered inside a nested call that itself "commits", conversely, does **not** fire yet — a
  savepoint release is not a real commit (this package's own `commit()` docblock already says so; only
  the outermost commit writes anything), so any hook queued there simply graduates to the enclosing
  level, indistinguishable from one registered directly against it, until something outside the whole
  transaction is what actually decides. A hook registered by an enclosing level, before a nested
  savepoint even opened, is untouched by that nested savepoint's own rollback — proven directly, not
  merely assumed from the design, since the naive "always wait for the outermost boundary" version of
  this would have wrongly fired that hook.

  Implemented as two flat queues (`$commit_hooks`/`$rollback_hooks`) plus a mark stack — one entry per
  open transaction level, pushed by `begin_transaction()`'s savepoint branch and popped by whichever of
  `commit()`/`rollback()` closes that level — recording how many hook entries existed the instant that
  level opened. A rollback splices the *tail* of each queue registered since that mark (that level's
  own hooks: fire the rollback ones, discard the commit ones); a commit just pops the mark and, only at
  the true outermost boundary, resolves everything that survived. A **foreign** transaction (this
  object did not open the outermost `BEGIN` — a shared connection, a test harness wrapping a suite)
  fires `on_commit()` at this object's own savepoint release, best-effort and documented as such: there
  is no way to observe what the transaction's actual owner does with it afterward. A catastrophic
  failure (`rollback()` itself throwing — a dropped connection) discards every pending hook rather than
  guessing which fired-or-not story matches an unknown state, and resets cleanly so nothing queued
  during the failure leaks into whatever transaction this `DataManager` is asked to run next.

  `tests/TransactionHooksTest.php` (35 assertions): every scenario above executed for real against
  SQLite — immediate-fire and no-op outside a transaction (confirmed by internal state via reflection,
  not only by never observing a fire, since a queued-but-never-resolved hook and a correctly-skipped
  one are otherwise indistinguishable from outside), a hook that does not fire on rollback and one that
  does, registration-order firing, both nesting cases above proven independently, a hook registered
  from inside another hook (already running at depth 0, so it fires immediately rather than queuing for
  a commit that will never come), both directions of the foreign-transaction case, and the catastrophic
  failure path including confirming a *later*, unrelated transaction's hooks are not contaminated by
  an earlier failure's discarded queue. Every branch mutation-verified — including two truthiness
  guards (`if ($result)` after `$this->driver->commit()`/`rollback()`) removed as dead code once
  confirmed, empirically, that this codebase's `ERRMODE_EXCEPTION` configuration means a failed commit
  or rollback always throws rather than returning `false`, and one further simplification the mutation
  sweep itself found: `begin_transaction()` pushing a hook mark for a **fresh, outermost** transaction
  is provably redundant with `pop_hook_mark()`'s own default, since both hook queues are invariantly
  empty whenever no transaction is open — proven, not merely argued, by removing the push and finding
  no assertion cared. Only the nested-savepoint mark push carries real information and remains.

- **`Table::optimistic_locking($column = 'version')` — optimistic locking.** JPA/Hibernate's `@Version`,
  Rails' `lock_version`, EF Core's concurrency tokens — a way to detect that a row changed between when
  a caller read it and when it tries to write it back, instead of the second write silently clobbering
  the first. This package had nothing: two concurrent `UPDATE`s to the same row just applied in
  whichever order the database happened to serialize them.

  ```php
  $accounts->optimistic_locking('version');   // column already declared in the schema, like timestamps()

  $dm->insert($accounts)->values(['balance' => 100])->execute();      // version starts at 1
  $dm->update($accounts)->set(['balance' => 90])
      ->where(eq($accounts->id, $id))->expect_version(1)->execute();  // succeeds, version is now 2

  // A second writer, still holding the stale version it read earlier:
  $dm->update($accounts)->set(['balance' => 80])
      ->where(eq($accounts->id, $id))->expect_version(1)->execute();  // throws OptimisticLockException
  ```

  `QueryBuilder::build_update()` compiles `SET version = version + 1` into **every** `UPDATE` once
  declared — unconditionally, discarding any value a caller put under that key in `->set([...])`,
  unlike `timestamps()`'s "trusted if already present" rule: the whole point of a version counter is
  that nothing else ever moves it. `->expect_version($n)` additionally ANDs `version = ?` onto the
  `WHERE`; `execute()` raises the new `Locking\OptimisticLockException` when that leaves zero rows
  affected — the row was deleted, or another write already moved the version. `expect_version()` itself
  raises immediately if called on anything but an `update()`, or against a table with no
  `optimistic_locking()` column, rather than silently checking nothing. `INSERT` defaults the column to
  `1` unless the caller already set one (importing rows whose version numbers are already meaningful).
  `DELETE` is explicitly out of scope — a version counter guards a row's *content*, and
  `Table::soft_deletes()` already covers "keep the row reachable" for callers who want that.

  `ActiveRow`'s `Persistable::save()` calls `expect_version()` automatically whenever
  `has_optimistic_locking()` is true, using the version the instance last read, and keeps the in-memory
  copy in sync with the real increment afterward (this does not re-`SELECT` after writing).

  `tests/OptimisticLockingTest.php` (27 assertions): the insert default and an explicit override, the
  unconditional bump proven even without `expect_version()`, a caller-supplied value in `->set()`
  proven discarded, a successful `expect_version()` and a failing one — including the actual "two
  concurrent writers" scenario reproduced literally, not just asserted in isolation — the guard against
  calling it on a table with no version column or on a non-`update()` query, and `ActiveRow` reaching
  the same protection including a genuinely stale in-memory instance. Every branch mutation-verified;
  the `RETURNING` path is rendered correctly but not executed here — this environment's SQLite (3.31)
  predates `RETURNING` support (3.35+), noted rather than silently skipped.

- **Composite primary key support in `ActiveRow`.** `Table::primary_key([...])` (2.27.0) and
  `TableQuery::find($id)` (2.26.0) already handled a composite key at the Data Mapper level;
  `ActiveRow`'s own `Persistable` never did — `static::$primary_key` was read as a bare string
  everywhere (`exists()`, `get_key()`, `save()`, `delete()`, `refresh()`, `SoftDeletes::force_delete()`),
  so a composite-key table used through an `ActiveRow` subclass silently built a `WHERE` against only
  the first column.

  ```php
  class OrderItemRow extends ActiveRow
  {
      use Persistable;
      protected static $primary_key = ['tenant_id', 'order_id'];   // was: a single string, always
  }
  ```

  A real, pre-existing bug surfaced while proving this against a table whose key is supplied by the
  caller rather than auto-generated (which every composite key is): `exists()` checked only whether
  `$data` already carried the primary key's columns — true the instant `make(['tenant_id' => 1,
  'order_id' => 5, ...])` is called, long before `save()` ever runs, since a composite key has no
  auto-increment id to be *absent* until INSERT the way a single-column one does. `create()` therefore
  took the `UPDATE` branch on a row that had never been persisted, silently no-op'ing (matching zero
  rows) instead of inserting anything. `exists()` now additionally requires `$original` to be non-empty
  — the same signal `get_dirty()` already uses for "nothing has persisted since this instance was
  made" — which fixes the composite case and, as a side effect neither introduced nor exercised by any
  existing test, the identical latent bug for a single-column *natural* (manually-assigned, not
  auto-increment) key.

  `get_key()` now returns `[column => value, ...]` for a composite key (a bare scalar, as before, for
  the ordinary case) — exactly the shape `TableQuery::find()` already accepts either way, so
  `$row::find($row->get_key())` round-trips regardless of which kind of key the table has. New
  `get_key_names(): array` names every column (`[static::$primary_key]` for a single-column key);
  `get_key_name(): string` is unchanged for the single-column case and now raises clearly on a
  composite one, naming `get_key_names()` instead — a `string` return type widened to also return an
  array would have been a breaking change under this project's own versioning policy, and there is
  nothing a single name could correctly mean for two columns.

  `tests/ActiveRowCompositeKeyTest.php` (29 assertions): `exists()`/`get_key()` before and after a row
  carries both key columns, `create()`/`save()` proven to touch the right row and not a same-tenant
  neighbour sharing one column, `find()` with the composite id array, `delete()`/`refresh()`/
  `replicate()`, `SoftDeletes::force_delete()`, and the single-column path re-run to confirm it is
  genuinely unaffected. Every branch mutation-verified but one, documented inline rather than
  papered over: stripping *every* composite-key column from a dirty `UPDATE`'s `SET` clause (not only
  the first) has no black-box-observable difference from stripping just the first, because the `WHERE`
  is built from the same current data a wrongly-included column's value would have come from — correct
  by inspection and by the identical, already-proven single-column case this generalises.

- **`Table::fulltext($columns)` / `Operators\fulltext_match()` — full-text search, and a real bug
  fixed along the way.** `Migration\Blueprint::fulltext()` already existed, but rendered MySQL's
  `FULLTEXT INDEX` syntax unconditionally — including on PostgreSQL and SQLite, neither of which have
  any such thing, so it failed at execution on both. The same shape of bug `Blueprint::enum()` had
  before 2.24.0, and unnoticed for the same reason: nothing had ever run it against a real non-MySQL
  server. There was also no way to *search* at all — a full-text index nothing could query.

  ```php
  $articles = mysql_table('articles', [...])->fulltext(['title', 'body']);

  $dm->select()->from($articles)
      ->where(fulltext_match($articles, ['title', 'body'], 'quick fox'))
      ->execute();
  ```

  `fulltext()` (now on `Schema\Table` too, not only migration `Blueprint`) and `fulltext_match()` both
  render three different ways, because there is no SQL-standard full-text mechanism the way there
  almost is for `CHECK`/`ENUM`:

  - **MySQL**: a native `FULLTEXT INDEX`; `MATCH(...) AGAINST (? IN NATURAL LANGUAGE MODE | IN BOOLEAN
    MODE)`.
  - **PostgreSQL/Supabase**: a `GIN` index over `to_tsvector('english', col1 || ' ' || col2 || ...)`
    (not required for search to work at all, only for it not to be a sequential scan); `@@
    plainto_tsquery(...)` for free text or `@@ to_tsquery(...)` for the engine's own operator syntax.
  - **SQLite**, which has no full-text *index* concept whatsoever — only a completely separate FTS5
    *virtual table*: an external-content FTS5 table (`content = this table`, so the indexed text is not
    duplicated) plus three triggers that keep it in sync on INSERT/UPDATE/DELETE. This needs the table
    to have exactly one, single-column integer primary key — FTS5's `content_rowid` needs SQLite's own
    rowid alias — and both `fulltext()` and `fulltext_match()` raise immediately, rather than emitting
    SQL that would fail, when that is not the case.

  MySQL and PostgreSQL are rendering-only, unexecuted here — the same limitation every other
  multi-dialect test in this package already accepts. SQLite is executed for real: rows inserted,
  searched, updated, deleted, and the FTS5 index proven — by searching, not by reading the trigger SQL
  — to track every one of those through the sync triggers.

  `tests/FulltextSearchTest.php` (29 assertions), covering both the new `Table::fulltext()` and the
  fixed `Blueprint::fulltext()` (including its `to_alter_sql()` path): DDL rendering on all three
  dialects for both entry points, `fulltext_match()`'s query-side rendering for MySQL/PostgreSQL in
  both natural and boolean mode, real SQLite execution — insert, search, an UPDATE that changes what
  matches, a DELETE that removes a row from the results, `fulltext_match()` composed with an ordinary
  `eq()` condition via `and_()` — and the composite-primary-key refusal on both `fulltext()` and
  `fulltext_match()`. Every new branch mutation-verified.

- **`Factories\Factory` / `Seeding\Seeder` — data factories and seeders.** Eloquent's model factories,
  Rails' FactoryBot, Laravel's `DatabaseSeeder`: a declared recipe for one row of a table, and a place
  to say what an application's seed data is and in what order it goes in. This package had neither —
  filling a table with test or demo data meant hand-writing every `->insert()->values([...])` call.

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

  UserFactory::new($dm, $users)->count(10)->create();                    // persisted
  UserFactory::new($dm, $users)->state(['role' => 'admin'])->make();     // built, not persisted
  ```

  No bundled fake-data generation (names, addresses, lorem text) — building a Faker-equivalent is an
  entire library's worth of scope this package has no present need for, and every value in
  `definition()` is plain PHP the caller already knows how to write. A value may be a plain
  scalar/array or a zero-argument `Closure`, evaluated once per row at build time — what makes
  `sequence()` (a per-factory-subclass counter) give each row of a `count(n)` batch its own value.
  `state($overrides)` — an array, or a `callable(array $row): array` that can derive one field from
  another already-resolved one — layers over the base `definition()`; repeated calls accumulate in
  registration order. `create()` persists inside one transaction (a failure partway through leaves
  nothing behind, not a partial batch) and merges each row's real, generated single-column primary key
  back in; a composite or absent key is left exactly as `definition()`/`state()` built it, the same rule
  `ActiveRow`'s own composite-key INSERT already follows. `make()` builds the same rows without
  touching the database at all.

  `Seeding\Seeder` is a small base class — `run()` plus `call()` to chain to another seeder with the
  same `DataManager` — and the new **`ix db:seed`** command (`--class=` to pick one other than
  `DatabaseSeeder`) is its CLI entry point, registered alongside the existing `db:push`/`db:pull`/
  `db:diff`/`db:squash`. The command itself is exercised manually against a real SQLite database (a
  full factory → seeder → CLI round trip, including the `ix db:seed` process actually persisting rows)
  rather than in the automated suite, the same treatment `db:diff`/`db:pull` already get — CLI
  argv-parsing and output are not the kind of thing this package's execute-and-assert test style fits.

  `tests/FactoriesAndSeedersTest.php` (26 assertions) covers the mechanism itself: `make()` never
  touching the database, `sequence()` isolated per factory subclass and distinct per row, `state()`'s
  array and callable forms (including layering and `$overrides` precedence), `create()` actually
  persisting with the generated id merged back correctly (proven with array_merge()'s own key
  precedence, not a redundant extra check — two defensive conditions this exact discovery removed
  as dead code, see below), transactional rollback on a genuine mid-batch constraint violation, the
  composite-key case, and `Seeder::call()` chaining. Every branch mutation-verified — including two
  found to be dead code and removed rather than kept for symmetry: `create()`'s merge of the generated
  id already lets a caller-supplied value in the row win via `array_merge()`'s own "later argument
  wins" rule, with no extra `array_key_exists()` check needed; and `create()` never calls
  `->returning()`, so the value `execute()` returns for an `INSERT` is always an `int`, making an
  `is_int()` guard around it permanently unreachable-in-effect rather than merely untested.

### Fixed

- **A `before_insert`/`before_update` hook that returned a full replacement row could silently drop
  `Table::timestamps()`'s and `optimistic_locking()`'s own defaults.** Found while double-checking this
  release against itself, not by a report: `build_insert()`/`build_update()` filled in `insert_dt`/
  `update_dt`/`version` *before* running hooks, on the reasoning that hooks work on "the row" and those
  columns are part of it. But a hook's documented contract is that returning an array **replaces** the
  row wholesale — the shape a legitimate field-whitelist hook takes (`return ['balance' =>
  $row['balance']]`) — and a replacement built from the hook's own input naturally does not carry a
  column the hook was never told to preserve. The default was gone from the SET clause with no error:
  a `NOT NULL` column raised downstream if the schema was strict enough to catch it, and a nullable one
  — `optimistic_locking()`'s `version` among them — just silently wrote `NULL`, or in `version`'s case
  failed to increment at all, leaving a future `expect_version()` call checking against a stale number
  it would keep succeeding against.

  This affects every table combining `Table::timestamps()` or `Table::optimistic_locking()` (both
  above) with a `before_insert`/`before_update` hook (2.28.0) shaped as a replacement rather than a
  merge — including, but not limited to, `optimistic_locking()` itself, since both shipped in this same
  release and nothing before now had executed the combination.

  `build_insert()`/`build_update()` now run hooks **first**, against the caller's own raw values, and
  apply `timestamps()`/`optimistic_locking()` **after** — so neither default can be dropped by
  whatever a hook decides to return, and `timestamps()`'s existing "trusted if already present" rule
  now also honours a value a hook explicitly set, which it could not correctly do the other way around
  either.

  Caught here, not merely reasoned about: reverting the reorder in isolation and re-running
  `HooksTest.php`/`OptimisticLockingTest.php` fails four specific assertions added for exactly this —
  a whitelist-shaped hook on a table combining `timestamps()` *and* `optimistic_locking()`, checked on
  both `INSERT` and `UPDATE`. Also newly covered: the composite-primary-key case (`optimistic_locking()`
  guarding a composite-key `ActiveRow` row, `tests/ActiveRowCompositeKeyTest.php`) and `Factories\
  Factory::create()` against a table with both a hook and `optimistic_locking()` declared — two
  combinations built independently of each other and of this fix, now proven to compose correctly
  rather than merely assumed to.

  **A related, deliberate non-fix, documented rather than patched around**: `Factory::create()` never
  re-`SELECT`s after writing (the same choice `Persistable::save()` already makes), so a column
  `timestamps()`/`optimistic_locking()` fills in is genuinely absent from `create()`'s *returned* rows
  — only the generated primary key is merged back. This is not the bug above (the database itself is
  correct; only the PHP-side return value is incomplete) and is called out explicitly in `Factory::
  create()`'s own docblock, with the workaround (read the row back explicitly) named, rather than
  guessed at by having `create()` recompute what `build_insert()` would have — which would risk being
  subtly wrong under exactly the kind of second-boundary clock drift this project's own conventions
  warn against assuming away.

## [2.28.0] — 2026-08-26

### Added

- **`Column::cast_as()` — attribute casting.** Every ORM this package was compared against (Eloquent's
  `$casts`, Doctrine's embeddable types, Django's model fields) converts a raw driver value into a PHP
  one automatically. This package had none: a JSON column came back as the string PDO handed over, a
  boolean was whatever `0`/`1`/`"0"`/`"1"` the driver used, and every reader wrote its own
  `json_decode()`/`new DateTime()`/`(bool)` at the call site — with the inverse conversion written a
  second time, separately, on the way back in. `Column::cast_as('array' | 'datetime' | 'bool' | 'int' |
  'float')` declares both directions once, against the column:

  ```php
  'metadata'   => text()->cast_as('array'),      // JSON string <-> PHP array
  'expires_dt' => varchar(30)->cast_as('datetime'), // string <-> DateTimeImmutable
  'is_active'  => integer()->cast_as('bool'),       // 0/1 <-> real bool
  ```

  `Italix\Orm\Casts\Cast` is the one place `decode()` (raw → PHP) and `encode()` (PHP → raw) live
  together, deliberately — the risk this closes is the two directions drifting apart, and that is
  harder to do by accident when they are adjacent in one file than when they are two independently
  hand-written call sites. Both query engines this package has apply it on read (`QueryBuilder::
  execute()`'s SELECT/RETURNING branches, and `TableQuery::execute_query()` — the same "prove it on
  both, a passing test on one says nothing about the other" rule `soft_deletes()` was held to in
  2.26.0), and only `QueryBuilder::build_insert()`/`build_update()` apply it on write, since writes
  never go through `TableQuery`. A `raw()`/`SQLExpression` value is passed through untouched in both
  directions — encoding it would corrupt the fragment (`json_encode()` on a `RawExpression` object
  silently produces `"{}"` instead of raising, which is exactly the kind of bug this guard exists to
  prevent).

  No `'bool'` case exists on the *write* side: `QueryBuilder::bind_params()` already binds any PHP
  `bool` as `PDO::PARAM_BOOL` regardless of casting, so an encode branch there would be dead code —
  confirmed by mutation-testing it and finding no assertion could tell the difference. Only the read
  direction (raw `0`/`1`/`"0"`/`"1"` → a real `bool`) is this cast's job for booleans.

- **`enum(SomeBackedEnum::class)` — native `BackedEnum` support.** `enum()` already took an array of
  plain values; it now also accepts a `class-string<\BackedEnum>`, reading the allowed values from
  `::cases()` once instead of typing them a second time where the DDL is declared:

  ```php
  enum OrderStatus: string { case Draft = 'draft'; case Placed = 'placed'; case Shipped = 'shipped'; }

  'status' => enum(OrderStatus::class)->not_null(),
  ```

  A column declared this way hydrates on read — `OrderStatus` instances, not raw strings — through
  `Column::enum_class()` and the same `Cast` machinery above; a plain `enum([...])` is unaffected and
  still returns bare strings, since there is no PHP type to hydrate into. A stored value outside the
  declared cases throws on read (`BackedEnum::from()`'s own behaviour) rather than silently passing the
  raw string through — the same "fail loud, not quiet" choice `enum()`'s existing DDL-level `CHECK`
  already makes for writes. `enum()` given a non-backed enum, or any other class, raises
  `\InvalidArgumentException` immediately rather than failing confusingly later.

  One real bug found and fixed while proving this against SQLite, not merely reading the code: an
  int-backed enum (`enum Priority: int { case Low = 1; case High = 2; }`) failed on every read with
  `TypeError: Priority::from(): Argument #1 ($value) must be of type int, string given`. SQLite has no
  `ENUM` type of its own — `enum()` renders `VARCHAR(255) CHECK (...)` there — and that column's TEXT
  affinity stores an int case as text and hands it back the same way; `BackedEnum::from()` type-checks
  strictly and rejects `"2"` as readily as it rejects a value outside `cases()`. `Cast::decode()` now
  coerces the raw value to the enum's actual backing type (via `ReflectionEnum::getBackingType()`,
  cached per class) before calling `from()`.

  `tests/AttributeCastingTest.php` (30 assertions): every cast type round-tripped through both query
  engines, the `SQLExpression` guard proven on an array-cast column specifically (the one cast where
  `json_encode()` would actually corrupt a raw fragment rather than harmlessly falling through a type
  check), `null` proven to decode as `null` rather than `false`/`0`/`0.0` for the bool/int/float casts,
  the int-backed-enum SQLite bug reproduced and fixed, and `enum()`'s new validation. Every branch in
  `Cast.php` and every wiring point in `ColumnTypes.php`/`QueryBuilder.php`/`RelationalQueryBuilder.php`
  mutation-verified individually — including two branches that turned out **not** to be necessary and
  were removed rather than kept for symmetry: the write-side `'bool'` case above, and a null-guard in
  `Cast::encode()` that every switch arm already handled safely on its own (unlike `decode()`'s
  null-guard, which is load-bearing — removing it was caught immediately by the bool/int/float-cast
  null tests).

- **`DataManager::on($table, $event, $hook)` — lifecycle hooks for Data Mapper writes.** The only
  place this package had ever let code react to a write was an `ActiveRow` subclass overriding a
  method. The Data Mapper style this package's own examples otherwise favour — `$dm->insert($table)
  ->values([...])->execute()`, a row a plain array — had nothing: stamping a value before an `INSERT`,
  or firing a side effect after one, had to be hand-written at every call site.

  ```php
  $dm->on($orders, 'before_insert', function (array $row): array {
      $row['reference'] = strtoupper(bin2hex(random_bytes(4)));
      return $row;                              // returning an array replaces the row
  });
  $dm->on($orders, 'after_insert', function (array $rows, ?int $id): void {
      Log::info("order {$id} created");          // void return — side effects only
  });
  ```

  Six events: `before_insert` (per row, in `build_insert()`), `before_update` (once, against the whole
  `SET` clause), `before_delete` (side effects only — a `DELETE` has no values to hand a hook), and
  `after_insert`/`after_update`/`after_delete` (in `execute()`, with the written values and either the
  new id or the affected row count). `before_insert`/`before_update` may return a replacement
  array to change what gets written; anything else, including no return at all, leaves it as the
  previous hook — or the caller — set it. Multiple hooks on the same table/event run in registration
  order, each seeing the previous one's mutation.

  Kept on `DataManager` (a new `Italix\Orm\Hooks\HookRegistry`, one instance per manager, handed to its
  `QueryBuilder` once in the constructor — the same wiring `use_query_cache()` already established),
  not a static registry, so two managers on the same schema never share each other's hooks. Matched by
  `Table` identity, the rule `ActiveRowRegistry` already uses.

  `after_*` fires by `$this->type` rather than by which SQL actually ran — a `soft_deletes()` row (a
  `DELETE` that compiles to an `UPDATE`) still fires `after_delete`, matching what the caller asked
  for. Since `Persistable::save()`/`delete()` compile to exactly these same `QueryBuilder` calls,
  `ActiveRow` reaches these hooks too, without needing to know they exist.

  `tests/HooksTest.php` (24 assertions), every branch mutation-verified: per-row `before_insert`
  mutation and its void-return no-op, hook chaining and ordering, `before_update`'s whole-values
  replacement, `before_delete` firing exactly once regardless of `soft_deletes()`, `after_insert`'s id
  and `after_update`/`after_delete`'s affected count, the `soft_deletes()` "fires by intent, not SQL"
  case, table isolation (a hook on one table never fires for another), `ActiveRow` reaching the same
  hook, an unrecognised event name raising immediately rather than silently registering a dead hook,
  and a bare `QueryBuilder` built without a `DataManager` (so with no `HookRegistry` at all) still
  writing without crashing.

- **`DataManager::add_global_scope($table, $name, $scope)` — generalized global scopes.**
  `Table::soft_deletes()`'s own read filter (2.26.0) was exactly one hard-coded instance of a pattern
  every major ORM offers generally — Eloquent's global scopes, Django's default manager `QuerySet`,
  Doctrine's filters: a named condition ANDed onto every read against a table, until a caller opts out.
  Multi-tenant isolation, "only published", a visibility window — every one of those used to mean
  repeating the same `WHERE` at every call site, with a missed one being a data leak rather than a
  compile error.

  ```php
  $dm->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, $current_tenant_id));

  $dm->select()->from($orders)->execute();                    // only this tenant's rows
  $dm->query_table($orders)->find_many();                     // same — both query engines
  $dm->select()->from($orders)->without_scopes()->execute();  // every tenant's — the escape hatch
  $dm->select()->from($orders)->without_scopes(['tenant'])->execute(); // skip just this one, by name
  ```

  A new `Italix\Orm\Scopes\ScopeRegistry` — instance-per-`DataManager`, `Table`-identity-matched, the
  same shape as the new `HookRegistry` above — extends `effective_where()` on **both** query engines
  (`QueryBuilder` and `TableQuery`), ANDing in every active scope's condition alongside the existing
  soft-delete filter. `TableQuery` has no persistent template to inherit config from the way
  `QueryBuilder` does — `DataManager::query_table()` builds a fresh instance every call — so scopes are
  threaded on explicitly there, right after construction, rather than once at construction time the
  way hooks are on `QueryBuilder`. `without_scopes()` — clone-based, like `with_trashed()` — takes no
  arguments to disable every scope, or an array of names to disable only those; repeated calls naming
  different scopes accumulate. Registering the same scope name again for a table replaces it rather
  than adding a second one. `TypedQuery::without_scopes()` forwards to it, and `Persistable::find_all()`
  gained a matching `'without_scopes'` option key, alongside the existing `'with_trashed'`.

  Like `soft_deletes()`'s own filter, this narrows reads only — `UPDATE`/`DELETE` do not go through
  `effective_where()`, so a scoped-out row is still reachable to correct or purge by primary key,
  the same reason Eloquent's own global scopes only ever narrow a read.

  `tests/GlobalScopesTest.php` (26 assertions): a scope narrowing `select()` and `query_table()`
  independently, `without_scopes()` with and without names, two scopes ANDed together and disabled
  selectively, re-registration replacing rather than duplicating, per-table isolation, a scope
  combined with `soft_deletes()` proving each filter is independent of the other (four combinations:
  neither, either, both escape hatches), writes proven unscoped, and `TypedQuery`/`Persistable`
  reaching the same scopes. Every branch in `ScopeRegistry` and both engines' wiring mutation-verified.

## [2.27.0] — 2026-08-25

### Added

- **`Table::primary_key(array $columns)`** — declares a composite primary key as one fact about the
  table, mirroring `Migration\Blueprint::primary($columns)`, which already worked this way.

  ```php
  $order_items = mysql_table('order_items', [
      'tenant_id' => integer()->not_null(),
      'order_id'  => integer()->not_null(),
      'product'   => varchar(50)->not_null(),
  ])->primary_key(['tenant_id', 'order_id']);
  ```

  Marks every named column `not_null()` and records the order given, which is the order the rendered
  `PRIMARY KEY (…)` clause lists them in. A name that is not a real column on the table is refused.

### Fixed

- **`Schema\Table::to_create_sql()` could not build a table with a genuine composite primary key.**
  `Table::get_primary_keys()` already aggregated every column carrying its own `is_primary_key` flag —
  correctly, and `find()`'s composite-key fix (2.26.0) already relied on that being right — but nothing
  downstream of it could act on more than one. Each such column rendered its **own** inline
  `PRIMARY KEY`, and MySQL, PostgreSQL and SQLite alike refuse two of those on one table
  (`"table has more than one primary key"` on SQLite). `to_create_sql()` now detects more than one
  primary key column — however they came to be marked, individually or through the new
  `primary_key()` above — suppresses the inline keyword on each (`Column::to_sql()` gained an
  `$inline_primary_key` parameter for exactly this), and emits one table-level clause instead.
  `NOT NULL` is preserved explicitly on those columns, since the inline `PRIMARY KEY` this suppresses
  was the only other thing implying it.

- **`db:pull` had the identical defect, the other direction.** `SchemaIntrospector::column_to_code()`
  generated `->primary_key()` per column too, so pulling a real database that already has a working
  composite key produced PHP that, run, hit the exact DDL bug above — the round trip this package holds
  itself to (`IntrospectionTest`) was broken for this one case. `generate_table_code()` now emits a
  single table-level `->primary_key([...])` call instead, and skips the per-column call on those
  columns (their `NOT NULL` is emitted explicitly in its place, the same fix as above, on the code-
  generation side).

  **A second, unrelated SQLite bug surfaced while proving this round trip, and is fixed alongside it**:
  `PRAGMA table_info`'s `pk` field is a 1-based *ordinal*, set on every column of a composite key, not
  a single-column boolean — and `get_sqlite_columns()` read it as "primary key column, and if it's
  INTEGER it must be the autoincrementing rowid alias," which is only true when a table has **exactly
  one** primary key column. Pulling a real composite-key SQLite table generated `->auto_increment()`
  on every one of its key columns, none of which were. Counted once per table now, and the alias
  inference only fires when that count is exactly 1.

  `tests/CompositePrimaryKeyTest.php` (18 assertions): DDL rendering on all three dialects, a table
  actually created and written to (not just valid-looking SQL — a duplicate composite key is proven
  refused by the server), the single-column path confirmed unaffected, and the full `db:pull` round
  trip — a hand-built composite-key table, introspected, code-generated, that code executed, the
  resulting table created and queried by composite key end to end. Each of the three fixes mutation-
  verified independently by reverting it and confirming the specific assertions it exists for fail —
  `to_create_sql()`'s suppression, `column_to_code()`'s per-column skip, and the SQLite `pk`-count fix
  each caught by a different, disjoint subset of the 18.

## [2.26.0] — 2026-08-25

### Added

- **`Table::soft_deletes()` now filters reads, not only writes.** Declaring it already turned
  `delete()` into an `UPDATE` (2.24.0); a soft-deleted row still came back from an ordinary
  `find_many()`/`find()`/`$dm->select()` exactly like any other, because nothing checked the column it
  had just set. Both query engines this package has (`QueryBuilder`, behind `$dm->select()->from(...)`,
  and `TableQuery`, behind `$dm->query_table(...)` — and therefore `Persistable`'s finders and
  `TypedQuery` on top of it) now compile `WHERE deleted_col IS NULL` into every `SELECT` against a
  table with `soft_deletes()` declared, automatically.

  ```php
  $orders->soft_deletes('deleted_dt');

  $dm->delete($orders)->where(eq($orders->id, 5))->execute();       // marks it, does not remove it
  $dm->query_table($orders)->find_many();                            // does not include it — new
  $dm->query_table($orders)->with_trashed()->find_many();            // includes it again — new
  ```

  `with_trashed()` is the escape hatch, on both engines and on `TypedQuery`
  (`OrderRow::query()->with_trashed()->...`); `Persistable::find_all()` takes it as an options key
  (`['with_trashed' => true]`), matching `where`/`with`/`order_by` already there. Applied to `SELECT`
  only — an `UPDATE` or a `DELETE` (including `force()`) still reaches a soft-deleted row without it,
  the same way Eloquent's own scope only ever narrows a read: correcting or purging a soft-deleted row
  has to still be possible.

  `tests/TimestampsAndSoftDeleteTest.php` extended with 13 new assertions — both engines, `find($id)`
  specifically (a soft-deleted single row is not found, then is with `with_trashed()`), and through
  `Persistable`/`TypedQuery`. Mutation-verified on both `effective_where()` implementations
  independently, since they are two separate methods on two separate classes with no code shared
  between them — a passing test on one proves nothing about the other.

### Fixed

- **`TableQuery::find($id)` silently ignored every primary key column but the first, on a composite
  key.** `$pk_columns[0]` was the only column ever compared — not a missing feature, a wrong answer
  handed back without complaint: on a table keyed by `(tenant_id, order_id)`, `find(1)` matched *any*
  row with `tenant_id = 1` regardless of `order_id`, and `find_first()` returned whichever one the
  server happened to see first. No exception, no wrong-row indication, a row that looked exactly like
  a right answer.

  `find()` now takes a scalar for a single-column key exactly as before, and an array keyed by column
  name for a composite one — `find(['tenant_id' => 3, 'order_id' => 5])`. A bare scalar against a
  composite key, or an array missing one of the columns, is refused with a message naming the columns
  involved, rather than silently narrowed to a partial answer. Reached automatically by
  `Persistable::find()` and `TypedQuery::find()`, since both compile through this same method.

  `tests/CompositeKeyFindTest.php` (8 assertions) reproduces the exact failure this closes: three rows
  sharing pairwise overlapping key columns (`(1,1)`, `(1,2)`, `(2,1)`), so a query matching only one
  column of the two would return a real but wrong row rather than nothing — the fixture the earlier bug
  needed and would not have been caught without. Mutation-verified: reverting to the single-column
  comparison fails 6 of the 8 assertions.

  **Not fixed here, and flagged rather than silently left**: `Schema\Table::to_create_sql()` itself
  cannot build a table with a genuine composite primary key — each column individually marked
  `primary_key()` renders its own inline `PRIMARY KEY`, and every server here refuses two of them on
  one table. `get_primary_keys()` reporting more than one column is therefore currently reachable only
  through a `Table` built by hand to describe an existing composite-key table (or, in principle, one
  `db:pull` introspects from a real database — untested, since nothing here can create one to pull
  from), never through this package's own `CREATE TABLE`. `find()`'s fix stands regardless of how the
  `Table` came to describe a composite key, since it works from `get_primary_keys()` either way — but
  building one from scratch through this package remains a separate, open gap. `ActiveRow`'s own
  `Persistable::save()`/`delete()` go further and assume a single-column `$primary_key` throughout,
  which is a deeper structural limitation than either of the two above and was not touched.

## [2.25.0] — 2026-08-25

### Added

- **`Persistable::query()`** — a fluent, typed query, ending in `ActiveRow` instances instead of the plain
  arrays `TableQuery` (`$dm->query_table($table)`) itself returns.

  ```php
  UserRow::query()->where(eq($users->role, 'admin'))->order_by(desc($users->id))->with(['posts' => true])->find_many();
  ```

  Not a second implementation: `find_all(['where' => …, 'with' => …])` already existed, and reading its
  own body shows it already assembled a `TableQuery` from that options array key by key before wrapping
  the result — the fluency was there, just hidden inside a loop over `isset($options[...])`. The new
  `ActiveRow\TypedQuery` is that same assembly exposed as the chain: every method on it does nothing but
  forward to the identically-named one on `TableQuery`. `find_all()`/`find()`/`find_one()` are unaffected
  and stay exactly as they are — this is a second way to reach the same query, not a replacement.

  **`find_many()`, `find_all()` and `all()` are true aliases**, and so are `find_first()`, `find_one()`,
  `first()` and `one()` — a naming inconsistency between this package's two layers (`TableQuery` speaks
  Drizzle's `findMany`/`findFirst`; `Persistable`'s own finders speak Eloquent's `all()`/`find_one()`)
  resolved by offering both vocabularies rather than picking a winner and asking everyone to relearn one
  of them. `all()`/`one()` are the short forms for the reader who wants the shortest name that says what
  it does; the longer names are there for whoever already knows one of the two conventions this package
  is built from.

  Immutable, like `TableQuery` and `QueryBuilder`: every fluent call clones. Two behaviours worth
  knowing, both inherited unchanged from `TableQuery` because nothing here reimplements it: **order
  among different methods in the chain never matters** — `->where(a).limit(1)` and `->limit(1).where(a)`
  compile identically, each call sets its own independent fact — **but repeating the same method is not
  the same story for every method.** `where()` called twice replaces the first condition rather than
  AND-ing it (the second call simply wins); `order_by()` called twice accumulates (`role asc, name desc`
  is genuinely two-level, not just the last call). Both measured, not assumed — a `where()` that
  silently replaced instead of erroring is exactly the kind of thing that reads as a working query and
  answers about something else.

- `tests/TypedQueryTest.php` (13 assertions) — the aliases are asserted by calling all of them and
  comparing results, not by reading three one-line method bodies; the order-independence and
  replace-vs-accumulate behaviours are each reproduced through `TypedQuery` with data built so the two
  possible outcomes would visibly differ (a coincidence where they'd match by accident would prove
  nothing, the same lesson `RelationClassDerivationTest.php` in 2.24.0 had already found once).

### Fixed

- **Two of this session's own test fixtures collided with existing global-namespace classes.**
  `tests/TypedQueryTest.php`'s `UserRow` matched one already declared by two files under `examples/`
  (`ActiveRow/active_row_example.php`, `MultiDatabase/multi_database_example.php`); a `BookRow` in
  `tests/DelegatedTypesFromSchemaTest.php` (2.24.0) matched one in `tests/RelationClassDerivationTest.php`
  from the same version. Harmless at runtime — every one of these files is its own `php file.php`
  process, never `require`d alongside another — but a project-wide static analysis pass sees every
  declared class at once, and picked up the wrong `UserRow` for a reference in
  `multi_database_example.php`, reporting a constant that in fact exists, just on a different class of
  the same name. Renamed (`PersonRow`, `DelegateBookRow`) rather than working around the symptom.

### Tests

- **`tests/BooleanLogicTest.php`** (9 assertions) — `and_()`/`or_()`/`not_()` nested up to three levels
  deep, plus the vacuous identities (`and_()` with no arguments is `1=1`, `or_()` with none is `1=0`).
  The nesting assertions exist because `where()` replacing rather than combining (above) means
  `and_()`/`or_()`/`not_()` are the *only* way to state more than one condition, and getting the
  parenthesisation wrong produces valid SQL that silently asks a different question — each operand is
  wrapped in its own `(...)` specifically so an outer `AND` cannot bleed into an inner `OR` by ordinary
  SQL precedence.

  The first version of this test used fixture data where dropping those parentheses happened to change
  nothing any assertion could see — a coincidence, not a proof, and mutation testing caught it: the
  data was widened by one row (a member both banned *and* over the score threshold, the one case where
  "`AND` binds tighter than `OR`" and "the intended nested meaning" actually disagree) until the
  mutation was visible. Left in the test's own comments as the reason that row exists, not just what it
  is.

### Documentation

- `README.md`: `check()`/`enum()` at the `Column`/`Table` level (previously only documented for
  migration `Blueprint`, a different class with methods of the same name — the two are now
  cross-referenced explicitly so `timestamps()`/`soft_deletes()`/`check()`/`enum()` are not mistaken for
  the same call on both), `Table::timestamps()`/`soft_deletes()`, and `Persistable::query()` with its
  full alias table and the order/repetition rules inherited from `TableQuery`.
- `docs/ACTIVE_ROW_GUIDE.md`: `query()`, and relation-class derivation (`$relation_classes` is now
  optional per relation, not required).
- `docs/DELEGATED_TYPES_GUIDE.md`: the four `DelegatedTypes` configuration methods now default to
  reading the bound `Table` — the API reference table and the worked example both updated to say so,
  without removing the override style the rest of the guide is built around (still valid, still wins).
- `docs/REFERENCE_MANUAL.md` **not** updated — it has been out of date since `2.4.0` (subqueries, CTEs)
  and closing that gap is a rewrite of its own, not an addition. `CHANGELOG.md` remains the accurate
  record for everything after `1.0.0`; said here rather than left for someone to discover by comparing
  the two.

## [2.24.0] — 2026-08-25

### Added

- **`ActiveRow` reads structural facts from `Table` instead of re-declaring them.** This package has had
  two ways to describe a table since `ActiveRow` existed: the schema (`Table`, read by the Data Mapper
  side — `$dm->query_table($table)`) and, separately, protected methods a subclass overrides. Nothing
  checked the two agreed, so they could — and, on the largest one, did — quietly say different things
  about the same table.

  - **Delegated types.** `ActiveRow\Traits\DelegatedTypes`'s four configuration methods
    (`get_type_column()`, `get_type_path_column()`, `get_delegate_foreign_key()`,
    `get_delegated_types()`) now default to reading the bound `Table`'s own `type_column()`,
    `type_path_column()`, `delegate_foreign_key()` and `delegates([...])` — the same configuration
    `Table::DelegatedTableAdapter` (338 lines) already held, previously re-typed as ~1290 lines of
    parallel, independently-overridable methods on the `ActiveRow` side, with no connection between
    them. An explicit override in a subclass still wins exactly as before; this only changes what the
    *default* answers.

    `get_type_path_column()` is a deliberate behaviour change in the case that changes: a `Table` bound
    and configured for delegation is trusted **exactly as it answers**, including `null` (hierarchy
    paths disabled) if that `Table` never called `type_path_column()` — where the trait used to answer
    the literal string `'type_path'` unconditionally. A class with no bound `Table`, or one not
    configured for delegation, is unaffected.

  - **`$relation_classes`.** Which class wraps a relation's rows was named a second time here, separate
    from `define_relations()` (which decides what gets *fetched*) — a relation renamed in one place and
    not the other simply failed to wrap, silently. `ActiveRow::resolved_relation_classes()` now derives
    an entry for any relation `$relation_classes` did not already name: `RelationsRegistry` says what
    `Table` a relation targets, `ActiveRowRegistry` (new — see below) says which class, if any, is bound
    to that `Table`. One-to-many, many-to-one, many-to-many (`through`) and `many_polymorphic` all have
    exactly one real target and are derived. **`one_polymorphic` is deliberately excluded** — a
    `commentable` can genuinely be a `Post` *or* a `Video`, and `PolymorphicOne::get_target_table()`
    (inherited from the same base method every relation has) answers with "the first configured target,
    for compatibility", not a real single answer; deriving from it would silently mis-wrap a row as the
    wrong class. Detected by `get_targets()`, a method that exists only on `PolymorphicOne` —
    `PolymorphicMany` does not have it, which is exactly why *it* is not excluded. This distinction was
    found empirically while writing the test for it, not designed in advance: an early version used
    `many_polymorphic` for the "must not derive" case, and the test failed — correctly, because
    `many_polymorphic` turned out to be safe and the test's own expectation was the part that was wrong.

    Computed once per class and cached (`Italix\Orm\ActiveRow::$resolved_relation_classes_cache`):
    `offsetGet()` — array access, `$row['relation']` — consults this on every read to decide whether a
    key is a relation at all, and a registry round trip on every access of every plain column would be
    a cost nobody asked for. Safe to cache for the process's lifetime: the bound `Table` and its
    relations do not change after `set_persistence()`/`define_relations()` bootstrap.

  - **`ActiveRow\ActiveRowRegistry`** (new) — the reverse index both fixes above are built on: which
    `ActiveRow` subclass, if any, is bound to a given `Table`. `Persistable::set_persistence()` already
    kept a class-name → `Table` map, but a trait's static properties are **not** shared across the
    classes that use it, so there was no way to go the other direction. A plain class, not a trait,
    exists to hold exactly that one index. Matched by identity (`===`), the ordinary way this package's
    own examples already build a `Table` once and pass that variable everywhere; two separate calls that
    happen to describe the same table name are not treated as the same table — a missed derivation, not
    a wrong one.

- **`Table::timestamps()` / `Table::soft_deletes()`** — the two ActiveRow-only traits `HasTimestamps` and
  `SoftDeletes` promoted to the schema, so the Data Mapper side gets the same behaviour without needing
  `ActiveRow` at all.

  ```php
  $orders->timestamps('insert_dt', 'update_dt');   // any column names — not tied to created_at/updated_at
  $orders->soft_deletes('deleted_dt');
  ```

  Once declared, `QueryBuilder::build_insert()` fills both timestamp columns with the same instant on
  every row of an INSERT (including through `insert_many()`, which shares the same compile path), and
  `build_update()` refills the update column on every UPDATE — **unless the caller already set that
  column**, checked by key, INSERT and UPDATE alike, so a value provided on purpose is never overwritten.
  `build_delete()` compiles a DELETE against a table with `soft_deletes()` declared into an `UPDATE …
  SET deleted_dt = ?` instead — reached by every caller that goes through `DataManager::delete()`,
  because that is the one method both styles call. A real delete is still available:
  `QueryBuilder::force()` — `$dm->delete($table)->force()->where(...)->execute()`.

  `ActiveRow\Traits\HasTimestamps` needed no change: it already sets both columns in PHP before `save()`
  reaches the query builder, so the automatic fill finds them already present and does nothing further.
  **`ActiveRow\Traits\SoftDeletes` needed one line fixed**: `force_delete()`'s own `perform_hard_delete()`
  called `$dm->delete($table)->where(...)->execute()` directly — which, on a table that also declares
  `Table::soft_deletes()` (a combination that could not exist before this version), would now silently
  become the automatic soft-delete instead of the real one `force_delete()` promises. It now calls
  `->force()`, restoring the promise. Found by the mutation suite for this change, not by inspection.

- `tests/DelegatedTypesFromSchemaTest.php` (12 assertions), `tests/TimestampsAndSoftDeleteTest.php` (17),
  `tests/RelationClassDerivationTest.php` (12) — all executed, not string-compared: a row is written and
  read back to prove a timestamp was really filled or a delete really only marked; `->delegate()` and
  `->relation()` are called end to end to prove a derived class is not merely the right name in a map
  but a real, correctly-populated instance. Every mutation described above was verified by hand
  (disable, watch the relevant assertion fail, restore) against each of the three suites. Zero
  regressions across the rest of the package (all 26 suites) and the two example-based test files under
  `examples/DelegatedTypes/` and `examples/ActiveRow/`, which exercise the pre-existing override-based
  configuration this change had to leave working exactly as it did before.

## [2.23.0] — 2026-08-25

### Added

- **`check()` — `CHECK` constraints, on a column or on a table.** Filed as a Drizzle-parity gap
  (`docs/FEATURE_REQUESTS.md`) and closed the same day: this package had `unsigned()` for "not
  negative" and nothing at all for any other rule, so `CHECK (amount_cents >= 0)` could only be
  hand-written into a migration's raw SQL, invisible to everything else here the way a hand-added
  foreign key already was (2.21.1).

  ```php
  'total_cents' => integer()->unsigned()->not_null()->check('total_cents >= 0'),
  ```

  On `Column` (the schema a model queries with) and on `Migration\Blueprint`'s `ColumnDefinition`
  alike — the two column classes this package keeps side by side, `unsigned()` and `enum()` both
  already existing on both. Calling `check()` more than once adds clauses rather than replacing the
  first: `CHECK (a) CHECK (b)` reads as two rules instead of one that has to be parsed to find both.

  A rule spanning more than one column of the same row cannot attach to either column alone, so
  there is a table-level form too — `Table::add_check($name, $expression)` and
  `Blueprint::check($expression, $name)`:

  ```php
  $table->add_check('valid_shipping', 'placed_dt < shipped_dt');
  ```

  Rendered inline in `CREATE TABLE` on all three dialects — MySQL, PostgreSQL and SQLite all parse
  `CHECK` there. **Not offered via `ALTER TABLE` on SQLite**: it has no `ADD CONSTRAINT` at all, not
  a version gap like `DROP COLUMN` (2.20.0) but a categorical absence, so `Blueprint::to_alter_sql()`
  refuses outright instead of emitting SQL that would fail on every version, and says what to do
  instead (declare it on `Schema::create()`, or rebuild the table). MySQL below 8.0.16 **parses but
  silently ignores** `CHECK` — a version fact about the server this package cannot detect without a
  connection at the point a migration file is merely being rendered to text, so it is written down
  here rather than guarded against.

  The expression is schema text, not a bound value — trusted at the same level as the table name
  beside it, the same position a view's `->as_query()` definition is in (2.8.0). Runtime values
  still belong in a query's `WHERE`, bound as usual.

  **Not wired into `db:diff` or `db:pull`** yet, for the same reason foreign keys are not (2.21.1):
  said here so a silent differ does not look like a differ that found nothing.

- **`enum()` as a schema column type**, closing the other half of the same gap:
  `Blueprint::enum()` already existed for migrations, but nothing under `ColumnTypes.php` — the
  functions a model actually declares its columns with — could say a column was one of a fixed set
  of values. A migration could create an `ENUM` column; nothing describing that model afterwards
  could say what it held.

  ```php
  'status' => enum(['draft', 'placed', 'shipped', 'cancelled'])->not_null(),
  ```

  Native `ENUM(...)` on MySQL. PostgreSQL and SQLite have no equivalent column type, so there it is
  `VARCHAR(255)` plus the `CHECK (col IN (...))` above, carrying the same values — same rule,
  enforced identically, and now built on `check()` rather than duplicating it.

  **Fixed a real gap this exposed in the already-existing `Blueprint::enum()`**: on every dialect but
  MySQL it rendered `VARCHAR(255)` and stopped — its own comment said the constraint was "handled
  separately," and nothing handled it, so a PostgreSQL or SQLite `enum()` column silently accepted
  any string at all. It now emits the same `CHECK (col IN (...))`. Found by executing the existing
  call, not by reading the diff — a mutation removing the fix is exactly what the old code already
  did, and the suite catches it.

- `tests/CheckAndEnumTest.php` — 24 assertions. Rendering is asserted on all three dialects;
  enforcement is proven on SQLite by inserting a row that violates the constraint and reading back
  that it was refused and not written, rather than comparing the generated string — a `CHECK` that
  renders correctly and is never sent to the server would pass a string-only test just as happily.
  Five mutations, all detected: the column-level check silently dropped, the table-level check
  silently dropped, the `enum()` auto-check dropped on both the schema and the migration side (the
  latter reproducing the exact pre-existing gap above), and the SQLite `ALTER` refusal removed.

## [2.22.0] — 2026-08-19

### Fixed

- **A transaction this manager did not open no longer breaks nesting.** `begin_transaction()`
  counted only the transactions it had opened itself, so a connection already inside one — a test
  harness wrapping a suite, a job bracketing its work, any code sharing the PDO — still received a
  `BEGIN`. PDO answers *"There is already an active transaction"*, and the caller reads it as a bug
  in its own code; the actual cause is invisible from the stack trace.

  It now asks the connection (`Driver::in_transaction()`, which existed all along and nothing
  called) and does what nesting does everywhere else in this class: opens a savepoint inside the
  enclosing transaction. Committing releases that savepoint and rolling back rolls back to it —
  **neither ever commits or rolls back a transaction somebody else began**, because that work is
  theirs to decide about. `in_foreign_transaction()` reports the state for a caller that needs it.

  Found by an application suite whose every test runs inside a harness-owned transaction: a service
  that opened one of its own — the correct thing for it to do — could not be tested at all.

## [2.21.1] — 2026-08-18

### Documentation

- **Foreign keys** in the README: the column form (`->references('parent', 'id')`) beside the table
  form (`add_foreign_key()`), what each emits, and that `db:pull` reads either back — so a pulled
  schema carries its relationships and not only its columns.

  With the note that `references()` used to be stored and never emitted, and a table of what
  `RESTRICT` / `CASCADE` / `SET NULL` are each right for. `add_foreign_key()` defaults to `CASCADE`,
  which is the one worth thinking about hardest: on a tenant key it erases a customer's data because
  somebody removed their account row.

- **What `db:diff` does not compare**: foreign keys. The `add_foreign_keys` and `drop_foreign_keys`
  keys in a diff are always empty, so a constraint added or dropped by hand is invisible to it. Said
  out loud because a tool that is silent looks exactly like a tool that found nothing.

## [2.21.0] — 2026-08-18

### Added

- **`unsigned()` on a column.** The schema could not say it, so a database full of `INT UNSIGNED`
  identifiers had no way to be described — and `db:diff` could not check the one property that was
  only true in a migration.

  ```php
  'id'        => serial()->unsigned(),
  'agency_id' => integer()->unsigned()->not_null(),
  ```

  Rendered on MySQL and SQLite; **dropped on PostgreSQL**, which has no unsigned integer type at all.
  Not approximated with a `CHECK (col >= 0)`: a constraint nobody wrote is a constraint nobody expects
  to find, and `db:pull` would report it as drift forever after.

  For the same reason the differ compares it on MySQL and SQLite and **not** on PostgreSQL — there the
  difference is real, permanent, and closeable by no migration, so reporting it would be crying wolf
  on every run.

  On SQLite an auto-increment primary key stays plain `INTEGER`: only that exact spelling is a rowid
  alias, and `INTEGER UNSIGNED PRIMARY KEY AUTOINCREMENT` is not one.

- `db:pull` reads it back — from the MySQL catalogue, which reports it separately, and from SQLite's
  declared type, which keeps the word verbatim.

- `canonical_type()` now strips `unsigned` before mapping: signedness is a property of the column kept
  beside the type, and left inside it every unsigned column looked like a type this package had never
  heard of. Found immediately, by the diff going noisy the moment the models started saying it.

- The round trip carries an unsigned column on all three dialects, and the diff suite asserts both
  halves: reported on MySQL and SQLite, ignored on PostgreSQL. Four mutations, all detected.

## [2.20.0] — 2026-08-18

### Fixed

- **`generate_migration_from_diff()` wrote a stub for a missing table** — `$table->id();
  $table->timestamps();` and nothing else — which is worse than writing nothing: it looks like a
  migration and creates the wrong table. It now emits the real columns, taken from the declaration
  the diff was made against (the method takes them as a second argument).

- **`type_to_method()` fell back to `string()`** for anything it had no entry for, so a `uuid`, a
  `blob` or a `jsonb` became a `VARCHAR(255)` in a generated migration and looked right doing it. The
  map is complete, and a type it does not know is now refused.

- **`Schema::table()` sent `ALTER TABLE … DROP COLUMN` to SQLite versions that do not have it**
  (3.35 added it) and handed back `near "DROP": syntax error`, which says nothing about the version
  or about the rebuild-and-copy that is the actual answer.

### Added

- **`ix db:diff --migration`** — the fix-forward half. Writes a numbered migration into
  `migrations/` and stops: a generated migration is a draft until somebody has read it.

  | | |
  |---|---|
  | a missing table | `Schema::create()` with its actual columns |
  | a missing column | `$table->…()` with type, nullability and default |
  | a column the declaration dropped | **commented out** — it holds data |
  | a changed type or length | a comment saying what changed; converting is dialect-specific and can lose data |
  | a table nobody declared | a comment: it may be a library's, and `ix_session` is nobody's model |

  `down()` reverses what `up()` actually did, and cannot reverse what it only proposed — which is one
  more reason those stay comments.

- **The other direction, printed rather than written.** For every column in disagreement, `ix db:diff`
  now also prints the declaration the database implies (`'id' => bigint()->primary_key()->auto_increment(),`)
  so it can be pasted into the model when the database is the side that was right.

  Rewriting models automatically is deliberately not offered: a model carries relations, delegated
  types and the comment explaining why a column is what it is, and regenerating it from the database
  destroys exactly the part the database does not know. Which side is right is a question about the
  application, so the command shows both ways and decides neither.

- The suite generates a migration, **runs it through the `Migrator`, and asserts the diff it was
  written from is then empty** — a generator is worth what the thing it generates does. Four
  mutations, all detected: the stub table, an uncommented `drop_column`, an unknown type guessed as a
  string, and the SQLite drop sent anyway.

## [2.19.0] — 2026-08-18

### Added

- **`QueryLog`** — `$dm->use_query_log(new QueryLog(0.1))`, then `queries_n()`, `total_seconds()`,
  `slow()` and `repeated()`.

  It counts before it flags, which is the whole design. "The page is slow" is answered by *31
  queries* far more often than by any single query being slow, and a log that keeps only the slow
  ones cannot say that. `repeated()` sharpens it: an N+1 is one statement in a loop, a hundred times,
  **none of them slow on its own** — no threshold ever sees it, and the example prints exactly that
  case.

  **Bound values are not kept by default.** A record holds the statement, the number of values, and
  the time. A query log is written to a file, shipped to a log service, pasted into a ticket, and the
  bound values are the password being hashed and the tax code — keeping them by default is leaking
  them by default. `remember_values()` turns it on and says so in its name.

  Covers the raw helpers and the builder alike. A cache hit is **not** counted: it never reached the
  server, and counting it would hide what the cache is there to show.

- **`explain()`** on any query, returning an `ExplainResult`: `rows()` as the server gave them,
  `(string)` as text, and `has_full_scan()`.

  The plan is deliberately not normalised — three servers describe their work in three vocabularies,
  and flattening them into one means inventing a fourth that none of them speaks. The one question
  worth asking automatically is normalised, and each spelling was measured: `SCAN TABLE t` on SQLite,
  `type: ALL` on MySQL/MariaDB, `Seq Scan` on PostgreSQL. A SQLite `SCAN … USING COVERING INDEX` is
  **not** counted — it reads an index end to end rather than the table, and calling that a table scan
  sends somebody looking for an index that is already there.

  `explain(true)` asks for `EXPLAIN ANALYZE` and is refused on anything but a `SELECT`: on PostgreSQL
  that form runs the statement, so `EXPLAIN ANALYZE DELETE …` deletes. There is a test that the
  refusal happens and one that plain `EXPLAIN` leaves the rows alone.

- `tests/ProfilingTest.php` — 20 assertions on SQLite, 33 with MySQL and PostgreSQL configured, each
  full-scan spelling checked against the server that says it. Four mutations, all detected — values
  kept by default, a cache hit counted, SQLite index scans counted as table scans, and analyze allowed
  on a write.

- `examples/profiling_example.php`, which produces a 31-query page where nothing is slow.

## [2.18.0] — 2026-08-18

### Fixed

- **On MySQL, `db:pull` described every view as a table.** `get_tables()` used `SHOW TABLES`, which
  lists views alongside tables, so a pull emitted `mysql_table('some_view', …)` — code that recreates
  a view as a table full of nothing. It now asks `information_schema` for `BASE TABLE` only.

  SQLite and PostgreSQL were already excluding them, which is why this needed a server to find: the
  round trip passes an explicit table list, so the bug lived in the one path the test did not walk.
  There is now an assertion that asks `get_tables()` itself.

### Added

- **Views in `db:pull`.** `SchemaIntrospector::get_views()` and `generate_view_code()` — a view comes
  out as `sqlite_view()` / `mysql_view()` / `pg_view()` with its columns and `->as_query($definition)`,
  after the tables it reads.

  This closes something `View`'s own docblock claimed: *"a view whose declaration and definition
  disagree is a bug, and one `ix db:pull` would surface"*. Until now it would not have.

- **PostgreSQL materialized views**, as `pg_materialized_view()`. Their columns come from
  `pg_attribute`: measured, a materialized view appears in **neither** `pg_tables` nor
  `information_schema.columns`, so every ordinary route describes it as having no columns at all.

- Each server hands back a definition it rewrote for itself, and none returns what you typed:

  | | |
  |---|---|
  | SQLite | the original `CREATE VIEW …`, verbatim — the preamble is stripped |
  | MySQL / MariaDB | normalised, **with the database name written into every reference** — stripped here, because a definition naming the database it came from only works in a database with that name |
  | PostgreSQL | normalised, re-indented, casts made explicit (`'a'::text`) |

- The round trip now carries a view on all three dialects and a materialized view on PostgreSQL: 43
  assertions with every server configured. Four mutations, all detected — `SHOW TABLES` again, views
  left out of the generated file, a materialized view read the ordinary way, and the database
  qualification left in.

## [2.17.0] — 2026-08-18

### Fixed

- **`push($tables, force: true)` dropped every table it was not given.** `diff()` calls any table it
  did not receive a "drop", because it cannot tell a partial declaration from a complete one — and
  passing *part* of a schema is the ordinary case. One flag meant both "apply the destructive changes
  you showed me" and "delete the rest of the database".

  It is now two: `force` drops the columns a declaration no longer has, and dropping tables the
  declaration does not mention needs `drop_undeclared` **as well**. `PushCommand` gained
  `--drop-undeclared` to match.

  Found by running a test written to check that push was safe against a development database with 21
  tables in it. 17 went. The backup was good, the restore matched the manifest row for row, and the
  destructive half of that suite now runs only against SQLite in memory — a database the test makes
  and throws away, which no environment variable can point somewhere else.

- **A schema that matched the database was reported as different**, on every dialect, which is the
  one thing a differ must never do: nobody reads the tenth false alarm, and the real difference is in
  it.

  | | |
  |---|---|
  | every primary key | SQLite reports `notnull = 0` for `INTEGER PRIMARY KEY`, and a declared `primary_key()` does not set `not_null()`. A key is now not-nullable on both sides. |
  | `boolean()` on MySQL | declared `BOOLEAN`, stored and reported as `TINYINT(1)`. The type is now compared **as it would be created here**, via `Column::sql_type($dialect)`. |
  | `datetime()` on PostgreSQL | `TIMESTAMP` there, `DATETIME` on MySQL — same story, same fix. |
  | `decimal()` on PostgreSQL | reported as `numeric`; they are one type on all three servers, so they now canonicalise to one name. |
  | `json()` on MariaDB | implemented as `LONGTEXT` and reported that way, so a JSON column looked like a text column from the outside. Known equivalence rather than a difference. |

- **`SchemaDiffer::column_to_definition()` called `Column::get_default_value()`, which does not
  exist** — a fatal the moment any column had to be added, which is the most ordinary diff there is.

- **A column that would be dropped was not reported at all** without `force`: no entry in `skipped`,
  no output, nothing. Silence is the one answer that guarantees nobody decides.

- **`ALTER TABLE … DROP COLUMN` reached SQLite versions that do not have it** (added in 3.35) as a raw
  syntax error. The push now says which version it is talking to and that rebuilding the table is a
  migration, not something to do on the way past.

### Added

- `SchemaIntrospector::canonical_type()` — one authority on "what factory is this server's type",
  used by the generator to write a schema and by the differ to compare one. Two answers to "is
  `TINYINT(1)` a boolean" is one answer too many.

- `Column::sql_type($dialect)`, public: comparing a declaration against a live database means asking
  what it *would be created as*, not what the developer typed.

- `tests/SchemaDiffTest.php` — 25 assertions on SQLite, 63 with MySQL and PostgreSQL configured. The
  1,300 lines of differ and pusher had one assertion between them before this: that the constructor
  does not throw. Four mutations, all detected — one flag again, primary keys nullable again, the
  type compared as declared, and column drops going unreported.

- `App\Console\DbDiffCommand` in this project (`ix db:diff`): reads every model under `src/Models`,
  asks the database what it has, prints the differences, and **cannot write** — `db:push` is
  deliberately not exposed here, because schema changes in this application go through `ix migrate`
  and its ledger, which a push would walk straight past.

## [2.16.0] — 2026-08-18

### Added

- **Reading inside a JSON column.** `json_text()`, `json_get()`, `json_length()`, `json_has()`,
  `json_missing()`, `json_contains()`, `json_not_contains()` — one path syntax in (`$.meta.age`,
  `$.tags[0]`, `$."odd,key"`), three renderings out.

  The package could declare `json()` and `jsonb()` columns and offered no way to ask anything about
  what was in them. The same gap the comparison operators had in 2.9.0: a type you can write down and
  cannot use.

  | | SQLite | MySQL / MariaDB | PostgreSQL |
  |---|---|---|---|
  | text at a path | `json_extract(c, ?)` | `JSON_UNQUOTE(JSON_EXTRACT(c, ?))` | `c #>> ?::text[]` |
  | JSON at a path | `json_extract(c, ?)` | `JSON_EXTRACT(c, ?)` | `c #> ?::text[]` |
  | array length | `json_array_length(c, ?)` | `JSON_LENGTH(c, ?)` | `jsonb_array_length(c #> ?::text[])` |
  | path exists | `json_type(c, ?) IS NOT NULL` | `JSON_CONTAINS_PATH(c, 'one', ?)` | `(c #> ?::text[]) IS NOT NULL` |
  | containment | **refused** | `JSON_CONTAINS(c, ?)` | `c @> ?::jsonb` |

  Measured on SQLite 3.31, MariaDB 10.3 and PostgreSQL 12 before a line was written, and every row of
  that table is a decision in the code:

  - **`json_text()` unquotes and `json_get()` does not.** MySQL's `JSON_EXTRACT` returns JSON —
    `"Ada"`, quotes included — so a comparison against `'Ada'` matches nothing and reports nothing.
    They are two functions because they are two questions.
  - **The `->` / `->>` operators are not used**, though MySQL 5.7 and SQLite 3.38 have them: this
    SQLite and this MariaDB both answer them with a syntax error, and the function forms work
    wherever the operators do.
  - **PostgreSQL's key-exists operator is `?`, which PDO takes for a placeholder** — `doc ? 'name'`
    arrives as `doc $1 'name'`. It can be escaped `??`; `json_has()` uses `(doc #> path) IS NOT NULL`
    instead, which needs no escaping and answers for a nested path rather than a top-level key. There
    is a test that the `?` form really does fail, so the reason stays visible.
  - **Containment is refused on SQLite** rather than approximated — no `@>`, no `JSON_CONTAINS()`, and
    no rewrite that means the same for an arbitrary document. Same choice as `distinct_on()`.
  - **Every PostgreSQL path segment is quoted.** An unquoted `text[]` literal ends at the first comma,
    so `{odd,key}` looks for `odd` then `key` while the document has a key called `odd,key`. The path
    is bound as a parameter on all three dialects; no part of it is interpolated.

- `tests/JsonTest.php` — 56 assertions when all three servers are configured, 17 on SQLite alone. The
  same body of assertions runs against each dialect, because every failure mode here produces valid
  SQL that answers about something else. Four mutations, all detected — dropping `JSON_UNQUOTE`,
  unquoting the PostgreSQL path segments, accepting any path, and letting SQLite containment through.

- `examples/json_example.php`, which prints the three renderings of one expression side by side.

## [2.15.0] — 2026-08-18

### Fixed

- **`db:pull` returned nothing on PostgreSQL, and said nothing about it.** Every introspection query
  used libqp's `$1` placeholders, which PDO does not parse — the same defect class as 2.12.0, in the
  one part of the package that had never been run against a server. A pull came back describing
  tables with no columns.

- **What a pull lost on every dialect.** The mapping sent `date` and `datetime` to `timestamp()`,
  `char` to `varchar()`, `float` and `double` to `decimal()`, `json` and `jsonb` to `text()`, and
  `uuid` and `time` to `varchar()` — each one a column this package can express exactly, described as
  something else. `bigserial` came back as `serial`.

- **Constraints were dropped entirely.** `generate_table_code()` emitted columns and nothing else: no
  `unique()`, no `references()`, no indexes. On SQLite the unique constraints were invisible anyway,
  because the introspector skipped `sqlite_autoindex_*` — which is the only place SQLite records
  them.

- **The import line was a fixed string**, so a generated file imported names it did not use and
  missed the ones it did: a fatal error the first time it was included. It is now read back out of
  the code that was just generated.

- **`->references()` never reached the DDL.** The reference was stored, used to resolve relations, and
  left out of `to_create_sql()` — so the foreign key a developer declared did not exist in the
  database, and nothing said so. Found by the round trip, not by reading the code.

- **`double_precision()` was emitted verbatim on MySQL**, underscore and all, which the server
  answered with a syntax error pointing at the following line. And `real()` was emitted as `REAL`,
  which MySQL treats as a **synonym for DOUBLE** — so a four-byte float silently became eight and came
  back as `double_precision()`. It is now `FLOAT`, which means there what `REAL` means elsewhere.

- **A default of "now" is spelled differently by every server** — `CURRENT_TIMESTAMP` on SQLite,
  `current_timestamp()` on MariaDB, `now()` on PostgreSQL — and any of them quoted as a string is a
  default the server then refuses (`Invalid default value`). All three are recognised, and PostgreSQL's
  `'draft'::character varying` is unwrapped to `draft` rather than stored with its cast.

- **SQLite declared types were collapsed** to `INTEGER`/`TEXT` on the way in. SQLite stores by
  affinity and accepts any type name, so the name it is given is the name it keeps: collapsing them
  changed nothing about storage and lost every `bigint()`, `datetime()`, `json()` and `uuid()` in the
  schema.

- The generated file now orders indexes **by name** rather than by creation order, so the same schema
  produces the same file — most of the reason to generate one is to diff it.

### Added

- `tests/IntrospectionTest.php` — a **round trip**: a real database → pulled PHP → a second database
  → pulled PHP, and the two must be identical. Run against SQLite, and against MySQL/MariaDB and
  PostgreSQL when configured (`IX_MY_*`, `IX_PG_*`); 31 assertions with all three.

  This is the only test that can prove a generator: reading what it wrote proves nothing about what
  it dropped. Every fix above was found by it, in the order it found them — the mapping first, then
  the missing DDL, then MySQL's synonyms, then the defaults. Five mutations, all detected.

- `App\Console\DbPullCommand` in this project (`ix db:pull [tables…] [--output=FILE]`), so the
  library's `db:pull` is reachable from the application's own connection. Its use is the second
  opinion: the file describes the database **as it is**, and a diff against `src/Models` is where a
  column added by hand or a migration nobody re-ran shows up.

## [2.14.0] — 2026-08-18

### Added

- **`insert_many()`** — many rows in few statements, and all of them in one transaction.

  Measured on this project's MariaDB, 5,000 rows:

  | | |
  |---|---|
  | one `INSERT` at a time | **273 s** |
  | one at a time, inside one transaction | **1.71 s** |
  | `insert_many()`, chunks of 500 | **1.18 s** |

  Worth reading twice, because it is not the number anyone expects: **batching is the smaller half**
  — 1.5× — and the transaction is the other 160×. Without one, every row is its own transaction and
  the database flushes to disk 5,000 times. This method gives you both, which is the argument for it
  existing rather than being a paragraph of advice.

  A failure half way therefore leaves nothing behind rather than half an import, and nesting is safe
  (inside a caller's transaction it opens a savepoint). Rows naming different columns are refused
  rather than reconciled — a multi-row `INSERT` has one column list, and filling the gaps with `NULL`
  writes something nobody asked for; the check runs over every row **before** the first statement, so
  a bad row at the end does not leave the good ones at the start behind.

  The chunk size is a size, not a limit discovered from a server: what bites is statement and packet
  size, not a placeholder count. Measured — this MariaDB accepted 90,000 placeholders in one
  statement and this SQLite 150,000, so a hard-coded "999" copied from folklore would have been wrong
  in both directions.

- **Read replicas.** `$dm->use_replicas(Driver::mysql($replica_config))` sends reads to a copy and
  writes to the primary.

  A replica is always a little behind, and how far is not something the application can see. The rest
  of the design is about keeping that from becoming a bug:

  | | |
  |---|---|
  | a read **after a write** | the primary — and not just the next read: every read until `resume_replica_reads()` |
  | a read **inside a transaction** | the primary; the rows it has written exist nowhere else yet |
  | `$dm->execute()` with raw SQL | assumed to have written. It cannot tell from the text, and guessing is how a `WITH … INSERT` lands on a replica |
  | several replicas | one picked at random per read |

  Saving a form and rendering the page that shows it is the most common thing an application does,
  and a replica that has not caught up shows the value from before the edit — from which the user
  concludes the save did not work. That is why the pin is sticky rather than per-query, and why a
  long-lived worker should call `resume_replica_reads()` between jobs.

  `on_primary(callable)` pins the reads that must be current whatever the lag — a balance before a
  payment, a check before an insert — and lifts the pin afterwards, including when the callback
  throws, without un-pinning a manager that had already written.

  Nothing here checks whether a replica is healthy or how far behind it is. That is a decision for
  the deployment, and an ORM pretending to make it would be pretending to know something it cannot.

- `tests/BatchTest.php` (19 assertions) and `tests/ReplicaTest.php` (23), plus
  `examples/batch_and_replica_example.php`.

  The replica suite gives the two databases **deliberately different contents**, which a real pair
  would never have. That is the point: identical copies make routing invisible, and a routing bug
  invisible with it. Five mutations, all detected — dropping the transaction, checking only the first
  row's columns, a write no longer pinning reads, a transaction no longer forcing the primary, and
  `on_primary()` not restoring the flag it found.

## [2.13.0] — 2026-08-18

### Added

- **Validation rules derived from the schema.** `SchemaRules::for_table($users)` returns a schedule
  of `RuleMeta` — `required` from `not_null()`, `max_length` from `varchar(n)`, `integer`, `numeric`,
  `date`, `uuid`, `unique` and `exists` — which `Italix\Rules\Checker` runs unchanged.

  `varchar(50)` is already a promise that a longer value will not survive the insert. Writing that
  promise a second time in a form definition is how the two drift apart: the column grows to 100 and
  the form keeps refusing at 50.

  **Neither package gained a dependency.** The schedule is `Italix\Contracts\RuleMeta`, which
  `italix/orm` already required — an ORM has no business depending on a validator, and the vocabulary
  they share was already in contracts. Deliberately not derived: primary keys (the database fills
  them), `text()`/`blob()` (no length to check), `boolean()`/`json()` (no rule in the shared
  vocabulary means either), and **composite unique constraints** — the *pair* is unique, and emitting
  `unique` per column would refuse rows that are perfectly legal.

- **`DatabaseRules`** settles the two rules a checker deliberately leaves open. `unique` and `exists`
  need a database; `Italix\Rules` reports them as deferred rather than passing them, and this is the
  other half. `['id' => 42]` is the parameter that keeps an edit form from failing its own uniqueness
  check when the address is left alone. Table and column names arrive as rule parameters and cannot
  be bound as values, so they are checked to be identifiers and quoted; anything else is refused.

- **`QueryCache`** — `$dm->use_query_cache(new QueryCache($cache, 300))`, then `->cached()` on any
  select.

  Caching is easy; knowing when to stop is the problem, and getting it wrong does not raise — it
  serves a row the user just changed themselves. Each table carries a **generation**: a random token
  in the cache, mixed into the key of every query that reads it. A write through this package
  replaces the token, retiring every answer about that table at once, however many there are.

  A token rather than a counter, deliberately: a counter whose key expired would restart at 1, and
  entries written under the *previous* generation 1 would come back — stale, and by then trusted.

  | | |
  |---|---|
  | a write through this package | retired automatically |
  | raw SQL through `$dm->execute()` | **not seen** — `$dm->query_cache()->invalidate('t')` |
  | another process, or a trigger | **not seen** — the lifetime is the only bound |
  | a table reached only inside a subquery | **not tracked** unless named: `->cached(300, ['tags'])` |

  `cached()` on a write, or with no cache on the manager, raises rather than running quietly
  uncached: a caching call that silently does nothing is a decoration.

- `tests/SchemaRulesTest.php` (39 assertions) and `tests/QueryCacheTest.php` (27), plus
  `examples/bridges_example.php`, which runs both end to end.

  The rules suite feeds its derived schedule to the **real** `Italix\Rules\Checker` when the package
  is installed. That is not belt and braces: a rule *name* this package invents fails loudly, but a
  wrong **parameter key** — `max` where the checker reads `length` — would compare against nothing and
  pass everything. Four mutations, all detected, that one included.

  The cache suite probes by changing the table **behind the cache's back** with raw SQL: if a later
  read still shows the old rows the answer came from the cache, and if it shows the new ones the cache
  let go. Nothing else tells a cache that is never consulted from one that is never invalidated.

### Changed

- Requires `italix/contracts ^1.7`, for the new `Cache` interface. `italix/rules` and `italix/cache`
  are **suggestions**, not dependencies.

### Note

- A fifth mutation — storing a generation with a lifetime instead of forever — is *not* a correctness
  failure, and the comment claiming it was has been corrected: a token that vanishes is replaced by a
  new random one, so nothing stale can be reached. It is a cost failure (every key for the table
  changes at once), which is why the generation is still stored without an expiry, and why the test
  asserts that intent rather than a staleness that cannot happen.

## [2.12.0] — 2026-08-18

### Fixed

- **Every parameterised query on PostgreSQL returned no rows, and no error.**

  The builder emitted libpq's numbered placeholders — `WHERE name = $1` — and **PDO does not parse
  that form.** It binds nothing to it; PostgreSQL receives a parameter nobody set; the comparison
  yields NULL; the query comes back empty. Nothing raises. Measured on PostgreSQL 12, at the PDO
  level and through this package:

  ```
  builder rows: 0        SELECT * FROM "t" WHERE "t"."name" = $1     -- 'ada' bound
  raw rows:     1        SELECT * FROM  t  WHERE  name       = ?     -- 'ada' bound
  ```

  So `?` is now emitted on every dialect — the only positional form PDO understands — in
  `QueryBuilder`, `Operators`, `RelationalQueryBuilder`, `PostgresqlDialect::get_placeholder()`, and
  `Sql::execute()`, which converted to `$1` just before preparing. `Sql::to_postgres()` stays for
  code talking to libpq directly (`pg_query_params()`), which is the only place the form belongs, and
  its docblock now says so.

  **Why it survived a test suite.** There were tests — eight of them across four files — and they
  asserted the presence of `$1`. They were checking that the package followed a convention, and the
  convention was the bug. Rendered SQL cannot answer whether a value arrived; only a server can. They
  are rewritten to assert `?` and, where it matters, the **order** the values bind in.

  This is a behavioural change for anyone who had worked around it, and a straight fix for everyone
  else. MySQL, MariaDB and SQLite were never affected: they were on `?` already.

- `tests/PostgresTest.php` — 20 assertions **executed against a real PostgreSQL**, which this package
  had never been. It skips without one (`IX_PG_HOST`, `IX_PG_DATABASE`, `IX_PG_USER`, …), and covers
  the path that was broken end to end: `WHERE`, `IN`, `BETWEEN`, `LIKE`/`ILIKE`, `INSERT … RETURNING`,
  `UPDATE`, `DELETE`, an aggregate in `HAVING`, a subquery, a window function, `DISTINCT ON`, a
  cursor, keyset paging, and the raw `sql()` builder.

### Added

- **Materialized views** (PostgreSQL). `pg_materialized_view($name, $columns)->as_query($select)`,
  plus `Schema::create_materialized_view()`, `create_materialized_view_if_not_exists()`,
  `create_or_replace_materialized_view()`, `refresh_materialized_view($view, $concurrently)`,
  `drop_materialized_view()`, `has_materialized_view()`, `is_materialized_view_populated()` and
  `get_materialized_views()`.

  A plain view runs its query every time you read it; this one stores the answer, which is the point
  and also the catch — **the rows are as old as the last refresh**, and refreshing is the
  application's decision, not SQL's.

  Measured on PostgreSQL 12, and each fact is in the code as a decision:

  | | |
  |---|---|
  | `CREATE OR REPLACE MATERIALIZED VIEW` | **does not exist** — syntax error. Replacing is always `DROP` then `CREATE`. |
  | `CREATE MATERIALIZED VIEW IF NOT EXISTS` | works, and is offered here — unlike on a plain view, this type is PostgreSQL by construction, so there is no dialect ambiguity to trip over. |
  | `WITH NO DATA` | works, and reading the view before its first refresh is then an **error**, not an empty result. `is_materialized_view_populated()` exists to ask first. |
  | `REFRESH … CONCURRENTLY` | needs a unique index and a populated view — hence `add_unique_index()`, whose statements `create_materialized_view()` runs as part of creating the view rather than leaving for later. |
  | `DROP VIEW` on one | `"x" is not a view`. They do not share a `DROP`, or a catalogue: `pg_matviews`, not `pg_views` — so `has_view()` delegates rather than answering "no" about something plainly there. |

  Building one for MySQL or SQLite is refused at construction: neither has materialized views, and
  the substitute — a real table plus a job that refills it — is a different thing, worth writing as
  one.

- `tests/MaterializedViewTest.php` — 30 assertions, 24 of them against a real server, including the
  two that only a server can settle: that a row inserted after creation does **not** appear until a
  refresh, and that reading a `WITH NO DATA` view raises rather than returning nothing.

## [2.11.0] — 2026-08-18

### Added

- **Reading a result without holding all of it.** `execute()` calls `fetchAll()`, which is right
  until the array itself is the problem — and then it is not subtle: the export dies at the memory
  limit having produced nothing.

  | | |
  |---|---|
  | `cursor()` | One statement, rows yielded as they arrive. Measured on 50,000 rows: **21.8 MB → 0.0 MB**. |
  | `each($handler)` | The same as a callback; returning `false` stops early. |
  | `chunk_by($key, $size, $handler)` | Keyset paging — `key > <last seen>`, one bounded query per page. |
  | `chunk($size, $handler)` | Offset paging, for when there is no single key to page on. |

  `DataManager::cursor($sql, $params)` does the same for raw SQL.

  **`chunk()` refuses to run without an `ORDER BY`.** `LIMIT 10 OFFSET 10` on an unordered query is
  not an error, but the server may order the pages differently each time, so one page repeats rows
  the last one had and others never arrive. Nothing reports that, and it looks like data that went
  missing on its own.

  **`chunk_by()` is the one to reach for.** It asks for the next keys rather than counting rows to
  skip, so the last page costs what the first did and concurrent writes cannot shift the window. From
  `examples/large_result_example.php`, a job that processes rows and deletes them as it goes:

  ```
  chunk_by():  50000 of 50000 rows processed
  chunk():      6500 of 50000 rows processed — OFFSET moved under the deletions
  ```

  The key must be unique, ordered, and among the selected columns — the last is checked, because
  otherwise paging has nothing to continue from and would silently restart at the beginning.

- `tests/IterationTest.php` — 29 assertions. Mostly about *which rows come back*, since every failure
  mode here is a working query with a wrong answer; the delete-while-paging pair asserts both that
  `chunk_by()` sees all 25 rows and that `chunk()` does not.

  Four mutations, all detected. The one that nearly got away: a `cursor()` that calls `fetchAll()`
  and yields from the array passes every behavioural assertion while saving nothing, so there is now
  a memory measurement — taken **at the first row**, because such a cursor allocates before the first
  yield and frees again before the loop ends, leaving nothing to see afterwards.

- `examples/large_result_example.php` — the numbers above, produced rather than quoted.

### Fixed

- `each()` under-reported by one when the callback stopped the loop: the row it had just been handed
  was not counted. It now counts before the verdict, the way `chunk()` does.

## [2.10.0] — 2026-08-18

### Added

- **Window functions.** `row_number()` `rank()` `dense_rank()` `percent_rank()` `cume_dist()`
  `ntile($n)` `lag()` `lead()` `first_value()` `last_value()` `nth_value()`, plus `->over()` on any
  aggregate and `window_call($name, …)` for anything else. Each takes `->partition_by(…)`,
  `->order_by(…)`, a frame and `->as('name')`.

  This is what answers the questions `GROUP BY` cannot, because grouping throws away the rows you
  still want: the three largest orders **of every** customer, each row beside its group's running
  total, the gap since the previous payment.

  Two places in this package already told you to reach for one — `distinct_on()` when you are not on
  PostgreSQL, and the note in relation loading about capping children in PHP — and until now there
  was nothing to reach for.

  ```php
  row_number()->partition_by($orders->customer_id)->order_by(desc($orders->placed_dt))->as('n')
  sql_sum($orders->total)->over()->order_by($orders->placed_dt)
      ->rows_between('unbounded preceding', 'current row')->as('running')
  lag($orders->total, 1, 0)->partition_by($orders->customer_id)->order_by($orders->placed_dt)
  ```

  **Frames are checked, not interpolated.** Each bound is `unbounded preceding`, `unbounded
  following`, `current row`, `N preceding` or `N following`; anything else is refused, including a
  frame that runs backwards. It is the one place in a window where free text would let arbitrary SQL
  through, and there are only five shapes worth having. `SqlFunction` likewise refuses a name that is
  not an identifier — it is not a second `raw()`.

  **`->over()` moves an aggregate's alias outward**, since `SUM(x) AS t OVER (…)` is not a statement
  any engine accepts.

  Nothing emits a window function on your behalf. They need SQLite 3.25, MySQL 8.0 or MariaDB 10.2 —
  a floor this package does not otherwise impose — so eager loading still caps children in PHP, and
  the note there now says why rather than describing an optimisation that did not exist.

- `tests/WindowTest.php` — 27 assertions, executed against SQLite and read back, including the two
  behaviours that are wrong answers rather than errors: `RANK` skipping after a tie where
  `DENSE_RANK` does not, and `LAST_VALUE` without a frame being the current row rather than the last
  of the partition. The suite skips itself below SQLite 3.25 rather than asserting about the server.

  Four mutations, all detected — dropping the frame, dropping `PARTITION BY`, passing frame bounds
  through unchecked, and rendering the window before the function's own arguments. The last needed a
  test where **both** sides bind a value; without one it survived, which is exactly the kind of
  passing suite that proves nothing.

- `examples/window_functions_example.php` — top N per group, a running total and the previous row,
  all executed, with the generated SQL printed at the end.

### Changed

- `distinct_on()`'s refusal on MySQL/SQLite now names the rewrite that exists here (`row_number()`
  ranked in a subquery, filtered outside) instead of describing one in the abstract.

## [2.9.0] — 2026-08-18

### Added

- **Either side of a condition can be an expression.** The operators were typed to take a `Column`
  and nothing else, which is not a missing convenience — it left `HAVING SUM(total) > 1000`, the most
  ordinary question a `GROUP BY` is asked, with **no form at all**. `gte(raw('total'), 1000)` was a
  `TypeError`, and the README shipped exactly that as its `HAVING` example (see 2.8.0), which is how
  this was found.

  `eq` `ne` `gt` `gte` `lt` `lte` `between` `not_between` `like` `not_like` `ilike` `not_ilike`
  `in_` `not_in_` `is_null` `is_not_null` — and the `sql_*` aggregates — now take a `Column` **or**
  any `SQLExpression`:

  ```php
  ->having(gt(sql_sum($orders->total), 1000))          // HAVING SUM(total) > 1000
  ->where(eq(raw('LOWER(email)'), $typed_email))       // WHERE LOWER(email) = ?
  ->where(gt(sub($total_spend), 10000))                // WHERE (SELECT …) > ?
  ->where(in_(raw('LOWER(status)'), ['active', 'trial']))
  ->having(gt(sql_sum(raw('quantity * unit_price')), 500))
  ```

  A subquery is parenthesised, because `(SELECT …) > 5` is the only form SQL takes there. Everything
  else is emitted as written — an aggregate is already a function call, and `raw()` means raw.

  The part that had to be got right is **binding order**: a left operand can now carry parameters of
  its own, and placeholders are positional, so it renders before the right-hand value does. Getting
  that wrong produces no error at all — just a query that runs and answers about something else.

- `tests/OperandsTest.php` — 23 assertions, executed against SQLite and read back rather than
  compared as strings, plus the PostgreSQL numbering of a left operand's bindings. Three mutations,
  all detected: dropping the subquery's parentheses, binding the right-hand value first, and
  reverting the aggregate to a column-only path.

### Changed

- `Comparison`, `InExpression`, `BetweenExpression`, `LikeExpression` and `NullExpression` renamed
  their protected `$column` to `$operand`, which is what it now holds. Constructor and factory
  parameter names are unchanged, so named arguments and every existing call site still work.

## [2.8.0] — 2026-08-18

### Added

- **Views.** `sqlite_view()` / `mysql_view()` / `pg_view()` / `supabase_view()` declare one the way
  you declare a table, and `->as_query($select)` gives it the query it stands for. Migrations get
  `Schema::create_view()`, `create_or_replace_view()`, `drop_view()`, `has_view()` and `get_views()`.

  A `View` **is a** `Table`, so the query builder, the relation loader and `describe_columns()` take
  it unchanged. That is the point: no second code path to drift from the first.

  Three things this gets right that are easy to get wrong, each measured rather than recalled:

  | | |
  |---|---|
  | `CREATE OR REPLACE VIEW` | PostgreSQL, MySQL, MariaDB yes — **SQLite no** (`near "OR": syntax error`), so it drops first. `to_replace_sql()` returns however many statements the dialect needs. |
  | `CREATE VIEW IF NOT EXISTS` | SQLite and MariaDB yes, MySQL and PostgreSQL no — and MariaDB reports its dialect as `mysql`. Deliberately **not offered**: a method that works on your server and fails on the deploy target is worse than one that does not exist. |
  | writing to a view | Refused here, before the statement is sent. Whether a view is updatable depends on the engine, the `SELECT`, and on MySQL the algorithm the optimiser picked; the message names the line in *your* code instead of arriving as a server error about SQL you did not write. |

  `CREATE VIEW` is DDL and binds nothing, so a definition's values are rendered into the statement by
  an encoder that accepts null, booleans, numbers, strings and `DateTimeInterface` and **refuses
  anything else** rather than guessing. Strings are escaped per dialect — including MySQL's
  backslash, which SQLite and PostgreSQL must *not* have doubled — and placeholders are replaced only
  where they are SQL syntax, so a `?` inside a string literal in a raw fragment stays a `?`.

  A view definition is schema, written by the developer at the same trust level as the table name
  beside it; runtime values still belong in the `WHERE` of the query that reads the view, bound as
  usual.

- `tests/ViewTest.php` — 54 assertions. The behavioural ones execute against SQLite and read the rows
  back, because an escaping bug that quietly selects nothing passes any test that only compares
  strings; the dialect ones assert the MySQL and PostgreSQL renderings, which are the only thing a
  SQLite-only run can say about a deployment. The MySQL path was additionally run end to end against
  a real MariaDB 10.3 server: create, replace over an existing view, read, and drop.

  Four mutations, all detected: dropping the write guard, letting SQLite take `CREATE OR REPLACE`,
  leaving single quotes undoubled, and inlining placeholders without skipping quoted regions.

- `examples/views_example.php` — the README's view, built, created, queried, refused a write and
  replaced against a live database, so the documented snippet is executed rather than proofread.

### Fixed

- The README's `HAVING` example called `gte(raw('total'), 1000)`, which cannot work: the comparison
  operators are typed to take a `Column` on the left, so `raw()` and aggregates are a `TypeError`.
  The example now uses `having(raw('total > 1000'))`. The underlying limitation stands — `HAVING
  SUM(x) > 1000` has no operator form yet.

## [2.7.0] — 2026-08-18

### Fixed

- **Nested transactions now use savepoints instead of a second `BEGIN`.**

  `transaction()` inside `transaction()` used to send a second `BEGIN` down a connection that was
  already in one. What follows is not an error anybody sees: PDO throws on some drivers, ignores it
  on others, and where it is ignored the **inner commit ends the outer transaction** — so work the
  caller believed was still provisional becomes durable, and the outer rollback that follows has
  nothing left to undo.

  The first `transaction()` opens a real transaction; each one inside it opens a savepoint.

  | | before | now |
  |---|---|---|
  | inner block fails | outer transaction unusable or already committed | inner work undone, **outer still open and accepting statements** |
  | outer block fails | inner "commit" may have been durable | everything goes, inner work included |

  The first row is not a nicety. On PostgreSQL a failed statement aborts the whole transaction —
  every later statement errors — and rolling back to a savepoint is the only way out short of
  abandoning all of it.

  The second is what a naive implementation gets wrong: **releasing a savepoint is not a commit.** It
  discards the marker and leaves the work inside the enclosing transaction, still provisional.
  Otherwise a helper could write through its caller's rollback.

- **`transaction()` catches `Throwable`, not `Exception`.** A `TypeError` inside the callback is
  every bit as much a reason not to keep the work, and used to leave the transaction open.

- **A failing rollback no longer replaces the original exception.** A connection that has gone away,
  or MySQL having implicitly committed on a DDL statement, would raise from inside the `catch` and
  the caller would receive that instead of the real cause.

### Added

- **`transaction_depth()`** — how many are open, counting nested ones.

- **Committing or rolling back with nothing open throws**, rather than returning quietly. A double
  commit used to look like success.

- **`TransactionTest`**, 26 assertions, every one of which ends by reading the table back — because
  the failure this replaces is not an error, it is the wrong rows being durable. Covered: an inner
  failure leaving the outer usable, an outer failure discarding an inner commit, three levels with a
  failure in the middle, the depth counter, closing what is not open, and a helper that opens its own
  transaction behaving identically whether or not it was called inside one.

  Three mutations verified: no savepoint on the second begin, an inner commit made real, an inner
  rollback taking the whole transaction.

## [2.6.0] — 2026-08-17

### Fixed

- **`limit` on a relation capped the whole fetch, not each parent.** The children of every parent are
  fetched in one batched query with an `IN (…)`; a `LIMIT` on that query is a limit across all of
  them. So `'limit' => 1` returned **one row in total** — the first parent took it and every other
  parent got an empty list, silently:

  ```
  before → [{"Alice": [120]}, {"Bob": []}]        // Bob has two orders
  after  → [{"Alice": [450]}, {"Bob": [300]}]     // one each, biggest first
  ```

  `limit` means *this many per parent* — the three most recent orders of every customer — and now
  does. Applied after grouping, where the children already are and where the child query's own
  `ORDER BY` has already decided which come first, so it is exact on every dialect. Fixed for
  one-to-many, many-to-many and polymorphic-many.

  The code carried a comment admitting it: *"per-parent limit requires more complex handling / for
  now, apply global limit"*. A TODO that had become the behaviour.

  The cost is that more rows cross the wire than are kept; pushing the cap into the database needs a
  window function, which is the optimisation to reach for if it ever matters and is noted in the
  method.

### Documentation

- **A "Worked examples: relations" section**, and `examples/relation_examples.php` beside it — the
  companion to the SQL examples. One-to-many, many-to-one, filtered and capped children, several
  levels of nesting, many-to-many through a junction, a table related to itself, a polymorphic child,
  and what happens when you forget the `with()`.

  Writing them is what found the `limit` defect: the assertions asked whether children arrive, and
  the example asked for *one per customer* and got one customer's.

- The eager-only contract is now written down where a reader meets it. Nothing queries when a
  relation is read — `ActiveRow` wraps rows that are already there and contains no queries at all —
  so an N+1 cannot appear by accident. A parent with no children gets `[]`; a relation never loaded
  has no key. `?? []` collapses the two, `array_key_exists()` does not.

## [2.5.2] — 2026-08-17

### Documentation

- **A "Worked examples" section in the README**, and `examples/worked_examples.php` next to it. Ten
  ordinary questions — customers who never ordered, products above the average price, a category
  subtree, spend per customer then filtered — each with the code that asks it and the SQL that
  reaches the database.

  A runnable file rather than a listing, on purpose. An example nobody runs is documentation that
  ages in silence; this package had two of those until last week, and it was writing *these* that
  found the two defects fixed in 2.5.1. The README quotes what the file prints.

## [2.5.1] — 2026-08-17

### Fixed

Two silent failures, both reachable only once derived tables existed, and both found by writing
worked examples for the README rather than by writing more tests. Neither raises: they return the
wrong number of rows, which is the failure that gets past a review because the query runs.

- **`from(Subquery)` did not take the subquery's dialect.** `new QueryBuilder()` defaults to MySQL
  and `from(Table)` corrects that; the subquery branch did not, so a SQLite derived table came out
  wrapped in backticks.

- **Every parameter was bound as a string.** `PDOStatement::execute($params)` binds everything as
  `PARAM_STR`. Against a real column that is invisible — the engine coerces toward the column's
  declared type. Against a column with **no** declared type, which is exactly what a derived table or
  a CTE produces, SQLite has nothing to coerce toward, and in its ordering every TEXT is greater than
  every number:

  ```sql
  SELECT * FROM (SELECT SUM(n) AS s FROM t) d WHERE d.s > ?   -- 100
  ```

  matched **nothing at all**, whatever the data. Measured against `s = 245`. Values are now bound by
  PHP type, and the assertion picks a threshold that returns two rows with the fix and zero without.

## [2.5.0] — 2026-08-17

### Fixed

- **`columns()` silently emptied every relation.** Eager loading matches children to parents by
  reading a key out of each parent row; narrowing the select with `columns(['name'])` did not fetch
  that key, `array_column()` found nothing, and every relation came back empty — with no error, no
  warning and no clue. A query asking for a name and its posts returned the name and no posts, which
  reads like missing data rather than a missing column.

  The same failure existed on the child side: `with(['posts' => ['columns' => ['title']]])` fetched
  titles without the `author_id` they are attached by.

  Both are fixed, on all five loaders — one, many, many-to-many, polymorphic one, polymorphic many.
  The keys are added to the SELECT and **removed from the rows afterwards**, so a caller that asked
  for a column list gets that list plus the relations it asked to load, and nothing else. A key the
  caller did ask for is kept.

  Found by writing the first assertion that narrowed the columns and then looked at what came back.

### Added

- **`EagerLoadingTest`**, 20 assertions. The existing `RelationsTest` asserts that relations are
  *declared* correctly — the right type, the right through-table, the right inverse. This asserts
  they *load*, which turned out to be a different question.

  Covered: one-to-many, many-to-one, many-to-many through a junction table, self-referencing
  relations, two relations between the same pair of tables told apart by name, arbitrary nesting, and
  `limit` / `order_by` / `where` applied to a relation rather than to its parents. Each of them again
  with the columns narrowed, because that is the path that was broken.

  Three mutations verified: the parent key not added, the child key not added, the added key not
  stripped.

## [2.4.0] — 2026-08-17

### Added

Three constructs that are SQL rather than sugar. Without them the only way to ask an ordinary
question — *"customers who have ordered"*, *"the whole subtree under this node"* — was `raw()`, which
takes a string and interpolates nothing: every value inside had to be pasted into the statement by
hand. That is the precise point at which a query builder stops protecting anybody, and it was reached
by the second question most schemas get asked.

- **Subqueries.** `sub($query)` wraps a SELECT as an `SQLExpression`, so it composes with what was
  already there instead of needing a parallel API:

  ```php
  where(in_($customers->id, sub($sel($orders, [$orders->customer_id])->where(gt($orders->total, 100)))))
  where(eq($customers->id, sub($biggest_spender)))        // scalar — Comparison already parenthesised
  from(sub($grouped)->alias('totals'))                     // derived table
  ```

  `in_()` and `not_in_()` widened from `array` to `array|SQLExpression`; a list still works. A derived
  table without an alias is refused, because every dialect requires one and the error a server gives
  for its absence names a line number rather than a cause.

- **`exists()` and `not_exists()`.** Not a second spelling of `IN`. `NOT IN` against a subquery whose
  column can be NULL is true for **no row at all** — correct three-valued logic and almost never the
  question that was asked. The suite asserts exactly that, so the reason `not_exists()` exists is
  written down where somebody will meet it.

- **Common table expressions.** `with_cte($name, sub($query))`, and `with_recursive()` for the
  recursive form. Several may be declared and are emitted in the order given.

  The point is not brevity. A query written as three CTEs reads top to bottom in the order the work
  happens; the same query as nested subqueries reads inside out, and the version somebody has to
  change six months later is the one they can follow.

  The recursive form is what makes a tree query one statement instead of a loop that issues one
  query per level — any adjacency-list hierarchy being the obvious case.

- **Set operations**: `union()`, `union_all()`, `intersect()`, `except()`.

- **`distinct()`** — `SELECT DISTINCT`. The flag that existed already was `COUNT(DISTINCT x)`, an
  aggregate; this is the other one. Distinct is over **everything selected**, which is the part
  people mean differently from what SQL does: adding a column to the select list changes which rows
  count as duplicates, and there is an assertion that says so.

- **`distinct_on()`** — PostgreSQL's `SELECT DISTINCT ON (…)`, the first row of each group as decided
  by `ORDER BY`. **Refused on MySQL and SQLite** rather than approximated: they have no equivalent,
  the rewrite is a window function or a correlated subquery depending on the query, and a builder
  that quietly emitted plain `DISTINCT` there would return a different number of rows on a different
  server.

### Notes

- **`ORDER BY`, `LIMIT` and `OFFSET` on a *branch* are refused**, not emitted. In standard SQL they
  belong to the whole compound and must follow the last branch; a dialect that accepts them inside
  one either parenthesises (MySQL, PostgreSQL) or rejects the statement (SQLite). Emitting them and
  hoping is how a query returns different rows on a laptop and on the server. Put them on the query
  you call `union()` on and they apply to the compound, which is what they mean.

- **`for_dialect()`**, and why a subquery needed it. A builder keeps the dialect of the table it was
  made from; embed it in a statement for another and backticks and `?` would appear inside a
  statement using double quotes and `$1`. A subquery is now rendered for the statement it lands in,
  and the original builder is not moved by that. Both halves are asserted.

- **Parameter order is the quiet one.** Placeholders are positional, so bindings must be collected in
  the order the clauses appear — `WITH` before `WHERE`, each branch after the one before it. Get it
  wrong and nothing raises: the statement executes, against the wrong values. Asserted directly, and
  the mutation that appends CTE bindings last fails six assertions including two that only look at
  returned rows.

### Testing

48 assertions, in two kinds because both are needed: **what SQL is emitted** catches a clause built
in the wrong place, and **what rows come back** catches emitted SQL that is valid and wrong — the
failure that survives a review, because the query runs. Everything is executed against a real SQLite
database, and the PostgreSQL and MySQL renderings are asserted for quoting and placeholder style.

Four mutations verified: CTE bindings appended last, set operations emitted after `ORDER BY`,
`for_dialect()` made a no-op, and `IN` interpolating its subquery's values instead of binding them.

## [2.2.0] — 2026-08-17

### Changed

- **snake_case throughout**, and this package is no longer excluded from `ix libs:check`.

  The exclusion had been there long enough to read as a permanent fact — "it predates all of this and
  carries its own conventions". Removing it reported **349 findings**, which is a number that reads
  like a rewrite. It was not:

  | | findings |
  |---|---:|
  | `examples/` | 324 |
  | `src/` | 11 |
  | `tests/` | 5 |

  The **public API was already entirely snake_case**, apart from six methods PHP itself dictates
  (`offsetGet`, `getIterator`, `jsonSerialize`, …). No properties were camelCase. So there is no BC
  break here and no MAJOR: what changed is local variables, and the examples.

  In `src/` the pattern was worth noticing — the properties and getters followed the rule and only
  the temporaries holding them did not:

  ```php
  $createdAtColumn = static::$created_at_column;   // snake_case source, camelCase local
  ```

  A local variable has no callers. Nothing sees it, no test names it, no refactoring forces it into
  line; it is the one place a convention can rot without anything protesting, which is why the check
  reads variables and not only method names.

  1 175 identifiers renamed, with PHP's own tokeniser rather than a regex — `$product_id` has to be
  renamed inside double-quoted strings and heredocs and left alone inside single-quoted array keys,
  because those are somebody else's API field names. One vocabulary for the whole set, because these
  files call each other.

### Fixed

- **Two examples had not run in a long time**, and were found by running all of them for the first
  time:

  - `ecommerce_example.php` passed `customer_name`, `customer_email`, `shipping_address` and
    `payment_method` straight to `create_order()` — columns the `orders` table has never had. It died
    on the insert. The customer is a row of its own and the order points at it; it does that now.
  - `polymorphic_relations_example.php` used `$comments` inside a closure that did not `use` it.

  An example that does not run is documentation that ages in silence. All 22 runnable examples now
  exit 0.

- **A consuming application's table name, in a docblock in `Migrator`**, used as the example for how
  migration class names are derived. A boundary violation of exactly the kind `ix libs:check` exists
  to catch, invisible for as long as the package was excluded from it, and replaced with a neutral
  `customers` / `invoices` pair.

  The lesson is about the exclusion rather than the name: a rule switched off for one directory stops
  being a rule, and nothing announces what walks in behind it. (This entry deliberately does not
  quote the offending string — the check reads changelogs too, and an exemption for them would be a
  smaller version of the same hole.)

- Five examples required the autoloader at one hardcoded depth, so they ran in one arrangement of
  directories and nowhere else.

## [2.1.0] — 2026-08-17

### Fixed

- **Four of this package's five test suites had not run in a long time.** They died on
  `Interface "Italix\Contracts\RelationalColumnMeta" not found` before reaching a single assertion —
  the manual autoloader in `src/autoload.php`, the one for running without Composer, knew only
  `Italix\Orm\`. `Column implements RelationalColumnMeta`, so building any schema was a fatal error.

  It went unnoticed because `ix test` reported them as "NOT RUN — they predate Italix\Testing", which
  was true and read like a note about style rather than about four broken suites in the largest
  package in the tree. 17,000 lines with no assertions running is not a coverage gap, it is an
  absence of testing.

  `src/autoload.php` now resolves `Italix\Contracts\` as well, wherever it sits.

### Changed

- **The suites use `Italix\Testing`**, so `ix test` runs them, counts their assertions and includes
  them in the JUnit report. The port is deliberately mechanical: each file keeps its own global
  `test()` as a four-line shim forwarding to the shared runner, so not one of the ~270 call sites
  changed. What they assert is exactly what they asserted before.

  **317 assertions**, previously invisible: ActiveRow 93, dialects 93, relations 63, migrations 35,
  SQL injection defences 33.

- `require-dev: italix/testing`, and a `test` script that runs all five — caught by `ix libs:ci`
  within a minute of the port.

## [2.0.1] — 2026-08-17

### Fixed

- **`php: >=7.4` was not true.** Four files have not parsed on 7.4 for some time: eight `match`
  expressions in `Migrator`, `SchemaIntrospector` and `Console\Commands\Command` (PHP 8.0), and the
  `: static` return type throughout the `DelegatedTypes` trait (PHP 8.0). The constraint now says
  `>=8.1`.

  This is a corrected declaration, not a narrowing: nothing that works today stops working, because
  nothing was working on 7.4. It went unnoticed because the application these libraries grew in runs
  8.1 and autoloads all of them from one PSR-4 map — the per-package manifests are never resolved by
  Composer, so nothing ever checked. Found by linting every file with a real `php7.4` binary.

  `: static` is the reason this package moves rather than being rewritten. In a trait with a fluent
  API it is the return type that is *correct*; `: self` would name the trait's own class and lie to
  every consumer that uses it. The other twenty packages had no such reason and stay on 7.4 — the two
  union types in `italix/datasets` and the two promoted constructors in `italix/mvc` were written
  back out instead.

## [2.0.0] — 2026-08

### Changed — BREAKING

- **Licence: Apache-2.0 → MPL-2.0.** No code changed. It is MAJOR because the new licence is the
  more restrictive of the two: MPL adds file-level copyleft, so a consumer who modifies a file of
  this library must publish that file's source. Their own files are unaffected, however they
  subclass or compose — which is the property Apache-2.0 gave for free and MPL gives deliberately.

  Releases up to and including 1.1.1 stay under Apache-2.0. This applies from here on.

  Every source file now carries the MPL Exhibit A notice: §1.4 defines "Covered Software" as source
  *to which that notice has been attached*, so it is not decoration.

  The `NOTICE` file is kept. It is an Apache-2.0 mechanism rather than an MPL one, but the
  acknowledgement it carries — that this library's API design was inspired by Drizzle ORM, and that
  it is an independent implementation with no affiliation — is worth keeping under any licence.

## [1.1.1] — 2026-08

### Fixed

- **The migration runner could not find any numbered migration's class.**

  `file_to_class()` derived the class name from the filename, stripping only a
  `YYYY_MM_DD_HHMMSS_` prefix. A file named `033_create_gen_media.php` therefore derived
  `033CreateGenMedia` — and a PHP class name may not begin with a digit, so `class_exists()` was
  false and the runner threw. Measured against a real project: **32 of 32 migrations were
  unrunnable**, which means no fresh database could ever be built from them.

  Stripping the number would not have been enough either: `002_create_customers.php` declares
  `CreateCustomersTable`, and no filename rule recovers a `Table` suffix the filename never had.

  The runner now asks PHP what the file declared — it scans `get_declared_classes()` for subclasses
  of `Migration` whose `ReflectionClass::getFileName()` is the file being run. Exact, and it frees a
  migration to be named anything. Zero or several matches throw with a message saying which.

  This stayed hidden because an existing database reaches the ledger through a baseline, which marks
  migrations applied without running them. Only a fresh install would have hit it — which is the
  worst moment to find out.

  `file_to_class()` is left in place: it is `protected`, so a subclass may be using it, and removing
  it would be a MAJOR for no benefit.

## [1.1.0] — 2026-08

### Added

- `Table` declares `Italix\Contracts\NamedTableMeta` alongside `DelegatedTableMeta`.

  Purely declarative — `get_name(): string` already existed with exactly that signature, so no
  behaviour changes and nothing that worked before can stop working. The point is that a consumer
  can now *require* a schema that knows its table name instead of duck-typing for the method;
  `italix/testing` derives its row factories through that guarantee.

### Changed

- Requires `italix/contracts: ^1.1` (was `^1.0`) for the interface above.

## [1.0.0] — baseline

Versioning starts here. This entry records the state of the library at the time the policy was
adopted, not a release. See `README.md` and `docs/` for usage.

### Contents

- **`Schema/`** — `Table` and the column vocabulary (`serial()`, `varchar()`, `datetime()`, …).
  Models are pure schema definitions; there is no active record on the model.
- **`DataManager`** — connection and query execution.
- Query builder, relations with eager loading, `ActiveRow`.
- **Migrations** — `Blueprint` / `Migrator`, with `bin/ix`: `diff`, `pull`, `push`, `squash`,
  `rollback`, `status`, `reset`, `refresh`.
- Drivers for MySQL, PostgreSQL, SQLite and Supabase.

### The property everything else depends on

`Table` describes every column — nullability, length, type. That single fact is what lets
`italix/forms` build a form, `SchemaValidator` derive required fields and max lengths, and
`italix/testing` derive factories instead of hand-writing them. Adding a `not_null()` column to a
table a library ships is therefore a **MAJOR**: it breaks derived factories and existing inserts.

### Known compatibility notes

- **`bin/ix` has no dry-run, no advisory lock and no destructive-change guard.** Planned as
  FRAMEWORK-FUTURE.md §4.5. `--check-destructive` will refuse `DROP` and narrowing `ALTER` without
  `--force`, which is a **MAJOR** despite being a flag addition: it changes the default behaviour of
  an existing command and will break unattended deploy pipelines. No code check catches that one.
- `bin/ix` is expected to become the framework-wide runner under `Italix\Console`, absorbing
  `run_migration.php` and `bin/encode-lint`. Moving an entry point breaks deploy scripts and cron,
  not code — the old paths stay as shims for one MINOR.

### Planned, MINOR

- A `Probe` implementation reporting queries and timings to the dev toolbar.
- `in_rollback()` on `DataManager` — run a closure inside a transaction that is always rolled back,
  which is how `italix/testing` isolates database tests.
