<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — reading a schema back out of a database (`ix db:pull`)
 *
 * A generator cannot be tested by looking at what it wrote. The only question
 * that matters is whether the schema **survives the trip**, so the shape of this
 * suite is a loop:
 *
 *     a real database  →  pulled PHP  →  a second database  →  pulled PHP
 *
 * and the two pulls must be identical. Anything the mapping loses — a `date`
 * described as a `timestamp`, a `unique` that was never noticed, a foreign key
 * left behind — comes back as a difference between the two, on the dialect it
 * actually happens on.
 *
 * That is not hypothetical. Before this suite existed the mapping sent `date`
 * and `datetime` to `timestamp()`, `char` to `varchar()`, `float` to
 * `decimal()`, `json` to `text()`; SQLite's unique constraints were skipped
 * along with the indexes that carry them; foreign keys were dropped entirely;
 * and every PostgreSQL query used `$1` placeholders, which PDO does not parse —
 * so a pull there returned nothing at all and said nothing about it.
 *
 * PostgreSQL and MySQL are exercised when configured, and skipped otherwise:
 *
 *     IX_PG_HOST=127.0.0.1 IX_PG_DATABASE=… IX_PG_USER=… \
 *     IX_MY_HOST=127.0.0.1 IX_MY_DATABASE=… IX_MY_USER=… IX_MY_PASSWORD=… \
 *     php tests/IntrospectionTest.php
 *
 * Run: php src/Libs/Italix/Orm/tests/IntrospectionTest.php
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
use Italix\Orm\Migration\SchemaIntrospector;
use Italix\Orm\Schema\Table;

use function Italix\Orm\{mysql, postgres, sqlite_memory};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - schema introspection');

/**
 * A manager for a configured server, or null when it is not answering.
 *
 * Configured and unreachable is the ordinary case on somebody else's machine —
 * and a suite that fatals there tells them their change broke something.
 */
$connect = static function (callable $make, string $label): ?DataManager {
    try {
        $dm = $make();
        $dm->query('SELECT 1');

        return $dm;
    } catch (\Throwable $e) {
        echo "  SKIPPED {$label} - configured but not answering: "
            . strtok($e->getMessage(), "\n") . "\n";

        return null;
    }
};

/**
 * The tables a pull has to survive, per dialect.
 *
 * Deliberately awkward: every type this package can express, a unique column, a
 * composite unique index, a plain index, a foreign key, and defaults of three
 * different shapes.
 *
 * @return array<int, string>
 */
$fixture = static function (string $dialect): array {
    if ($dialect === 'sqlite') {
        return [
            'CREATE TABLE ix_pull_companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
            "CREATE TABLE ix_pull_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(50) NOT NULL,
                nickname CHAR(8),
                age INTEGER,
                tally BIGINT,
                seats INTEGER UNSIGNED,
                ratio REAL,
                weight DOUBLE PRECISION,
                balance DECIMAL(10,2) DEFAULT 0,
                status VARCHAR(20) DEFAULT 'draft',
                payload JSON,
                public_id UUID,
                birth_d DATE,
                seen_dt DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                company_id INTEGER REFERENCES ix_pull_companies(id)
            )",
            'CREATE INDEX ix_pull_users_name_idx ON ix_pull_users (name)',
            'CREATE UNIQUE INDEX ix_pull_users_pair_idx ON ix_pull_users (name, age)',
            "CREATE VIEW ix_pull_named AS SELECT id, name FROM ix_pull_users WHERE name IS NOT NULL",
        ];
    }

    if ($dialect === 'mysql') {
        return [
            'CREATE TABLE ix_pull_companies (id INT AUTO_INCREMENT PRIMARY KEY, name TEXT NOT NULL)',
            "CREATE TABLE ix_pull_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(50) NOT NULL,
                nickname CHAR(8),
                age INT,
                tally BIGINT,
                seats INT UNSIGNED,
                ratio FLOAT,
                weight DOUBLE,
                balance DECIMAL(10,2) DEFAULT 0,
                status VARCHAR(20) DEFAULT 'draft',
                birth_d DATE,
                seen_dt DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                company_id INT,
                CONSTRAINT ix_pull_users_company_fk FOREIGN KEY (company_id) REFERENCES ix_pull_companies(id)
            )",
            'CREATE INDEX ix_pull_users_name_idx ON ix_pull_users (name)',
            'CREATE UNIQUE INDEX ix_pull_users_pair_idx ON ix_pull_users (name, age)',
            'CREATE VIEW ix_pull_named AS SELECT id, name FROM ix_pull_users WHERE name IS NOT NULL',
        ];
    }

    return [
        'CREATE TABLE ix_pull_companies (id SERIAL PRIMARY KEY, name TEXT NOT NULL)',
        "CREATE TABLE ix_pull_users (
            id SERIAL PRIMARY KEY,
            email VARCHAR(120) NOT NULL UNIQUE,
            name VARCHAR(50) NOT NULL,
            nickname CHAR(8),
            age INTEGER,
            tally BIGINT,
            seats INTEGER,
            ratio REAL,
            weight DOUBLE PRECISION,
            balance DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'draft',
            payload JSONB,
            public_id UUID,
            birth_d DATE,
            seen_dt TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            company_id INTEGER REFERENCES ix_pull_companies(id)
        )",
        'CREATE INDEX ix_pull_users_name_idx ON ix_pull_users (name)',
        'CREATE UNIQUE INDEX ix_pull_users_pair_idx ON ix_pull_users (name, age)',
        'CREATE VIEW ix_pull_named AS SELECT id, name FROM ix_pull_users WHERE name IS NOT NULL',
        'CREATE MATERIALIZED VIEW ix_pull_counted AS SELECT count(*) AS n FROM ix_pull_users',
    ];
};

$drop = static function (DataManager $dm): void {
    foreach ([
        'DROP MATERIALIZED VIEW IF EXISTS ix_pull_counted',
        'DROP VIEW IF EXISTS ix_pull_named',
        'DROP TABLE IF EXISTS ix_pull_users',
        'DROP TABLE IF EXISTS ix_pull_companies',
    ] as $statement) {
        try {
            $dm->execute($statement);
        } catch (\Throwable $e) {
            // A dialect without materialized views, or an object that was not
            // there. Nothing to undo either way.
        }
    }
};

/** Only the tables this suite made. */
$pulled = static function (DataManager $dm): string {
    return (new SchemaIntrospector($dm))->generate_schema_code(['ix_pull_companies', 'ix_pull_users']);
};

/** The names of everything the pull describes, in order. */
$declared_names = static function (string $code): array {
    preg_match_all("/^\\\$(\\w+) = /m", $code, $matches);

    return $matches[1];
};

/**
 * Run generated schema code and hand back the tables it declares.
 *
 * @return array<int, Table>
 */
$tables_from = static function (string $code): array {
    $named = strpos($code, '$ix_pull_named') !== false ? ', $ix_pull_named' : '';
    $count = strpos($code, '$ix_pull_counted') !== false ? ', $ix_pull_counted' : '';

    return eval(substr($code, strlen('<?php'))
        . " return [\$ix_pull_companies, \$ix_pull_users{$named}{$count}];");
};

// -----------------------------------------------------------------------------
section('SQLITE: WHAT GOES IN COMES BACK OUT');

$first_code = null;

$round_trip = static function (DataManager $source, DataManager $target, callable $fixture_sql, string $dialect)
    use ($drop, $pulled, $tables_from): array {
    $drop($source);
    $drop($target);

    foreach ($fixture_sql($dialect) as $sql) {
        $source->execute($sql);
    }

    $first  = $pulled($source);
    $tables = $tables_from($first);

    $target->create_tables(...$tables);

    return [$first, $pulled($target)];
};

$sqlite_source = sqlite_memory();
$sqlite_target = sqlite_memory();

[$first, $second] = $round_trip($sqlite_source, $sqlite_target, $fixture, 'sqlite');

test('the pulled schema recreates a database that pulls the same', $first === $second, $first . "\n---\n" . $second);

// The details, so a failure above says which part moved rather than just "not
// the same".
test('a unique column keeps its unique()', strpos($first, "'email' => varchar(120)->not_null()->unique()") !== false);
test('a foreign key lands on its column', strpos($first, "->references('ix_pull_companies', 'id')") !== false);
test('a plain index survives', strpos($first, "add_index('ix_pull_users_name_idx', ['name'])") !== false);
test('a composite unique index survives', strpos($first, "add_unique('ix_pull_users_pair_idx', ['name', 'age'])") !== false);
test('…and is not also declared on a column', substr_count($first, '->unique()') === 1);
test('a date is a date, not a timestamp', strpos($first, "'birth_d' => date()") !== false);
test('a datetime is a datetime', strpos($first, "'seen_dt' => datetime()") !== false);
test('a char is a char', strpos($first, "'nickname' => char(8)") !== false);
test('a real is a real', strpos($first, "'ratio' => real()") !== false);
test('AN UNSIGNED COLUMN COMES BACK UNSIGNED', strpos($first, "'seats' => integer()->unsigned()") !== false, $first);
test('json stays json', strpos($first, "'payload' => json()") !== false);
test('a uuid stays a uuid', strpos($first, "'public_id' => uuid()") !== false);
test('a string default is the value, not the quoting', strpos($first, "->default('draft')") !== false);
test('a numeric default is a number', strpos($first, '->default(0)') !== false);
test('CURRENT_TIMESTAMP survives', strpos($first, "->default('CURRENT_TIMESTAMP')") !== false);

test('the import line names what the file uses', (static function () use ($first): bool {
    preg_match('/use function Italix\\\\Orm\\\\Schema\\\\\{([^}]+)\};/', $first, $matches);
    $imported = array_map('trim', explode(',', $matches[1] ?? ''));

    foreach (['char', 'date', 'datetime', 'json', 'real', 'uuid', 'varchar'] as $needed) {
        if (!in_array($needed, $imported, true)) {
            return false;
        }
    }

    // And nothing it does not: an unused import is a smaller sin than a missing
    // one, but a generated file should be exactly right.
    foreach ($imported as $name) {
        if ($name !== '' && strpos($first, $name . '(') === false) {
            return false;
        }
    }

    return true;
})());

test('THE GENERATED FILE IS VALID PHP THAT RUNS', (static function () use ($first, $tables_from): bool {
    $declared = $tables_from($first);

    foreach ($declared as $one) {
        if (!$one instanceof Table) {
            return false;
        }
    }

    return count($declared) === 3 && $declared[1]->get_name() === 'ix_pull_users';
})());

// A view was described as a table before this: columns and all, with nothing to
// say it had a definition somewhere. On MySQL it was worse — `SHOW TABLES` lists
// views, so the pull emitted `mysql_table('a_view', …)`, which would recreate it
// as a table full of nothing.
test('A VIEW COMES BACK AS A VIEW', strpos($first, "sqlite_view('ix_pull_named'") !== false, $first);
test('…carrying its definition', strpos($first, '->as_query(') !== false);
test('…and its columns', strpos($first, "'name' => varchar(50)") !== false);
test('…and it is not also listed as a table', strpos($first, "sqlite_table('ix_pull_named'") === false);

test('the view is declared after the table it reads', (static function () use ($first, $declared_names): bool {
    $names = $declared_names($first);

    return array_search('ix_pull_named', $names, true) > array_search('ix_pull_users', $names, true);
})());

test('the definition is a View object once the file runs', (static function () use ($first, $tables_from): bool {
    $declared = $tables_from($first);

    return $declared[2] instanceof \Italix\Orm\Schema\View
        && $declared[2]->has_definition();
})());

$drop($sqlite_source);
$drop($sqlite_target);

// -----------------------------------------------------------------------------
section('MySQL / MariaDB');

$my_config = [
    'host'     => getenv('IX_MY_HOST') ?: '',
    'database' => getenv('IX_MY_DATABASE') ?: '',
    'username' => getenv('IX_MY_USER') ?: '',
    'password' => getenv('IX_MY_PASSWORD') ?: '',
];

if ($my_config['host'] === '' || $my_config['database'] === '') {
    echo "  SKIPPED - no MySQL configured (set IX_MY_HOST, IX_MY_DATABASE, IX_MY_USER, IX_MY_PASSWORD).\n";
} else {
    $my_source = $connect(static fn() => mysql($my_config), 'mysql');
    $my_target = $my_source === null ? null : mysql($my_config);

    // One server, so the round trip runs in two passes over the same names.
    $drop($my_source);

    foreach ($fixture('mysql') as $sql) {
        $my_source->execute($sql);
    }

    $first  = $pulled($my_source);
    $tables = $tables_from($first);

    $drop($my_source);
    $my_target->create_tables(...$tables);

    $second = $pulled($my_target);

    test('the pulled schema recreates a database that pulls the same', $first === $second, $first . "\n---\n" . $second);
    test('a unique column keeps its unique()', substr_count($first, '->unique()') >= 1);
    test('a foreign key lands on its column', strpos($first, "->references('ix_pull_companies', 'id')") !== false);
    test('a date is a date', strpos($first, "'birth_d' => date()") !== false);
    test('an unsigned column comes back unsigned', strpos($first, "'seats' => integer()->unsigned()") !== false);
    test('the table factory is the right one', strpos($first, 'mysql_table(') !== false);

    // `SHOW TABLES` lists views, so the pull used to emit `mysql_table()` for
    // one — which recreates it as a table full of nothing. This is the dialect
    // where that actually happened, and the list itself is where it starts: a
    // pull with no explicit table list asks get_tables() what is there.
    test('A VIEW IS NOT AMONG THE TABLES', (static function () use ($my_source): bool {
        $tables = (new SchemaIntrospector($my_source))->get_tables();

        return !in_array('ix_pull_named', $tables, true)
            && in_array('ix_pull_users', $tables, true);
    })());

    test('…nor emitted as one', strpos($first, "mysql_table('ix_pull_named'") === false);
    test('…it is a view', strpos($first, "mysql_view('ix_pull_named'") !== false);
    test('…and its definition no longer names the database it came from',
        strpos($first, '`' . $my_config['database'] . '`.') === false, $first);

    $drop($my_target);
}

// -----------------------------------------------------------------------------
section('POSTGRESQL — WHERE THE PULL USED TO RETURN NOTHING');

$pg_config = [
    'host'     => getenv('IX_PG_HOST') ?: '',
    'port'     => (int) (getenv('IX_PG_PORT') ?: 5432),
    'database' => getenv('IX_PG_DATABASE') ?: '',
    'username' => getenv('IX_PG_USER') ?: '',
    'password' => getenv('IX_PG_PASSWORD') ?: '',
];

if (!extension_loaded('pdo_pgsql') || $pg_config['host'] === '' || $pg_config['database'] === '') {
    echo "  SKIPPED - no PostgreSQL configured (set IX_PG_HOST, IX_PG_DATABASE, IX_PG_USER).\n";
    exit(summary());
}

$pg_source = $connect(static fn() => postgres($pg_config), 'postgresql');

if ($pg_source === null) {
    exit(summary());
}

$pg_target = postgres($pg_config);

$drop($pg_source);

foreach ($fixture('postgresql') as $sql) {
    $pg_source->execute($sql);
}

$first = $pulled($pg_source);

// The old failure was silent: every introspection query used `$1`, PDO bound
// nothing, and the pull came back describing a table with no columns.
test('THE PULL RETURNS COLUMNS AT ALL', substr_count($first, '=>') >= 15, $first);

$tables = $tables_from($first);

$drop($pg_source);
$pg_target->create_tables(...$tables);

$second = $pulled($pg_target);

test('the pulled schema recreates a database that pulls the same', $first === $second, $first . "\n---\n" . $second);
test('a serial primary key comes back as serial', strpos($first, "'id' => serial()") !== false);

// PostgreSQL has no unsigned integer type at all, so there is nothing to read
// back — which is exactly why the differ does not compare it on this dialect.
test('and nothing is unsigned here, because the type does not exist',
    strpos($first, '->unsigned()') === false, $first);
test('a unique column keeps its unique()', strpos($first, '->unique()') !== false);
test('a foreign key lands on its column', strpos($first, "->references('ix_pull_companies', 'id')") !== false);
test('jsonb stays jsonb', strpos($first, "'payload' => jsonb()") !== false);
test('a uuid stays a uuid', strpos($first, "'public_id' => uuid()") !== false);
test('a string default is the value, without its cast', strpos($first, "->default('draft')") !== false);
test('the table factory is the right one', strpos($first, 'pg_table(') !== false);
test('a view comes back as a view', strpos($first, "pg_view('ix_pull_named'") !== false);

// A materialized view is in neither pg_tables nor information_schema.columns —
// measured, the second comes back empty — so its columns are read from
// pg_attribute, or it would be described as a view with nothing in it.
test('A MATERIALIZED VIEW COMES BACK, WITH ITS COLUMNS',
    strpos($first, "pg_materialized_view('ix_pull_counted'") !== false
    && strpos($first, "'n' => bigint()") !== false, $first);

$drop($pg_target);

exit(summary());
