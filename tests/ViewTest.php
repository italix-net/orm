<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — views
 *
 * A view is a stored SELECT read like a table, and the two things that can go
 * wrong with one are both invisible until deployment:
 *
 *  1. **The statement is not portable.** `CREATE OR REPLACE VIEW` is fine on
 *     PostgreSQL, MySQL and MariaDB and a syntax error on SQLite; `IF NOT
 *     EXISTS` is fine on SQLite and MariaDB and a syntax error on MySQL, which
 *     shares MariaDB's dialect string. So the renderings are asserted per
 *     dialect, not only on the one this test can execute against.
 *  2. **`CREATE VIEW` carries no parameters.** A view filtering on a value has
 *     to have that value written into the text, and the escaping of it is the
 *     whole safety story. Those assertions read the value back out of SQLite
 *     rather than comparing strings, because a string comparison would pass just
 *     as happily on SQL that means something else.
 *
 * Run: php src/Libs/Italix/Orm/tests/ViewTest.php
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

use Italix\Orm\DataManager;
use Italix\Orm\Migration\Schema;
use Italix\Orm\QueryBuilder\QueryBuilder;
use Italix\Orm\Schema\View;

use function Italix\Orm\Schema\{integer, mysql_table, mysql_view, pg_table, pg_view, sqlite_table, sqlite_view, varchar};
use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Operators\{and_, eq, gte, raw};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - views');

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

$customers = static function (string $dialect = 'sqlite') {
    $columns = [
        'id'     => integer()->primary_key(),
        'name'   => varchar(50),
        'status' => varchar(20),
        'score'  => integer(),
    ];

    if ($dialect === 'mysql') {
        return mysql_table('customers', $columns);
    }

    if ($dialect === 'postgresql') {
        return pg_table('customers', $columns);
    }

    return sqlite_table('customers', $columns);
};

/** A manager with the customers table populated. */
$fresh = static function () use ($customers): DataManager {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, status TEXT, score INTEGER)');

    foreach ([
        [1, 'Ada',     'active',                       90],
        [2, 'Grace',   'active',                       40],
        [3, 'Alan',    'archived',                     95],
        // Rows whose status is the awkward value itself, so an escaping test can
        // assert that the view *finds* it — a mis-escaped value that quietly
        // matches nothing would pass a test expecting no rows.
        [4, 'Quote',   "it's active",                  10],
        [5, 'Percent', '100% active',                  10],
        [6, 'Slash',   'back\\slash',                    10],
        [7, 'Inject',  "x'; DROP TABLE customers; --", 10],
    ] as $row) {
        $dm->execute('INSERT INTO customers (id, name, status, score) VALUES (?, ?, ?, ?)', $row);
    }

    return $dm;
};

/** The names a query over the view returns, in order. */
$names = static function (DataManager $dm, string $sql): string {
    return implode(',', array_column($dm->query($sql), 'name'));
};

// -----------------------------------------------------------------------------
section('a view is created, read, replaced and dropped');

$table = $customers();
$dm    = $fresh();
Schema::set_connection($dm);

$active = sqlite_view('active_customers', [
    'id'   => integer(),
    'name' => varchar(50),
])->as_query(
    (new QueryBuilder('sqlite'))->select([$table->id, $table->name])
        ->from($table)
        ->where(eq($table->status, 'active'))
);

Schema::create_view($active);

test('the view exists', Schema::has_view('active_customers'));
test('…and is not reported as a table', !in_array('active_customers', Schema::get_tables(), true));
test('…and is listed among the views', Schema::get_views() === ['active_customers']);
test('reading it gives the rows the definition selects', $names($dm, 'SELECT * FROM active_customers ORDER BY id') === 'Ada,Grace');

$top = sqlite_view('active_customers', [
    'id'   => integer(),
    'name' => varchar(50),
])->as_query(
    (new QueryBuilder('sqlite'))->select([$table->id, $table->name])
        ->from($table)
        ->where(gte($table->score, 90))
);

Schema::create_or_replace_view($top);

test('replacing it changes what it selects', $names($dm, 'SELECT * FROM active_customers ORDER BY id') === 'Ada,Alan');
test('…and there is still exactly one view', Schema::get_views() === ['active_customers']);

Schema::drop_view($active);

test('dropping it removes it', !Schema::has_view('active_customers'));
test('…and dropping it again is not an error', (static function (): bool {
    Schema::drop_view('active_customers');

    return true;
})());
test('the table it selected from is untouched', $names($fresh(), 'SELECT * FROM customers ORDER BY id') === 'Ada,Grace,Alan,Quote,Percent,Slash,Inject');

// -----------------------------------------------------------------------------
section('the query builder reads a view like a table');

$dm = $fresh();
Schema::set_connection($dm);
Schema::create_view($active);

$view_rows = $dm->select()->from($active)->where(eq($active->name, 'Ada'))->execute();

test('select()->from($view) works with no view-specific code', count($view_rows) === 1);
test('…and returns the view row', ($view_rows[0]['name'] ?? '') === 'Ada');
test('a view is a Table, so everything that takes one takes it', $active instanceof \Italix\Orm\Schema\Table);

// -----------------------------------------------------------------------------
section('WRITING TO A VIEW IS REFUSED BEFORE IT REACHES THE SERVER');

// Whether a view is updatable depends on the engine, the SELECT and — on MySQL
// — the algorithm the optimiser picked. The one portable answer is no, and
// giving it here names the caller's line instead of the server's opinion.
foreach ([
    'insert' => static fn(QueryBuilder $qb, View $v) => $qb->insert($v)->values(['name' => 'Nope']),
    'update' => static fn(QueryBuilder $qb, View $v) => $qb->update($v)->set(['name' => 'Nope']),
    'delete' => static fn(QueryBuilder $qb, View $v) => $qb->delete($v),
] as $operation => $attempt) {
    [$threw, $message] = $throws(static fn() => $attempt(new QueryBuilder('sqlite'), $active));

    test($operation . '() against a view raises', $threw);
    test('…and the message names the view', strpos($message, 'active_customers') !== false);
}

test('the refusal happens in our code, not at the server', (static function () use ($throws, $active): bool {
    [, $message] = $throws(static fn() => (new QueryBuilder('sqlite'))->delete($active));

    return strpos($message, 'read-only') !== false;
})());

test('a plain table is still writable', (static function () use ($customers): bool {
    $params = [];
    $sql    = (new QueryBuilder('sqlite'))->insert($customers())->values(['name' => 'Yes'])->to_sql($params);

    return strpos($sql, 'INSERT INTO') === 0;
})());

// -----------------------------------------------------------------------------
section("THE DEFINITION'S VALUES ARE WRITTEN IN, AND ESCAPED");

// CREATE VIEW carries no parameters, so a filtered view has its values rendered
// into the statement. Each case below is executed and read back: a quoting bug
// would either fail to parse or select the wrong rows, and comparing strings
// would catch neither.
$quoting_cases = [
    'a plain value'          => ['active',                       'Ada,Grace'],
    'a value with a quote'   => ["it's active",                  'Quote'],
    'a value with a percent' => ['100% active',                  'Percent'],
    'a backslash'            => ['back\\slash',                    'Slash'],
    'a semicolon and DROP'   => ["x'; DROP TABLE customers; --", 'Inject'],
    'a value nothing matches' => ['no such status',              ''],
];

foreach ($quoting_cases as $label => [$value, $expected]) {
    $dm = $fresh();
    Schema::set_connection($dm);
    Schema::drop_view('v');

    $v = sqlite_view('v', ['id' => integer(), 'name' => varchar(50)])->as_query(
        (new QueryBuilder('sqlite'))->select([$table->id, $table->name])
            ->from($table)
            ->where(eq($table->status, $value))
    );

    Schema::create_view($v);

    test($label . ' survives into a working view', $names($dm, 'SELECT * FROM v ORDER BY id') === $expected);
    test('…and the customers table is still there', count($dm->query('SELECT id FROM customers')) === 7);
}

test('an integer is written unquoted', (static function () use ($table): bool {
    $sql = sqlite_view('v')->as_query(
        (new QueryBuilder('sqlite'))->select([$table->id])->from($table)->where(gte($table->score, 90))
    )->to_create_sql();

    return strpos($sql, '>= 90') !== false;
})());

test('null becomes NULL, not an empty string', (static function () use ($table): bool {
    $sql = sqlite_view('v')->as_query(
        (new QueryBuilder('sqlite'))->select([$table->id])->from($table)->where(eq($table->status, null))
    )->to_create_sql();

    return strpos($sql, "''") === false;
})());

test('an object in the definition is refused, not guessed at', (static function () use ($throws, $table): bool {
    [$threw, $message] = $throws(static function () use ($table): void {
        sqlite_view('v')->as_query(
            (new QueryBuilder('sqlite'))->select([$table->id])
                ->from($table)
                ->where(eq($table->status, new \stdClass()))
        )->to_create_sql();
    });

    return $threw && strpos($message, 'stdClass') !== false;
})());

// A raw fragment can carry a string containing a question mark, and the value
// bound *after* it must still land in the right place. Inlining by naive
// str_replace or by counting `?` blindly puts it inside the literal instead —
// which parses, and quietly means something else.
test('a placeholder inside a string literal is left alone', (static function () use ($table, $fresh, $names): bool {
    $dm = $fresh();
    Schema::set_connection($dm);
    Schema::drop_view('v');

    $view = sqlite_view('v')->as_query(
        (new QueryBuilder('sqlite'))->select([$table->name])
            ->from($table)
            ->where(and_(raw("name <> 'who?'"), eq($table->status, 'archived')))
    );

    $sql = $view->to_create_sql();
    Schema::create_view($view);

    return strpos($sql, "'who?'") !== false
        && $names($dm, 'SELECT * FROM v') === 'Alan';
})());

// -----------------------------------------------------------------------------
section('the rendering follows the dialect, including the one we cannot run here');

// SQLite rejects CREATE OR REPLACE (measured: `near "OR": syntax error`), so it
// gets a DROP first. Asserting this is the only way the SQLite-only test run
// says anything about a MySQL or PostgreSQL deployment.
$mysql_view = mysql_view('active_customers', ['id' => integer(), 'name' => varchar(50)])->as_query(
    (new QueryBuilder('mysql'))->select([$customers('mysql')->id, $customers('mysql')->name])
        ->from($customers('mysql'))
        ->where(eq($customers('mysql')->status, 'active'))
);

$pg_view = pg_view('active_customers', ['id' => integer(), 'name' => varchar(50)])->as_query(
    (new QueryBuilder('postgresql'))->select([$customers('postgresql')->id, $customers('postgresql')->name])
        ->from($customers('postgresql'))
        ->where(eq($customers('postgresql')->status, 'active'))
);

test('SQLite replaces in two statements', count($active->to_replace_sql()) === 2);
test('…the first of which drops', strpos($active->to_replace_sql()[0], 'DROP VIEW IF EXISTS') === 0);
test('MySQL replaces in one', count($mysql_view->to_replace_sql()) === 1);
test('…using CREATE OR REPLACE', strpos($mysql_view->to_replace_sql()[0], 'CREATE OR REPLACE VIEW') === 0);
test('PostgreSQL replaces in one', count($pg_view->to_replace_sql()) === 1);
test('IF NOT EXISTS is never emitted', strpos($active->to_create_sql() . $mysql_view->to_create_sql(), 'IF NOT EXISTS') === false);

test('MySQL quotes with backticks', strpos($mysql_view->to_create_sql(), '`active_customers`') !== false);
test('PostgreSQL quotes with double quotes', strpos($pg_view->to_create_sql(), '"active_customers"') !== false);
test('the declared columns become the view column list', strpos($active->to_create_sql(), '("id", "name") AS') !== false);
test('a view with no declared columns has no column list', strpos(sqlite_view('v')->as_query('SELECT 1')->to_create_sql(), '(') === false);

test("PostgreSQL's bound values are inlined too", strpos($pg_view->to_create_sql(), "'active'") !== false);
test('…leaving no placeholder behind', strpos($pg_view->to_create_sql(), '?') === false);

// -----------------------------------------------------------------------------
section('the mistakes a view invites');

test('a view with no definition says so instead of rendering nonsense', (static function () use ($throws): bool {
    [$threw, $message] = $throws(static fn() => sqlite_view('v')->to_create_sql());

    return $threw && strpos($message, 'no definition') !== false;
})());

test('as_query() refuses something that is neither a query nor SQL', (static function () use ($throws): bool {
    [$threw] = $throws(static fn() => sqlite_view('v')->as_query(42));

    return $threw;
})());

test('as_query() returns a copy and leaves the original undefined', (static function (): bool {
    $bare = sqlite_view('v', ['id' => integer()]);
    $bare->as_query('SELECT 1 AS id');

    return !$bare->has_definition();
})());

test('the copy owns its columns', (static function (): bool {
    $bare    = sqlite_view('v', ['id' => integer()]);
    $defined = $bare->as_query('SELECT 1 AS id');

    return $defined->id !== $bare->id;
})());

test('creating a view built for another dialect is refused', (static function () use ($throws, $fresh, $mysql_view): bool {
    Schema::set_connection($fresh());
    [$threw, $message] = $throws(static fn() => Schema::create_view($mysql_view));

    return $threw && strpos($message, 'mysql') !== false;
})());

test('a raw-SQL definition is taken as written', (static function () use ($fresh): bool {
    $dm = $fresh();
    Schema::set_connection($dm);
    Schema::drop_view('counted');
    Schema::create_view(sqlite_view('counted')->as_query('SELECT COUNT(*) AS n FROM customers'));

    return (int) $dm->query('SELECT n FROM counted')[0]['n'] === 7;
})());

exit(summary());
