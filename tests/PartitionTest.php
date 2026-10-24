<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Table::partitioned_by() and DataManager::with_partition()
 *
 * Every row of a partitioned table belongs to exactly one partition; every query a DataManager builds carries
 * the partition it was made in. The writes are the point: a DELETE by code inside one partition must not delete
 * that code in the others. Both query engines are exercised, as GlobalScopesTest does — QueryBuilder (select())
 * and TableQuery (query_table()) share no implementation.
 *
 * Run: php src/Libs/Italix/Orm/tests/PartitionTest.php
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

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\DataManager;
use Italix\Orm\Migration\Blueprint;
use Italix\Orm\Relations\RelationalQueryBuilder;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Schema\{integer, serial, timestamp, varchar, sqlite_table};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Table::partitioned_by()');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

/** Whether `$work` threw a `$class`. */
$throws = static function (callable $work, string $class = \LogicException::class): bool {
    try {
        $work();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }

    return false;
};

$terms = sqlite_table('terms', [
    'id'           => serial(),
    'collection_c' => varchar(191)->not_null()->default(''),
    'code'         => varchar(50)->not_null(),
    'label'        => varchar(50),
])->partitioned_by('collection_c');

/** code => collection, every row, unfiltered. */
$all_rows = static function (DataManager $dm): array {
    $out = [];
    foreach ($dm->query('SELECT code, collection_c FROM terms ORDER BY id') as $r) {
        $out[] = $r['collection_c'] . ':' . $r['code'];
    }

    return $out;
};

$dm = sqlite_memory();
$dm->create_tables($terms);

// -----------------------------------------------------------------------------
section('INSERT fills the partition');

$dm->insert($terms)->values(['code' => '42', 'label' => 'no context'])->execute();
$dm->with_partition('shop-a', function (DataManager $dm) use ($terms): void {
    $dm->insert($terms)->values(['code' => '42', 'label' => 'a'])->execute();
    $dm->insert($terms)->values(['code' => '7', 'label' => 'a', 'collection_c' => 'shop-b'])->execute();
});
$dm->with_partition('shop-b', fn (DataManager $dm) => $dm->insert_many($terms, [['code' => '42', 'label' => 'b']]));

test('no context: the table\'s default partition, \'\'', $all_rows($dm)[0] === ':42');
test('inside with_partition(): that partition', $all_rows($dm)[1] === 'shop-a:42');
test('a value the caller set wins, as with timestamps()', $all_rows($dm)[2] === 'shop-b:7');
test('insert_many() fills it too', $all_rows($dm)[3] === 'shop-b:42');

$custom = sqlite_table('custom', [
    'id'      => serial(),
    'tenant'  => varchar(20)->not_null(),
    'note'    => varchar(20),
])->partitioned_by('tenant', 'public');
$dm->create_tables($custom);
$dm->insert($custom)->values(['note' => 'x'])->execute();
test('column name and default value are both the caller\'s choice', $dm->query('SELECT tenant FROM custom')[0]['tenant'] === 'public');

// -----------------------------------------------------------------------------
section('SELECT sees its own partition only — QueryBuilder');

$codes = static fn (array $rows): array => array_map(static fn (array $r): string => $r['collection_c'] . ':' . $r['code'], $rows);

test('no context reads the \'\' partition only — isolated like any other', $codes($dm->select()->from($terms)->execute()) === [':42']);
test('shop-a reads its own rows', $dm->with_partition('shop-a', fn (DataManager $dm) => $codes($dm->select()->from($terms)->execute())) === ['shop-a:42']);
test('a where() is ANDed with the partition, not replaced by it',
    $dm->with_partition('shop-b', fn (DataManager $dm) => $codes($dm->select()->from($terms)->where(eq($terms->code, '42'))->execute())) === ['shop-b:42']);

// -----------------------------------------------------------------------------
section('within(), across(), across_all()');

test('within() reads another partition', $codes($dm->select()->from($terms)->within('shop-a')->execute()) === ['shop-a:42']);
test('across() reads several', $codes($dm->select()->from($terms)->across(['shop-a', 'shop-b'])->execute()) === ['shop-a:42', 'shop-b:7', 'shop-b:42']);
test('across_all() reads every one', count($dm->select()->from($terms)->across_all()->execute()) === 4);
test('across([]) is refused — neither "nothing" nor "all" by accident',
    $throws(fn () => $dm->select()->from($terms)->across([]), \InvalidArgumentException::class));
test('within() on an INSERT fills that partition', (function () use ($dm, $terms, $all_rows): bool {
    $dm->insert($terms)->within('shop-c')->values(['code' => '1'])->execute();

    return in_array('shop-c:1', $all_rows($dm), true);
})());
test('an INSERT across several partitions is refused', $throws(fn () => $dm->insert($terms)->across(['a', 'b'])->values(['code' => '1'])->execute()));
test('an INSERT across_all() is refused', $throws(fn () => $dm->insert($terms)->across_all()->values(['code' => '1'])->execute()));

// -----------------------------------------------------------------------------
section('UPDATE and DELETE stay in their partition');

$dm->with_partition('shop-a', fn (DataManager $dm) => $dm->update($terms)->set(['label' => 'changed'])->where(eq($terms->code, '42'))->execute());
$labels = array_column($dm->query("SELECT collection_c, label FROM terms WHERE code = '42' ORDER BY id"), 'label', 'collection_c');
test('an UPDATE by code changes that code in its own partition', $labels['shop-a'] === 'changed');
test('…and in no other', $labels[''] === 'no context' && $labels['shop-b'] === 'b');

$dm->with_partition('shop-b', fn (DataManager $dm) => $dm->delete($terms)->where(eq($terms->code, '42'))->execute());
test('a DELETE by code deletes it in its own partition only', !in_array('shop-b:42', $all_rows($dm), true)
    && in_array('shop-a:42', $all_rows($dm), true) && in_array(':42', $all_rows($dm), true));

$dm->with_partition('shop-c', fn (DataManager $dm) => $dm->delete($terms)->execute());
test('a DELETE with no where() empties its own partition, nothing else', !in_array('shop-c:1', $all_rows($dm), true) && count($all_rows($dm)) === 3);

// -----------------------------------------------------------------------------
section('a row never leaves its partition by accident');

test('an UPDATE setting the partition column is refused', $throws(fn () => $dm->with_partition('shop-a',
    fn (DataManager $dm) => $dm->update($terms)->set(['collection_c' => 'shop-b'])->where(eq($terms->code, '42'))->execute())));
test('…and wrote nothing', in_array('shop-a:42', $all_rows($dm), true));
$dm->update($terms)->across_all()->set(['collection_c' => 'shop-z'])->where(eq($terms->code, '7'))->execute();
test('across_all() says it on purpose, and it happens', in_array('shop-z:7', $all_rows($dm), true));
test('an upsert whose update sets the partition column is refused', $throws(fn () => $dm->insert($terms)
    ->values(['id' => 1, 'code' => '42'])->on_conflict_do_update(['id'], ['collection_c' => 'shop-b'])->execute()));

// Found by review (2026-09-25): a conflict on the primary key — or on any unique key not led by the partition — matched
// another partition's row, and the upsert rewrote it. MySQL's ON DUPLICATE KEY cannot be filtered, so it is refused.
$z_row = $dm->query("SELECT id, code FROM terms WHERE collection_c = 'shop-z'")[0];   // shop-z:7, moved there above
test('any upsert on a partitioned table is refused — one whose conflict key is another partition\'s row included',
    $throws(fn () => $dm->with_partition('shop-a', fn (DataManager $dm) => $dm->insert($terms)
        ->values(['id' => (int) $z_row['id'], 'code' => 'zz'])->on_conflict_do_update(['id'], ['code' => 'zz'])->execute())));
test('…and the other partition\'s row is untouched', $z_row['code'] === '7'
    && $dm->query('SELECT code FROM terms WHERE id = ' . (int) $z_row['id'])[0]['code'] === '7');

// Found by review: the move guard compared keys by exact PHP name, while SQL reaches the column by its database name,
// in any letter case.
$renamed = sqlite_table('renamed', [
    'id'         => serial(),
    'collection' => varchar(191)->not_null()->default('')->set_db_name('collection_c'),
    'code'       => varchar(20)->not_null(),
])->partitioned_by('collection');
$dm->create_tables($renamed);
$dm->insert($renamed)->within('a')->values(['code' => 'r'])->execute();
test('a column whose PHP key differs from its name: the INSERT fills it', $dm->query('SELECT collection_c FROM renamed')[0]['collection_c'] === 'a');
test('an UPDATE naming it by its database name is refused', $throws(fn () => $dm->update($renamed)->within('a')->set(['collection_c' => 'b'])->execute()));
test('…and in another letter case too', $throws(fn () => $dm->update($terms)->within('shop-a')->set(['COLLECTION_C' => 'b'])->execute()));
test('an INSERT naming it by its database name keeps the caller\'s value, once', (function () use ($dm, $renamed): bool {
    $dm->insert($renamed)->within('a')->values(['code' => 's', 'collection_c' => 'c'])->execute();

    return $dm->query("SELECT collection_c FROM renamed WHERE code = 's'")[0]['collection_c'] === 'c';
})());
test('nothing moved', $dm->query("SELECT collection_c FROM renamed WHERE code = 'r'")[0]['collection_c'] === 'a');

// -----------------------------------------------------------------------------
section('with_partition() restores, and a builder keeps the partition it was made in');

try {
    $dm->with_partition('shop-a', function (): void {
        throw new \RuntimeException('boom');
    });
} catch (\RuntimeException $e) {
}
test('restored after an exception', $dm->partition() === null);

$inner = null;
$dm->with_partition('outer', function (DataManager $dm) use (&$inner): void {
    $dm->with_partition('inner', function (DataManager $dm) use (&$inner): void {
        $inner = $dm->partition();
    });
    $inner .= '/' . $dm->partition();
});
test('nested calls stack and unwind', $inner === 'inner/outer' && $dm->partition() === null);

$made_inside = $dm->with_partition('shop-a', fn (DataManager $dm) => $dm->select()->from($terms));
test('a builder made inside, run outside, still reads the partition it was written in', $codes($made_inside->execute()) === ['shop-a:42']);

// -----------------------------------------------------------------------------
section('independent of scopes and soft deletes');

$dm->add_global_scope($terms, 'only-42', fn (Table $t) => eq($t->code, '42'));
test('without_scopes() does not lift the partition', $codes($dm->select()->from($terms)->within('shop-z')->without_scopes()->execute()) === ['shop-z:7']);
test('…while the scope itself still narrows inside it', $dm->select()->from($terms)->within('shop-z')->execute() === []);

$docs = sqlite_table('docs', [
    'id'         => serial(),
    'scope_c'    => varchar(191)->not_null()->default(''),
    'code'       => varchar(20)->not_null(),
    'deleted_at' => timestamp(),
])->partitioned_by()->soft_deletes();
$dm->create_tables($docs);
$dm->insert($docs)->within('a')->values(['code' => 'x'])->execute();
$dm->insert($docs)->within('b')->values(['code' => 'x'])->execute();
$dm->delete($docs)->within('a')->where(eq($docs->code, 'x'))->execute();
test('a soft DELETE marks its own partition\'s row only', $dm->query('SELECT scope_c FROM docs WHERE deleted_at IS NULL')[0]['scope_c'] === 'b');
test('with_trashed() brings back deleted rows, still in their partition', count($dm->select()->from($docs)->within('a')->with_trashed()->execute()) === 1);

// -----------------------------------------------------------------------------
section('TableQuery and TypedQuery — the other engine');

$dm2 = sqlite_memory();
$dm2->create_tables($terms);
$dm2->insert_many($terms, [['code' => '1', 'collection_c' => 'a'], ['code' => '2', 'collection_c' => 'b'], ['code' => '3', 'collection_c' => '']]);

test('query_table() reads the context\'s partition', $dm2->with_partition('a', fn (DataManager $dm) => array_column($dm->query_table($terms)->find_many(), 'code')) === ['1']);
test('…find() by id too: another partition\'s id is not found', $dm2->with_partition('a', fn (DataManager $dm) => $dm->query_table($terms)->find(2)) === null);
test('query_table() with no context reads \'\'', array_column($dm2->query_table($terms)->find_many(), 'code') === ['3']);
test('within()/across()/across_all() on TableQuery', array_column($dm2->query_table($terms)->within('b')->find_many(), 'code') === ['2']
    && count($dm2->query_table($terms)->across(['a', 'b'])->find_many()) === 2 && count($dm2->query_table($terms)->across_all()->find_many()) === 3);

class PartitionedTermRow extends ActiveRow
{
    use Persistable;
}

PartitionedTermRow::set_persistence($dm2, $terms);
test('TypedQuery reads \'\' with no context', count(PartitionedTermRow::query()->find_many()) === 1);
test('TypedQuery forwards within(), across(), across_all()', count(PartitionedTermRow::query()->within('a')->find_many()) === 1
    && count(PartitionedTermRow::query()->across(['a', 'b'])->find_many()) === 2 && count(PartitionedTermRow::query()->across_all()->find_many()) === 3);

// Found by review: a row read with within('b') and saved or deleted outside 'b' ran in the caller's partition, matched
// nothing, and returned as if it had worked — the delete even cleared the row's key.
$row_b = PartitionedTermRow::query()->within('b')->find_first();
$row_b['label'] = 'changed';
test('an ActiveRow of another partition refuses save() — loudly, not a lost write', $throws(fn () => $row_b->save()));
test('…refuses delete(), and keeps its key', $throws(fn () => $row_b->delete()) && $row_b->exists());
test('…refuses refresh()', $throws(fn () => $row_b->refresh()));
test('inside its own partition it saves', $dm2->with_partition('b', fn () => $row_b->save()) === $row_b
    && $dm2->query("SELECT label FROM terms WHERE collection_c = 'b'")[0]['label'] === 'changed');

// Found by the re-review: the check above passed rows that did not carry their partition, and those still wrote nothing.
$made = $dm2->with_partition('a', fn () => PartitionedTermRow::create(['code' => 'new']));
test('an inserted row learns the partition it went into', $made['collection_c'] === 'a');
test('…so it refuses save() in another partition, and delete() outside', $throws(fn () => $dm2->with_partition('b', function () use ($made): void {
    $made['label'] = 'N2';
    $made->save();
})) && $throws(fn () => $made->delete()) && $made->exists());
test('…and saves in its own', $dm2->with_partition('a', fn () => $made->save()) === $made
    && $dm2->query("SELECT label FROM terms WHERE code = 'new'")[0]['label'] === 'N2');

$partial = PartitionedTermRow::query()->within('b')->columns([$terms->id, $terms->label])->find_first();
$partial['label'] = 'partial';
test('a row read without its partition column refuses to write, even in its partition', $throws(fn () => $dm2->with_partition('b', fn () => $partial->save())));

$loose = sqlite_table('loose', ['id' => serial(), 'collection_c' => varchar(191), 'label' => varchar(20)])->partitioned_by('collection_c');
$dm2->create_tables($loose);
$dm2->execute("INSERT INTO loose (collection_c, label) VALUES (NULL, 'x')");

class LooseRow extends ActiveRow
{
    use Persistable;
}

LooseRow::set_persistence($dm2, $loose);
$null_row = LooseRow::query()->across_all()->find_first();
$null_row['label'] = 'y';
test('a row whose partition is NULL refuses to write — \'\' in SQL would never match it', $throws(fn () => $null_row->save()));

$keyed = sqlite_table('keyed', [
    'collection_c' => varchar(191)->not_null(),
    'code'         => varchar(20)->not_null(),
    'label'        => varchar(20),
])->primary_key(['collection_c', 'code'])->partitioned_by('collection_c');
$dm2->create_tables($keyed);
$dm2->execute("INSERT INTO keyed VALUES ('a', '1', 'A1'), ('b', '1', 'B1')");

class KeyedRow extends ActiveRow
{
    use Persistable;

    protected static $primary_key = ['collection_c', 'code'];
}

KeyedRow::set_persistence($dm2, $keyed);
$keyed_a = KeyedRow::query()->within('a')->find_first();
$keyed_a['collection_c'] = 'b';
$keyed_a['label'] = 'X';
test('a move through a composite key — which save() strips from the SET — is refused',
    $throws(fn () => $dm2->with_partition('a', fn () => $keyed_a->save())));
test('…and both rows are as they were', $dm2->query('SELECT collection_c, label FROM keyed ORDER BY collection_c')
    === [['collection_c' => 'a', 'label' => 'A1'], ['collection_c' => 'b', 'label' => 'B1']]);

// -----------------------------------------------------------------------------
section('eager-loaded relations are reached by key, not filtered');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, collection_c TEXT NOT NULL DEFAULT '', code TEXT)");
$pdo->exec("CREATE TABLE m (id INTEGER PRIMARY KEY, collection_c TEXT NOT NULL DEFAULT '', to_id INTEGER)");
$pdo->exec("INSERT INTO t VALUES (1, 'universal', 'surgery')");
$pdo->exec("INSERT INTO m VALUES (1, 'shop-a', 1)");
$t = sqlite_table('t', ['id' => serial(), 'collection_c' => varchar(191), 'code' => varchar(50)])->partitioned_by('collection_c');
$m = sqlite_table('m', ['id' => serial(), 'collection_c' => varchar(191), 'to_id' => integer()])->partitioned_by('collection_c');
$registry = ['m' => define_relations($m, static fn ($r) => ['target' => $r->one($t, ['fields' => [$m->to_id], 'references' => [$t->id]])])];
$q = new RelationalQueryBuilder($pdo, 'sqlite', $registry);
$rows = $q->query($m)->set_partition_context('shop-a')->with(['target' => []])->find_many();
test('a shop-a mapping loads its universal target', count($rows) === 1 && ($rows[0]['target']['code'] ?? null) === 'surgery');

// -----------------------------------------------------------------------------
section('the table must declare the column it is partitioned by');

$broken = sqlite_table('broken', ['id' => serial(), 'code' => varchar(10)])->partitioned_by('collection_c');
test('a query on it says which column is missing', $throws(fn () => $dm->select()->from($broken)->execute()));

// -----------------------------------------------------------------------------
section('Blueprint::partition()');

$bp = new Blueprint('ix_things', 'mysql');
$bp->id();
$bp->partition('collection_c');
test('VARCHAR(191) NOT NULL DEFAULT \'\'', str_contains($bp->to_create_sql(), "`collection_c` VARCHAR(191) NOT NULL DEFAULT ''"));
test('with its index', str_contains(implode("\n", $bp->to_index_sql()), 'collection_c'));
$bp2 = new Blueprint('ix_things', 'mysql');
$bp2->partition();
test('named scope_c by default', str_contains($bp2->to_create_sql(), '`scope_c`'));

// -----------------------------------------------------------------------------
section('a table that is not partitioned is untouched');

$plain = sqlite_table('plain', ['id' => serial(), 'code' => varchar(10)]);
$dm->create_tables($plain);
$dm->with_partition('shop-a', fn (DataManager $dm) => $dm->insert($plain)->values(['code' => 'p'])->execute());
test('with_partition() adds nothing to it', count($dm->select()->from($plain)->execute()) === 1);
test('and within() on it is a no-op', count($dm->select()->from($plain)->within('elsewhere')->execute()) === 1);

exit(summary());
