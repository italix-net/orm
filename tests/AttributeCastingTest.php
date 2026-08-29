<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Column::cast_as() and enum(BackedEnum::class)
 *
 * Every ORM in wide use casts attributes — Eloquent's $casts, Doctrine's
 * embeddables/types, Django's model fields. This package had none: a JSON
 * column came back as the string PDO handed over, and every caller wrote its
 * own json_decode()/new DateTime()/(bool) at the read site, with the inverse
 * conversion written a second time, separately, on the way back in. One of
 * those two ends drifting out of sync is exactly the bug class this closes.
 *
 * `Italix\Orm\Casts\Cast` is the single place both directions live. It is
 * exercised here through both query engines independently — QueryBuilder
 * (select()/insert()/update()) and TableQuery (query_table()) do not share
 * a row-fetching implementation, so a cast proven on one says nothing about
 * the other; each gets its own assertions below, the same principle already
 * applied to soft_deletes() in TimestampsAndSoftDeleteTest.php.
 *
 * enum(BackedEnum::class) reuses the same Cast machinery: the allowed values
 * are read from the enum's own cases() instead of being typed a second time,
 * and a read hydrates a real enum instance rather than a bare string.
 *
 * Run: php src/Libs/Italix/Orm/tests/AttributeCastingTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\Casts\Cast;

use function Italix\Orm\Schema\{integer, varchar, text, boolean, sqlite_table, enum};
use function Italix\Orm\Operators\{eq, raw};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Column::cast_as() and enum(BackedEnum::class)');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

enum OrderStatus: string
{
    case Draft   = 'draft';
    case Placed  = 'placed';
    case Shipped = 'shipped';
}

enum Priority: int
{
    case Low  = 1;
    case High = 2;
}

// Not a backed enum on purpose — used below to prove enum() rejects it.
enum PlainEnum
{
    case One;
}

// -----------------------------------------------------------------------------
section('Column::cast_as(\'array\') — JSON string <-> PHP array, through QueryBuilder');

$orders = sqlite_table('orders', [
    'id'       => integer()->primary_key()->auto_increment(),
    'metadata' => text()->cast_as('array'),
]);
$dm = sqlite_memory();
$dm->create_tables($orders);

$dm->insert($orders)->values(['metadata' => ['a' => 1, 'b' => [2, 3]]])->execute();
$raw_row = $dm->query('SELECT metadata FROM orders')[0];
test('the raw column actually holds a JSON string, not a PHP array', is_string($raw_row['metadata']) && json_decode($raw_row['metadata'], true) === ['a' => 1, 'b' => [2, 3]]);

$decoded = $dm->select()->from($orders)->execute();
test('select() hydrates it back to a PHP array', $decoded[0]['metadata'] === ['a' => 1, 'b' => [2, 3]]);

$dm->update($orders)->set(['metadata' => ['x' => 'y']])->where(eq($orders->id, 1))->execute();
$after_update = $dm->select()->from($orders)->execute();
test('update() encodes an array value too', $after_update[0]['metadata'] === ['x' => 'y']);

$dm->execute('DELETE FROM orders');
$dm->insert($orders)->values(['metadata' => null])->execute();
$null_row = $dm->select()->from($orders)->execute();
test('a null value passes through uncast in both directions', $null_row[0]['metadata'] === null);

// -----------------------------------------------------------------------------
section('Column::cast_as(\'datetime\') — string <-> DateTimeImmutable');

$events = sqlite_table('events', [
    'id'      => integer()->primary_key()->auto_increment(),
    'when_dt' => varchar(30)->cast_as('datetime'),
]);
$dm->create_tables($events);

$dm->insert($events)->values(['when_dt' => new \DateTimeImmutable('2020-06-01 12:30:00')])->execute();
$raw_event = $dm->query('SELECT when_dt FROM events')[0];
test('the raw column holds a plain string', $raw_event['when_dt'] === '2020-06-01 12:30:00');

$event = $dm->select()->from($events)->execute()[0];
test('select() hydrates a DateTimeImmutable', $event['when_dt'] instanceof \DateTimeImmutable);
test('…with the right instant', $event['when_dt']->format('Y-m-d H:i:s') === '2020-06-01 12:30:00');

// -----------------------------------------------------------------------------
section('Column::cast_as(\'bool\') / (\'int\') / (\'float\')');

$flags = sqlite_table('flags', [
    'id'       => integer()->primary_key()->auto_increment(),
    'active'   => integer()->cast_as('bool'),
    'score'    => text()->cast_as('float'),
    'hits'     => text()->cast_as('int'),
]);
$dm->create_tables($flags);

$dm->insert($flags)->values(['active' => true, 'score' => 3.5, 'hits' => 7])->execute();
$flag_raw = $dm->query('SELECT active FROM flags')[0];
test('a PHP true is stored as 1, not the literal boolean', $flag_raw['active'] === 1 || $flag_raw['active'] === '1');

$flag = $dm->select()->from($flags)->execute()[0];
test('select() hydrates a real bool back', $flag['active'] === true);
test('…and a float back', $flag['score'] === 3.5);

$dm->execute('DELETE FROM flags');
$dm->execute("INSERT INTO flags (active, score, hits) VALUES (1, '3.5', '7')");
$hits_row = $dm->select()->from($flags)->execute()[0];
test('cast_as(\'int\') hydrates a real int, not the stored string', $hits_row['hits'] === 7 && is_int($hits_row['hits']));

// A bare (bool)/(int)/(float) cast of null is false/0/0.0, not null — the
// early null check in Cast::decode() is what keeps a genuinely absent value
// absent instead of manufacturing a false zero.
$dm->execute('DELETE FROM flags');
$dm->execute('INSERT INTO flags (active, score, hits) VALUES (NULL, NULL, NULL)');
$null_flags = $dm->select()->from($flags)->execute()[0];
test('a NULL bool-cast value decodes to null, not false', $null_flags['active'] === null);
test('a NULL int-cast value decodes to null, not 0', $null_flags['hits'] === null);
test('a NULL float-cast value decodes to null, not 0.0', $null_flags['score'] === null);

// -----------------------------------------------------------------------------
section('cast_as() reaches TableQuery (query_table()) independently of QueryBuilder');

$dm->execute('DELETE FROM orders');
$dm->insert($orders)->values(['metadata' => ['via' => 'table_query']])->execute();

$via_table_query = $dm->query_table($orders)->find_many();
test(
    'query_table()->find_many() hydrates the array too — a separate engine, its own wiring',
    $via_table_query[0]['metadata'] === ['via' => 'table_query']
);

$one = $dm->query_table($orders)->find((int) $via_table_query[0]['id']);
test('query_table()->find($id) hydrates it as well', $one['metadata'] === ['via' => 'table_query']);

// -----------------------------------------------------------------------------
section('a raw SQLExpression is never cast/encoded — it would corrupt the fragment');

$stamped = sqlite_table('stamped', [
    'id'         => integer()->primary_key()->auto_increment(),
    'created_at' => varchar(30)->cast_as('datetime'),
]);
$dm->create_tables($stamped);

$dm->insert($stamped)->values(['created_at' => raw("'2021-01-01 00:00:00'")])->execute();
$stamped_row = $dm->query('SELECT created_at FROM stamped')[0];
test('a raw() value is written verbatim, not date-encoded into garbage', $stamped_row['created_at'] === '2021-01-01 00:00:00');

// The 'array' cast is the branch that actually proves the guard: without it,
// json_encode() on a RawExpression object (only its public properties, which
// it has none of) silently turns the fragment into "{}" before build_insert()
// ever gets a chance to recognise it as SQLExpression and inline it.
$notes = sqlite_table('notes', [
    'id'   => integer()->primary_key()->auto_increment(),
    'tags' => text()->cast_as('array'),
]);
$dm->create_tables($notes);
$dm->insert($notes)->values(['tags' => raw("'[\"a\",\"b\"]'")])->execute();
$notes_row = $dm->query('SELECT tags FROM notes')[0];
test('…same guard, on an array-cast column, where json_encode() would actually corrupt it', $notes_row['tags'] === '["a","b"]');

// -----------------------------------------------------------------------------
section('a table with no cast column at all is a no-op — Cast::decode_rows()/encode_values() fast path');

$plain = sqlite_table('plain_orders', [
    'id'   => integer()->primary_key()->auto_increment(),
    'name' => varchar(50)->not_null(),
]);
$dm->create_tables($plain);
$dm->insert($plain)->values(['name' => 'Widget'])->execute();
$plain_rows = $dm->select()->from($plain)->execute();
test('an ordinary table round-trips unaffected', $plain_rows[0]['name'] === 'Widget');

// -----------------------------------------------------------------------------
section('enum(BackedEnum::class) — DDL carries the same values a plain enum([...]) would');

$tickets_mysql = \Italix\Orm\Schema\mysql_table('tickets', [
    'status' => enum(OrderStatus::class)->not_null(),
]);
test(
    'the CHECK/ENUM values come from ::cases(), not a hand-typed array',
    strpos($tickets_mysql->to_create_sql(), "ENUM('draft', 'placed', 'shipped')") !== false
);

// -----------------------------------------------------------------------------
section('enum(BackedEnum::class) — hydrates a real enum instance on read, encodes on write');

$tickets = sqlite_table('tickets', [
    'id'     => integer()->primary_key()->auto_increment(),
    'status' => enum(OrderStatus::class)->not_null(),
]);
$dm->create_tables($tickets);

$dm->insert($tickets)->values(['status' => OrderStatus::Placed])->execute();
$raw_ticket = $dm->query('SELECT status FROM tickets')[0];
test('the stored value is the plain scalar (->value), not a serialized object', $raw_ticket['status'] === 'placed');

$ticket = $dm->select()->from($tickets)->execute()[0];
test('select() hydrates a real OrderStatus instance', $ticket['status'] instanceof OrderStatus);
test('…the correct case', $ticket['status'] === OrderStatus::Placed);

$via_table_query_enum = $dm->query_table($tickets)->find_many();
test('query_table() hydrates the enum too', $via_table_query_enum[0]['status'] === OrderStatus::Placed);

[$threw] = (static function () use ($dm, $tickets): array {
    try {
        $dm->execute('DELETE FROM tickets');
        $dm->execute("INSERT INTO tickets (status) VALUES ('bogus')");
        $dm->select()->from($tickets)->execute();

        return [false];
    } catch (\Throwable $e) {
        return [true];
    }
})();
test('a stored value outside the declared cases throws on read rather than silently passing the raw string through', $threw);

// -----------------------------------------------------------------------------
section('enum() with an int-backed enum');

$jobs = sqlite_table('jobs', [
    'id'       => integer()->primary_key()->auto_increment(),
    'priority' => enum(Priority::class)->not_null(),
]);
$dm->create_tables($jobs);
$dm->insert($jobs)->values(['priority' => Priority::High])->execute();
$job_raw = $dm->query('SELECT priority FROM jobs')[0];
test('an int-backed case is stored as its int value', $job_raw['priority'] === 2 || $job_raw['priority'] === '2');
$job = $dm->select()->from($jobs)->execute()[0];
test('…and hydrates back to the right case', $job['priority'] === Priority::High);

// -----------------------------------------------------------------------------
section('enum() rejects a class that is not a BackedEnum');

[$threw_plain] = (static function (): array {
    try {
        enum(PlainEnum::class);

        return [false];
    } catch (\InvalidArgumentException $e) {
        return [true];
    }
})();
test('a non-backed enum class is refused with a clear exception, not a confusing failure later', $threw_plain);

[$threw_missing] = (static function (): array {
    try {
        enum(\stdClass::class);

        return [false];
    } catch (\InvalidArgumentException $e) {
        return [true];
    }
})();
test('a plain class name is refused the same way', $threw_missing);

test(
    'a plain array of values still works exactly as before — no enum_class set',
    enum(['a', 'b'])->get_enum_class() === null
);

exit(summary());
