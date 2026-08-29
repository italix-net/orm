<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - A validation rule derived from the schema
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Rules;

use Italix\Contracts\RuleMeta;

/**
 * One rule, as a value.
 *
 * ## Why this class exists rather than a `use Italix\Rules\Rule`
 *
 * Because then `italix/orm` would depend on `italix/rules`, and the two have no
 * business knowing about each other: one describes a schema, the other checks
 * values. What they share is a vocabulary, and the vocabulary already lives in
 * `italix/contracts` — which this package requires anyway.
 *
 * So a schedule built here is `RuleMeta[]`, and `Italix\Rules\Checker` runs it
 * because it dispatches on `get_name()`, which the contract calls "the stable
 * part". Neither package gained a dependency; the seam is where the seam goes.
 *
 * The names and parameter keys must match what the checker expects — `length`
 * for `max_length`, `table`/`column` for `unique`. A wrong *name* is loud (no
 * check registered for it); a wrong *parameter key* would be quiet, so
 * `SchemaRulesTest` runs the derived schedule through the real checker whenever
 * `italix/rules` is installed.
 */
final class SchemaRule implements RuleMeta
{
    private string $name;

    /** @var array<string, mixed> */
    private array $params;

    private ?string $message;

    /** @param array<string, mixed> $params */
    public function __construct(string $name, array $params = [], ?string $message = null)
    {
        $this->name    = $name;
        $this->params  = $params;
        $this->message = $message;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function get_params(): array
    {
        return $this->params;
    }

    /**
     * Always null.
     *
     * A schema knows that a column holds at most 50 characters. It does not know
     * how to say so to a person, in which language, or in what tone — that is
     * the application's, and `Italix\I18n`'s. The contract asks for null exactly
     * so the consumer can choose.
     */
    public function get_message(): ?string
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'rule'    => $this->name,
            'params'  => $this->params,
            'message' => $this->message,
        ];
    }
}
