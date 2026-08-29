<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Global query scopes
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Scopes;

use Italix\Orm\Schema\Table;

/**
 * A named `WHERE` fragment ANDed onto every read against a `Table`, until a
 * caller opts out with `without_scopes()`.
 *
 * `Table::soft_deletes()` already does exactly this — `effective_where()`
 * quietly narrows a SELECT to the non-deleted rows — but that filter is
 * built into the schema itself, one hand-written case. Multi-tenant
 * isolation ("only this tenant's rows"), a visibility rule ("only published
 * posts"), a publish window — every one of those used to mean repeating the
 * same `WHERE` at every call site, with a missed one being a data leak
 * rather than a compile error. A global scope declares it once, against the
 * `Table`, and every `$dm->select()`/`query_table()` read carries it from
 * then on — the same reach `soft_deletes()` already has, generalised past
 * the one case this package hard-coded.
 *
 * Kept on `DataManager` (see `HookRegistry`'s docblock for why: not a static
 * registry, so two managers on the same schema do not share scopes), and
 * matched by `Table` identity for the same reason `ActiveRowRegistry`
 * already is.
 */
final class ScopeRegistry
{
    /** @var array<int, array{table: Table, scopes: array<string, callable>}> */
    private array $entries = [];

    /**
     * Register `$scope` under `$name` for `$table`. `$scope` is called with
     * the `Table` and must return a `SQLExpression` — the same shape a
     * `where()` condition takes. Registering the same name again for the
     * same table replaces it rather than adding a second copy.
     */
    public function add(Table $table, string $name, callable $scope): void
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry['table'] === $table) {
                $this->entries[$i]['scopes'][$name] = $scope;

                return;
            }
        }

        $this->entries[] = ['table' => $table, 'scopes' => [$name => $scope]];
    }

    /**
     * Every scope registered for `$table`, name => callable, in
     * registration order — `[]` when none were, the ordinary case.
     *
     * @return array<string, callable>
     */
    public function for_table(Table $table): array
    {
        foreach ($this->entries as $entry) {
            if ($entry['table'] === $table) {
                return $entry['scopes'];
            }
        }

        return [];
    }
}
