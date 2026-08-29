<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - EXISTS
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\QueryBuilder;

use Italix\Orm\Operators\SQLExpression;

/**
 * `EXISTS (SELECT …)` and its negation.
 *
 *     use function Italix\Orm\Operators\{exists, not_exists, eq, sub};
 *
 *     // customers who have ordered at least once
 *     QueryBuilder::select($customers)->where(exists(
 *         sub(QueryBuilder::select($orders, [$orders->id])
 *             ->where(eq($orders->customer_id, $customers->id)))
 *     ));
 *
 * Worth having as well as `IN (…)`, and not only for style. `EXISTS` stops at
 * the first matching row, and — unlike `NOT IN` — it behaves the way people
 * expect when the subquery returns a NULL. `NOT IN (1, 2, NULL)` is never true
 * for anything, which is correct three-valued logic and almost never what was
 * meant; `NOT EXISTS` is.
 *
 * The correlation in the example is an ordinary column reference: the inner
 * query mentions a column of the outer table, and nothing here has to know
 * that it did.
 */
final class ExistsExpression implements SQLExpression
{
    /** @var Subquery */
    private $subquery;

    /** @var bool */
    private $negated;

    public function __construct(Subquery $subquery, bool $negated = false)
    {
        $this->subquery = $subquery;
        $this->negated = $negated;
    }

    public function to_sql(string $dialect, array &$params): string
    {
        $keyword = $this->negated ? 'NOT EXISTS' : 'EXISTS';

        return $keyword . ' (' . $this->subquery->to_sql($dialect, $params) . ')';
    }
}
