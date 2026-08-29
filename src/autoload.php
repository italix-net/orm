<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Manual Autoloader (for testing without Composer)
 */

declare(strict_types=1);

// PSR-4 for this package and for the interfaces it implements.
//
// `Italix\Contracts` is in `require`, so a Composer install resolves it and this
// file is never reached. It is reached when somebody runs a suite without
// Composer — and knowing only `Italix\Orm\` meant `Column implements
// Italix\Contracts\RelationalColumnMeta` was a fatal error the moment any
// schema was built. Four of this package's five suites had not run since.
$prefixes = [
    'Italix\\Orm\\'       => __DIR__ . '/',
    'Italix\\Contracts\\' => null,   // resolved below, wherever it happens to sit
];

foreach ([
    __DIR__ . '/../../Contracts/src/',                 // vendored beside this package
    __DIR__ . '/../vendor/italix/contracts/src/',      // installed as a dependency
    __DIR__ . '/../../../../vendor/italix/contracts/src/',
] as $candidate) {
    if (is_dir($candidate)) {
        $prefixes['Italix\\Contracts\\'] = $candidate;
        break;
    }
}

spl_autoload_register(function ($class) use ($prefixes) {
    foreach ($prefixes as $prefix => $base_dir) {
        if ($base_dir === null || strncmp($prefix, $class, strlen($prefix)) !== 0) {
            continue;
        }

        $file = $base_dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (file_exists($file)) {
            require $file;

            return;
        }
    }
});

// Load function files
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Schema/ColumnTypes.php';
require_once __DIR__ . '/Operators/Operators.php';
require_once __DIR__ . '/Relations/functions.php';

// Sql class is autoloaded, but ensure it's available
require_once __DIR__ . '/Sql.php';
