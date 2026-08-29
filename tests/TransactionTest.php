<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — nested transactions
 *
 * `transaction()` used to send a second `BEGIN` down a connection that was
 * already in a transaction. What follows is not an error anybody sees: PDO
 * throws on some drivers, ignores it on others, and where it is ignored the
 * *inner* commit ends the *outer* transaction — so work the caller believed was
 * still provisional becomes durable, and the outer rollback that follows has
 * nothing left to undo.
 *
 * That failure is invisible in the ordinary case, because the ordinary case is
 * one transaction. It appears the first time a method that opens a transaction
 * is called from another that already has one — which is exactly the situation
 * nesting exists for, and exactly the situation nobody sets out to create.
 *
 * So the assertions here are about **what survives**. Every one ends by reading
 * the table back.
 *
 * Run: php src/Libs/Italix/Orm/tests/TransactionTest.php
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

use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - nested transactions');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

/** A fresh manager over an empty table. */
$fresh = static function (): DataManager {
    $dm = sqlite_memory();
    $dm->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

    return $dm;
};

/** What is actually in the table, in insertion order. */
$rows = static function (DataManager $dm): string {
    return implode(',', array_column($dm->query('SELECT v FROM t ORDER BY id'), 'v'));
};

$write = static function (DataManager $dm, string $value): void {
    $dm->execute('INSERT INTO t (v) VALUES (?)', [$value]);
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

// -----------------------------------------------------------------------------
section('one transaction still behaves');

$dm = $fresh();
$dm->transaction(static function (DataManager $dm) use ($write): void {
    $write($dm, 'kept');
});

test('a committed transaction keeps its work', $rows($dm) === 'kept');

$dm = $fresh();
$throws(static function () use ($dm, $write): void {
    $dm->transaction(static function (DataManager $dm) use ($write): void {
        $write($dm, 'lost');

        throw new RuntimeException('no');
    });
});

test('a failed one keeps none of it', $rows($dm) === '');
test('…and the exception reaches the caller', (static function () use ($fresh, $throws): bool {
    [$threw, $message] = $throws(static function () use ($fresh): void {
        $fresh()->transaction(static function (): void {
            throw new RuntimeException('the real cause');
        });
    });

    return $threw && $message === 'the real cause';
})());

test('the return value comes back', $fresh()->transaction(static function (): int {
    return 42;
}) === 42);

test('the depth is zero again afterwards', (static function () use ($fresh): bool {
    $dm = $fresh();
    $dm->transaction(static function (): void {
    });

    return $dm->transaction_depth() === 0;
})());

// -----------------------------------------------------------------------------
section('AN INNER FAILURE LEAVES THE OUTER TRANSACTION USABLE');

// The property savepoints are for. Rolling back the inner block must undo the
// inner block and nothing else — and the outer transaction must still accept
// statements afterwards. On PostgreSQL this is not a nicety: a failed statement
// aborts the whole transaction, and rolling back to a savepoint is the only way
// out of it short of abandoning everything.
$dm = $fresh();

$dm->transaction(static function (DataManager $dm) use ($write, $throws): void {
    $write($dm, 'outer');

    $throws(static function () use ($dm, $write): void {
        $dm->transaction(static function (DataManager $dm) use ($write): void {
            $write($dm, 'inner');

            throw new RuntimeException('inner fails');
        });
    });

    // The outer transaction is still open and still accepts work.
    $write($dm, 'after');
});

test('the inner work is gone', strpos($rows($dm), 'inner') === false, $rows($dm));
test('the outer work before it survives', strpos($rows($dm), 'outer') !== false, $rows($dm));
test('AND THE OUTER TRANSACTION STILL ACCEPTED WORK AFTERWARDS',
    strpos($rows($dm), 'after') !== false,
    'the inner rollback took the whole transaction with it: ' . $rows($dm));

test('…so exactly the two outer writes are there', $rows($dm) === 'outer,after', $rows($dm));

// -----------------------------------------------------------------------------
section('an outer failure discards the inner work, committed or not');

// The other direction, and the one a naive implementation gets wrong: an inner
// `commit()` must not make anything durable, or a helper could quietly write
// through its caller's rollback.
$dm = $fresh();

$throws(static function () use ($dm, $write): void {
    $dm->transaction(static function (DataManager $dm) use ($write): void {
        $dm->transaction(static function (DataManager $dm) use ($write): void {
            $write($dm, 'inner-committed');
        });

        throw new RuntimeException('outer fails');
    });
});

test('AN INNER COMMIT IS NOT A COMMIT',
    $rows($dm) === '',
    'the inner block wrote through its caller\'s rollback: ' . $rows($dm));

test('the depth is zero after an outer failure', $dm->transaction_depth() === 0);

// -----------------------------------------------------------------------------
section('three levels');

$dm = $fresh();

$dm->transaction(static function (DataManager $dm) use ($write, $throws): void {
    $write($dm, 'L1');

    $throws(static function () use ($dm, $write): void {
        $dm->transaction(static function (DataManager $dm) use ($write): void {
            $write($dm, 'L2');

            $dm->transaction(static function (DataManager $dm) use ($write): void {
                $write($dm, 'L3');
            });

            throw new RuntimeException('L2 fails');
        });
    });

    $write($dm, 'L1-after');
});

test('a failure at level 2 takes level 3 with it', $rows($dm) === 'L1,L1-after', $rows($dm));

$dm = $fresh();
$dm->transaction(static function (DataManager $dm) use ($write): void {
    $write($dm, 'a');
    $dm->transaction(static function (DataManager $dm) use ($write): void {
        $write($dm, 'b');
        $dm->transaction(static function (DataManager $dm) use ($write): void {
            $write($dm, 'c');
        });
    });
});

test('three levels that all succeed keep everything', $rows($dm) === 'a,b,c', $rows($dm));

// -----------------------------------------------------------------------------
section('the depth is tracked, not guessed');

$dm = $fresh();

test('it starts at zero', $dm->transaction_depth() === 0);

$dm->begin_transaction();
test('one begin makes it one', $dm->transaction_depth() === 1);

$dm->begin_transaction();
$dm->begin_transaction();
test('nested begins raise it', $dm->transaction_depth() === 3);

$dm->commit();
test('a commit lowers it', $dm->transaction_depth() === 2);

$dm->rollback();
test('a rollback lowers it too', $dm->transaction_depth() === 1);

$dm->commit();
test('the last commit returns it to zero', $dm->transaction_depth() === 0);

// -----------------------------------------------------------------------------
section('closing what is not open');

[$threw, $message] = $throws(static function () use ($fresh): void {
    $fresh()->commit();
});

test('COMMITTING WITH NOTHING OPEN IS REFUSED', $threw,
    'it returned quietly, so a double commit looks like success');
test('…and the message suggests what happened',
    strpos($message, 'no transaction open') !== false, $message);

[$threw] = $throws(static function () use ($fresh): void {
    $fresh()->rollback();
});

test('rolling back with nothing open is refused too', $threw);

// A double commit inside a block: the second one has nothing left.
$dm = $fresh();
$dm->begin_transaction();
$dm->commit();

[$threw] = $throws(static function () use ($dm): void {
    $dm->commit();
});

test('a second commit is refused rather than silently ignored', $threw);

// -----------------------------------------------------------------------------
section('a helper does not have to know whether it was called inside one');

// The composability that is the whole point: a method needing a transaction can
// ask for one without first asking whether it already has one, because the
// answer depends on its callers and it does not know them.
$dm = $fresh();

$audited_write = static function (DataManager $dm, string $value) use ($write): void {
    $dm->transaction(static function (DataManager $dm) use ($write, $value): void {
        $write($dm, $value);
        $write($dm, $value . '-audit');
    });
};

$audited_write($dm, 'alone');

test('the helper works on its own', $rows($dm) === 'alone,alone-audit', $rows($dm));

$dm = $fresh();

$dm->transaction(static function (DataManager $dm) use ($audited_write, $write): void {
    $write($dm, 'caller');
    $audited_write($dm, 'nested');
});

test('…and unchanged inside somebody else\'s transaction',
    $rows($dm) === 'caller,nested,nested-audit', $rows($dm));

$dm = $fresh();

$throws(static function () use ($dm, $audited_write, $write): void {
    $dm->transaction(static function (DataManager $dm) use ($audited_write, $write): void {
        $write($dm, 'caller');
        $audited_write($dm, 'nested');

        throw new RuntimeException('the caller changes its mind');
    });
});

test('…and the caller can still discard all of it', $rows($dm) === '', $rows($dm));

// -----------------------------------------------------------------------------
section('a transaction this manager did not open');

// The case that made this section necessary: the connection is already inside a
// transaction somebody else began — a test harness wrapping a suite, a job
// bracketing its work, any code sharing the PDO. The depth counter knew nothing
// about it and BEGIN went out anyway, so PDO answered "There is already an
// active transaction" and the caller read it as a bug in its own code.

$dm  = $fresh();
$pdo = $dm->get_connection();

$pdo->beginTransaction();          // not through the DataManager, on purpose
$write($dm, 'theirs');

$dm->transaction(static function (DataManager $dm) use ($write): void {
    $write($dm, 'ours');
});

test('a nested transaction opens instead of throwing',
    $rows($dm) === 'theirs,ours', $rows($dm));
test('…and knows the outermost one is not its own', $dm->in_foreign_transaction() === false,
    'the flag is cleared once the nested block has finished');

// Their transaction must still be theirs to end: ours committing it would make
// work durable that the owner had not decided about yet.
test('the enclosing transaction is still open', $pdo->inTransaction());

$pdo->rollBack();

test('so the owner can still discard everything, ours included', $rows($dm) === '', $rows($dm));

// The same for a nested block that fails: rolling back to the savepoint must
// leave the enclosing transaction usable, not closed.
$dm  = $fresh();
$pdo = $dm->get_connection();

$pdo->beginTransaction();
$write($dm, 'theirs');

$throws(static function () use ($dm, $write): void {
    $dm->transaction(static function (DataManager $dm) use ($write): void {
        $write($dm, 'ours');

        throw new RuntimeException('the nested block fails');
    });
});

test('a failed nested block discards only its own work', $rows($dm) === 'theirs', $rows($dm));
test('…and leaves the enclosing transaction open', $pdo->inTransaction());

$pdo->commit();

test('which the owner can then commit', $rows($dm) === 'theirs', $rows($dm));

exit(summary());
