<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - The validation rules that need a database
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Rules;

use Italix\Contracts\RuleMeta;
use Italix\Orm\DataManager;
use RuntimeException;

/**
 * Settles `unique` and `exists` — the two rules a checker deliberately does not.
 *
 *     $outcome = $checker->check_all($schedule, $_POST);
 *     $errors  = array_merge(
 *         $outcome->errors(),
 *         (new DatabaseRules($dm))->check($schedule, $outcome->normalized(), ['id' => $editing_id])
 *     );
 *
 * A validator with no database cannot answer "is this e-mail already taken".
 * `Italix\Rules` says so out loud — it lists those rules as *deferred* rather
 * than passing them — and this is the other half: something that does have a
 * database, answering them.
 *
 * ## Why the values come from the outcome
 *
 * Pass `$outcome->normalized()`, not the raw input. The canonical value is what
 * would be stored — an IBAN with its spaces removed, a code upper-cased — and a
 * uniqueness check against anything else asks about a row that will not exist.
 *
 * ## `ignore`, and the bug it prevents
 *
 * Editing a row and saving it unchanged must not fail its own uniqueness check.
 * `['id' => 42]` adds `AND id <> 42`, which is the difference between an edit
 * form that works and one that refuses every save that leaves the e-mail alone.
 *
 * ## What this returns
 *
 * `field => 'unique'` or `field => 'exists'` — machine codes, the same
 * vocabulary `Outcome::errors()` speaks, so the two merge. It does not build an
 * `Outcome`: that type belongs to `italix/rules`, and this package does not
 * depend on it — see {@see SchemaRule} for why the seam is where it is.
 *
 * ## What it will not do
 *
 * Interpolate a table or column name it was not sure about. Those arrive as rule
 * parameters, so they are developer-written and not user input, but they are
 * also the one thing here that cannot be bound as a parameter — so they are
 * checked to be identifiers and quoted, and a name that is neither is refused.
 */
final class DatabaseRules
{
    private DataManager $dm;

    public function __construct(DataManager $dm)
    {
        $this->dm = $dm;
    }

    /**
     * Run every `unique` and `exists` in the schedule.
     *
     * @param array<string, RuleMeta[]> $schedule field or path => rules
     * @param array<string, mixed>      $values   field => value, normally `Outcome::normalized()`
     * @param array<string, mixed>      $ignore   column => value identifying the row being edited
     * @return array<string, string> field => error code, empty when everything passed
     */
    public function check(array $schedule, array $values, array $ignore = []): array
    {
        $errors = [];

        foreach ($schedule as $key => $rules) {
            foreach ($rules as $rule) {
                $name = $rule->get_name();

                if ($name !== 'unique' && $name !== 'exists') {
                    continue;
                }

                foreach ($this->paths_for((string) $key, $values) as $path) {
                    if (isset($errors[$path])) {
                        continue;               // one reason per field is enough
                    }

                    $value = $values[$path] ?? null;

                    // An absent or empty value is `required`'s business, not
                    // uniqueness's. Querying for it would ask whether some other
                    // row is also empty, which is a different question.
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (!$this->holds($name, $rule->get_params(), $path, (string) $value, $ignore)) {
                        $errors[$path] = $name;
                    }
                }
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------

    /**
     * Does this one rule hold?
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $ignore
     */
    private function holds(string $name, array $params, string $path, string $value, array $ignore): bool
    {
        $table  = $this->identifier((string) ($params['table'] ?? ''), 'table');
        $column = $this->identifier((string) ($params['column'] ?? '') ?: $this->leaf($path), 'column');

        $sql    = 'SELECT 1 FROM ' . $this->quote($table) . ' WHERE ' . $this->quote($column) . ' = ?';
        $bind   = [$value];

        if ($name === 'unique') {
            foreach ($ignore as $ignore_column => $ignore_value) {
                if ($ignore_value === null || $ignore_value === '') {
                    continue;                   // creating, not editing: nothing to exclude
                }

                $sql   .= ' AND ' . $this->quote($this->identifier((string) $ignore_column, 'column')) . ' <> ?';
                $bind[] = $ignore_value;
            }
        }

        $found = $this->dm->query_one($sql . ' LIMIT 1', $bind) !== null;

        return $name === 'unique' ? !$found : $found;
    }

    /**
     * The concrete keys a schedule key names.
     *
     * A plain field names itself. A key with wildcards — `lines.*.qty` — names
     * every value whose path matches, so an error lands on `lines.1.qty` and the
     * form can point at the row it means.
     *
     * @param array<string, mixed> $values
     * @return string[]
     */
    private function paths_for(string $key, array $values): array
    {
        if (strpos($key, '*') === false) {
            return [$key];
        }

        $pattern = '/^' . str_replace('\*', '[^.]+', preg_quote($key, '/')) . '$/';
        $paths   = [];

        foreach (array_keys($values) as $path) {
            if (preg_match($pattern, (string) $path) === 1) {
                $paths[] = (string) $path;
            }
        }

        return $paths;
    }

    /** `lines.1.qty` names the column `qty`. */
    private function leaf(string $path): string
    {
        $parts = explode('.', $path);

        return (string) end($parts);
    }

    /**
     * An identifier, or an exception.
     *
     * These cannot be bound — a table name is not a value — so the only
     * protection available is refusing anything that is not a name. Schema
     * qualification (`public.users`) is allowed, one dot deep.
     */
    private function identifier(string $name, string $kind): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name) !== 1) {
            throw new RuntimeException(
                'A ' . $kind . ' name is an identifier, and "' . $name . '" is not one. '
                . 'It reached SQL from a validation rule, where it cannot be bound as a value.'
            );
        }

        return $name;
    }

    private function quote(string $name): string
    {
        $dialect = $this->dm->get_driver()->get_dialect_name();
        $parts   = explode('.', $name);

        $quoted = array_map(static function (string $part) use ($dialect): string {
            return $dialect === 'mysql'
                ? '`' . str_replace('`', '``', $part) . '`'
                : '"' . str_replace('"', '""', $part) . '"';
        }, $parts);

        return implode('.', $quoted);
    }
}
