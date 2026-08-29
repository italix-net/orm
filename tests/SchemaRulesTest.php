<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — rules derived from the schema, and the two that need a database
 *
 * `varchar(50)` is already a promise that a longer value will not survive the
 * insert. Writing that promise a second time in a form is how the two drift
 * apart, so `SchemaRules` derives it, and `DatabaseRules` settles the two rules
 * a checker deliberately leaves open: `unique` and `exists`.
 *
 * Neither package depends on the other — the schedule is `RuleMeta[]`, from
 * `italix/contracts`. That is the design, and it is also the risk: a rule *name*
 * this package invents is caught loudly by the checker, but a wrong **parameter
 * key** would be silent. So the second half of this suite feeds the derived
 * schedule to the real `Italix\Rules\Checker` and asserts the verdicts, skipping
 * when the package is not installed.
 *
 * Run: php src/Libs/Italix/Orm/tests/SchemaRulesTest.php
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

use Italix\Contracts\RuleMeta;
use Italix\Orm\DataManager;
use Italix\Orm\Rules\DatabaseRules;
use Italix\Orm\Rules\SchemaRule;
use Italix\Orm\Rules\SchemaRules;

use function Italix\Orm\Schema\{bigint, boolean, datetime, decimal, integer, serial, sqlite_table, text, uuid, varchar};
use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Orm - schema-derived rules');

/** @return array{0: bool, 1: string} */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (\Throwable $e) {
        return [true, $e->getMessage()];
    }
};

$users = sqlite_table('users', [
    'id'         => serial(),
    'email'      => varchar(120)->not_null()->unique(),
    'name'       => varchar(50)->not_null(),
    'nickname'   => varchar(30),
    'age'        => integer(),
    'balance'    => decimal(10, 2),
    'bio'        => text(),
    'is_active'  => boolean()->not_null()->default(1),
    'public_id'  => uuid(),
    'company_id' => bigint()->references('companies', 'id'),
    'insert_dt'  => datetime()->not_null()->default('CURRENT_TIMESTAMP'),
]);

/** The rule names derived for one column, in order. */
$names_for = static function (array $schedule, string $column): string {
    return implode(',', array_map(
        static fn(RuleMeta $rule): string => $rule->get_name(),
        $schedule[$column] ?? []
    ));
};

$schedule = SchemaRules::for_table($users);

// -----------------------------------------------------------------------------
section('what a column already says');

test('NOT NULL with no default is required', $names_for($schedule, 'name') === 'required,max_length');
test('…and a nullable one is not', $names_for($schedule, 'nickname') === 'max_length');
test('NOT NULL WITH a default is not required either', $names_for($schedule, 'is_active') === '');
test('…nor is a NOT NULL timestamp that defaults', $names_for($schedule, 'insert_dt') === 'date');

test('varchar carries its length', (static function () use ($schedule): bool {
    foreach ($schedule['name'] as $rule) {
        if ($rule->get_name() === 'max_length') {
            // The key is `length`, because that is what the checker reads. A
            // wrong name here fails loudly; a wrong key would not.
            return $rule->get_params() === ['length' => 50];
        }
    }

    return false;
})());

test('integers are integers', $names_for($schedule, 'age') === 'integer');
test('decimals are numeric', $names_for($schedule, 'balance') === 'numeric');
test('datetimes are dates', $names_for($schedule, 'insert_dt') === 'date');
test('uuid columns are uuids', $names_for($schedule, 'public_id') === 'uuid');
test('unique columns carry unique', strpos($names_for($schedule, 'email'), 'unique') !== false);
test('a foreign key becomes exists', $names_for($schedule, 'company_id') === 'integer,exists');

test('…pointing at the table it references', (static function () use ($schedule): bool {
    foreach ($schedule['company_id'] as $rule) {
        if ($rule->get_name() === 'exists') {
            return $rule->get_params() === ['table' => 'companies', 'column' => 'id'];
        }
    }

    return false;
})());

// -----------------------------------------------------------------------------
section('what is deliberately not derived');

test('the primary key is not asked of anybody', !isset($schedule['id']));
test('text has no length to check', $names_for($schedule, 'bio') === '');
test('booleans get no rule, because none of them would be right', $names_for($schedule, 'is_active') === '');

test('a composite unique constraint is not split into two', (static function (): bool {
    $table = sqlite_table('memberships', [
        'id'      => serial(),
        'user_id' => integer()->not_null(),
        'team_id' => integer()->not_null(),
    ]);
    $table->add_unique('user_team', ['user_id', 'team_id']);

    $schedule = SchemaRules::for_table($table);

    // The *pair* is unique; requiring each column to be unique on its own would
    // refuse rows that are perfectly legal.
    $names = implode(',', array_map(
        static fn(RuleMeta $rule): string => $rule->get_name(),
        array_merge($schedule['user_id'], $schedule['team_id'])
    ));

    return strpos($names, 'unique') === false;
})());

test('only/except select columns', (static function () use ($users): bool {
    $only   = SchemaRules::for_table($users, ['only' => ['email', 'name']]);
    $except = SchemaRules::for_table($users, ['except' => ['email']]);

    return array_keys($only) === ['email', 'name'] && !isset($except['email']);
})());

test('database rules can be left out entirely', (static function () use ($users, $names_for): bool {
    $schedule = SchemaRules::for_table($users, ['database' => false]);

    return strpos($names_for($schedule, 'email'), 'unique') === false
        && strpos($names_for($schedule, 'company_id'), 'exists') === false;
})());

test('a rule carries no message, on purpose', (new SchemaRule('required'))->get_message() === null);
test('…and exports as an array for a client-side validator',
    (new SchemaRule('max_length', ['length' => 5]))->to_array()
        === ['rule' => 'max_length', 'params' => ['length' => 5], 'message' => null]);

// -----------------------------------------------------------------------------
section('UNIQUE AND EXISTS, AGAINST A REAL DATABASE');

$dm = sqlite_memory();
$dm->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, company_id INTEGER)');
$dm->execute('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT)');
$dm->execute("INSERT INTO companies (id, name) VALUES (1, 'Acme')");
$dm->execute("INSERT INTO users (id, email, company_id) VALUES (1, 'ada@example.com', 1)");
$dm->execute("INSERT INTO users (id, email, company_id) VALUES (2, 'grace@example.com', 1)");

$database = new DatabaseRules($dm);

$taken_schedule = [
    'email'      => [new SchemaRule('unique', ['table' => 'users', 'column' => 'email'])],
    'company_id' => [new SchemaRule('exists', ['table' => 'companies', 'column' => 'id'])],
];

test('a taken value fails uniqueness', $database->check($taken_schedule, [
    'email'      => 'ada@example.com',
    'company_id' => 1,
]) === ['email' => 'unique']);

test('a free one passes', $database->check($taken_schedule, [
    'email'      => 'katherine@example.com',
    'company_id' => 1,
]) === []);

test('a missing reference fails exists', $database->check($taken_schedule, [
    'email'      => 'katherine@example.com',
    'company_id' => 99,
]) === ['company_id' => 'exists']);

// The bug this prevents: an edit form that refuses every save which leaves the
// e-mail alone, because the row being edited is the row that already has it.
test('EDITING A ROW DOES NOT FAIL ITS OWN UNIQUENESS', $database->check(
    $taken_schedule,
    ['email' => 'ada@example.com', 'company_id' => 1],
    ['id' => 1]
) === []);

test('…while the same address still collides for a different row', $database->check(
    $taken_schedule,
    ['email' => 'ada@example.com', 'company_id' => 1],
    ['id' => 2]
) === ['email' => 'unique']);

test('…and creating, with nothing to ignore, still collides', $database->check(
    $taken_schedule,
    ['email' => 'ada@example.com', 'company_id' => 1],
    ['id' => null]
) === ['email' => 'unique']);

test('an empty value is required\'s business, not uniqueness\'s', $database->check($taken_schedule, [
    'email'      => '',
    'company_id' => 1,
]) === []);

test('a field the values do not mention is skipped', $database->check($taken_schedule, []) === []);

test('the column defaults to the field name', (static function () use ($database): bool {
    $schedule = ['email' => [new SchemaRule('unique', ['table' => 'users'])]];

    return $database->check($schedule, ['email' => 'ada@example.com']) === ['email' => 'unique'];
})());

test('wildcards land the error on the row that failed', (static function () use ($database): bool {
    $schedule = ['rows.*.email' => [new SchemaRule('unique', ['table' => 'users', 'column' => 'email'])]];

    return $database->check($schedule, [
        'rows.0.email' => 'free@example.com',
        'rows.1.email' => 'grace@example.com',
    ]) === ['rows.1.email' => 'unique'];
})());

test('A TABLE NAME THAT IS NOT AN IDENTIFIER IS REFUSED', (static function () use ($throws, $database): bool {
    $schedule = ['email' => [new SchemaRule('unique', ['table' => 'users WHERE 1=1; DROP TABLE users; --'])]];
    [$threw, $message] = $throws(static fn() => $database->check($schedule, ['email' => 'x@example.com']));

    return $threw && strpos($message, 'is not one') !== false;
})());

test('…and the table is still there', count($dm->query('SELECT id FROM users')) === 2);

// -----------------------------------------------------------------------------
section('THE DERIVED SCHEDULE RUN BY THE REAL CHECKER');

if (!class_exists(\Italix\Rules\Checker::class)) {
    echo "  SKIPPED - italix/rules is not installed.\n";
    exit(summary());
}

$checker = new \Italix\Rules\Checker();

$outcome = $checker->check_all(SchemaRules::for_table($users), [
    'email'      => 'ada@example.com',
    'name'       => 'Ada',
    'age'        => '36',
    'balance'    => '10.50',
    'public_id'  => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
    'company_id' => '1',
    'insert_dt'  => '2026-08-18 10:00:00',
]);

test('a good record passes every derived rule', $outcome->is_valid(), json_encode($outcome->errors()));

$too_long = $checker->check_all(SchemaRules::for_table($users), [
    'email' => 'ada@example.com',
    'name'  => str_repeat('a', 51),
]);

// This is the assertion that catches a wrong parameter key: with the key the
// checker does not read, max_length would compare against nothing.
test('MAX_LENGTH REACHES THE CHECKER WITH ITS LENGTH', $too_long->errors() === ['name' => 'max_length'],
    json_encode($too_long->errors()));

$missing = $checker->check_all(SchemaRules::for_table($users), ['email' => 'ada@example.com']);

test('a required column is required', ($missing->errors()['name'] ?? '') === 'required');

$bad_types = $checker->check_all(SchemaRules::for_table($users), [
    'email'     => 'ada@example.com',
    'name'      => 'Ada',
    'age'       => 'thirty',
    'public_id' => 'not-a-uuid',
]);

test('an integer rule refuses a word', ($bad_types->errors()['age'] ?? '') === 'integer');
// The rule is named `uuid`; the code it fails with is `format` — the checker's
// vocabulary is its own, and this package does not get to assume they match.
test('a uuid rule refuses a non-uuid', ($bad_types->errors()['public_id'] ?? '') === 'format');

test('THE CHECKER DEFERS EXACTLY WHAT DatabaseRules SETTLES', (static function () use ($checker, $users): bool {
    $outcome = $checker->check_all(SchemaRules::for_table($users), [
        'email'      => 'ada@example.com',
        'name'       => 'Ada',
        'company_id' => '1',
    ]);

    $deferred = $outcome->deferred();

    return ($deferred['email'] ?? []) === ['unique']
        && ($deferred['company_id'] ?? []) === ['exists'];
})());

test('…and the two halves together settle the record', (static function () use ($checker, $users, $database): bool {
    $schedule = SchemaRules::for_table($users);
    $data     = ['email' => 'ada@example.com', 'name' => 'Ada', 'company_id' => '1'];
    $outcome  = $checker->check_all($schedule, $data);

    $errors = array_merge(
        $outcome->errors(),
        $database->check($schedule, $outcome->normalized())
    );

    // The address is taken, and only the database could say so.
    return $outcome->is_valid() && $errors === ['email' => 'unique'];
})());

exit(summary());
