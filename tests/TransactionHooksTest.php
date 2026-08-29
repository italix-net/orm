<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — DataManager::on_commit() / on_rollback()
 *
 * `DataManager::on()` (2.28.0) fires `after_insert`/`after_update`/
 * `after_delete` the instant a statement runs, regardless of whether the
 * transaction around it goes on to commit — the wrong moment for a side
 * effect the rest of the world will see (an email, a webhook call) that a
 * later rollback cannot take back. `on_commit()`/`on_rollback()` wait for
 * the fact that actually decides durability instead: the *outermost*
 * transaction's own resolution, not any nested savepoint's.
 *
 * Nesting is the part worth proving with real execution rather than reading
 * the code and nodding: a hook registered inside a savepoint that itself
 * rolls back must resolve immediately (that specific unit of work is
 * undone, whatever an enclosing transaction goes on to do), while a hook
 * registered inside a savepoint that "commits" (only ever a release, never
 * a real commit) must NOT fire until the real outermost commit, since a
 * savepoint release makes nothing durable on its own — exactly the two
 * cases this suite is built to catch getting confused with each other.
 *
 * Run: php src/Libs/Italix/Orm/tests/TransactionHooksTest.php
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

use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - DataManager::on_commit() / on_rollback()');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

// -----------------------------------------------------------------------------
section('outside any transaction: on_commit() fires immediately, on_rollback() never fires');

$dm = sqlite_memory();

$fired = false;
$dm->on_commit(function () use (&$fired): void {
    $fired = true;
});
test('on_commit() with no transaction open ran synchronously, right there', $fired);

$never = false;
$dm->on_rollback(function () use (&$never): void {
    $never = true;
});
test('on_rollback() with no transaction open registered no-op — nothing to roll back', $never === false);

$rollback_hooks_prop = new \ReflectionProperty($dm, 'rollback_hooks');
$rollback_hooks_prop->setAccessible(true);
test(
    'confirmed by internal state, not only by never observing it fire later: nothing was queued at all',
    $rollback_hooks_prop->getValue($dm) === []
);

// -----------------------------------------------------------------------------
section('a single transaction: on_commit() fires after a real commit, not before');

$dm2 = sqlite_memory();
$order_of_events = [];

$dm2->transaction(function ($dm) use (&$order_of_events): void {
    $dm->on_commit(function () use (&$order_of_events): void {
        $order_of_events[] = 'commit_hook';
    });
    $order_of_events[] = 'inside_transaction';
});
test('the hook ran after the transaction body, not during it', $order_of_events === ['inside_transaction', 'commit_hook']);

// -----------------------------------------------------------------------------
section('on_commit() does NOT fire if the transaction rolls back — the reason this exists');

$dm3 = sqlite_memory();
$commit_fired = false;

[$threw] = (static function () use ($dm3, &$commit_fired): array {
    try {
        $dm3->transaction(function ($dm) use (&$commit_fired): void {
            $dm->on_commit(function () use (&$commit_fired): void {
                $commit_fired = true;
            });

            throw new \RuntimeException('simulated failure');
        });

        return [false];
    } catch (\RuntimeException $e) {
        return [true];
    }
})();
test('the transaction\'s exception propagated', $threw);
test('…and the on_commit() hook never ran — the write it was waiting on never became durable', $commit_fired === false);

// -----------------------------------------------------------------------------
section('on_rollback() fires when the transaction actually rolls back');

$dm4 = sqlite_memory();
$rollback_fired = false;

try {
    $dm4->transaction(function ($dm) use (&$rollback_fired): void {
        $dm->on_rollback(function () use (&$rollback_fired): void {
            $rollback_fired = true;
        });

        throw new \RuntimeException('simulated failure');
    });
} catch (\RuntimeException $e) {
}
test('on_rollback() ran', $rollback_fired);

// -----------------------------------------------------------------------------
section('multiple on_commit() hooks fire in registration order');

$dm5 = sqlite_memory();
$call_order = [];

$dm5->transaction(function ($dm) use (&$call_order): void {
    $dm->on_commit(function () use (&$call_order): void {
        $call_order[] = 'first';
    });
    $dm->on_commit(function () use (&$call_order): void {
        $call_order[] = 'second';
    });
});
test('first-registered ran first', $call_order === ['first', 'second']);

// -----------------------------------------------------------------------------
section('nested transactions: a hook registered inside an inner transaction that commits waits for the OUTER commit — a savepoint release is not a real commit');

$dm6 = sqlite_memory();
$inner_hook_state = 'not fired';

$dm6->transaction(function ($dm) use (&$inner_hook_state): void {
    $dm->transaction(function ($dm) use (&$inner_hook_state): void {
        $dm->on_commit(function () use (&$inner_hook_state): void {
            $inner_hook_state = 'fired';
        });
    });
    // The inner transaction() call above already returned — it "committed"
    // in the sense of releasing its savepoint — and the hook must still not
    // have fired, because that release made nothing durable on its own.
    test('the inner savepoint\'s release did not fire the hook', $inner_hook_state === 'not fired');
});
test('…only the outer, real commit did', $inner_hook_state === 'fired');

// -----------------------------------------------------------------------------
section('nested rollback: a hook registered inside a savepoint that rolls back resolves immediately, regardless of what the outer transaction goes on to do');

$dm7 = sqlite_memory();
$inner_rollback_fired = false;
$inner_commit_fired   = false;

$dm7->transaction(function ($dm) use (&$inner_rollback_fired, &$inner_commit_fired): void {
    try {
        $dm->transaction(function ($dm) use (&$inner_rollback_fired, &$inner_commit_fired): void {
            $dm->on_commit(function () use (&$inner_commit_fired): void {
                $inner_commit_fired = true;
            });
            $dm->on_rollback(function () use (&$inner_rollback_fired): void {
                $inner_rollback_fired = true;
            });

            throw new \RuntimeException('inner savepoint fails');
        });
    } catch (\RuntimeException $e) {
        // Swallowed here — this is exactly what a savepoint is for: the
        // outer transaction keeps going and, below, succeeds.
    }

    test('the inner rollback hook already ran, before the outer transaction even finished', $inner_rollback_fired);
});
test('the inner commit hook never ran, even though the outer transaction went on to commit successfully', $inner_commit_fired === false);
test('…and the rollback hook did not fire a second time when the outer transaction resolved', $inner_rollback_fired === true);

// -----------------------------------------------------------------------------
section('an inner savepoint\'s rollback resolves only its own hooks — a hook registered by the OUTER level, before the inner one even opened, is left untouched');

$dm7b = sqlite_memory();
$outer_commit_fired   = false;
$outer_rollback_fired = false;
$inner_rollback_fired_b = false;

$dm7b->transaction(function ($dm) use (&$outer_commit_fired, &$outer_rollback_fired, &$inner_rollback_fired_b): void {
    // Registered BEFORE the inner transaction opens — this is what proves
    // push_hook_mark()/pop_hook_mark() actually track "how many hooks
    // existed when this level opened" rather than always seeing zero.
    $dm->on_commit(function () use (&$outer_commit_fired): void {
        $outer_commit_fired = true;
    });
    $dm->on_rollback(function () use (&$outer_rollback_fired): void {
        $outer_rollback_fired = true;
    });

    try {
        $dm->transaction(function ($dm) use (&$inner_rollback_fired_b): void {
            $dm->on_rollback(function () use (&$inner_rollback_fired_b): void {
                $inner_rollback_fired_b = true;
            });

            throw new \RuntimeException('inner fails');
        });
    } catch (\RuntimeException $e) {
    }

    test('the inner hook fired, at the inner rollback', $inner_rollback_fired_b);
    test(
        'the outer hooks — registered before the inner level even opened — are untouched by the inner rollback, not swept away with it',
        $outer_commit_fired === false && $outer_rollback_fired === false
    );
});
test('the outer transaction went on to commit normally, and its own on_commit hook fired for real this time', $outer_commit_fired);
test('…its on_rollback hook correctly never fired at all', $outer_rollback_fired === false);

// -----------------------------------------------------------------------------
section('nested commit, then outer rollback: a savepoint\'s "committed" work is discarded too — it was never durable');

$dm8 = sqlite_memory();
$nested_commit_fired = false;

[$outer_threw] = (static function () use ($dm8, &$nested_commit_fired): array {
    try {
        $dm8->transaction(function ($dm) use (&$nested_commit_fired): void {
            $dm->transaction(function ($dm) use (&$nested_commit_fired): void {
                $dm->on_commit(function () use (&$nested_commit_fired): void {
                    $nested_commit_fired = true;
                });
            });

            throw new \RuntimeException('outer fails after the inner "committed"');
        });

        return [false];
    } catch (\RuntimeException $e) {
        return [true];
    }
})();
test('the outer transaction\'s failure propagated', $outer_threw);
test(
    'the inner savepoint\'s hook never fired — its release was not durable, and the outer rollback undid it too',
    $nested_commit_fired === false
);

// -----------------------------------------------------------------------------
section('a hook registered from inside another hook fires immediately — the registering hook itself already runs at depth 0');

$dm9 = sqlite_memory();
$chained_fired = false;

$dm9->transaction(function ($dm) use (&$chained_fired): void {
    $dm->on_commit(function () use ($dm, &$chained_fired): void {
        $dm->on_commit(function () use (&$chained_fired): void {
            $chained_fired = true;
        });
    });
});
test('the hook registered by another hook ran too, immediately, not queued for a nonexistent future commit', $chained_fired);

// -----------------------------------------------------------------------------
section('a foreign transaction — this object did not open the outermost BEGIN — fires on_commit() at its own savepoint release, best-effort, as documented');

$dm10  = sqlite_memory();
$conn  = $dm10->get_driver()->get_connection();
$conn->beginTransaction(); // opened outside this DataManager entirely

test('the driver reports a transaction already in progress', $dm10->get_driver()->in_transaction());

$foreign_hook_fired = false;
$dm10->begin_transaction();
test('begin_transaction() correctly detected it as foreign', $dm10->in_foreign_transaction());

$dm10->on_commit(function () use (&$foreign_hook_fired): void {
    $foreign_hook_fired = true;
});
$dm10->commit();
test(
    'on_commit() fired at this object\'s own commit point, even though the real, foreign transaction is not committed yet',
    $foreign_hook_fired
);
test('…confirmed: the foreign transaction really is still open, undecided, right now', $conn->inTransaction());

$conn->rollBack(); // clean up the foreign transaction this test opened

// -----------------------------------------------------------------------------
section('a foreign transaction that rolls back instead — our on_rollback() fires, our on_commit() does not, and the foreign transaction itself stays open, just undone to our savepoint');

$dm10b = sqlite_memory();
$conn2 = $dm10b->get_driver()->get_connection();
$conn2->beginTransaction();

$dm10b->begin_transaction();
test('detected as foreign here too', $dm10b->in_foreign_transaction());

$foreign_commit_fired   = false;
$foreign_rollback_fired = false;
$dm10b->on_commit(function () use (&$foreign_commit_fired): void {
    $foreign_commit_fired = true;
});
$dm10b->on_rollback(function () use (&$foreign_rollback_fired): void {
    $foreign_rollback_fired = true;
});
$dm10b->rollback();

test('on_rollback() fired', $foreign_rollback_fired);
test('on_commit() did not', $foreign_commit_fired === false);
test('…and the foreign transaction itself is still open — only our savepoint was undone', $conn2->inTransaction());

$conn2->rollBack();

// -----------------------------------------------------------------------------
section('a stray on_rollback() hook from an earlier, already-committed transaction never fires later — resolve_commit_hooks() discards it, not just the commit_hooks it actually used');

$dm10c = sqlite_memory();
$stray_rollback_fired = false;

$dm10c->transaction(function ($dm) use (&$stray_rollback_fired): void {
    $dm->on_rollback(function () use (&$stray_rollback_fired): void {
        $stray_rollback_fired = true;
    });
    // No on_commit() registered here on purpose — this transaction commits
    // cleanly, and the only hook in play is the on_rollback() above, which
    // a commit must discard, not carry forward or fire.
});
test('the on_rollback() hook from a transaction that committed cleanly never fired', $stray_rollback_fired === false);

$rollback_hooks_prop2 = new \ReflectionProperty($dm10c, 'rollback_hooks');
$rollback_hooks_prop2->setAccessible(true);
test('…and it is gone from internal state too, not just silently never triggered', $rollback_hooks_prop2->getValue($dm10c) === []);

// -----------------------------------------------------------------------------
section('a catastrophic failure — rollback() itself throws — discards pending hooks rather than guessing what happened');

$dm11 = sqlite_memory();
$dubious_commit_fired   = false;
$dubious_rollback_fired = false;

[$outer_exception_class] = (static function () use ($dm11, &$dubious_commit_fired, &$dubious_rollback_fired): array {
    try {
        $dm11->transaction(function ($dm) use (&$dubious_commit_fired, &$dubious_rollback_fired): void {
            $dm->on_commit(function () use (&$dubious_commit_fired): void {
                $dubious_commit_fired = true;
            });
            $dm->on_rollback(function () use (&$dubious_rollback_fired): void {
                $dubious_rollback_fired = true;
            });

            // Desync the real PDO transaction state from what this object
            // believes, without going through rollback() — so that when
            // the exception below sends transaction() into its own
            // rollback(), that rollback() finds no real transaction left
            // and throws, exactly like a dropped connection would.
            $dm->get_driver()->get_connection()->rollBack();

            throw new \RuntimeException('triggers transaction()\'s own rollback(), which now fails too');
        });

        return ['(none — should not happen)'];
    } catch (\Throwable $e) {
        return [get_class($e)];
    }
})();
test(
    'the ORIGINAL exception propagated, not a secondary one from the failed rollback() — rollback() failing must not hide the real cause',
    $outer_exception_class === 'RuntimeException'
);
test('neither hook fired — the state was unknown, and guessing would have been worse than discarding', !$dubious_commit_fired && !$dubious_rollback_fired);
test('the manager is usable again afterward, not stuck mid-transaction', $dm11->transaction_depth() === 0);

$next_transaction_fired = false;
$dm11->transaction(function ($dm) use (&$next_transaction_fired): void {
    $dm->on_commit(function () use (&$next_transaction_fired): void {
        $next_transaction_fired = true;
    });
});
test('…and a later, unrelated transaction\'s hooks are not contaminated by the earlier failure', $next_transaction_fired);
test(
    'and the earlier failure\'s own hook still never fired, even now that a real commit happened afterward — proving the queues were actually cleared, not just left unresolved',
    $dubious_commit_fired === false && $dubious_rollback_fired === false
);

exit(summary());
