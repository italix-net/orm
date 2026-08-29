# Feature Requests

**Both requests below were implemented in 2.23.0 (2026-08-25) — see `CHANGELOG.md`.** Left in place
as the record of why, and of the design questions that came up on the way (the PostgreSQL native
`CREATE TYPE ... AS ENUM` question in particular was decided, not defaulted into: `enum()` uses
`VARCHAR` + `CHECK` on PostgreSQL too, for the reason given below, matching SQLite instead of
Drizzle's `pgEnum()`).

---

Gaps found while comparing this package against Drizzle ORM's feature set for an application using
it (`docs/DRIZZLE_VS_ITALIX_MIGRATIONS.md` covers only migrations and relations; these two were
found by reading the current source, not from that document). Not yet implemented — filed here rather
than fixed inline because both are schema-shape decisions with more than one reasonable design,
worth agreeing on before writing the round-trip tests the rest of this package holds itself to.

## `check()` constraints on `Blueprint`

Drizzle supports column- and table-level `CHECK` constraints (PostgreSQL, SQLite, MySQL 8.0.16+).
`Blueprint` (`src/Migration/Blueprint.php`) has no `check()` at all — today a constraint like
`CHECK (amount_cents >= 0)` can only be added by hand-writing raw SQL inside a migration's `up()`,
invisible to `db:diff`/`db:pull` the same way a hand-added foreign key already is (see the
CHANGELOG's 2.21.1 note on what `db:diff` does not compare).

Proposed shape, mirroring the `references()` precedent:

```php
$table->integer('amount_cents')->unsigned()->check('amount_cents >= 0');

// table-level, for a check spanning multiple columns:
$table->check('start_dt < end_dt', 'valid_date_range');
```

Needs a documented refusal (not an approximation) on MySQL below 8.0.16, the same policy already
used for `distinct_on()` and for JSON containment on SQLite.

## `enum()` as a schema column type, not only a migration one

`Blueprint::enum($name, $values)` exists for DDL (`src/Migration/Blueprint.php:347`), but
`ColumnTypes.php` — the functions a model actually declares its columns with
(`mysql_table('t', ['status' => …])`) — has no `enum()`. A migration can create an ENUM column;
nothing in the schema layer can describe reading or writing one afterwards. Drizzle's `pgEnum()` /
`mysqlEnum()` are first-class schema types for exactly this reason: the set of values is written
once and used both to create the column and to type the query against it.

Proposed: `enum(array $values): Column` in `ColumnTypes.php`, alongside the existing
`char()`/`varchar()` factories. Rendering differs by dialect and needs its own decision before
implementing:

- MySQL: native `ENUM(...)`.
- SQLite / PostgreSQL: `VARCHAR` plus a `CHECK (col IN (...))` — which makes this depend on the
  `check()` request above, or ship after it.
- PostgreSQL also has a native `CREATE TYPE ... AS ENUM`, a separate, named, cross-table type
  rather than a per-column constraint — a materially different design (renaming a value requires
  `ALTER TYPE`, and the type is shared) worth deciding on explicitly rather than defaulting into.

---

Filed 2026-08-25.
