<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - View
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use InvalidArgumentException;
use Italix\Orm\QueryBuilder\QueryBuilder;
use RuntimeException;

/**
 * A database view: a stored SELECT that is read like a table.
 *
 *     $active_customers = sqlite_view('active_customers', [
 *         'id'   => serial(),
 *         'name' => varchar(50),
 *     ])->as_query($select_of_active_customers);
 *
 *     $dm->select()->from($active_customers)->where(...)->execute();
 *
 * ## Why it is a Table
 *
 * Because everything that reads one should not have to care. A view extends
 * {@see Table}, so the query builder, the relation loader and `describe_columns()`
 * take it exactly as they take a table — no new code path, and none of them can
 * drift from the table one.
 *
 * The columns are declared here rather than discovered, for the same reason a
 * table's are: this package describes a schema in PHP and does not query the
 * server to find out what it looks like. A view whose declaration and
 * definition disagree is a bug, and one `ix db:pull` would surface.
 *
 * ## Writing is refused, not attempted
 *
 * Some views are updatable and most are not, and which ones depends on the
 * engine, the SELECT, and — on MySQL — the algorithm the optimiser chose. So
 * `INSERT`, `UPDATE` and `DELETE` against a view raise here rather than being
 * sent and failing at the server with a message about the view's algorithm.
 *
 * Measured: SQLite answers `cannot modify v because it is a view`, which is at
 * least clear; the point of refusing earlier is that it names the line in *your*
 * code rather than a statement you did not write.
 *
 * ## `CREATE OR REPLACE` is not portable, and this hides that
 *
 * PostgreSQL, MySQL and MariaDB take `CREATE OR REPLACE VIEW`. **SQLite does
 * not** — measured, `near "OR": syntax error` — and needs a `DROP` first. So
 * {@see to_replace_sql()} returns however many statements the dialect needs, and
 * the caller runs all of them.
 *
 * `CREATE VIEW IF NOT EXISTS` is deliberately not offered: SQLite and MariaDB
 * accept it, MySQL and PostgreSQL do not, and the dialect string says `mysql`
 * for both MySQL and MariaDB. A method that works on the server you have and
 * fails on the one you deploy to is worse than one that does not exist.
 *
 * ## The definition's values are written into the statement
 *
 * `CREATE VIEW` is DDL and carries no parameters, so `where(eq($t->status,
 * 'active'))` — the archetypal view — has to end up in the text. Its values are
 * rendered by {@see literal()}, which takes null, booleans, numbers, strings and
 * `DateTimeInterface` and **refuses everything else** rather than guessing.
 * Strings are escaped for the dialect and never interpolated.
 *
 * This is not the loophole it looks like. A view definition is schema written by
 * the developer, at the same trust level as the table name next to it; the thing
 * a query builder exists to prevent is *runtime* values reaching SQL as text,
 * and those belong in the `WHERE` of the query that reads the view, which binds
 * them as usual.
 */
class View extends Table
{
    /** @var QueryBuilder|string|null what the view selects */
    protected $definition;

    /**
     * A copy owns its columns.
     *
     * {@see Table::__construct()} points every column back at its table, and a
     * shallow copy would leave the copy's columns pointing at the original — so
     * `$view->id` would qualify itself with the other object's name. Same name
     * today, but the two objects are free to diverge, and a bug that only shows
     * up after a rename is not one worth leaving in.
     */
    public function __clone()
    {
        foreach ($this->columns as $name => $column) {
            $copy = clone $column;
            $copy->set_table($this);
            $this->columns[$name] = $copy;
        }
    }

    /**
     * What this view selects.
     *
     * A builder is rendered for the view's own dialect. A string is taken as
     * written — for a definition using something this package cannot express,
     * which is a real case for a view and the reason the door is open.
     *
     * @param QueryBuilder|string $definition
     */
    public function as_query($definition): self
    {
        if (!$definition instanceof QueryBuilder && !is_string($definition)) {
            throw new InvalidArgumentException(
                'A view is defined by a QueryBuilder or a SQL string; '
                . (is_object($definition) ? get_class($definition) : gettype($definition)) . ' given.'
            );
        }

        $view = clone $this;
        $view->definition = $definition;

        return $view;
    }

    /** @return QueryBuilder|string|null */
    public function get_definition()
    {
        return $this->definition;
    }

    public function has_definition(): bool
    {
        return $this->definition !== null;
    }

    /** `CREATE VIEW name AS …` */
    public function to_create_sql(): string
    {
        return 'CREATE VIEW ' . $this->quoted_name() . $this->column_list()
            . ' AS ' . $this->definition_sql();
    }

    /**
     * The statements that replace this view, in order.
     *
     * One on PostgreSQL and MySQL, two on SQLite — which has no `OR REPLACE`
     * and has to be told to drop first.
     *
     * @return string[]
     */
    public function to_replace_sql(): array
    {
        if ($this->is_sqlite()) {
            return [
                'DROP VIEW IF EXISTS ' . $this->quoted_name(),
                $this->to_create_sql(),
            ];
        }

        return [
            'CREATE OR REPLACE VIEW ' . $this->quoted_name() . $this->column_list()
                . ' AS ' . $this->definition_sql(),
        ];
    }

    public function to_drop_sql(): string
    {
        return 'DROP VIEW IF EXISTS ' . $this->quoted_name();
    }

    // -------------------------------------------------------------------------

    /**
     * The SELECT, as text, with the definition's values written in.
     *
     * DDL takes no parameters, so a definition that binds any has to have them
     * rendered — see the class docblock for why that is sound here and not a
     * hole in the builder. Placeholders are replaced only where they are SQL
     * syntax: the walk skips quoted regions, so a `?` inside a string literal in
     * a raw fragment stays a `?`.
     */
    protected function definition_sql(): string
    {
        if ($this->definition === null) {
            throw new RuntimeException(
                'The view "' . $this->get_name() . '" has no definition. '
                . 'Give it one with ->as_query($select).'
            );
        }

        if (is_string($this->definition)) {
            return $this->definition;
        }

        $params = [];
        $sql    = $this->definition->for_dialect($this->get_dialect())->to_sql($params);

        return $params === [] ? $sql : $this->inline_params($sql, $params);
    }

    /**
     * Put each bound value where its placeholder is.
     *
     * Placeholders are `?` on every dialect, because that is the only
     * positional form PDO parses — see `QueryBuilder::get_placeholder()`.
     * Quoted regions are copied through untouched: a `?` inside a string
     * literal is not a placeholder.
     *
     * @param array<int, mixed> $params
     */
    protected function inline_params(string $sql, array $params): string
    {
        $values      = array_values($params);
        $out         = '';
        $next        = 0;
        $quote       = null;
        $length      = strlen($sql);
        $backslashes = $this->get_dialect() === 'mysql';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $out .= $char;

                if ($backslashes && $char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $out .= $sql[++$i];
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out  .= $char;
                continue;
            }

            if ($char === '?') {
                $out .= $this->literal($this->take_param($values, $next++));
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $values
     * @return mixed
     */
    protected function take_param(array $values, int $index)
    {
        if (!array_key_exists($index, $values)) {
            throw new RuntimeException(
                'The definition of view "' . $this->get_name() . '" has more placeholders than '
                . 'bound values, which means its SQL and its parameters disagree.'
            );
        }

        return $values[$index];
    }

    /**
     * One bound value, as SQL text.
     *
     * Anything that is not null, a boolean, a number, a string or a
     * `DateTimeInterface` is refused: there is no encoding for it that is right
     * on every dialect, and guessing one is how a view ends up meaning something
     * slightly different in production.
     *
     * @param mixed $value
     */
    protected function literal($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            // PostgreSQL has a real boolean; MySQL and SQLite store 1/0 and
            // accept TRUE only as an alias, so the number is the portable form.
            if ($this->is_sqlite() || $this->get_dialect() === 'mysql') {
                return $value ? '1' : '0';
            }

            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new RuntimeException(
                    'The definition of view "' . $this->get_name() . '" binds ' . $value
                    . ', which is not a value SQL has.'
                );
            }

            // var_export, not a cast: it does not follow the locale, so a French
            // server does not get a decimal comma in the middle of a statement.
            return var_export($value, true);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->quote_string($value->format('Y-m-d H:i:s'));
        }

        if (is_string($value)) {
            return $this->quote_string($value);
        }

        throw new RuntimeException(
            'The definition of view "' . $this->get_name() . '" binds a '
            . (is_object($value) ? get_class($value) : gettype($value))
            . ', and a CREATE VIEW can only carry null, booleans, numbers, strings and dates. '
            . 'Keep the view structural and put the varying part in the WHERE of the query that '
            . 'reads it.'
        );
    }

    /**
     * A string, quoted for this dialect.
     *
     * The doubled quote is the standard escape and is understood everywhere. The
     * backslash is not standard: MySQL treats it as an escape character unless
     * the server runs with `NO_BACKSLASH_ESCAPES`, so it is doubled there and
     * left alone on SQLite and PostgreSQL, where doubling it would change the
     * value. A NUL byte is refused — no dialect has a literal for it.
     */
    protected function quote_string(string $value): string
    {
        if (strpos($value, "\0") !== false) {
            throw new RuntimeException(
                'The definition of view "' . $this->get_name() . '" binds a string containing a '
                . 'NUL byte, which cannot be written into a statement.'
            );
        }

        if ($this->get_dialect() === 'mysql') {
            $value = str_replace('\\', '\\\\', $value);
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** `(a, b, c)` when the view renames its columns, otherwise nothing. */
    protected function column_list(): string
    {
        if ($this->columns === []) {
            return '';
        }

        $names = [];

        foreach ($this->columns as $column) {
            $names[] = $this->quote_identifier($column->get_db_name());
        }

        return ' (' . implode(', ', $names) . ')';
    }

    protected function quoted_name(): string
    {
        return $this->quote_identifier($this->get_full_name());
    }

    protected function is_sqlite(): bool
    {
        return $this->get_dialect() === 'sqlite';
    }
}
