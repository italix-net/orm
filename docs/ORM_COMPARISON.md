# Italix ORM vs. the field

A grounded feature comparison against the ORMs this package gets measured against in practice —
Eloquent (Laravel/PHP), Doctrine (PHP), Django ORM (Python), SQLAlchemy (Python), Rails
ActiveRecord (Ruby), EF Core (C#), Drizzle (TypeScript). Written after the 2.28.0/2.29.0 round of
work closed most of the gaps found by this same comparison — see `CHANGELOG.md` for exactly what
shipped and when, and `docs/DRIZZLE_VS_ITALIX_MIGRATIONS.md` for the older, narrower comparison
scoped to migrations and relations specifically.

**The honest verdict**: on the features most applications actually reach for, this package is now
at parity with the best of these libraries, and ahead on two — cross-dialect full-text search with
a real, working SQLite implementation, and the mutation-tested rigor behind every feature listed
below. It remains narrower than the large, decades-old ecosystems on lifecycle richness and
relation expressiveness. Both halves of that verdict are laid out below with specifics, not just
asserted.

---

## At parity with the best of the field

| Feature | Italix ORM | Comparable to |
|---|---|---|
| Attribute casting | `Column::cast_as('array'\|'datetime'\|'bool'\|'int'\|'float')` — 2.28.0 | Eloquent's `$casts`, Rails' `serialize`/Attributes API, Doctrine's custom types |
| Native enum hydration | `enum(SomeBackedEnum::class)` — 2.28.0 | Eloquent's enum casts, SQLAlchemy's `Enum` type |
| Lifecycle hooks | `DataManager::on($table, $event, $hook)`, 6 events — 2.28.0; `on_commit()`/`on_rollback()`, transaction-scoped — 2.30.0 | Rails callbacks (a subset — see gaps below), Eloquent model events, Rails' own `after_commit`/`after_rollback` |
| Global scopes | `DataManager::add_global_scope($table, $name, $scope)`, `without_scopes()` — 2.28.0 | Eloquent global scopes, EF Core global query filters, Rails `default_scope` |
| Optimistic locking | `Table::optimistic_locking('version')`, `->expect_version($n)`, `Locking\OptimisticLockException` — 2.29.0 | Rails `lock_version`, Doctrine `@Version`, EF Core concurrency tokens |
| Composite primary keys (ORM layer, not just schema DDL) | `ActiveRow`'s `static::$primary_key = [...]`, full `Persistable`/`SoftDeletes` support — 2.29.0 | SQLAlchemy (always had this), EF Core, Rails 7.1+ (recent addition) |
| Data factories | `Factories\Factory` — `definition()`/`state()`/`count()`/`sequence()`/`make()`/`create()` — 2.29.0 | Eloquent model factories (closely — same shape) |
| Seeders | `Seeding\Seeder`, `ix db:seed` — 2.29.0 | Laravel's `DatabaseSeeder` |
| Soft deletes (read + write) | `Table::soft_deletes()`, filters every read automatically — 2.24.0/2.26.0 | Eloquent `SoftDeletes` trait, Rails `paranoia`/`discard` gems |
| `CHECK`/`ENUM` constraints, schema-layer and migration-layer | `Column::check()`/`enum()`, `Blueprint::check()`/`enum()` — 2.23.0 | Drizzle's `check()`, native `ENUM` support across dialects |

## Where this package is ahead

- **Full-text search that actually runs on all three dialects, SQLite included.** `Table::fulltext()`
  / `Operators\fulltext_match()` (2.29.0) render a native `FULLTEXT INDEX` + `MATCH()/AGAINST()` on
  MySQL, a `GIN` index over `to_tsvector()` on PostgreSQL, and — the piece nearly every comparable
  ORM leaves out entirely, deferring to a separate search package (Laravel Scout, `pg_search`,
  an Elasticsearch bridge) — a genuine FTS5 virtual table plus three sync triggers on SQLite,
  proven end to end by inserting, searching, updating and deleting rows and watching the index
  track every one of those, not merely by asserting the trigger SQL looks right.

- **Mutation-verified correctness as standard practice, not an occasional test.** Every branch
  added since 2.23.0 was proven necessary by breaking it, confirming the specific assertion that
  exists for it fails, and restoring it — including cross-feature interactions nobody explicitly
  asked to have checked (composite-key `ActiveRow` combined with optimistic locking; a factory
  combined with hooks and optimistic locking together). One real bug was found this way that had
  already shipped silently: a `before_insert`/`before_update` hook returning a full replacement row
  (a legitimate field-whitelist pattern) could drop `Table::timestamps()`'s and
  `optimistic_locking()`'s own defaults with no error — fixed, and now regression-tested from three
  independent angles. This level of scrutiny is not something most of the libraries above document
  publicly about their own internals.

## Known gaps — stated plainly, not glossed over

- **Lifecycle events are a working subset, not the full Rails set.** Eight events now — the original
  six (`before_insert`/`after_insert`/`before_update`/`after_update`/`before_delete`/`after_delete`)
  plus `on_commit()`/`on_rollback()` (2.30.0, transaction-scoped rather than table-scoped) — against
  Rails' roughly twenty (`before_validation`, `after_touch`, …). Deliberately not closed further:
  a validation-phase pair was considered and rejected — this package's write path has no validation
  concept to hook into (validation is `italix/rules`, a separate concern), and the one genuine "after
  validation, before persist" need found while designing this (deriving one field from another
  already-valid one — a decoded tax code, a looked-up province) turned out to already be covered by
  `prepare_data()` on the application's own `BaseAdminAction`, outside this package entirely. Adding a
  second hook for the same moment would have been redundant, not a gap.

- **No polymorphic associations beyond `DelegatedTypes`.** `DelegatedTypes` covers "this row is
  actually a Book or a Movie" (Rails' single-table-inheritance-adjacent delegated types pattern);
  there is nothing comparable to Eloquent's or Rails' `morphTo`/`polymorphic: true` — a comment
  belonging to either a post or a photo through one association.

- **No relation-aware query DSL.** Nothing here is equivalent to Eloquent's `whereHas()`/
  `whereDoesntHave()` (filter a query by a condition on a related table) — that has to be
  hand-written as a join or a subquery today.

- **No bundled fake-data generation.** Deliberate, not an oversight: building a Faker-equivalent
  (names, addresses, lorem text, locales) is an entire library's worth of scope this package has
  had no actual need for yet — see `Factories\Factory`'s own docblock. `definition()` values are
  plain PHP the caller writes, same as Rails' FactoryBot expects outside of its own optional Faker
  integration.

- **Migrations are solid but younger.** Full up/down, batch tracking, `db:diff`/`db:pull`/`db:push`/
  `db:squash` all work and are tested — but without the years of production edge cases Rails' and
  Laravel's migration systems have absorbed (concurrent migration conflicts, zero-downtime column
  changes, etc.).

---

*Last updated 2026-08-26, after the 2.30.0 release. Revisit this file the next time a comparison
pass against another library's feature set turns up something worth adding — the same way
`FEATURE_REQUESTS.md` records gaps found but not yet closed.*
