<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Attribute casting
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Casts;

use Italix\Orm\Operators\SQLExpression;
use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;

/**
 * What `Column::cast_as()` and `enum()` actually do: turn a raw driver value
 * into a PHP one on the way out, and back on the way in.
 *
 * Every row this package hands back is what PDO gave it — a JSON column is
 * the text it was stored as, a boolean is whatever `0`/`1`/`"0"`/`"1"` the
 * driver used, a datetime is a string. Correct, and the reason every reader
 * of a JSON column used to write its own `json_decode()` and every reader of
 * a datetime column its own `new \DateTime(...)` — the same conversion,
 * written again at every call site instead of declared once where the
 * column already is.
 *
 * A plain class rather than a trait or an interface with implementations
 * per type: there is one direction to get wrong twice (encode without its
 * matching decode, or the reverse), and keeping both together is what makes
 * that visible in one place instead of two files that can drift.
 */
final class Cast
{
    /**
     * Raw driver value → PHP value.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function decode(Column $column, $raw)
    {
        if ($raw === null) {
            return null;
        }

        $enum_class = $column->get_enum_class();

        if ($enum_class !== null) {
            // BackedEnum::from() type-checks strictly: an int-backed enum
            // rejects "2" as readily as it rejects a value outside cases().
            // SQLite has no ENUM type of its own — enum() renders it as
            // VARCHAR + CHECK on that dialect (see ColumnTypes::enum()),
            // whose TEXT column affinity stores an int-backed case as text
            // and hands it back the same way. Coerce to the enum's actual
            // backing type first; a value outside the declared cases still
            // throws below, which is the right answer — silently returning
            // the raw string would look like a cast that worked.
            $raw = self::is_int_backed($enum_class) ? (int) $raw : (string) $raw;

            return $enum_class::from($raw);
        }

        switch ($column->get_cast()) {
            case 'array':
                return is_string($raw) ? json_decode($raw, true) : $raw;

            case 'datetime':
                return is_string($raw) ? new \DateTimeImmutable($raw) : $raw;

            case 'bool':
                return (bool) $raw;

            case 'int':
                return (int) $raw;

            case 'float':
                return (float) $raw;

            default:
                return $raw;
        }
    }

    /**
     * PHP value → the value actually bound to the statement.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function encode(Column $column, $value)
    {
        // Schema text, not a value this cast has any business rewriting —
        // `raw('CURRENT_TIMESTAMP')`, a subquery, a case expression. json_
        // encoding an SQLExpression object would silently corrupt the write.
        if ($value instanceof SQLExpression) {
            return $value;
        }

        // No separate null check here (unlike decode()): every case below
        // already passes null through unchanged on its own terms — neither
        // instanceof check matches null, and json_encode()/->format() are
        // never reached for it — so a dedicated early return would be dead
        // code, not a needed guard.
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        switch ($column->get_cast()) {
            case 'array':
                return (is_array($value) || is_object($value)) ? json_encode($value) : $value;

            case 'datetime':
                return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;

            // No 'bool' case: QueryBuilder::bind_params() already binds any
            // PHP bool as PDO::PARAM_BOOL regardless of casting, so encoding
            // it here would be redundant — only the read direction (raw
            // 0/1/"0"/"1" -> real bool) is this cast's job. See decode().
            default:
                return $value;
        }
    }

    /**
     * Every row of a result set, with each castable column decoded.
     *
     * A no-op pass-through when the table has no cast or enum-backed column
     * at all — the ordinary case, and the reason this is safe to call
     * unconditionally after every SELECT rather than only when a caller
     * remembers a cast is in play.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function decode_rows(Table $table, array $rows): array
    {
        $castable = self::castable_columns($table);

        if ($castable === [] || $rows === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($castable as $name => $column) {
                if (array_key_exists($name, $row)) {
                    $row[$name] = self::decode($column, $row[$name]);
                }
            }
        }

        unset($row);

        return $rows;
    }

    /**
     * A row of values about to be written, with each castable column
     * encoded. Used on `INSERT`/`UPDATE` — only the keys actually present
     * are touched, so a partial `UPDATE ... SET` is unaffected in the
     * columns it does not mention.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function encode_values(Table $table, array $values): array
    {
        foreach ($values as $name => $value) {
            $column = $table->get_column($name);

            if ($column !== null && ($column->get_cast() !== null || $column->get_enum_class() !== null)) {
                $values[$name] = self::encode($column, $value);
            }
        }

        return $values;
    }

    /** @var array<class-string<\BackedEnum>, bool> */
    private static array $backing_type_cache = [];

    /** @param class-string<\BackedEnum> $enum_class */
    private static function is_int_backed(string $enum_class): bool
    {
        if (!array_key_exists($enum_class, self::$backing_type_cache)) {
            self::$backing_type_cache[$enum_class] =
                (new \ReflectionEnum($enum_class))->getBackingType()->getName() === 'int';
        }

        return self::$backing_type_cache[$enum_class];
    }

    /** @return array<string, Column> */
    private static function castable_columns(Table $table): array
    {
        $castable = [];

        foreach ($table->get_columns() as $name => $column) {
            if ($column->get_cast() !== null || $column->get_enum_class() !== null) {
                $castable[$name] = $column;
            }
        }

        return $castable;
    }
}
