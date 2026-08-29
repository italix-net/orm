<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Data factories
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Factories;

use Italix\Orm\DataManager;
use Italix\Orm\Schema\Table;

/**
 * A named recipe for one row of `$table` — Eloquent's model factories and
 * Rails' FactoryBot, adapted to a package with no fixed model layer: this
 * produces plain arrays (`Persistable`/`ActiveRow` already wrap an array
 * into a row when a caller wants one — `SomeRow::wrap_many($factory
 * ->create())` — so a factory has no reason to know either style exists).
 *
 * No bundled fake-data generation (names, addresses, lorem text): that is
 * an entire library's worth of scope this package has no present need for,
 * and every value in `definition()` is plain PHP the caller already knows
 * how to write — `rand()`, a literal, a small array of samples, `sequence()`
 * below for a cheap unique counter. Building a Faker-equivalent before
 * something in this codebase actually needs one would be exactly the
 * un-asked-for abstraction this project's own conventions warn against.
 *
 *     class UserFactory extends Factory
 *     {
 *         public function definition(): array
 *         {
 *             return [
 *                 'name'  => fn () => 'User ' . $this->sequence(),
 *                 'email' => fn () => 'user' . $this->sequence() . '@example.com',
 *                 'role'  => 'member',
 *             ];
 *         }
 *     }
 *
 *     UserFactory::new($dm, $users)->count(3)->create();
 *     UserFactory::new($dm, $users)->state(['role' => 'admin'])->create();
 *     UserFactory::new($dm, $users)->make();   // built, not persisted
 *
 * A value may be a plain scalar/array, or a zero-argument `Closure`
 * evaluated once per row at build time — that is what makes `sequence()`
 * give each row of a `count(3)` batch its own value instead of all three
 * sharing whatever the first call returned.
 */
abstract class Factory
{
    protected DataManager $dm;
    protected Table $table;
    protected int $count_n = 1;

    /** @var array<int, array<string, mixed>|callable> */
    protected array $states = [];

    /** @var array<class-string, int> */
    private static array $sequences = [];

    final public function __construct(DataManager $dm, Table $table)
    {
        $this->dm = $dm;
        $this->table = $table;
    }

    /**
     * The base attributes for one row — column name => value, where a value
     * may be a `Closure` (see the class docblock) evaluated per row.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    public static function new(DataManager $dm, Table $table): static
    {
        return new static($dm, $table);
    }

    /** How many rows `make()`/`create()` build — 1 unless changed. */
    public function count(int $n): static
    {
        $factory = clone $this;
        $factory->count_n = $n;

        return $factory;
    }

    /**
     * A named variation, merged over `definition()`'s result — a plain
     * array of overrides, or a `callable(array $row): array` computed from
     * the row `definition()` already built (so a state can derive one field
     * from another, e.g. a status-dependent timestamp). Repeated calls
     * accumulate and apply in the order registered.
     *
     * @param array<string, mixed>|callable $state
     */
    public function state($state): static
    {
        $factory = clone $this;
        $factory->states[] = $state;

        return $factory;
    }

    /**
     * `count()` rows, built and resolved but never sent to the database —
     * for a caller that wants fake data without persisting it (a unit test
     * fixture, most often).
     *
     * @param array<string, mixed> $overrides applied last, after every state
     * @return array<int, array<string, mixed>>
     */
    public function make(array $overrides = []): array
    {
        $rows = [];

        for ($i = 0; $i < $this->count_n; $i++) {
            $row = $this->resolve($this->definition());

            foreach ($this->states as $state) {
                $applied = is_callable($state) ? $state($row) : $state;
                $row = array_merge($row, $this->resolve($applied));
            }

            $rows[] = array_merge($row, $overrides);
        }

        return $rows;
    }

    /**
     * `make()`, then inserted — one `INSERT` per row (not a single
     * multi-row statement: that is what makes each row's generated id
     * available to return, needed for a caller building a related row
     * next, e.g. a post factory needing the author it just created). All
     * of `count()`'s rows insert inside one transaction, so a failure
     * partway through does not leave a partial batch behind.
     *
     * A single-column primary key's generated value is merged back into
     * the returned row; a composite or absent key is left exactly as
     * `definition()`/`state()`/`$overrides` built it — nothing here invents
     * a value for a key it was never given, the same rule
     * `ActiveRow`'s own composite-key INSERT already follows.
     *
     * This never re-`SELECT`s afterward — the same choice `Persistable::
     * save()` already makes, for the same reason (an extra round trip per
     * row, paid on every `create()` to cover a case most calls do not hit).
     * That means a column `Table::timestamps()` or `optimistic_locking()`
     * fills in automatically (`insert_dt`, `version`, …) is **not** reflected
     * in the returned row unless `definition()`/`state()`/`$overrides`
     * already set it — only the primary key is. A caller that needs the
     * true post-insert row for such a table should read it back explicitly
     * (`$dm->query_table($table)->find($id)`), the same as any other
     * `$dm->insert(...)->execute()` caller already has to.
     *
     * @param array<string, mixed> $overrides
     * @return array<int, array<string, mixed>>
     */
    public function create(array $overrides = []): array
    {
        $rows = $this->make($overrides);
        $pk_columns = $this->table->get_primary_keys();
        $single_pk = count($pk_columns) === 1 ? $pk_columns[0] : null;

        return $this->dm->transaction(function () use ($rows, $single_pk): array {
            $created = [];

            foreach ($rows as $row) {
                // Always (int) lastInsertId(): this never calls ->returning().
                $new_id = $this->dm->insert($this->table)->values($row)->execute();

                // array_merge() lets $row's own value win when it already
                // has this key (a caller-supplied id) — no extra check
                // needed to prefer it over lastInsertId().
                $created[] = $single_pk !== null ? array_merge([$single_pk => $new_id], $row) : $row;
            }

            return $created;
        });
    }

    /**
     * A counter private to this factory subclass, starting at 1 and
     * incrementing on every call — cheap uniqueness for a batch (`'email' =>
     * fn () => "user{$this->sequence()}@example.com"`) without depending on
     * a value the database has not assigned yet.
     */
    protected function sequence(): int
    {
        $class = static::class;
        self::$sequences[$class] = (self::$sequences[$class] ?? 0) + 1;

        return self::$sequences[$class];
    }

    /**
     * Every `Closure` value evaluated, everything else left as-is.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function resolve(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof \Closure) {
                $attributes[$key] = $value();
            }
        }

        return $attributes;
    }
}
