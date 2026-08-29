<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — reading inside a JSON column
 *
 * The package could declare `json()` and `jsonb()` columns and offered no way to
 * ask anything about what was in them. Now it does, and the three dialects agree
 * about none of it — so the assertions **run the query on each server** and
 * check the rows, because the failure here is never a syntax error:
 *
 *   - `JSON_EXTRACT` on MySQL returns `"Ada"`, quotes included, so comparing it
 *     to `'Ada'` matches nothing and says nothing.
 *   - An unquoted PostgreSQL path array ends at the first comma, so a key with
 *     one addresses a different place in the document.
 *   - PostgreSQL's key-exists operator is `?`, which PDO eats as a placeholder.
 *
 * SQLite runs always; MySQL and PostgreSQL when configured (`IX_MY_*`,
 * `IX_PG_*`), and are skipped otherwise.
 *
 * Run: php src/Libs/Italix/Orm/tests/JsonTest.php
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
use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\{integer, json, jsonb, mysql_table, pg_table, sqlite_table, text};
use function Italix\Orm\Operators\{eq, gt, json_contains, json_get, json_has, json_length, json_missing,
    json_not_contains, json_text};
use function Italix\Orm\{mysql, postgres, sqlite_memory};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - JSON columns');

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

/** The documents every dialect gets, by id. */
$documents = [
    1 => '{"name":"Ada","status":"paid","tags":["tools","new"],"meta":{"age":36},"odd,key":"yes"}',
    2 => '{"name":"Grace","status":"draft","tags":["tools"],"meta":{"age":45}}',
    3 => '{"name":"Alan","status":"paid","tags":[],"meta":{}}',
];

/**
 * Everything that must hold on every dialect.
 *
 * @param Table $orders a table with `id` and a JSON `doc`
 */
$shared = static function (DataManager $dm, Table $orders, string $dialect) use ($throws): void {
    $names = static fn(array $rows): string => implode(',', array_column($rows, 'name'));

    // -------------------------------------------------------------------------
    section($dialect . ': reading a value out');

    $read = $dm->select([$orders->id, json_text($orders->doc, '$.name')->as('name')])
        ->from($orders)->order_by($orders->id)->execute();

    test($dialect . ': a scalar comes back as text', $names($read) === 'Ada,Grace,Alan');

    test($dialect . ': …nested, too', (static function () use ($dm, $orders): bool {
        $rows = $dm->select([json_text($orders->doc, '$.meta.age')->as('age')])
            ->from($orders)->where(eq($orders->id, 2))->execute();

        return (string) $rows[0]['age'] === '45';
    })());

    test($dialect . ': …and out of an array', (static function () use ($dm, $orders): bool {
        $rows = $dm->select([json_text($orders->doc, '$.tags[0]')->as('tag')])
            ->from($orders)->where(eq($orders->id, 1))->execute();

        return $rows[0]['tag'] === 'tools';
    })());

    // The MySQL trap: JSON_EXTRACT returns `"Ada"`, and a comparison against
    // 'Ada' then matches nothing at all.
    test($dialect . ': COMPARING TEXT FINDS THE ROWS', (static function () use ($dm, $orders, $names): bool {
        $rows = $dm->select([$orders->id, json_text($orders->doc, '$.name')->as('name')])
            ->from($orders)
            ->where(eq(json_text($orders->doc, '$.status'), 'paid'))
            ->order_by($orders->id)
            ->execute();

        return $names($rows) === 'Ada,Alan';
    })());

    test($dialect . ': a key with a comma addresses the right place', (static function () use ($dm, $orders): bool {
        $rows = $dm->select([json_text($orders->doc, '$."odd,key"')->as('odd')])
            ->from($orders)->where(eq($orders->id, 1))->execute();

        return $rows[0]['odd'] === 'yes';
    })());

    test($dialect . ': a missing path is null, not an error', (static function () use ($dm, $orders): bool {
        $rows = $dm->select([json_text($orders->doc, '$.nowhere')->as('gone')])
            ->from($orders)->where(eq($orders->id, 1))->execute();

        return $rows[0]['gone'] === null;
    })());

    // -------------------------------------------------------------------------
    section($dialect . ': asking about shape');

    test($dialect . ': json_length counts an array', (static function () use ($dm, $orders): bool {
        $rows = $dm->select([$orders->id, json_length($orders->doc, '$.tags')->as('n')])
            ->from($orders)->order_by($orders->id)->execute();

        return implode(',', array_column($rows, 'n')) === '2,1,0';
    })());

    test($dialect . ': …and can be compared', (static function () use ($dm, $orders, $names): bool {
        $rows = $dm->select([$orders->id, json_text($orders->doc, '$.name')->as('name')])
            ->from($orders)
            ->where(gt(json_length($orders->doc, '$.tags'), 1))
            ->execute();

        return $names($rows) === 'Ada';
    })());

    test($dialect . ': json_has finds a nested path', (static function () use ($dm, $orders, $names): bool {
        $rows = $dm->select([$orders->id, json_text($orders->doc, '$.name')->as('name')])
            ->from($orders)
            ->where(json_has($orders->doc, '$.meta.age'))
            ->order_by($orders->id)
            ->execute();

        return $names($rows) === 'Ada,Grace';
    })());

    test($dialect . ': json_missing is its opposite', (static function () use ($dm, $orders, $names): bool {
        $rows = $dm->select([$orders->id, json_text($orders->doc, '$.name')->as('name')])
            ->from($orders)
            ->where(json_missing($orders->doc, '$.meta.age'))
            ->execute();

        return $names($rows) === 'Alan';
    })());

    test($dialect . ': json_has refuses the root', (static function () use ($throws, $dm, $orders): bool {
        [$threw, $message] = $throws(static function () use ($dm, $orders): void {
            $params = [];
            json_has($orders->doc, '$')->to_sql($orders->get_dialect(), $params);
        });

        return $threw && strpos($message, 'by definition') !== false;
    })());

    // -------------------------------------------------------------------------
    section($dialect . ': paths that are not paths');

    foreach (['name', '$..name', '$.tags[x]', '$ .name'] as $bad) {
        [$threw] = $throws(static fn() => json_text($orders->doc, $bad));

        test($dialect . ': "' . $bad . '" is refused', $threw);
    }
};

// -----------------------------------------------------------------------------
if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED sqlite - pdo_sqlite is absent.\n";
} else {
    $orders = sqlite_table('ix_json_orders', ['id' => integer()->primary_key(), 'doc' => text()]);
    $dm     = sqlite_memory();
    $dm->execute('CREATE TABLE ix_json_orders (id INTEGER PRIMARY KEY, doc TEXT)');

    foreach ($documents as $id => $doc) {
        $dm->execute('INSERT INTO ix_json_orders (id, doc) VALUES (?, ?)', [$id, $doc]);
    }

    $shared($dm, $orders, 'sqlite');

    section('sqlite: what it has no answer for');

    // PostgreSQL's @> and MySQL's JSON_CONTAINS have no SQLite equivalent, and
    // approximating one would match a different set of rows without saying so.
    test('sqlite: CONTAINMENT IS REFUSED, NOT APPROXIMATED', (static function () use ($throws, $dm, $orders): bool {
        [$threw, $message] = $throws(static function () use ($dm, $orders): void {
            $dm->select()->from($orders)->where(json_contains($orders->doc, ['status' => 'paid']))->execute();
        });

        return $threw && strpos($message, 'no JSON containment') !== false;
    })());

    test('sqlite: …and the message says what to use instead', (static function () use ($throws, $dm, $orders): bool {
        [, $message] = $throws(static function () use ($dm, $orders): void {
            $dm->select()->from($orders)->where(json_contains($orders->doc, []))->execute();
        });

        return strpos($message, 'json_has()') !== false;
    })());
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
    $dm = $connect(static fn() => mysql($my_config), 'mysql');
}

if (isset($dm) && $dm !== null && $my_config['host'] !== '') {
    $orders = mysql_table('ix_json_orders', ['id' => integer()->primary_key(), 'doc' => json()]);

    $dm->execute('DROP TABLE IF EXISTS ix_json_orders');
    $dm->execute('CREATE TABLE ix_json_orders (id INT PRIMARY KEY, doc JSON)');

    foreach ($documents as $id => $doc) {
        $dm->execute('INSERT INTO ix_json_orders (id, doc) VALUES (?, ?)', [$id, $doc]);
    }

    $shared($dm, $orders, 'mysql');

    section('mysql: containment');

    test('mysql: json_contains finds the document', count(
        $dm->select()->from($orders)->where(json_contains($orders->doc, ['status' => 'paid']))->execute()
    ) === 2);

    test('mysql: …and json_not_contains the rest', count(
        $dm->select()->from($orders)->where(json_not_contains($orders->doc, ['status' => 'paid']))->execute()
    ) === 1);

    test('mysql: JSON already written as a string is taken as written', count(
        $dm->select()->from($orders)->where(json_contains($orders->doc, '{"name":"Ada"}'))->execute()
    ) === 1);

    test('mysql: json_get keeps the JSON', (static function () use ($dm, $orders): bool {
        $rows = $dm->select([json_get($orders->doc, '$.meta')->as('meta')])
            ->from($orders)->where(eq($orders->id, 1))->execute();

        return json_decode((string) $rows[0]['meta'], true) === ['age' => 36];
    })());

    $dm->execute('DROP TABLE IF EXISTS ix_json_orders');
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

$dm = $connect(static fn() => postgres($pg_config), 'postgresql');

if ($dm === null) {
    exit(summary());
}

$orders = pg_table('ix_json_orders', ['id' => integer()->primary_key(), 'doc' => jsonb()]);

$dm->execute('DROP TABLE IF EXISTS ix_json_orders');
$dm->execute('CREATE TABLE ix_json_orders (id INT PRIMARY KEY, doc JSONB)');

foreach ($documents as $id => $doc) {
    $dm->execute('INSERT INTO ix_json_orders (id, doc) VALUES (?, ?::jsonb)', [$id, $doc]);
}

$shared($dm, $orders, 'postgresql');

section('postgresql: containment');

test('postgresql: json_contains finds the document', count(
    $dm->select()->from($orders)->where(json_contains($orders->doc, ['status' => 'paid']))->execute()
) === 2);

test('postgresql: …and json_not_contains the rest', count(
    $dm->select()->from($orders)->where(json_not_contains($orders->doc, ['status' => 'paid']))->execute()
) === 1);

test('postgresql: json_get keeps the JSON', (static function () use ($dm, $orders): bool {
    $rows = $dm->select([json_get($orders->doc, '$.meta')->as('meta')])
        ->from($orders)->where(eq($orders->id, 1))->execute();

    return json_decode((string) $rows[0]['meta'], true) === ['age' => 36];
})());

// The operator this deliberately does not use: `?` is what PostgreSQL calls
// key-exists, and PDO takes it for a placeholder before the server ever sees it.
test('postgresql: THE `?` OPERATOR REALLY IS UNUSABLE THROUGH PDO', (static function () use ($throws, $dm): bool {
    [$threw] = $throws(static function () use ($dm): void {
        $dm->query("SELECT id FROM ix_json_orders WHERE doc ? 'name'");
    });

    return $threw;
})());

test('postgresql: …which is why json_has() is written the other way', count(
    $dm->select()->from($orders)->where(json_has($orders->doc, '$.name'))->execute()
) === 3);

$dm->execute('DROP TABLE IF EXISTS ix_json_orders');

exit(summary());
