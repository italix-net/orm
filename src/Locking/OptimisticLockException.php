<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Optimistic locking
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Locking;

/**
 * Thrown by `QueryBuilder::execute()` when an `UPDATE` made with
 * `->expect_version($n)` affects zero rows: either the row no longer exists,
 * or — the case this class exists to name — another write already moved the
 * version out from under this one. Both look identical from here (a `WHERE
 * id = ? AND version = ?` that matched nothing), and both mean the caller's
 * assumption about the row's state was wrong, so neither is worth telling
 * apart: `SELECT`ing again to find out which one happened would itself race.
 */
class OptimisticLockException extends \RuntimeException
{
    public function __construct(string $table_name, $expected_version)
    {
        parent::__construct(
            "Optimistic lock failed on '{$table_name}': expected version "
            . var_export($expected_version, true)
            . ', but the row was not found with that version — it was either'
            . ' deleted or updated by someone else since it was read.'
        );
    }
}
