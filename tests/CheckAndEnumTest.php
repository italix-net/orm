<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — CHECK constraints and enum()
 *
 * Two gaps found comparing this package against Drizzle: no way to say
 * `CHECK (total_cents >= 0)` at all, and `enum()` existing only as a
 * migration DDL call with no equivalent in the schema layer a model actually
 * queries with.
 *
 * Both are exercised the way this suite exercises everything that renders
 * SQL: the rendering is asserted per dialect (MySQL and PostgreSQL cannot be
 * executed here), and where SQLite can run it, the constraint is proven by
 * inserting a row that violates it and watching the server refuse — a
 * string comparison would pass on SQL that renders correctly and enforces
 * nothing.
 *
 * `enum()` on PostgreSQL/SQLite is not a new type — it is `VARCHAR(255)`
 * plus the same `CHECK (col IN (...))` a hand-written one would be. The
 * migration side already had `Blueprint::enum()`; on every dialect but MySQL
 * it rendered `VARCHAR(255)` and stopped, silently accepting anything. That
 * regression gets its own assertion below.
 *
 * Run: php src/Libs/Italix/Orm/tests/CheckAndEnumTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',               // checked out on its own
        __DIR__ . '/../../../../../vendor/autoload.php',   // vendored in a project
        __DIR__ . '/../../../../vendor/autoload.php',      // installed as a package
        __DIR__ . '/../../../autoload.php',                // sibling autoloader
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\Migration\Blueprint;

use function Italix\Orm\Schema\{integer, mysql_table, pg_table, sqlite_table, enum};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - check() and enum()');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

/** @return array{0: bool, 1: string} */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (\Throwable $e) {
        return [true, $e->getMessage()];
    }
};

// -----------------------------------------------------------------------------
section('Column::check() renders an inline CHECK, identically on every dialect');

$mysql_orders = mysql_table('orders', [
    'id'          => integer()->primary_key()->auto_increment(),
    'total_cents' => integer()->unsigned()->not_null()->check('total_cents >= 0'),
]);
test('MySQL carries the clause', strpos($mysql_orders->to_create_sql(), 'CHECK (total_cents >= 0)') !== false);

$pg_orders = pg_table('orders', [
    'id'          => integer()->primary_key()->auto_increment(),
    'total_cents' => integer()->not_null()->check('total_cents >= 0'),
]);
test('PostgreSQL: same wording', strpos($pg_orders->to_create_sql(), 'CHECK (total_cents >= 0)') !== false);

$sqlite_orders = sqlite_table('orders', [
    'id'          => integer()->primary_key()->auto_increment(),
    'total_cents' => integer()->not_null()->check('total_cents >= 0'),
]);
$sqlite_orders_sql = $sqlite_orders->to_create_sql();
test('SQLite: same wording', strpos($sqlite_orders_sql, 'CHECK (total_cents >= 0)') !== false);

// -----------------------------------------------------------------------------
section('…and it is enforced by the server, not only rendered');

$dm = sqlite_memory();
$dm->execute($sqlite_orders_sql);
$dm->execute('INSERT INTO orders (total_cents) VALUES (?)', [1000]);

test('a value that satisfies the check is written', (int) $dm->query('SELECT COUNT(*) AS n FROM orders')[0]['n'] === 1);

[$threw] = $throws(fn () => $dm->execute('INSERT INTO orders (total_cents) VALUES (?)', [-500]));
test('a negative total is refused by SQLite itself', $threw);
test('…and did not get written', (int) $dm->query('SELECT COUNT(*) AS n FROM orders')[0]['n'] === 1);

// -----------------------------------------------------------------------------
section('calling check() twice adds two clauses, neither replacing the other');

$bounded = sqlite_table('bounded', [
    'id' => integer()->primary_key()->auto_increment(),
    'n'  => integer()->not_null()->check('n >= 0')->check('n <= 100'),
]);
$bounded_sql = $bounded->to_create_sql();
test('both clauses appear in the rendered SQL', substr_count($bounded_sql, 'CHECK (') === 2);

$dm_bounded = sqlite_memory();
$dm_bounded->execute($bounded_sql);
$dm_bounded->execute('INSERT INTO bounded (n) VALUES (?)', [50]);
test('a value inside both bounds is accepted', (int) $dm_bounded->query('SELECT COUNT(*) AS n FROM bounded')[0]['n'] === 1);

[$threw] = $throws(fn () => $dm_bounded->execute('INSERT INTO bounded (n) VALUES (?)', [150]));
test('a value violating only the second clause is still refused', $threw);

// -----------------------------------------------------------------------------
section('Table::add_check() adds a table-level constraint spanning more than one column');

$shipping = sqlite_table('orders', [
    'id'         => integer()->primary_key()->auto_increment(),
    'placed_dt'  => integer()->not_null(),
    'shipped_dt' => integer()->not_null(),
]);
$shipping->add_check('valid_shipping', 'placed_dt < shipped_dt');
$shipping_sql = $shipping->to_create_sql();

test(
    'the constraint is named and rendered as CONSTRAINT ... CHECK',
    strpos($shipping_sql, 'CONSTRAINT "valid_shipping" CHECK (placed_dt < shipped_dt)') !== false
);

$dm_shipping = sqlite_memory();
$dm_shipping->execute($shipping_sql);
$dm_shipping->execute('INSERT INTO orders (placed_dt, shipped_dt) VALUES (?, ?)', [100, 200]);
test('a row where the rule holds is accepted', (int) $dm_shipping->query('SELECT COUNT(*) AS n FROM orders')[0]['n'] === 1);

[$threw] = $throws(fn () => $dm_shipping->execute(
    'INSERT INTO orders (placed_dt, shipped_dt) VALUES (?, ?)',
    [200, 100]
));
test('a row where it does not is refused — no single column could have said this alone', $threw);

// -----------------------------------------------------------------------------
section('enum() is a native ENUM on MySQL, a constrained VARCHAR everywhere else');

$statuses = ['draft', 'placed', 'shipped', 'cancelled'];

$mysql_status = mysql_table('orders', ['status' => enum($statuses)->not_null()]);
test(
    'MySQL renders a native ENUM(...)',
    strpos($mysql_status->to_create_sql(), "ENUM('draft', 'placed', 'shipped', 'cancelled')") !== false
);

$pg_status     = pg_table('orders', ['status' => enum($statuses)->not_null()]);
$pg_status_sql = $pg_status->to_create_sql();
test('PostgreSQL falls back to VARCHAR(255)', strpos($pg_status_sql, 'VARCHAR(255)') !== false);
test(
    '…constrained by an IN (...) check carrying the same values',
    strpos($pg_status_sql, 'CHECK ("status" IN (\'draft\', \'placed\', \'shipped\', \'cancelled\'))') !== false
);

$sqlite_status     = sqlite_table('orders', ['status' => enum($statuses)->not_null()]);
$sqlite_status_sql = $sqlite_status->to_create_sql();

$dm_status = sqlite_memory();
$dm_status->execute($sqlite_status_sql);
$dm_status->execute('INSERT INTO orders (status) VALUES (?)', ['placed']);
test('a listed value is accepted', (int) $dm_status->query('SELECT COUNT(*) AS n FROM orders')[0]['n'] === 1);

[$threw] = $throws(fn () => $dm_status->execute('INSERT INTO orders (status) VALUES (?)', ['bogus']));
test('a value outside the list is refused — SQLite has no native ENUM to refuse it for us', $threw);

// -----------------------------------------------------------------------------
section('the same two calls from a migration Blueprint');

$bp_column = new Blueprint('orders', 'sqlite');
$bp_column->integer('total_cents')->not_null()->check('total_cents >= 0');
test(
    'a column-level check() from a migration renders the same clause',
    strpos($bp_column->to_create_sql(), 'CHECK (total_cents >= 0)') !== false
);

$bp_table = new Blueprint('orders', 'sqlite');
$bp_table->integer('placed_dt')->not_null();
$bp_table->integer('shipped_dt')->not_null();
$bp_table->check('placed_dt < shipped_dt', 'valid_shipping');
test(
    'a table-level check() from a migration is named and rendered',
    strpos($bp_table->to_create_sql(), 'CONSTRAINT "valid_shipping" CHECK (placed_dt < shipped_dt)') !== false
);

// The regression this suite exists to close: Blueprint::enum() already
// existed, and on every dialect but MySQL its own comment said the CHECK
// was "handled separately" while nothing handled it — the column accepted
// any string at all. Confirmed by executing, not by reading the diff.
$bp_enum = new Blueprint('orders', 'sqlite');
$bp_enum->id();
$bp_enum->enum('status', $statuses)->not_null();

$dm_bp_enum = sqlite_memory();
$dm_bp_enum->execute($bp_enum->to_create_sql());
$dm_bp_enum->execute('INSERT INTO orders (status) VALUES (?)', ['draft']);
test('a migration enum() accepts a listed value', (int) $dm_bp_enum->query('SELECT COUNT(*) AS n FROM orders')[0]['n'] === 1);

[$threw] = $throws(fn () => $dm_bp_enum->execute('INSERT INTO orders (status) VALUES (?)', ['bogus']));
test('…and now refuses one outside the list — this used to pass silently', $threw);

// -----------------------------------------------------------------------------
section('ALTER TABLE ... ADD CONSTRAINT CHECK is refused outright on SQLite, emitted elsewhere');

$bp_alter_sqlite = new Blueprint('orders', 'sqlite');
$bp_alter_sqlite->check('total_cents >= 0', 'positive_total');
[$threw, $message] = $throws(fn () => $bp_alter_sqlite->to_alter_sql());
test('adding a check via ALTER on SQLite raises rather than emitting SQL that would fail', $threw);
test('…and the message says what to do instead', strpos($message, 'ADD CONSTRAINT') !== false);

$bp_alter_mysql = new Blueprint('orders', 'mysql');
$bp_alter_mysql->check('total_cents >= 0', 'positive_total');
$mysql_alter_statements = $bp_alter_mysql->to_alter_sql();
test(
    'the same call on MySQL emits ALTER TABLE ... ADD CONSTRAINT',
    strpos($mysql_alter_statements[0], 'ADD CONSTRAINT `positive_total` CHECK (total_cents >= 0)') !== false
);

exit(summary());
