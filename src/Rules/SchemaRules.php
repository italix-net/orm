<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Validation rules derived from the schema
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Rules;

use Italix\Contracts\RuleMeta;
use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;

/**
 * The rules a table already implies.
 *
 *     $schedule = SchemaRules::for_table($users);
 *     $outcome  = $checker->check_all($schedule, $_POST);
 *
 * `varchar(50)` is a promise that a longer value will not survive the insert.
 * `not_null()` is a promise that a missing one will not either. Writing those
 * facts a second time in a form definition is how the two drift apart — the
 * column grows to 100 and the form keeps refusing at 50, or the column shrinks
 * and the form starts accepting values the database truncates.
 *
 * ## What is derived, and from what
 *
 * | schema | rule |
 * |---|---|
 * | `not_null()`, no default, not auto-increment | `required` |
 * | `varchar(n)` / `char(n)` | `max_length` with `n` |
 * | `integer()` / `bigint()` / `smallint()` | `integer` |
 * | `decimal()` / `numeric()` / `real()` / `double_precision()` | `numeric` |
 * | `date()` / `datetime()` / `timestamp()` | `date` |
 * | `uuid()` | `uuid` |
 * | `unique()` | `unique` on this table and column |
 * | `references($table, $column)` | `exists` in that table and column |
 *
 * ## What is not, and why
 *
 * **Primary keys and auto-increment columns.** The database fills them; asking a
 * person for one is asking for the wrong thing.
 *
 * **`text()` and `blob()`.** They declare no length, so there is no number to
 * check against. The engine's own limit is a different thing from a rule.
 *
 * **`boolean()` and `json()`.** There is no rule in the shared vocabulary that
 * means either, and inventing one here — a list of `'1', 'true', 'yes'` — would
 * be this package deciding what a checkbox posts, which it cannot know.
 *
 * **Multi-column unique constraints.** `add_unique('a_b', ['a', 'b'])` says the
 * *pair* is unique. Emitting `unique` on each column separately would refuse
 * values that are perfectly legal — the wrong answer, arrived at confidently.
 *
 * ## And what a schema cannot know
 *
 * That a `NOT NULL` column is filled in by the server rather than the form. That
 * an e-mail column holds e-mail addresses — `varchar(255)` does not say so. So
 * the schedule is a **starting point to add to**, not a finished specification:
 *
 *     $schedule = SchemaRules::for_table($users, ['except' => ['created_by_id']]);
 *     $schedule['email'][] = Rule::email();
 */
final class SchemaRules
{
    /**
     * The schedule for a table.
     *
     * @param array{only?: string[], except?: string[], database?: bool} $options
     *        `only`/`except` select columns; `database` (default true) decides
     *        whether `unique`/`exists` are included — they are the two rules a
     *        checker cannot settle on its own, see {@see DatabaseRules}.
     * @return array<string, RuleMeta[]> column name => rules
     */
    public static function for_table(Table $table, array $options = []): array
    {
        $only     = $options['only'] ?? null;
        $except   = $options['except'] ?? [];
        $database = $options['database'] ?? true;
        $schedule = [];

        foreach ($table->get_columns() as $name => $column) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            if (in_array($name, $except, true)) {
                continue;
            }

            $rules = self::for_column($table, $column, $database);

            if ($rules !== []) {
                $schedule[$name] = $rules;
            }
        }

        return $schedule;
    }

    /**
     * The rules one column implies.
     *
     * @return RuleMeta[]
     */
    public static function for_column(Table $table, Column $column, bool $database = true): array
    {
        // The database fills these in. A form that asks for one is asking the
        // wrong question, and a rule that requires one refuses every insert.
        if ($column->is_primary_key() || $column->is_auto_increment()) {
            return [];
        }

        $rules = [];

        // A NOT NULL column with a default is not a field somebody has to fill:
        // leaving it out is how you ask for the default.
        if (!$column->is_nullable() && !$column->has_default()) {
            $rules[] = new SchemaRule('required');
        }

        $type = strtoupper($column->get_type());

        if (in_array($type, ['VARCHAR', 'CHAR'], true) && $column->get_length() !== null) {
            $rules[] = new SchemaRule('max_length', ['length' => $column->get_length()]);
        }

        if (in_array($type, ['INTEGER', 'BIGINT', 'SMALLINT'], true)) {
            $rules[] = new SchemaRule('integer');
        }

        if (in_array($type, ['DECIMAL', 'NUMERIC', 'REAL', 'DOUBLE_PRECISION'], true)) {
            $rules[] = new SchemaRule('numeric');
        }

        if (in_array($type, ['DATE', 'DATETIME', 'TIMESTAMP'], true)) {
            $rules[] = new SchemaRule('date');
        }

        if ($type === 'UUID') {
            $rules[] = new SchemaRule('uuid');
        }

        if (!$database) {
            return $rules;
        }

        if ($column->is_unique()) {
            $rules[] = new SchemaRule('unique', [
                'table'  => $table->get_name(),
                'column' => $column->get_db_name(),
            ]);
        }

        $references = $column->get_references();

        if ($references !== []) {
            $rules[] = new SchemaRule('exists', [
                'table'  => $references['table'],
                'column' => $references['column'],
            ]);
        }

        return $rules;
    }
}
