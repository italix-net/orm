<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM — the two bridges
 *
 *   1. **Validation the schema already implies.** `varchar(120)` is a promise
 *      that a longer value will not survive the insert; writing that promise a
 *      second time in a form is how the two drift apart.
 *   2. **Answers worth keeping.** A cached query, and what makes it let go.
 *
 * Neither `italix/rules` nor `italix/cache` is a dependency of `italix/orm`:
 * both seams are `italix/contracts` interfaces.
 *
 * Run: php src/Libs/Italix/Orm/examples/bridges_example.php
 */

declare(strict_types=1);

foreach ([
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

use Italix\Contracts\Cache;
use Italix\Contracts\RuleMeta;
use Italix\Orm\Cache\QueryCache;
use Italix\Orm\Rules\DatabaseRules;
use Italix\Orm\Rules\SchemaRules;

use function Italix\Orm\Schema\{integer, serial, sqlite_table, varchar};
use function Italix\Orm\Operators\eq;
use function Italix\Orm\sqlite_memory;

$users = sqlite_table('users', [
    'id'         => serial(),
    'email'      => varchar(120)->not_null()->unique(),
    'name'       => varchar(50)->not_null(),
    'age'        => integer(),
    'company_id' => integer()->references('companies', 'id'),
]);

$dm = sqlite_memory();
$dm->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, age INTEGER, company_id INTEGER)');
$dm->execute('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT)');
$dm->execute("INSERT INTO companies VALUES (1, 'Acme')");
$dm->execute("INSERT INTO users VALUES (1, 'ada@example.com', 'Ada', 36, 1)");

// -----------------------------------------------------------------------------
// 1. What the table already says about its own data.

echo "Rules derived from the schema\n-----------------------------\n";

$schedule = SchemaRules::for_table($users);

foreach ($schedule as $field => $rules) {
    $described = array_map(static function (RuleMeta $rule): string {
        $params = $rule->get_params();

        return $rule->get_name() . ($params === [] ? '' : '(' . implode(', ', array_map(
            static fn($key, $value): string => $key . '=' . $value,
            array_keys($params),
            $params
        )) . ')');
    }, $rules);

    printf("  %-11s %s\n", $field, implode(' ', $described));
}

// -----------------------------------------------------------------------------
// The two rules a checker cannot settle on its own.

echo "\nThe two that need a database\n----------------------------\n";

$database = new DatabaseRules($dm);

$creating = $database->check($schedule, [
    'email'      => 'ada@example.com',
    'name'       => 'Ada',
    'company_id' => 1,
]);

echo '  creating with a taken address: ', json_encode($creating), "\n";

$editing = $database->check($schedule, [
    'email'      => 'ada@example.com',
    'name'       => 'Ada',
    'company_id' => 1,
], ['id' => 1]);

// Editing row 1 and leaving the address alone must not fail its own check.
echo '  the same, editing row 1:       ', json_encode($editing), " (nothing)\n";

$missing_company = $database->check($schedule, ['email' => 'new@example.com', 'company_id' => 99]);

echo '  a company that is not there:   ', json_encode($missing_company), "\n";

if (class_exists(\Italix\Rules\Checker::class)) {
    $outcome = (new \Italix\Rules\Checker())->check_all($schedule, [
        'email' => 'not-an-address-and-far-too-long-' . str_repeat('x', 120),
        'age'   => 'thirty',
    ]);

    echo "\nThe same schedule, run by Italix\\Rules\n--------------------------------------\n";
    echo '  errors:   ', json_encode($outcome->errors()), "\n";
    echo '  deferred: ', json_encode($outcome->deferred()), " ← DatabaseRules settles these\n";
}

// -----------------------------------------------------------------------------
// 2. Keeping an answer, and letting go of it.

/** A cache small enough to read: an array, and a counter of the misses. */
final class LoggingCache implements Cache
{
    /** @var array<string, mixed> */
    private array $entries = [];

    public int $misses_n = 0;

    public function get(string $key, $default = null)
    {
        return $this->entries[$key] ?? $default;
    }

    public function set(string $key, $value, int $ttl_n = 0): bool
    {
        $this->entries[$key] = $value;

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function remember(string $key, int $ttl_n, callable $producer)
    {
        if (isset($this->entries[$key])) {
            return $this->entries[$key];
        }

        $this->misses_n++;

        return $this->entries[$key] = $producer();
    }
}

$cache = new LoggingCache();
$dm->use_query_cache(new QueryCache($cache, 300));

$count = static fn(): int => count($dm->select()->from($users)->cached()->execute());

echo "\nA cached query\n--------------\n";
echo '  first read:  ', $count(), " users (asked the database)\n";
echo '  second read: ', $count(), " users (asked the cache)\n";
echo '  misses so far: ', $cache->misses_n, "\n";

// A row inserted with raw SQL is invisible to the cache — the documented limit.
$dm->execute("INSERT INTO users VALUES (2, 'grace@example.com', 'Grace', 45, 1)");

echo "\nAfter an INSERT the package cannot see\n--------------------------------------\n";
echo '  cached read:   ', $count(), " users (still the old answer)\n";
echo '  the database:  ', count($dm->query('SELECT id FROM users')), " users\n";

// A write through the package moves the table's generation, and every cached
// answer about it becomes unreachable at once.
$dm->insert($users)->values(['id' => 3, 'email' => 'kat@example.com', 'name' => 'Katherine'])->execute();

echo "\nAfter an INSERT through the package\n-----------------------------------\n";
echo '  cached read:   ', $count(), " users (the answer was retired)\n";
echo '  misses so far: ', $cache->misses_n, "\n";
