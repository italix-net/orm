<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Table::fulltext() / Operators\fulltext_match()
 *
 * `Migration\Blueprint::fulltext()` already existed, but rendered MySQL's
 * `FULLTEXT INDEX` syntax on every dialect — including PostgreSQL and
 * SQLite, neither of which have any such thing, so it failed at execution
 * on both. It went unnoticed for the same reason `Blueprint::enum()`'s
 * identical-shaped bug did in 2.24.0: nothing had ever run it against a
 * real non-MySQL server.
 *
 * There was also no way to *search* at all — a full-text index with no
 * operator to query it against was a schema-only feature. This closes both:
 * `fulltext()` now renders correctly on all three dialects (a native
 * `FULLTEXT INDEX` on MySQL, a GIN index over `to_tsvector(...)` on
 * PostgreSQL, and — SQLite has neither concept, only a wholly separate FTS5
 * *virtual table* — an external-content FTS5 table plus three sync triggers
 * on SQLite), and the new `Operators\fulltext_match()` renders a WHERE
 * fragment for each of the three, composable with the rest of a query the
 * same as any other condition.
 *
 * MySQL and PostgreSQL are rendering-only assertions here — this suite has
 * no server for either, the same limitation every other multi-dialect test
 * in this package accepts (see `CheckAndEnumTest.php`). SQLite is executed
 * for real: rows inserted, searched, updated, deleted, and the FTS5 index
 * proven to track every one of those through the sync triggers — not
 * assumed from the schema declaration alone.
 *
 * Run: php src/Libs/Italix/Orm/tests/FulltextSearchTest.php
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

use Italix\Orm\Migration\Blueprint;

use function Italix\Orm\Schema\{integer, varchar, text, mysql_table, pg_table, sqlite_table};
use function Italix\Orm\Operators\{fulltext_match, eq};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Table::fulltext() / Operators\\fulltext_match()');

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
        return [true, get_class($e) . ': ' . $e->getMessage()];
    }
};

// -----------------------------------------------------------------------------
section('Table::fulltext() renders per dialect — MySQL and PostgreSQL, rendering only');

$mysql_articles = mysql_table('articles', [
    'id'    => integer()->primary_key()->auto_increment(),
    'title' => varchar(200)->not_null(),
    'body'  => text()->not_null(),
])->fulltext(['title', 'body']);

$mysql_index_sql = $mysql_articles->get_index_sql();
test(
    'MySQL: a native FULLTEXT INDEX statement',
    count($mysql_index_sql) === 1
        && strpos($mysql_index_sql[0], 'CREATE FULLTEXT INDEX') === 0
        && strpos($mysql_index_sql[0], '(`title`, `body`)') !== false
);

$pg_articles = pg_table('articles', [
    'id'    => integer()->primary_key()->auto_increment(),
    'title' => varchar(200)->not_null(),
    'body'  => text()->not_null(),
])->fulltext(['title', 'body']);

$pg_index_sql = $pg_articles->get_index_sql();
test(
    'PostgreSQL: a GIN index over to_tsvector(...) of the concatenated columns',
    count($pg_index_sql) === 1
        && strpos($pg_index_sql[0], 'USING GIN') !== false
        && strpos($pg_index_sql[0], "to_tsvector('english', \"title\" || ' ' || \"body\")") !== false
);

// -----------------------------------------------------------------------------
section('Operators\\fulltext_match() renders per dialect too — the query side, not only the DDL side');

$mysql_params = [];
$mysql_match_sql = fulltext_match($mysql_articles, ['title', 'body'], 'quick fox')->to_sql('mysql', $mysql_params);
test(
    'MySQL: MATCH(...) AGAINST (? IN NATURAL LANGUAGE MODE) by default',
    $mysql_match_sql === 'MATCH(`title`, `body`) AGAINST (? IN NATURAL LANGUAGE MODE)'
);
test('…and the query text is bound as a parameter, not inlined', $mysql_params === ['quick fox']);

$mysql_bool_params = [];
$mysql_bool_sql = fulltext_match($mysql_articles, ['title', 'body'], '+fox -turtle', 'boolean')->to_sql('mysql', $mysql_bool_params);
test('MySQL: mode=\'boolean\' switches to IN BOOLEAN MODE', strpos($mysql_bool_sql, 'IN BOOLEAN MODE') !== false);

$pg_params = [];
$pg_match_sql = fulltext_match($pg_articles, ['title', 'body'], 'quick fox')->to_sql('postgresql', $pg_params);
test(
    'PostgreSQL: to_tsvector(...) @@ plainto_tsquery(...) by default',
    $pg_match_sql === "to_tsvector('english', \"title\" || ' ' || \"body\") @@ plainto_tsquery('english', ?)"
);
test('…query text bound as a parameter here too', $pg_params === ['quick fox']);

$pg_bool_params = [];
$pg_bool_sql = fulltext_match($pg_articles, ['title', 'body'], 'fox & quick', 'boolean')->to_sql('postgresql', $pg_bool_params);
test('PostgreSQL: mode=\'boolean\' switches to to_tsquery (raw tsquery syntax)', strpos($pg_bool_sql, 'to_tsquery(') !== false && strpos($pg_bool_sql, 'plainto_tsquery') === false);

// -----------------------------------------------------------------------------
section('Table::fulltext() on SQLite — real execution: virtual table + sync triggers');

$articles = sqlite_table('articles', [
    'id'    => integer()->primary_key()->auto_increment(),
    'title' => varchar(200)->not_null(),
    'body'  => text()->not_null(),
])->fulltext(['title', 'body']);

$sqlite_index_sql = $articles->get_index_sql();
test('SQLite: four statements — the virtual table and three triggers', count($sqlite_index_sql) === 4);
test('…the first creates the FTS5 virtual table', strpos($sqlite_index_sql[0], 'CREATE VIRTUAL TABLE') === 0);

$dm = sqlite_memory();
$dm->create_tables($articles);

$dm->insert($articles)->values(['title' => 'Quick brown fox', 'body' => 'jumps over the lazy dog'])->execute();
$dm->insert($articles)->values(['title' => 'Slow turtle', 'body' => 'never jumps at all'])->execute();
$dm->insert($articles)->values(['title' => 'Weather report', 'body' => 'sunny with a chance of rain'])->execute();

$fox_results = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'fox'))->execute();
test('a term only in one row\'s title is found — and only that row', count($fox_results) === 1 && $fox_results[0]['title'] === 'Quick brown fox');

$jumps_results = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'jumps'))->execute();
test('a term appearing in two different rows\' bodies finds both', count($jumps_results) === 2);

$none_results = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'nonexistentword'))->execute();
test('a term matching nothing returns no rows, not an error', $none_results === []);

// -----------------------------------------------------------------------------
section('the FTS5 index stays in sync — UPDATE and DELETE, not only INSERT');

$dm->update($articles)->set(['body' => 'stays completely still'])->where(eq($articles->title, 'Slow turtle'))->execute();
$after_update = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'jumps'))->execute();
test('after UPDATE, the old body text no longer matches', count($after_update) === 1 && $after_update[0]['title'] === 'Quick brown fox');

$new_match = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'still'))->execute();
test('…and the new body text does', count($new_match) === 1 && $new_match[0]['title'] === 'Slow turtle');

$dm->delete($articles)->where(eq($articles->title, 'Quick brown fox'))->execute();
$after_delete = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'fox'))->execute();
test('after DELETE, the row no longer matches its own former text', $after_delete === []);

$still_there = $dm->select()->from($articles)->where(fulltext_match($articles, ['title', 'body'], 'weather'))->execute();
test('…and an unrelated row is unaffected by the delete', count($still_there) === 1);

// -----------------------------------------------------------------------------
section('fulltext_match() composes with the rest of a WHERE clause, like any other condition');

$dm->execute('DELETE FROM articles');
$dm->insert($articles)->values(['title' => 'Draft: quick review', 'body' => 'not yet published'])->execute();
$dm->insert($articles)->values(['title' => 'Published: quick review', 'body' => 'now live'])->execute();

$combined = $dm->select()->from($articles)
    ->where(\Italix\Orm\Operators\and_(
        fulltext_match($articles, ['title', 'body'], 'quick'),
        eq($articles->title, 'Published: quick review')
    ))
    ->execute();
test('ANDed with an ordinary eq() condition, only the doubly-matching row comes back', count($combined) === 1 && $combined[0]['title'] === 'Published: quick review');

// -----------------------------------------------------------------------------
section('fulltext_match() on SQLite refuses a table with no single-column primary key');

$composite = sqlite_table('composite_docs', [
    'tenant_id' => integer()->not_null(),
    'doc_id'    => integer()->not_null(),
    'body'      => text()->not_null(),
])->primary_key(['tenant_id', 'doc_id']);

[$match_threw, $match_message] = $throws(function () use ($composite): void {
    $dummy_params = [];
    fulltext_match($composite, ['body'], 'x')->to_sql('sqlite', $dummy_params);
});
test('fulltext_match() raises rather than rendering SQL referencing a rowid that cannot exist', $match_threw);
test('…naming the actual problem', strpos($match_message, 'single-column') !== false);

[$fulltext_decl_threw] = $throws(function () use ($composite): void {
    $composite->fulltext(['body']);
    $composite->get_index_sql();
});
test('…and Table::fulltext()\'s own SQLite rendering refuses the same table for the same reason', $fulltext_decl_threw);

// -----------------------------------------------------------------------------
section('Migration\\Blueprint::fulltext() — the bug this suite exists to close');

$bp_mysql = new Blueprint('posts', 'mysql');
$bp_mysql->id();
$bp_mysql->string('title');
$bp_mysql->fulltext('title');
$bp_mysql_sql = $bp_mysql->to_index_sql();
test('Blueprint on MySQL still renders FULLTEXT INDEX, unaffected', strpos($bp_mysql_sql[0], 'CREATE FULLTEXT INDEX') === 0);

$bp_pg = new Blueprint('posts', 'postgresql');
$bp_pg->id();
$bp_pg->string('title');
$bp_pg->fulltext('title');
$bp_pg_sql = $bp_pg->to_index_sql();
test(
    'Blueprint on PostgreSQL now renders a GIN index — previously this was MySQL syntax that would fail here',
    strpos($bp_pg_sql[0], 'USING GIN') !== false
);
test('…and definitely not the old, invalid FULLTEXT INDEX syntax', strpos($bp_pg_sql[0], 'FULLTEXT') === false);

$bp_sqlite = new Blueprint('posts', 'sqlite');
$bp_sqlite->id();
$bp_sqlite->string('title');
$bp_sqlite->text('body');
$bp_sqlite->fulltext(['title', 'body']);
$bp_sqlite_sql = $bp_sqlite->to_index_sql();
test(
    'Blueprint on SQLite now renders the FTS5 virtual table + triggers — previously this was MySQL syntax that would fail here',
    count($bp_sqlite_sql) === 4 && strpos($bp_sqlite_sql[0], 'CREATE VIRTUAL TABLE') === 0
);

// And executed for real, exactly like the schema-level path above.
$dm_bp = sqlite_memory();
$dm_bp->execute($bp_sqlite->to_create_sql());
foreach ($bp_sqlite_sql as $stmt) {
    $dm_bp->execute($stmt);
}
$dm_bp->execute('INSERT INTO posts (title, body) VALUES (?, ?)', ['Hello world', 'first post']);
$bp_search = $dm_bp->query('SELECT id FROM posts WHERE id IN (SELECT rowid FROM posts_fts WHERE posts_fts MATCH ?)', ['world']);
test('the Blueprint-created FTS5 table actually works end to end', count($bp_search) === 1);

// -----------------------------------------------------------------------------
section('Blueprint::fulltext() on SQLite refuses a table with no single-column primary key, same as Table::fulltext()');

$bp_composite = new Blueprint('composite_posts', 'sqlite');
$bp_composite->integer('tenant_id');
$bp_composite->integer('doc_id');
$bp_composite->text('body');
$bp_composite->primary(['tenant_id', 'doc_id']);
$bp_composite->fulltext('body');

[$bp_threw, $bp_message] = $throws(fn () => $bp_composite->to_index_sql());
test('raises rather than emitting SQL referencing a rowid alias that does not exist', $bp_threw);
test('…naming the actual problem', strpos($bp_message, 'single-column') !== false);

// -----------------------------------------------------------------------------
section('Migration\\Blueprint::to_alter_sql() — the ALTER TABLE path, not only CREATE');

$bp_alter_pg = new Blueprint('posts', 'postgresql');
$bp_alter_pg->fulltext('title');
$bp_alter_pg_sql = $bp_alter_pg->to_alter_sql();
test(
    'ALTER TABLE ... ADD FULLTEXT on PostgreSQL renders a GIN index too, not MySQL syntax',
    strpos($bp_alter_pg_sql[0], 'USING GIN') !== false
);

exit(summary());
