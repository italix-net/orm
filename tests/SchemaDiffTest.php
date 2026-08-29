<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — comparing a declared schema with the database, and applying it
 *
 * `SchemaDiffer` (390 lines) and `SchemaPusher` (282) were covered by one
 * assertion between them — `$differ instanceof SchemaDiffer`, that the
 * constructor does not throw — and the pusher issues `ALTER TABLE … DROP
 * COLUMN`. This is the suite that says what they do.
 *
 * The property everything else rests on is the first one here: **a schema that
 * matches the database produces an empty diff.** A differ that cries wolf is not
 * a nuisance, it is unusable — nobody reads the tenth false alarm, and the real
 * difference is in it. (It did cry wolf: every primary key was reported as a
 * nullability change, because SQLite says `notnull=0` for `INTEGER PRIMARY KEY`
 * and a declared `primary_key()` does not set `not_null()`.)
 *
 * MySQL and PostgreSQL are exercised when configured (`IX_MY_*`, `IX_PG_*`),
 * because the interesting failure is a type that is written one way in PHP and
 * reported another way by the server.
 *
 * ## Nothing destructive runs against a database this suite did not create
 *
 * The destructive assertions — `force`, `drop_undeclared` — run **only on
 * SQLite in memory**, which exists for the length of the test and belongs to
 * nobody. Against a configured server the suite creates its `ix_diff_*` tables,
 * asserts that drops are *reported*, and never passes a flag that could apply
 * one.
 *
 * That is not caution for its own sake. The first version of this file called
 * `push($tables, true)` against whatever `IX_MY_*` pointed at, which was a
 * development database, and `push()` with one flag dropped every table not in
 * the list it was given — 17 of 21. The backup was good and the restore matched
 * the manifest row for row. The flag became two flags, and the test that found
 * it the hard way now cannot reach a database it did not make.
 *
 * Run: php src/Libs/Italix/Orm/tests/SchemaDiffTest.php
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
use Italix\Orm\Migration\Migrator;
use Italix\Orm\Migration\SchemaDiffer;
use Italix\Orm\Migration\SchemaIntrospector;
use Italix\Orm\Migration\SchemaPusher;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\{bigint, boolean, datetime, decimal, integer, mysql_table, pg_table,
    serial, sqlite_table, text, varchar};
use function Italix\Orm\{mysql, postgres, sqlite_memory};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - schema diff and push');

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

/** @return array{0: bool, 1: string} */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (\Throwable $e) {
        return [true, $e->getMessage()];
    }
};

/** The declared table, per dialect, in its "matching the database" form. */
$declared = static function (string $dialect): Table {
    $columns = [
        'id'         => $dialect === 'postgresql' ? serial() : integer()->primary_key()->auto_increment(),
        'email'      => varchar(120)->not_null(),
        'name'       => varchar(50),
        'age'        => integer(),
        'is_active'  => boolean(),
        'balance'    => decimal(10, 2),
        'bio'        => text(),
        'created_at' => datetime(),
    ];

    if ($dialect === 'mysql') {
        return mysql_table('ix_diff_users', $columns);
    }

    if ($dialect === 'postgresql') {
        return pg_table('ix_diff_users', $columns);
    }

    return sqlite_table('ix_diff_users', $columns);
};

/** The same table as DDL the server writes itself. */
$fixture_sql = static function (string $dialect): string {
    if ($dialect === 'mysql') {
        return 'CREATE TABLE ix_diff_users (
            id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(120) NOT NULL, name VARCHAR(50),
            age INT, is_active TINYINT(1), balance DECIMAL(10,2), bio TEXT, created_at DATETIME)';
    }

    if ($dialect === 'postgresql') {
        return 'CREATE TABLE ix_diff_users (
            id SERIAL PRIMARY KEY, email VARCHAR(120) NOT NULL, name VARCHAR(50),
            age INTEGER, is_active BOOLEAN, balance DECIMAL(10,2), bio TEXT, created_at TIMESTAMP)';
    }

    return 'CREATE TABLE ix_diff_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(120) NOT NULL, name VARCHAR(50),
        age INTEGER, is_active BOOLEAN, balance DECIMAL(10,2), bio TEXT, created_at DATETIME)';
};

/** Everything that must hold on every dialect. */
$shared = static function (DataManager $dm, string $dialect) use ($declared, $fixture_sql, $throws): void {
    Schema::set_connection($dm);

    $reset = static function () use ($dm, $fixture_sql, $dialect): void {
        $dm->execute('DROP TABLE IF EXISTS ix_diff_users');
        $dm->execute('DROP TABLE IF EXISTS ix_diff_extra');
        $dm->execute($fixture_sql($dialect));
    };

    $reset();

    $users  = $declared($dialect);
    $differ = new SchemaDiffer($dm);

    // -------------------------------------------------------------------------
    section($dialect . ': A SCHEMA THAT MATCHES SAYS NOTHING');

    $diff = $differ->diff([$users]);

    test($dialect . ': nothing to create', $diff['create_tables'] === []);
    test($dialect . ': NOTHING TO ALTER', $diff['alter_tables'] === [], json_encode($diff['alter_tables']));

    test($dialect . ': …and pushing it changes nothing', (static function () use ($dm, $users): bool {
        $result = (new SchemaPusher($dm))->push([$users]);

        return $result['created_tables'] === []
            && $result['altered_tables'] === []
            && $result['errors'] === [];
    })());

    // -------------------------------------------------------------------------
    section($dialect . ': a column the database does not have');

    $with_extra = $declared($dialect);
    $with_extra = $with_extra;                     // the declaration below adds to it

    $extended = $dialect === 'mysql'
        ? mysql_table('ix_diff_users', ['nickname' => varchar(30)])
        : ($dialect === 'postgresql'
            ? pg_table('ix_diff_users', ['nickname' => varchar(30)])
            : sqlite_table('ix_diff_users', ['nickname' => varchar(30)]));

    // Rebuild the full declaration with one column more.
    $columns_plus = [];

    foreach ($users->get_columns() as $name => $column) {
        $columns_plus[$name] = $column;
    }

    $columns_plus['nickname'] = varchar(30);

    $plus_one = $dialect === 'mysql'
        ? mysql_table('ix_diff_users', $columns_plus)
        : ($dialect === 'postgresql'
            ? pg_table('ix_diff_users', $columns_plus)
            : sqlite_table('ix_diff_users', $columns_plus));

    $diff = $differ->diff([$plus_one]);

    test($dialect . ': it is reported as an addition',
        ($diff['alter_tables']['ix_diff_users']['add_columns'][0]['name'] ?? '') === 'nickname');

    test($dialect . ': ADDING IT ACTUALLY ADDS IT', (static function () use ($dm, $plus_one): bool {
        (new SchemaPusher($dm))->push([$plus_one]);

        $columns = array_column((new SchemaIntrospector($dm))->get_columns('ix_diff_users'), 'name');

        return in_array('nickname', $columns, true);
    })());

    test($dialect . ': …and then there is nothing left to do', (static function () use ($dm, $plus_one): bool {
        $diff = (new SchemaDiffer($dm))->diff([$plus_one]);

        return $diff['alter_tables'] === [];
    })());

    $reset();

    // -------------------------------------------------------------------------
    section($dialect . ': A COLUMN THE DECLARATION NO LONGER HAS');

    $columns_less = [];

    foreach ($users->get_columns() as $name => $column) {
        if ($name !== 'bio') {
            $columns_less[$name] = $column;
        }
    }

    $minus_one = $dialect === 'mysql'
        ? mysql_table('ix_diff_users', $columns_less)
        : ($dialect === 'postgresql'
            ? pg_table('ix_diff_users', $columns_less)
            : sqlite_table('ix_diff_users', $columns_less));

    $diff = $differ->diff([$minus_one]);

    test($dialect . ': it is reported as a removal',
        ($diff['alter_tables']['ix_diff_users']['drop_columns'] ?? []) === ['bio']);

    // No force here, on purpose: against a configured server this suite only
    // ever asks. Whether force *works* is settled on SQLite, below.
    $result = (new SchemaPusher($dm))->push([$minus_one]);

    test($dialect . ': PUSHING WITHOUT FORCE DOES NOT DROP IT', (static function () use ($dm): bool {
        $columns = array_column((new SchemaIntrospector($dm))->get_columns('ix_diff_users'), 'name');

        return in_array('bio', $columns, true);
    })());

    test($dialect . ': …and it says so rather than staying silent',
        strpos(implode(' ', $result['skipped']), 'ix_diff_users.bio') !== false,
        json_encode($result['skipped']));

    $reset();

    // -------------------------------------------------------------------------
    section($dialect . ': a table nobody declared');

    $dm->execute('CREATE TABLE ix_diff_extra (id INTEGER PRIMARY KEY)');

    $diff = $differ->diff([$users]);

    test($dialect . ': it is reported', in_array('ix_diff_extra', $diff['drop_tables'], true));

    test($dialect . ': A PUSH WITHOUT THE FLAGS LEAVES IT ALONE', (static function () use ($dm, $users): bool {
        (new SchemaPusher($dm))->push([$users]);

        return in_array('ix_diff_extra', (new SchemaIntrospector($dm))->get_tables(), true);
    })());

    // The declaration passed here is one table out of a database that has more,
    // which is the ordinary case and exactly the shape that used to mean
    // "delete the rest".
    test($dialect . ': …and says which tables it did not touch', (static function () use ($dm, $users): bool {
        $result = (new SchemaPusher($dm))->push([$users]);

        return strpos(implode(' ', $result['skipped']), 'ix_diff_extra') !== false;
    })());

    test($dialect . ': preview() writes nothing at all', (static function () use ($dm, $users): bool {
        $before  = (new SchemaIntrospector($dm))->get_tables();
        $preview = (new SchemaPusher($dm))->preview([$users]);
        $after   = (new SchemaIntrospector($dm))->get_tables();

        return $before === $after && $preview['drop_tables'] !== [];
    })());

    $dm->execute('DROP TABLE IF EXISTS ix_diff_extra');

    // -------------------------------------------------------------------------
    section($dialect . ': a table the database does not have');

    $new_table = $dialect === 'mysql'
        ? mysql_table('ix_diff_extra', ['id' => integer()->primary_key()->auto_increment(), 'label' => varchar(20)])
        : ($dialect === 'postgresql'
            ? pg_table('ix_diff_extra', ['id' => serial(), 'label' => varchar(20)])
            : sqlite_table('ix_diff_extra', ['id' => integer()->primary_key()->auto_increment(), 'label' => varchar(20)]));

    $diff = $differ->diff([$users, $new_table]);

    test($dialect . ': it is reported as a creation', $diff['create_tables'] === ['ix_diff_extra']);

    test($dialect . ': and pushing creates it', (static function () use ($dm, $users, $new_table): bool {
        (new SchemaPusher($dm))->push([$users, $new_table]);

        return in_array('ix_diff_extra', (new SchemaIntrospector($dm))->get_tables(), true);
    })());

    test($dialect . ': …after which there is nothing to do', (static function () use ($dm, $users, $new_table): bool {
        $diff = (new SchemaDiffer($dm))->diff([$users, $new_table]);

        return $diff['create_tables'] === [] && $diff['alter_tables'] === [];
    })());

    $dm->execute('DROP TABLE IF EXISTS ix_diff_extra');

    // -------------------------------------------------------------------------
    section($dialect . ': a column whose shape changed');

    $columns_wider = [];

    foreach ($users->get_columns() as $name => $column) {
        $columns_wider[$name] = $name === 'name' ? varchar(200) : $column;
    }

    $wider = $dialect === 'mysql'
        ? mysql_table('ix_diff_users', $columns_wider)
        : ($dialect === 'postgresql'
            ? pg_table('ix_diff_users', $columns_wider)
            : sqlite_table('ix_diff_users', $columns_wider));

    $diff = $differ->diff([$wider]);

    // `unsigned` is the one property that exists on two dialects and not on the
    // third. Comparing it on PostgreSQL would report a difference on every such
    // column, on every run, that no migration could close — so it is not
    // compared there, and this says so out loud.
    test($dialect . ': AN UNSIGNED COLUMN THE DATABASE DOES NOT HAVE IS ' .
        ($dialect === 'postgresql' ? 'IGNORED' : 'REPORTED'),
        (static function () use ($dm, $users, $dialect): bool {
            $columns = [];

            foreach ($users->get_columns() as $name => $column) {
                $columns[$name] = $name === 'age' ? integer()->unsigned() : $column;
            }

            $table = $dialect === 'mysql'
                ? mysql_table('ix_diff_users', $columns)
                : ($dialect === 'postgresql'
                    ? pg_table('ix_diff_users', $columns)
                    : sqlite_table('ix_diff_users', $columns));

            $diff  = (new SchemaDiffer($dm))->diff([$table]);
            $found = isset($diff['alter_tables']['ix_diff_users']['modify_columns']['age']['unsigned']);

            return $dialect === 'postgresql' ? !$found : $found;
        })());

    test($dialect . ': the length change is seen',
        ($diff['alter_tables']['ix_diff_users']['modify_columns']['name']['length']['to'] ?? null) === 200);

    // Changing a column's type is dialect-specific and can lose data, so the
    // pusher reports it instead of guessing. Saying "I did not do this" is a
    // useful answer; doing half of it is not.
    test($dialect . ': A CHANGE IS NOT APPLIED BEHIND YOUR BACK', (static function () use ($dm, $wider): bool {
        (new SchemaPusher($dm))->push([$wider]);

        foreach ((new SchemaIntrospector($dm))->get_columns('ix_diff_users') as $column) {
            if ($column['name'] === 'name') {
                return (int) $column['length'] === 50;
            }
        }

        return false;
    })());

    test($dialect . ': …and the caller is told', (static function () use ($dm, $wider): bool {
        $result = (new SchemaPusher($dm))->push([$wider]);
        $notes  = array_merge($result['skipped'], $result['altered_tables']['ix_diff_users'] ?? []);

        return $notes !== [] && strpos(implode(' ', $notes), 'manual') !== false;
    })());

    $dm->execute('DROP TABLE IF EXISTS ix_diff_users');
    $dm->execute('DROP TABLE IF EXISTS ix_diff_extra');
};

// -----------------------------------------------------------------------------
// The migration a diff can write for you — generated, then **run**, on a
// database made here.
//
// A generator is only worth as much as the thing it generates does, so the
// assertion is not "the file mentions the column": it is that after running it,
// there is no difference left.
// -----------------------------------------------------------------------------
$generates = static function () use ($throws): void {
    section('A DIFF CAN WRITE THE MIGRATION THAT CLOSES IT');

    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE ix_diff_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(120) NOT NULL)');

    $users = sqlite_table('ix_diff_users', [
        'id'       => integer()->primary_key()->auto_increment(),
        'email'    => varchar(120)->not_null(),
        'nickname' => varchar(30),
    ]);

    $notes = sqlite_table('ix_diff_notes', [
        'id'    => integer()->primary_key()->auto_increment(),
        'body'  => text(),
        'stars' => integer(),
    ]);

    $differ = new SchemaDiffer($dm);
    $code   = $differ->generate_migration_from_diff($differ->diff([$users, $notes]), [$users, $notes]);

    test('a missing table is created with its real columns',
        strpos($code, "\$table->text('body')") !== false && strpos($code, "Schema::create('ix_diff_notes'") !== false,
        $code);

    test('a missing column is added', strpos($code, "\$table->string('nickname', 30)") !== false);

    // The half that must not be automatic: a column in the database and not in
    // the declaration holds data, and a generated file should not take it away
    // while nobody is reading.
    test('A COLUMN THE DECLARATION DROPPED IS LEFT COMMENTED OUT', (static function () use ($dm, $differ): bool {
        $narrow = sqlite_table('ix_diff_users', ['id' => integer()->primary_key()->auto_increment()]);
        $code   = $differ->generate_migration_from_diff($differ->diff([$narrow]), [$narrow]);

        return strpos($code, "// \$table->drop_column('email')") !== false
            && strpos($code, "\n    \$table->drop_column('email')") === false;
    })());

    test('…and so is a conversion', (static function () use ($dm, $differ): bool {
        $wider = sqlite_table('ix_diff_users', [
            'id'    => integer()->primary_key()->auto_increment(),
            'email' => varchar(400)->not_null(),
        ]);

        $code = $differ->generate_migration_from_diff($differ->diff([$wider]), [$wider]);

        return strpos($code, '// ix_diff_users.email length') !== false;
    })());

    // -------------------------------------------------------------------------
    section('and the generated migration RUNS');

    $directory = sys_get_temp_dir() . '/ix_diff_migration_' . getmypid();
    @mkdir($directory, 0755, true);
    file_put_contents($directory . '/001_schema_diff.php', $code);

    try {
        $migrator = new Migrator($dm, $directory);
        $migrator->set_output(false);
        $migrator->migrate();

        test('the table it created is there', in_array('ix_diff_notes', (new SchemaIntrospector($dm))->get_tables(), true));
        test('the column it added is there',
            in_array('nickname', array_column((new SchemaIntrospector($dm))->get_columns('ix_diff_users'), 'name'), true));

        // The whole point, in one line.
        test('AND THE DIFF IT WAS WRITTEN FROM IS NOW EMPTY', (static function () use ($dm, $users, $notes): bool {
            $diff = (new SchemaDiffer($dm))->diff([$users, $notes]);

            return $diff['create_tables'] === [] && $diff['alter_tables'] === [];
        })());

        // down() drops the column up() added, which SQLite could not do before
        // 3.35. Where it cannot, the migration must say so rather than hand back
        // the server's `near "DROP": syntax error`.
        $can_drop = version_compare((string) $dm->query('SELECT sqlite_version() AS v')[0]['v'], '3.35', '>=');

        test('rolling it back undoes what it did' . ($can_drop ? '' : ' — or says why it cannot'),
            (static function () use ($migrator, $dm, $can_drop, $throws): bool {
                [$threw, $message] = $throws(static fn() => $migrator->rollback());
                $tables            = (new SchemaIntrospector($dm))->get_tables();

                if ($can_drop) {
                    return !$threw && !in_array('ix_diff_notes', $tables, true);
                }

                return $threw && strpos($message, 'DROP COLUMN') !== false;
            })());
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    test('a type with no Blueprint method is refused, not guessed', (static function () use ($throws): bool {
        $dm = sqlite_memory();
        $odd = sqlite_table('ix_diff_odd', ['id' => integer()->primary_key(), 'thing' => varchar(10)]);

        // A definition carrying a type the Blueprint has no word for: guessing
        // `string()` would create a VARCHAR(255) and look right doing it.
        $differ = new SchemaDiffer($dm);
        [$threw, $message] = $throws(static fn() => $differ->generate_migration_from_diff([
            'create_tables' => [],
            'drop_tables'   => [],
            'alter_tables'  => ['ix_diff_odd' => [
                'add_columns'    => [['name' => 'thing', 'type' => 'GEOMETRY', 'length' => null,
                                      'nullable' => true, 'default' => null, 'primary' => false,
                                      'auto_increment' => false, 'unique' => false]],
                'drop_columns'   => [],
                'modify_columns' => [],
            ]],
        ]));

        return $threw && strpos($message, 'GEOMETRY') !== false;
    })());
};

$generates();

// -----------------------------------------------------------------------------
// The destructive half, on a database made here and thrown away here.
//
// SQLite in memory: it exists for the length of this function, and there is
// nothing in it anybody wanted. Nothing below runs against a configured server,
// whatever the environment says.
// -----------------------------------------------------------------------------
$destructive = static function () use ($throws): void {
    $fresh = static function (): DataManager {
        $dm = sqlite_memory();
        $dm->execute('CREATE TABLE ix_diff_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(120) NOT NULL, bio TEXT)');
        $dm->execute('CREATE TABLE ix_diff_keep (id INTEGER PRIMARY KEY, label VARCHAR(20))');
        $dm->execute("INSERT INTO ix_diff_keep (id, label) VALUES (1, 'important')");

        return $dm;
    };

    $tables_of = static fn(DataManager $dm): array => (new SchemaIntrospector($dm))->get_tables();

    $users = sqlite_table('ix_diff_users', [
        'id'    => integer()->primary_key()->auto_increment(),
        'email' => varchar(120)->not_null(),
        'bio'   => text(),
    ]);

    section('THE TWO GATES ARE NOT THE SAME GATE');

    // The declaration below names one of the two tables — the ordinary case,
    // and the one that used to mean "delete the other".
    test('force alone does not drop a table nobody declared', (static function () use ($fresh, $users, $tables_of): bool {
        $dm = $fresh();
        (new SchemaPusher($dm))->push([$users], true);

        return in_array('ix_diff_keep', $tables_of($dm), true);
    })());

    test('drop_undeclared alone does not either', (static function () use ($fresh, $users, $tables_of): bool {
        $dm = $fresh();
        (new SchemaPusher($dm))->push([$users], false, true);

        return in_array('ix_diff_keep', $tables_of($dm), true);
    })());

    test('…and the rows are still in it', (static function () use ($fresh, $users): bool {
        $dm = $fresh();
        (new SchemaPusher($dm))->push([$users], true);

        return count($dm->query('SELECT id FROM ix_diff_keep')) === 1;
    })());

    test('both together do drop it', (static function () use ($fresh, $users, $tables_of): bool {
        $dm = $fresh();
        $result = (new SchemaPusher($dm))->push([$users], true, true);

        return !in_array('ix_diff_keep', $tables_of($dm), true)
            && $result['dropped_tables'] === ['ix_diff_keep'];
    })());

    section('dropping a column, where the server can');

    $without_bio = sqlite_table('ix_diff_users', [
        'id'    => integer()->primary_key()->auto_increment(),
        'email' => varchar(120)->not_null(),
    ]);

    $can_drop = version_compare((string) sqlite_memory()->query('SELECT sqlite_version() AS v')[0]['v'], '3.35', '>=');

    test('force drops the column' . ($can_drop ? '' : ' — or says why not'),
        (static function () use ($fresh, $without_bio, $can_drop): bool {
            $dm     = $fresh();
            $result = (new SchemaPusher($dm))->push([$without_bio], true);
            $names  = array_column((new SchemaIntrospector($dm))->get_columns('ix_diff_users'), 'name');

            if ($can_drop) {
                return !in_array('bio', $names, true) && $result['errors'] === [];
            }

            // SQLite grew ALTER TABLE … DROP COLUMN in 3.35. Below that,
            // rebuilding the table is a migration with data in it, and a push
            // must say so rather than send a statement the server refuses.
            return in_array('bio', $names, true)
                && $result['errors'] === []
                && strpos(implode(' ', $result['skipped']), 'DROP COLUMN') !== false;
        })());

    test('and dropping a column never touches another table', (static function () use ($fresh, $without_bio, $tables_of): bool {
        $dm = $fresh();
        (new SchemaPusher($dm))->push([$without_bio], true);

        return in_array('ix_diff_keep', $tables_of($dm), true);
    })());
};

$destructive();

// -----------------------------------------------------------------------------
if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED sqlite - pdo_sqlite is absent.\n";
} else {
    $shared(sqlite_memory(), 'sqlite');
}

// -----------------------------------------------------------------------------
$my_config = [
    'host'     => getenv('IX_MY_HOST') ?: '',
    'database' => getenv('IX_MY_DATABASE') ?: '',
    'username' => getenv('IX_MY_USER') ?: '',
    'password' => getenv('IX_MY_PASSWORD') ?: '',
];

if ($my_config['host'] === '' || $my_config['database'] === '') {
    echo "  SKIPPED mysql - not configured (IX_MY_HOST, IX_MY_DATABASE, IX_MY_USER, IX_MY_PASSWORD).\n";
} else {
    $reachable = $connect(static fn() => mysql($my_config), 'mysql');

    if ($reachable !== null) {
        $shared($reachable, 'mysql');
    }
}

// -----------------------------------------------------------------------------
$pg_config = [
    'host'     => getenv('IX_PG_HOST') ?: '',
    'port'     => (int) (getenv('IX_PG_PORT') ?: 5432),
    'database' => getenv('IX_PG_DATABASE') ?: '',
    'username' => getenv('IX_PG_USER') ?: '',
    'password' => getenv('IX_PG_PASSWORD') ?: '',
];

if (!extension_loaded('pdo_pgsql') || $pg_config['host'] === '' || $pg_config['database'] === '') {
    echo "  SKIPPED postgresql - not configured (IX_PG_HOST, IX_PG_DATABASE, IX_PG_USER).\n";
    exit(summary());
}

$reachable = $connect(static fn() => postgres($pg_config), 'postgresql');

if ($reachable !== null) {
    $shared($reachable, 'postgresql');
}

exit(summary());
