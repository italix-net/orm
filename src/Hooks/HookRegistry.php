<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Lifecycle hooks for Data Mapper writes
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Hooks;

use Italix\Orm\Schema\Table;

/**
 * `before_insert` / `after_insert` / `before_update` / `after_update` /
 * `before_delete` / `after_delete` — one place to react to a write, for
 * callers using `$dm->insert()`/`update()`/`delete()` directly rather than
 * an `ActiveRow` subclass with its own overridable lifecycle methods. Before
 * this, that style had no hook at all: an audit log, a cache warm, a
 * `updated_at` on a *different* table than the one being written, all had to
 * be bolted on by hand at every call site instead of declared once against
 * the `Table`.
 *
 * Kept on `DataManager` (one instance per manager, handed to its
 * `QueryBuilder` once in the constructor — same wiring as
 * `use_query_cache()`), not as a static registry: two managers pointed at
 * the same schema should not silently share each other's hooks.
 *
 * Matched by `Table` identity (`===`), the same rule `ActiveRowRegistry`
 * already uses — build a `Table` once and pass that variable everywhere.
 */
final class HookRegistry
{
    public const EVENTS = [
        'before_insert',
        'after_insert',
        'before_update',
        'after_update',
        'before_delete',
        'after_delete',
    ];

    /** @var array<int, array{table: Table, event: string, hooks: callable[]}> */
    private array $entries = [];

    /**
     * Register `$hook` to run on `$event` for writes against `$table`.
     * Hooks for the same table/event run in the order they were registered.
     */
    public function on(Table $table, string $event, callable $hook): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException(
                "HookRegistry::on(): '{$event}' is not a recognised event ("
                . implode(', ', self::EVENTS) . ')'
            );
        }

        foreach ($this->entries as $i => $entry) {
            if ($entry['table'] === $table && $entry['event'] === $event) {
                $this->entries[$i]['hooks'][] = $hook;

                return;
            }
        }

        $this->entries[] = ['table' => $table, 'event' => $event, 'hooks' => [$hook]];
    }

    /**
     * Every hook registered for `$table`/`$event`, in registration order —
     * `[]` when none were, which is the ordinary case and why callers can
     * iterate the result unconditionally.
     *
     * @return callable[]
     */
    public function for_table(Table $table, string $event): array
    {
        foreach ($this->entries as $entry) {
            if ($entry['table'] === $table && $entry['event'] === $event) {
                return $entry['hooks'];
            }
        }

        return [];
    }
}
