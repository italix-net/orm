<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - ActiveRow / Table reverse index
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\ActiveRow;

use Italix\Orm\Schema\Table;

/**
 * Which `ActiveRow` subclass, if any, is bound to a given `Table`.
 *
 * `Persistable::set_persistence()` already keeps a class-name => Table map,
 * but it is one map **per class** — a trait's static properties are not
 * shared across the classes that use it, so there was no way to go the other
 * direction: given a `Table`, which class reads and writes it. That reverse
 * lookup is what `DelegatedTypes` and relation-wrapping need in order to ask
 * the `Table` what a delegate or a related row's class is, instead of having
 * it declared a second time on the `ActiveRow` subclass. This class exists
 * only to hold that one index — a plain class, not a trait, precisely so the
 * state really is shared.
 *
 * Matched by identity (`===`), not by table name: the ordinary way to use
 * this package is to build a `Table` once — `$books = sqlite_table(...)` —
 * and pass that same variable everywhere (to `mysql_table()`'s siblings,
 * to `->delegates([...])`, to `set_persistence()`). Two separate calls that
 * happen to describe the same table name are **not** treated as the same
 * table here; the lookup returns `null`, and callers fall back to whatever
 * they did before this registry existed. A wrong match would silently wrap a
 * row in the wrong class; no match is only a missed convenience.
 */
final class ActiveRowRegistry
{
    /** @var array<int, array{table: Table, class: class-string}> */
    private static array $entries = [];

    /** Called by `Persistable::set_persistence()` — not meant to be called directly. */
    public static function register(Table $table, string $class): void
    {
        foreach (self::$entries as $i => $entry) {
            if ($entry['table'] === $table) {
                self::$entries[$i]['class'] = $class;

                return;
            }
        }

        self::$entries[] = ['table' => $table, 'class' => $class];
    }

    /** The `ActiveRow` subclass registered for this exact `Table` instance, or `null`. */
    public static function class_for_table(Table $table): ?string
    {
        foreach (self::$entries as $entry) {
            if ($entry['table'] === $table) {
                return $entry['class'];
            }
        }

        return null;
    }
}
