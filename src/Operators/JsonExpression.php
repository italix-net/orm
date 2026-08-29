<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Reading inside a JSON column
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Operators;

use InvalidArgumentException;
use Italix\Orm\Schema\Column;

/**
 * A path into a JSON document, written once and rendered per dialect.
 *
 * The syntax accepted is MySQL's and SQLite's — `$.meta.age`, `$.tags[0]`,
 * `$."odd,key"` — because it is the one most people have seen. PostgreSQL wants
 * a `text[]` instead, so the same path becomes `{"meta","age"}` there.
 *
 * **Every segment is quoted on the way to PostgreSQL**, which is not decoration:
 * an unquoted array literal ends at the first comma or brace, so a key
 * containing one would silently address a different place in the document.
 * Measured — `{"odd,key"}` finds it, `{odd,key}` looks for `odd` then `key`.
 *
 * The rendered path is **bound as a parameter** on all three dialects, so no
 * part of it is ever interpolated into SQL.
 */
final class JsonPath
{
    /** @var array<int, string> the segments, in order */
    private array $segments;

    private string $source;

    public function __construct(string $path)
    {
        $this->source   = $path;
        $this->segments = self::parse($path);
    }

    /** @return array<int, string> */
    private static function parse(string $path): array
    {
        $trimmed = trim($path);

        if ($trimmed === '' || $trimmed[0] !== '$') {
            throw new InvalidArgumentException(
                'A JSON path starts at the document root: "$", "$.name", "$.tags[0]". '
                . '"' . $path . '" does not.'
            );
        }

        $rest     = substr($trimmed, 1);
        $segments = [];

        while ($rest !== '') {
            if (preg_match('/^\.("([^"]*)"|[A-Za-z_][A-Za-z0-9_]*)/', $rest, $matches) === 1) {
                $segments[] = isset($matches[2]) && $matches[0][1] === '"' ? $matches[2] : $matches[1];
                $rest       = substr($rest, strlen($matches[0]));
                continue;
            }

            if (preg_match('/^\[(\d+)\]/', $rest, $matches) === 1) {
                $segments[] = $matches[1];
                $rest       = substr($rest, strlen($matches[0]));
                continue;
            }

            throw new InvalidArgumentException(
                'A JSON path is made of `.key`, `."quoted key"` and `[0]`; "' . $path
                . '" has something else at "' . $rest . '".'
            );
        }

        return $segments;
    }

    /** The path as this dialect wants it, ready to bind. */
    public function for_dialect(string $dialect): string
    {
        if ($dialect === 'mysql' || $dialect === 'sqlite') {
            $path = '$';

            foreach ($this->segments as $segment) {
                $path .= ctype_digit($segment)
                    ? '[' . $segment . ']'
                    : (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) === 1
                        ? '.' . $segment
                        : '."' . $segment . '"');
            }

            return $path;
        }

        // PostgreSQL: a text[] literal, every element quoted — see the class note.
        $quoted = array_map(
            static fn(string $segment): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $segment) . '"',
            $this->segments
        );

        return '{' . implode(',', $quoted) . '}';
    }

    public function is_root(): bool
    {
        return $this->segments === [];
    }

    public function __toString(): string
    {
        return $this->source;
    }
}

/**
 * A value read out of a JSON column.
 *
 *     json_text($orders->doc, '$.customer.name')          // as text
 *     json_get($orders->doc, '$.items')                   // as JSON
 *     json_length($orders->doc, '$.items')                // how many
 *
 * These are expressions, so they go wherever one goes — a `SELECT` list, an
 * `ORDER BY`, and either side of a comparison:
 *
 *     ->where(eq(json_text($orders->doc, '$.status'), 'paid'))
 *
 * ## The three dialects do not agree about anything here
 *
 * Measured on SQLite 3.31, MariaDB 10.3 and PostgreSQL 12:
 *
 * | | SQLite | MySQL / MariaDB | PostgreSQL |
 * |---|---|---|---|
 * | text at a path | `json_extract(c, ?)` | `JSON_UNQUOTE(JSON_EXTRACT(c, ?))` | `c #>> ?::text[]` |
 * | JSON at a path | `json_extract(c, ?)` | `JSON_EXTRACT(c, ?)` | `c #> ?::text[]` |
 * | array length | `json_array_length(c, ?)` | `JSON_LENGTH(c, ?)` | `jsonb_array_length(c #> ?::text[])` |
 *
 * **`JSON_EXTRACT` on MySQL returns JSON, not text** — `"Ada"`, with the quotes
 * — so comparing it to `'Ada'` is comparing a two-character-longer string. That
 * is what `json_text()` exists for, and why it is not the same call as
 * `json_get()`.
 *
 * **The `->` and `->>` operators are not used**, although MySQL 5.7 and SQLite
 * 3.38 have them: this SQLite (3.31) and this MariaDB (10.3) both answer them
 * with a syntax error. The function forms work everywhere those operators do.
 */
final class JsonExpression implements SQLExpression
{
    use SqlHelper;

    public const AS_TEXT   = 'text';
    public const AS_JSON   = 'json';
    public const AS_LENGTH = 'length';

    /** @var Column|SQLExpression */
    private $document;

    private JsonPath $path;

    private string $mode;

    private ?string $alias = null;

    /** @param Column|SQLExpression $document */
    public function __construct($document, string $path = '$', string $mode = self::AS_TEXT)
    {
        if (!$document instanceof Column && !$document instanceof SQLExpression) {
            throw new InvalidArgumentException(
                'A JSON expression reads a column or another expression; '
                . (is_object($document) ? get_class($document) : gettype($document)) . ' given.'
            );
        }

        $this->document = $document;
        $this->path     = new JsonPath($path);
        $this->mode     = $mode;
    }

    /** The name this takes in the result set. */
    public function as(string $alias): self
    {
        $copy        = clone $this;
        $copy->alias = $alias;

        return $copy;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $column = $this->render_operand($this->document, $dialect, $params);
        $sql    = $this->render($column, $dialect, $params);

        if ($this->alias !== null) {
            $sql .= ' AS ' . $this->quote_identifier($this->alias, $dialect);
        }

        return $sql;
    }

    // -------------------------------------------------------------------------

    private function render(string $column, string $dialect, array &$params): string
    {
        if ($dialect === 'sqlite') {
            return $this->render_sqlite($column, $dialect, $params);
        }

        if ($dialect === 'mysql') {
            return $this->render_mysql($column, $dialect, $params);
        }

        return $this->render_postgres($column, $dialect, $params);
    }

    private function render_sqlite(string $column, string $dialect, array &$params): string
    {
        $path = $this->bind_path($dialect, $params);

        if ($this->mode === self::AS_LENGTH) {
            return "json_array_length({$column}, {$path})";
        }

        // One function for both: SQLite hands back the SQL value for a scalar
        // and the JSON text for an object or an array, which is what each mode
        // wants anyway.
        return "json_extract({$column}, {$path})";
    }

    private function render_mysql(string $column, string $dialect, array &$params): string
    {
        $path = $this->bind_path($dialect, $params);

        if ($this->mode === self::AS_LENGTH) {
            return "JSON_LENGTH({$column}, {$path})";
        }

        if ($this->mode === self::AS_JSON) {
            return "JSON_EXTRACT({$column}, {$path})";
        }

        // JSON_EXTRACT alone returns `"Ada"`, quotes included.
        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, {$path}))";
    }

    private function render_postgres(string $column, string $dialect, array &$params): string
    {
        $path = $this->bind_path($dialect, $params) . '::text[]';

        if ($this->mode === self::AS_LENGTH) {
            return "jsonb_array_length(({$column} #> {$path})::jsonb)";
        }

        return $this->mode === self::AS_JSON
            ? "({$column} #> {$path})"
            : "({$column} #>> {$path})";
    }

    /** The path, bound rather than written into the statement. */
    private function bind_path(string $dialect, array &$params): string
    {
        $params[] = $this->path->for_dialect($dialect);

        return $this->get_placeholder(count($params), $dialect);
    }
}

/**
 * A question about a JSON document that answers true or false.
 *
 *     ->where(json_has($orders->doc, '$.shipping.tracking'))
 *     ->where(json_contains($orders->doc, ['status' => 'paid']))
 *
 * ## Containment is not portable, and this says so
 *
 * PostgreSQL has `@>` and MySQL has `JSON_CONTAINS()`; **SQLite has neither**,
 * and there is no rewrite that means the same thing for an arbitrary document.
 * So `json_contains()` refuses on SQLite rather than approximating — the same
 * choice `distinct_on()` makes, for the same reason: a condition that quietly
 * matches a different set of rows is worse than one that will not run.
 *
 * On PostgreSQL containment is a **`jsonb` operator**: `json @> jsonb` does not
 * exist (measured). A `json()` column has to be declared `jsonb()`, or cast.
 *
 * ## `json_has()` works everywhere, at any depth
 *
 * PostgreSQL's own key-exists operator is `?`, which **PDO parses as a
 * placeholder** — `doc ? 'name'` reaches the server as `doc $1 'name'` and is a
 * syntax error. It can be escaped as `??`, and this uses `(doc #> path) IS NOT
 * NULL` instead: no escaping to remember, and it answers for a nested path
 * rather than only a top-level key.
 */
final class JsonCondition implements SQLExpression
{
    use SqlHelper;

    public const HAS      = 'has';
    public const CONTAINS = 'contains';

    /** @var Column|SQLExpression */
    private $document;

    private string $mode;

    private ?JsonPath $path;

    /** @var mixed */
    private $value;

    private bool $negated;

    /**
     * @param Column|SQLExpression $document
     * @param mixed $value a value for CONTAINS, a path string for HAS
     */
    public function __construct($document, string $mode, $value, bool $negated = false)
    {
        $this->document = $document;
        $this->mode     = $mode;
        $this->negated  = $negated;
        $this->path     = $mode === self::HAS ? new JsonPath((string) $value) : null;
        $this->value    = $value;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $column = $this->render_operand($this->document, $dialect, $params);

        return $this->mode === self::HAS
            ? $this->render_has($column, $dialect, $params)
            : $this->render_contains($column, $dialect, $params);
    }

    // -------------------------------------------------------------------------

    private function render_has(string $column, string $dialect, array &$params): string
    {
        if ($this->path === null || $this->path->is_root()) {
            throw new InvalidArgumentException(
                'json_has() asks whether a path is there, so it needs one: "$" is the whole document, '
                . 'which is there by definition.'
            );
        }

        $params[]    = $this->path->for_dialect($dialect);
        $placeholder = $this->get_placeholder(count($params), $dialect);

        // The opposite of `IS NOT NULL` is `IS NULL`, not `IS NOT NOT NULL` —
        // which parses on neither server and was caught by the one assertion
        // that asked for the negative.
        $test = $this->negated ? 'IS NULL' : 'IS NOT NULL';

        if ($dialect === 'sqlite') {
            return "json_type({$column}, {$placeholder}) {$test}";
        }

        if ($dialect === 'mysql') {
            return ($this->negated ? 'NOT ' : '') . "JSON_CONTAINS_PATH({$column}, 'one', {$placeholder})";
        }

        return "({$column} #> {$placeholder}::text[]) {$test}";
    }

    private function render_contains(string $column, string $dialect, array &$params): string
    {
        if ($dialect === 'sqlite') {
            throw new InvalidArgumentException(
                'SQLite has no JSON containment — neither PostgreSQL\'s @> nor MySQL\'s '
                . 'JSON_CONTAINS() — and there is no rewrite that means the same for an arbitrary '
                . 'document. Ask about a specific path with json_has() or json_text() instead.'
            );
        }

        $params[]    = is_string($this->value) ? $this->value : json_encode($this->value);
        $placeholder = $this->get_placeholder(count($params), $dialect);
        $not         = $this->negated ? 'NOT ' : '';

        if ($dialect === 'mysql') {
            return "{$not}JSON_CONTAINS({$column}, {$placeholder})";
        }

        // `@>` is a jsonb operator; a json column has to be declared jsonb().
        return "{$not}({$column} @> {$placeholder}::jsonb)";
    }
}
