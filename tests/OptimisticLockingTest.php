<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — Table::optimistic_locking()
 *
 * JPA/Hibernate's @Version, Rails' lock_version, EF Core's concurrency
 * tokens — every major ORM this package was compared against has some form
 * of "detect that someone else changed this row since I read it, instead of
 * silently overwriting their write." This package had none: two concurrent
 * `UPDATE`s to the same row just applied in whichever order the database
 * happened to serialize them, the second one clobbering the first with no
 * signal that it had happened.
 *
 * `Table::optimistic_locking('version')` declares the column;
 * `QueryBuilder::build_update()` compiles `SET version = version + 1` into
 * every UPDATE unconditionally (a caller-supplied value under that key is
 * discarded — proven below), and `->expect_version($n)` additionally ANDs
 * `version = ?` onto the WHERE, so a lost race becomes zero rows affected —
 * proven by executing two real UPDATEs against the same row and checking
 * which one actually changed the value, not by reading the generated SQL.
 *
 * Run: php src/Libs/Italix/Orm/tests/OptimisticLockingTest.php
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
use Italix\Orm\Locking\OptimisticLockException;

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - Table::optimistic_locking()');

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
section('insert() defaults the version column to 1');

$accounts = sqlite_table('accounts', [
    'id'      => integer()->primary_key()->auto_increment(),
    'balance' => integer()->not_null(),
    'version' => integer()->not_null(),
]);
$accounts->optimistic_locking('version');

$dm = sqlite_memory();
$dm->create_tables($accounts);

test('has_optimistic_locking() reports true once declared', $accounts->has_optimistic_locking());
test('version_column() names it', $accounts->version_column() === 'version');

$dm->insert($accounts)->values(['balance' => 100])->execute();
$row = $dm->query('SELECT * FROM accounts')[0];
test('a fresh row starts at version 1', (int) $row['version'] === 1);

$dm->execute('DELETE FROM accounts');
$dm->insert($accounts)->values(['balance' => 100, 'version' => 7])->execute();
$explicit_row = $dm->query('SELECT * FROM accounts')[0];
test('an explicit starting version is trusted over the default', (int) $explicit_row['version'] === 7);

// -----------------------------------------------------------------------------
section('update() bumps the version unconditionally, even without expect_version()');

$dm->execute('DELETE FROM accounts');
$dm->insert($accounts)->values(['balance' => 100])->execute();
$id = (int) $dm->query('SELECT id FROM accounts')[0]['id'];

$dm->update($accounts)->set(['balance' => 150])->where(eq($accounts->id, $id))->execute();
$after = $dm->query('SELECT * FROM accounts')[0];
test('version moved from 1 to 2 on a plain UPDATE', (int) $after['version'] === 2);
test('…and the actual change applied too', (int) $after['balance'] === 150);

// -----------------------------------------------------------------------------
section('a caller-supplied version value in ->set() is discarded, not trusted');

$dm->update($accounts)->set(['balance' => 200, 'version' => 999])->where(eq($accounts->id, $id))->execute();
$after_bad_set = $dm->query('SELECT * FROM accounts')[0];
test(
    'the real increment (3) won, not the value the caller tried to set (999)',
    (int) $after_bad_set['version'] === 3
);

// -----------------------------------------------------------------------------
section('expect_version() succeeds when the version still matches');

$dm->execute('DELETE FROM accounts');
$dm->insert($accounts)->values(['balance' => 500])->execute();
$id2 = (int) $dm->query('SELECT id FROM accounts')[0]['id'];

$affected = $dm->update($accounts)->set(['balance' => 400])->where(eq($accounts->id, $id2))
    ->expect_version(1)->execute();
test('the UPDATE reports one row affected', $affected === 1);

$row2 = $dm->query('SELECT * FROM accounts')[0];
test('the change applied', (int) $row2['balance'] === 400);
test('…and version moved to 2', (int) $row2['version'] === 2);

// -----------------------------------------------------------------------------
section('expect_version() throws when the version has already moved — the reason this exists');

[$threw, $message] = $throws(fn () => $dm->update($accounts)->set(['balance' => 999])
    ->where(eq($accounts->id, $id2))->expect_version(1)->execute());
test('a stale expected version (1, but the row is now at 2) raises OptimisticLockException', $threw);
test('…the actual exception class, not just any Throwable', strpos($message, 'OptimisticLockException') !== false);

$row_after_conflict = $dm->query('SELECT * FROM accounts')[0];
test('the conflicting write never applied — balance is still 400', (int) $row_after_conflict['balance'] === 400);
test('…and version was not bumped a second time by the failed attempt', (int) $row_after_conflict['version'] === 2);

// expect_version() also guards the RETURNING path (QueryBuilder::execute()
// calls the identical assert_version_matched() there) — not exercised here
// because this environment's SQLite (3.31) predates RETURNING support
// (3.35+); the mutation sweep for that call site is covered by proxy
// through the plain rowCount() path above, which is the same method.

// -----------------------------------------------------------------------------
section('expect_version() throws the same way when the row was deleted out from under it');

$dm->execute('DELETE FROM accounts');
[$threw_deleted] = $throws(fn () => $dm->update($accounts)->set(['balance' => 1])
    ->where(eq($accounts->id, $id2))->expect_version(2)->execute());
test('updating a version that matched a now-deleted row still raises', $threw_deleted);

// -----------------------------------------------------------------------------
section('two "concurrent" writers — the scenario this feature exists for, reproduced literally');

$dm->execute('DELETE FROM accounts');
$dm->insert($accounts)->values(['balance' => 1000])->execute();
$shared_id = (int) $dm->query('SELECT id FROM accounts')[0]['id'];

// Both "read" the row at version 1.
$reader_a_version = 1;
$reader_b_version = 1;

// Writer A commits first.
$dm->update($accounts)->set(['balance' => 900])->where(eq($accounts->id, $shared_id))
    ->expect_version($reader_a_version)->execute();

// Writer B, unaware, tries to commit against the version it originally read.
[$writer_b_threw] = $throws(fn () => $dm->update($accounts)->set(['balance' => 1100])
    ->where(eq($accounts->id, $shared_id))->expect_version($reader_b_version)->execute());

test('writer A\'s write won', $dm->query('SELECT balance FROM accounts')[0]['balance'] == 900);
test('writer B was told its write lost the race, instead of silently clobbering A\'s', $writer_b_threw);

// -----------------------------------------------------------------------------
section('expect_version() refuses to run against a table with no optimistic_locking() column');

$plain = sqlite_table('plain_accounts', [
    'id'      => integer()->primary_key()->auto_increment(),
    'balance' => integer()->not_null(),
]);
$dm->create_tables($plain);
$dm->insert($plain)->values(['balance' => 1])->execute();

[$threw_no_locking, $no_locking_message] = $throws(
    fn () => $dm->update($plain)->set(['balance' => 2])->where(eq($plain->id, 1))->expect_version(1)
);
test('calling expect_version() on an unlocked table raises immediately — a typo should fail loudly', $threw_no_locking);
test('…not silently perform an unguarded update', strpos($no_locking_message, 'LogicException') === 0);

// -----------------------------------------------------------------------------
section('expect_version() refuses on a query type other than UPDATE');

[$threw_not_update] = $throws(fn () => $dm->select()->from($accounts)->expect_version(1));
test('calling it on a select() raises — it would silently do nothing otherwise', $threw_not_update);

// -----------------------------------------------------------------------------
section('a table with optimistic_locking() not declared is completely unaffected');

$dm->execute('DELETE FROM plain_accounts');
$dm->insert($plain)->values(['balance' => 10])->execute();
$dm->update($plain)->set(['balance' => 20])->where(eq($plain->id, 1))->execute();
$plain_row = $dm->query('SELECT * FROM plain_accounts')[0];
test('no version column was invented', array_keys($plain_row) === ['id', 'balance']);

// -----------------------------------------------------------------------------
section('DELETE is not version-checked — out of scope by design');

$dm->execute('DELETE FROM accounts');
$dm->insert($accounts)->values(['balance' => 1])->execute();
$del_id = (int) $dm->query('SELECT id FROM accounts')[0]['id'];
$deleted_n = $dm->delete($accounts)->where(eq($accounts->id, $del_id))->execute();
test('delete() still works normally, with no version guard at all', $deleted_n === 1);

// -----------------------------------------------------------------------------
section('the version bump survives a before_update hook that fully replaces the SET clause — a real bug found and fixed while reviewing this feature against DataManager::on() (2.29.0)');

$dm->execute('DELETE FROM accounts');
$dm->insert($accounts)->values(['balance' => 100])->execute();
$hook_id = (int) $dm->query('SELECT id FROM accounts')[0]['id'];

// A field-whitelist hook — a legitimate, common pattern — that returns a
// brand new array rather than merging into the one it was given. Before
// the fix, this silently dropped the version increment: the hook ran
// *before* optimistic_locking() injected `version = version + 1`, so a
// full replacement threw that injection away with it.
$dm->on($accounts, 'before_update', function (array $values): array {
    return ['balance' => $values['balance']];
});

$dm->update($accounts)->set(['balance' => 50])->where(eq($accounts->id, $hook_id))->execute();
$hooked_row = $dm->query('SELECT * FROM accounts')[0];
test('the whitelist hook\'s intended change still applied', (int) $hooked_row['balance'] === 50);
test('…and the version still moved from 1 to 2, despite the hook replacing the whole SET clause', (int) $hooked_row['version'] === 2);

// -----------------------------------------------------------------------------
section('ActiveRow\'s Persistable::save() applies expect_version() automatically');

class AccountRow extends ActiveRow
{
    use Persistable;
}

$dm2 = sqlite_memory();
$dm2->create_tables($accounts);
AccountRow::set_persistence($dm2, $accounts);

$account = AccountRow::create(['balance' => 100]);
test('create() starts at version 1, reflected in memory too', $account['version'] === 1);

$account['balance'] = 150;
$account->save();
test('save() bumped the in-memory version to match the real increment', $account['version'] === 2);

$fresh_row = $dm2->query('SELECT * FROM accounts')[0];
test('…and the database agrees', (int) $fresh_row['version'] === 2);

// Simulate another process updating the row between this instance's read and its write.
$dm2->update($accounts)->set(['balance' => 999])->where(eq($accounts->id, $account['id']))->execute();

[$stale_save_threw] = $throws(function () use ($account): void {
    $account['balance'] = 42;
    $account->save();
});
test(
    'saving a now-stale ActiveRow instance (its in-memory version no longer matches the DB) raises OptimisticLockException',
    $stale_save_threw
);

$row_after_stale_save = $dm2->query('SELECT * FROM accounts')[0];
test('the stale save never applied — the other process\'s write stands', (int) $row_after_stale_save['balance'] === 999);

exit(summary());
