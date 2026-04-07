<?php
/**
 * Italix ORM - TableMetaFromArray Trait
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Concerns;

use Italix\Contracts\ColumnMeta;
use Italix\Orm\DataManager;

/**
 * Trait to implement TableMeta interface from an array of columns.
 *
 * Provides a quick way to implement TableMeta if you have columns
 * stored as an array. Compatible with italix/forms library.
 *
 * @example
 * class MyTable implements TableMeta
 * {
 *     use TableMetaFromArray;
 *
 *     private array $columns = [];
 *
 *     protected function get_columns_for_description(): array
 *     {
 *         return $this->columns;
 *     }
 * }
 */
trait TableMetaFromArray
{
    /** @var callable|null Custom fetcher for relation options */
    protected $relation_fetcher = null;

    /** @var DataManager|null Database connection for relations */
    protected ?DataManager $forms_dm = null;

    /**
     * Override this method to provide your columns array.
     *
     * @return array<string, ColumnMeta>
     */
    abstract protected function get_columns_for_description(): array;

    /**
     * Return an iterable of column descriptors.
     *
     * Implements TableMeta::describe_columns()
     *
     * @return iterable<string, ColumnMeta>
     */
    public function describe_columns(): iterable
    {
        return $this->get_columns_for_description();
    }

    /**
     * Get a specific column descriptor by name.
     *
     * Implements TableMeta::describe_column()
     *
     * @param string $name Column name
     * @return ColumnMeta|null
     */
    public function describe_column(string $name): ?ColumnMeta
    {
        $columns = $this->get_columns_for_description();
        return $columns[$name] ?? null;
    }

    /**
     * Set a callable that fetches rows from related tables.
     *
     * The fetcher receives: ($table, $key, $label, $limit)
     * and must return an associative array of [key => label] pairs.
     *
     * @param callable $fetcher
     * @return self
     */
    public function set_relation_fetcher(callable $fetcher): self
    {
        $this->relation_fetcher = $fetcher;
        return $this;
    }

    /**
     * Get the relation fetcher callable.
     *
     * @return callable|null
     */
    public function get_relation_fetcher(): ?callable
    {
        return $this->relation_fetcher;
    }

    /**
     * Set the database connection for form relation lookups.
     *
     * This allows RelationMetaAdapter instances to fetch options
     * from related tables automatically.
     *
     * @param DataManager $dm
     * @return self
     */
    public function set_forms_dm(DataManager $dm): self
    {
        $this->forms_dm = $dm;

        // Propagate to columns that have relations
        foreach ($this->get_columns_for_description() as $column) {
            if (method_exists($column, 'get_relation')) {
                $relation = $column->get_relation();
                if ($relation !== null && method_exists($relation, 'set_dm')) {
                    $relation->set_dm($dm);
                }
            }
        }

        return $this;
    }

    /**
     * Get the database connection for forms.
     *
     * @return DataManager|null
     */
    public function get_forms_dm(): ?DataManager
    {
        return $this->forms_dm;
    }
}
