<?php
/**
 * Italix ORM - RelationMetaAdapter Class
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use Italix\Contracts\RelationMeta;
use Italix\Orm\DataManager;

/**
 * Adapter that implements RelationMeta for foreign key relationships.
 *
 * Provides metadata about a foreign key relationship and can fetch
 * options from the related table for form select/autocomplete fields.
 */
class RelationMetaAdapter implements RelationMeta
{
    /** @var string Foreign table name */
    protected string $foreign_table;

    /** @var string Foreign key column in target table */
    protected string $foreign_key;

    /** @var string Label column in target table */
    protected string $foreign_label;

    /** @var DataManager|null Database connection for fetching options */
    protected ?DataManager $dm = null;

    /** @var callable|null Custom fetcher for options */
    protected $fetcher = null;

    /**
     * Create a new RelationMetaAdapter.
     *
     * @param string $foreign_table The foreign table name
     * @param string $foreign_key The foreign key column (default: 'id')
     * @param string $foreign_label The label column (default: 'name')
     */
    public function __construct(
        string $foreign_table,
        string $foreign_key = 'id',
        string $foreign_label = 'name'
    ) {
        $this->foreign_table = $foreign_table;
        $this->foreign_key = $foreign_key;
        $this->foreign_label = $foreign_label;
    }

    /**
     * Get the foreign table name.
     *
     * @return string
     */
    public function get_foreign_table(): string
    {
        return $this->foreign_table;
    }

    /**
     * Get the foreign key column name in the target table.
     *
     * @return string
     */
    public function get_foreign_key(): string
    {
        return $this->foreign_key;
    }

    /**
     * Get the column to use as display label in the foreign table.
     *
     * @return string
     */
    public function get_foreign_label(): string
    {
        return $this->foreign_label;
    }

    /**
     * Set the database connection for fetching options.
     *
     * @param DataManager $dm
     * @return self
     */
    public function set_dm(DataManager $dm): self
    {
        $this->dm = $dm;
        return $this;
    }

    /**
     * Set a custom fetcher callable for loading options.
     *
     * The fetcher receives: ($table, $key, $label, $limit)
     * and must return an associative array of [key => label] pairs.
     *
     * @param callable $fetcher
     * @return self
     */
    public function set_fetcher(callable $fetcher): self
    {
        $this->fetcher = $fetcher;
        return $this;
    }

    /**
     * Set the label column to use for display.
     *
     * @param string $label
     * @return self
     */
    public function set_foreign_label(string $label): self
    {
        $this->foreign_label = $label;
        return $this;
    }

    /**
     * Fetch option pairs from the related table.
     *
     * @param int $max_options Maximum number of options to fetch
     * @return array|null [key => label] pairs or null if too many
     */
    public function fetch_options(int $max_options = 100): ?array
    {
        // Use custom fetcher if provided
        if ($this->fetcher !== null) {
            return ($this->fetcher)(
                $this->foreign_table,
                $this->foreign_key,
                $this->foreign_label,
                $max_options
            );
        }

        // Use database connection if available
        if ($this->dm !== null) {
            return $this->fetch_from_db($max_options);
        }

        // Cannot fetch without db or fetcher
        return null;
    }

    /**
     * Fetch options directly from the database.
     *
     * @param int $max_options
     * @return array|null
     */
    protected function fetch_from_db(int $max_options): ?array
    {
        $pdo = $this->dm->get_pdo();
        $dialect = $this->dm->get_dialect();

        // First check count
        $count_sql = sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->quote_identifier($this->foreign_table, $dialect)
        );

        $stmt = $pdo->query($count_sql);
        $count = (int)$stmt->fetchColumn();

        if ($count > $max_options) {
            return null; // Too many options, use autocomplete
        }

        // Fetch options
        $sql = sprintf(
            'SELECT %s, %s FROM %s ORDER BY %s LIMIT %d',
            $this->quote_identifier($this->foreign_key, $dialect),
            $this->quote_identifier($this->foreign_label, $dialect),
            $this->quote_identifier($this->foreign_table, $dialect),
            $this->quote_identifier($this->foreign_label, $dialect),
            $max_options
        );

        $stmt = $pdo->query($sql);
        $options = [];

        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            $options[$row[0]] = $row[1];
        }

        return $options;
    }

    /**
     * Quote identifier based on dialect.
     *
     * @param string $name
     * @param string $dialect
     * @return string
     */
    protected function quote_identifier(string $name, string $dialect): string
    {
        if ($dialect === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
