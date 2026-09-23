<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — renaming a column on MySQL and MariaDB
 *
 * `RENAME COLUMN` needs MySQL 8.0 or MariaDB 10.5.2. Below those it is a plain
 * syntax error, and the message names the whole clause rather than the version
 * — so the failure reads like a bug in the migration rather than in the ORM.
 *
 * This suite existed after `rename_column()` was found emitting that statement
 * on every version, from both branches of an `if ($this->dialect === 'mysql')`
 * whose two arms were identical, under a comment saying MySQL needs `CHANGE`
 * with a definition. The comment was right and the code had never followed it.
 *
 * `CHANGE` needs the column's full definition, which is why the assertions
 * below are about the definition surviving: a rename that quietly widened a
 * column, dropped its NOT NULL or lost its default would pass a test that only
 * checked the new name existed.
 *
 * Run: php src/Libs/Italix/Orm/tests/RenameColumnTest.php
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

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Orm\Migration\Schema;

use function Italix\Orm\mysql;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix ORM — rename_column on MySQL/MariaDB');

$config = [
    'host'     => getenv('IX_MY_HOST') ?: '',
    'database' => getenv('IX_MY_DATABASE') ?: '',
    'username' => getenv('IX_MY_USER') ?: '',
    'password' => getenv('IX_MY_PASSWORD') ?: '',
];

if ($config['host'] === '' || $config['database'] === '') {
    echo "  SKIPPED - no MySQL configured (set IX_MY_HOST, IX_MY_DATABASE, IX_MY_USER, IX_MY_PASSWORD).\n";
    exit(summary());
}

$dm = mysql($config);
Schema::set_connection($dm);

$table_c = 'ix_rename_probe';
$pdo     = $dm->get_connection();

$pdo->exec("DROP TABLE IF EXISTS `{$table_c}`");
$pdo->exec("CREATE TABLE `{$table_c}` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plain_c VARCHAR(64) NOT NULL,
    with_default_c VARCHAR(20) NOT NULL DEFAULT 'unset',
    nullable_c VARCHAR(32) NULL,
    insert_dt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("INSERT INTO `{$table_c}` (plain_c, nullable_c) VALUES ('keep me', 'and me')");

/** @return array<string, array<string, string|null>> */
$columns = static function () use ($pdo, $table_c): array {
    $out = [];

    foreach ($pdo->query("SHOW FULL COLUMNS FROM `{$table_c}`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $out[$row['Field']] = $row;
    }

    return $out;
};

$before = $columns();

echo "  server: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

// -----------------------------------------------------------------------------
section('the rename itself');

Schema::table($table_c, static function ($table): void {
    $table->rename_column('plain_c', 'plain_tk');
    $table->rename_column('with_default_c', 'with_default_tk');
    $table->rename_column('nullable_c', 'nullable_tk');
    $table->rename_column('insert_dt', 'insert_tk');
});

$after = $columns();

test('the old names are gone', !isset($after['plain_c'], $after['with_default_c'], $after['nullable_c'], $after['insert_dt']));
test('the new ones are there', isset($after['plain_tk'], $after['with_default_tk'], $after['nullable_tk'], $after['insert_tk']));

// -----------------------------------------------------------------------------
section('and nothing else about the columns moved');

// The assertions that matter. A `CHANGE` built from a guessed definition
// compiles and runs, and quietly rewrites whatever it guessed wrong.
test('the type survives', $after['plain_tk']['Type'] === $before['plain_c']['Type']);
test('NOT NULL survives', $after['plain_tk']['Null'] === 'NO' && $before['plain_c']['Null'] === 'NO');
test('a nullable column stays nullable', $after['nullable_tk']['Null'] === 'YES');
test('the collation survives', $after['plain_tk']['Collation'] === $before['plain_c']['Collation']);
test('a default survives', $after['with_default_tk']['Default'] === 'unset');
test('...and a column with no default does not acquire one',
    $after['plain_tk']['Default'] === null);

// The bug a quoted expression default would cause: DEFAULT CURRENT_TIMESTAMP
// rewritten as DEFAULT 'CURRENT_TIMESTAMP', a literal string instead of the
// server filling the column in on every insert. Checked two ways: the
// metadata still names the expression (not one exact spelling — MySQL reports
// it bare, CURRENT_TIMESTAMP; MariaDB as a call, current_timestamp(), each
// confirmed against a real server, not assumed) — and, the assertion that
// actually catches a quoted-literal regression, a fresh row is stamped by the
// server rather than storing the literal text.
test('an expression default (CURRENT_TIMESTAMP) still names the expression after the rename',
    stripos((string) $after['insert_tk']['Default'], 'CURRENT_TIMESTAMP') !== false,
    (string) $after['insert_tk']['Default']);

$pdo->exec("INSERT INTO `{$table_c}` (plain_tk, nullable_tk) VALUES ('after rename', NULL)");
$stamped = $pdo->query("SELECT insert_tk FROM `{$table_c}` WHERE plain_tk = 'after rename'")->fetchColumn();
test('...and the server really does fill it in on insert, not the literal text',
    $stamped !== 'CURRENT_TIMESTAMP' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $stamped) === 1, (string) $stamped);

// -----------------------------------------------------------------------------
section('and the rows are untouched');

$row = $pdo->query("SELECT plain_tk, nullable_tk FROM `{$table_c}`")->fetch(\PDO::FETCH_ASSOC);

test('the data came across', $row['plain_tk'] === 'keep me' && $row['nullable_tk'] === 'and me');

$pdo->exec("DROP TABLE IF EXISTS `{$table_c}`");

exit(summary());
