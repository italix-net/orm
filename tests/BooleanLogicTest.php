<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — and_() / or_() / not_(), including nested
 *
 * `where()` takes exactly one `SQLExpression`. Calling it more than once
 * does not accumulate a chain of AND conditions the way it does in some
 * other query builders — the second call replaces the first outright (see
 * `CheckAndEnumTest.php`'s sibling discussion in the CHANGELOG for 2.25.0).
 * Combining more than one condition means building **one** expression with
 * `and_()`/`or_()`/`not_()` and passing that single expression to `where()`.
 *
 * Asserted by executing against real rows and reading which ones come back,
 * not by comparing the rendered SQL string — a boolean expression with a
 * dropped parenthesis or a swapped `AND`/`OR` still produces syntactically
 * valid SQL, and only running it says whether it asks the right question.
 * Nesting three levels deep is the part actually worth proving: each
 * operand is individually parenthesized (`to_sql()` wraps every condition
 * in `(...)`), so operator precedence cannot bleed between an outer `AND`
 * and an inner `OR` the way it silently would if they were not.
 *
 * Run: php src/Libs/Italix/Orm/tests/BooleanLogicTest.php
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

use function Italix\Orm\Schema\{integer, varchar, sqlite_table};
use function Italix\Orm\Operators\{eq, gt, and_, or_, not_};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - and_() / or_() / not_(), including nested');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$members = sqlite_table('members', [
    'id'     => integer()->primary_key()->auto_increment(),
    'role'   => varchar(20)->not_null(),
    'score'  => integer()->not_null(),
    'banned' => integer()->not_null(),
]);

$dm = sqlite_memory();
$dm->create_tables($members);

// 1: admin, low score,         not banned
// 2: user,  high score (>100), not banned
// 3: user,  low score,         not banned
// 4: admin, low score,         banned
// 5: user,  high score (>100), banned  — see the "nested" section below for
//    why this row exists: without it, dropping the parentheses around a
//    nested and_()/or_() renders SQL where AND silently binds tighter than
//    OR, and every other row in this fixture happens to answer the same
//    way regardless — a mutation that changes nothing an assertion here
//    can see is a test that proves nothing, and this row is what closes it.
$dm->insert($members)->values(['role' => 'admin', 'score' => 10,  'banned' => 0])->execute();
$dm->insert($members)->values(['role' => 'user',  'score' => 200, 'banned' => 0])->execute();
$dm->insert($members)->values(['role' => 'user',  'score' => 5,   'banned' => 0])->execute();
$dm->insert($members)->values(['role' => 'admin', 'score' => 1,   'banned' => 1])->execute();
$dm->insert($members)->values(['role' => 'user',  'score' => 500, 'banned' => 1])->execute();

$ids = fn (array $rows): array => array_column($rows, 'id');

// -----------------------------------------------------------------------------
section('or_() — either condition, not just the last one where() would have kept');

$admins_or_high_score = $dm->query_table($members)
    ->where(or_(eq($members->role, 'admin'), gt($members->score, 100)))
    ->find_many();

test('rows 1, 2, 4 and 5 match (admin OR score > 100)', $ids($admins_or_high_score) === [1, 2, 4, 5]);

// -----------------------------------------------------------------------------
section('not_() — negates a single condition');

$not_banned = $dm->query_table($members)->where(not_(eq($members->banned, 1)))->find_many();

test('rows 1, 2 and 3 match (NOT banned)', $ids($not_banned) === [1, 2, 3]);

// -----------------------------------------------------------------------------
section('and_() combines what two where() calls could not — the second where() would have replaced the first');

$active_admin = $dm->query_table($members)
    ->where(and_(eq($members->role, 'admin'), not_(eq($members->banned, 1))))
    ->find_many();

test('only row 1 matches (admin AND NOT banned)', $ids($active_admin) === [1]);

$two_where_calls = $dm->query_table($members)
    ->where(eq($members->role, 'admin'))
    ->where(not_(eq($members->banned, 1)))
    ->find_many();

test(
    '…confirmed: two separate where() calls give a different, wrong answer (only the second applied)',
    $ids($two_where_calls) !== $ids($active_admin)
);

// -----------------------------------------------------------------------------
section('nested: AND containing OR — the case that would break if parentheses were dropped');

$nested = $dm->query_table($members)
    ->where(and_(
        not_(eq($members->banned, 1)),
        or_(eq($members->role, 'admin'), gt($members->score, 100))
    ))
    ->find_many();

// Without parentheses around the OR, "NOT banned AND role='admin' OR score>100"
// would parse as "(NOT banned AND role='admin') OR score>100" by ordinary SQL
// precedence — which would incorrectly include row 2 even if it were banned,
// and does not here, but the assertion is on the actual rows, not the intent.
test('rows 1 and 2 match — NOT banned AND (admin OR score > 100)', $ids($nested) === [1, 2]);

// -----------------------------------------------------------------------------
section('nested the other way: OR of two AND branches (De Morgan-shaped)');

$or_of_ands = $dm->query_table($members)
    ->where(or_(
        and_(eq($members->role, 'admin'), eq($members->banned, 0)),
        and_(eq($members->role, 'user'), gt($members->score, 100))
    ))
    ->find_many();

test(
    'rows 1, 2 and 5 match — (admin AND not banned) OR (user AND score > 100)',
    $ids($or_of_ands) === [1, 2, 5]
);

// -----------------------------------------------------------------------------
section('not_() wrapping a nested and_()/or_() — negating a whole sub-expression, not just one column');

$not_the_nested_group = $dm->query_table($members)
    ->where(not_(and_(
        not_(eq($members->banned, 1)),
        or_(eq($members->role, 'admin'), gt($members->score, 100))
    )))
    ->find_many();

// The exact complement of $nested (rows 1, 2) out of all five rows.
test(
    'rows 3, 4 and 5 match — the complement of the earlier nested query',
    $ids($not_the_nested_group) === [3, 4, 5]
);

// -----------------------------------------------------------------------------
section('and_()/or_() with no arguments are the vacuous identities, not an error');

$empty_and = $dm->query_table($members)->where(and_())->find_many();
$empty_or  = $dm->query_table($members)->where(or_())->find_many();

test('and_() with nothing is vacuously true — every row matches', count($empty_and) === 5);
test('or_() with nothing is vacuously false — no row matches', $empty_or === []);

exit(summary());
