<?php
/**
 * Italix ORM - DelegatedTableAdapter Class
 *
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Schema;

use Italix\Contracts\DelegatedTableMeta;
use Italix\Contracts\ColumnMeta;
use Italix\Contracts\TableMeta;
use Italix\Orm\ActiveRow\ActiveRow;

/**
 * Adapter that implements DelegatedTableMeta for tables using the DelegatedTypes pattern.
 *
 * This adapter wraps a Table and its corresponding ActiveRow class (which uses the
 * DelegatedTypes trait) to provide delegated types metadata for form generation.
 *
 * @example
 * // Thing uses DelegatedTypes trait with Book, Movie, Person delegates
 * $delegated_table = new DelegatedTableAdapter($things_table, ThingRow::class);
 *
 * // Now it can be used with italix/forms
 * $form = form_meta($delegated_table);
 */
class DelegatedTableAdapter implements DelegatedTableMeta
{
    /** @var Table The underlying table */
    protected Table $table;

    /** @var string|null The ActiveRow class that uses DelegatedTypes */
    protected ?string $row_class = null;

    /** @var string|null Type column name */
    protected ?string $type_column = null;

    /** @var string|null Type path column name */
    protected ?string $type_path_column = null;

    /** @var string Delegate foreign key column */
    protected string $delegate_foreign_key = 'thing_id';

    /** @var array<string, TableMeta> Delegate tables map */
    protected array $delegate_tables = [];

    /**
     * Create a new DelegatedTableAdapter.
     *
     * @param Table $table The underlying table
     * @param string|null $row_class Optional ActiveRow class using DelegatedTypes
     */
    public function __construct(Table $table, ?string $row_class = null)
    {
        $this->table = $table;

        if ($row_class !== null) {
            $this->from_row_class($row_class);
        }
    }

    /**
     * Configure from an ActiveRow class that uses DelegatedTypes trait.
     *
     * Uses reflection to extract configuration from the protected methods.
     *
     * @param string $row_class
     * @return self
     */
    public function from_row_class(string $row_class): self
    {
        if (!class_exists($row_class) || !is_subclass_of($row_class, ActiveRow::class)) {
            throw new \InvalidArgumentException("$row_class must be an ActiveRow subclass");
        }

        $this->row_class = $row_class;

        // Create instance for reflection
        $instance = new $row_class([]);

        // Extract configuration using reflection
        $this->type_column = $this->call_protected_method($instance, 'get_type_column') ?? 'type';
        $this->type_path_column = $this->call_protected_method($instance, 'get_type_path_column');
        $this->delegate_foreign_key = $this->call_protected_method($instance, 'get_delegate_foreign_key') ?? 'thing_id';

        // Get delegated types and convert to TableMeta
        $delegated_types = $this->call_protected_method($instance, 'get_delegated_types') ?? [];

        foreach ($delegated_types as $type_name => $delegate_class) {
            if (is_subclass_of($delegate_class, ActiveRow::class) && method_exists($delegate_class, 'get_table')) {
                $delegate_table = $delegate_class::get_table();
                if ($delegate_table instanceof TableMeta) {
                    $this->delegate_tables[$type_name] = $delegate_table;
                }
            }
        }

        return $this;
    }

    /**
     * Call a protected method on an instance using reflection.
     *
     * @param object $instance
     * @param string $method
     * @return mixed
     */
    protected function call_protected_method(object $instance, string $method)
    {
        try {
            $reflection = new \ReflectionMethod($instance, $method);
            $reflection->setAccessible(true);
            return $reflection->invoke($instance);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    // =========================================
    // TableMeta Interface
    // =========================================

    /**
     * Return an iterable of column descriptors.
     *
     * @return iterable<string, ColumnMeta>
     */
    public function describe_columns(): iterable
    {
        return $this->table->describe_columns();
    }

    /**
     * Get a specific column descriptor by name.
     *
     * @param string $name Column name
     * @return ColumnMeta|null
     */
    public function describe_column(string $name): ?ColumnMeta
    {
        return $this->table->describe_column($name);
    }

    // =========================================
    // DelegatedTableMeta Interface
    // =========================================

    /**
     * Get the type discriminator column name.
     *
     * @return string|null
     */
    public function get_type_column(): ?string
    {
        return $this->type_column;
    }

    /**
     * Get the type path column name.
     *
     * @return string|null
     */
    public function get_type_path_column(): ?string
    {
        return $this->type_path_column;
    }

    /**
     * Get the foreign key column name used in delegate tables.
     *
     * @return string
     */
    public function get_delegate_foreign_key(): string
    {
        return $this->delegate_foreign_key;
    }

    /**
     * Get the direct delegate sub-tables.
     *
     * @return array<string, TableMeta>
     */
    public function get_delegate_tables(): array
    {
        return $this->delegate_tables;
    }

    // =========================================
    // Configuration Methods
    // =========================================

    /**
     * Set the type column name.
     *
     * @param string $column
     * @return self
     */
    public function type_column(string $column): self
    {
        $this->type_column = $column;
        return $this;
    }

    /**
     * Set the type path column name.
     *
     * @param string|null $column
     * @return self
     */
    public function type_path_column(?string $column): self
    {
        $this->type_path_column = $column;
        return $this;
    }

    /**
     * Set the delegate foreign key column name.
     *
     * @param string $column
     * @return self
     */
    public function foreign_key(string $column): self
    {
        $this->delegate_foreign_key = $column;
        return $this;
    }

    /**
     * Add a delegate table.
     *
     * @param string $type_name The type identifier
     * @param TableMeta $table The delegate table
     * @return self
     */
    public function add_delegate(string $type_name, TableMeta $table): self
    {
        $this->delegate_tables[$type_name] = $table;
        return $this;
    }

    /**
     * Set all delegate tables at once.
     *
     * @param array<string, TableMeta> $tables
     * @return self
     */
    public function delegates(array $tables): self
    {
        $this->delegate_tables = $tables;
        return $this;
    }

    // =========================================
    // Passthrough Methods
    // =========================================

    /**
     * Get the underlying table.
     *
     * @return Table
     */
    public function get_table(): Table
    {
        return $this->table;
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function get_name(): string
    {
        return $this->table->get_name();
    }

    /**
     * Get all columns.
     *
     * @return array<string, Column>
     */
    public function get_columns(): array
    {
        return $this->table->get_columns();
    }

    /**
     * Get a specific column.
     *
     * @param string $name
     * @return Column|null
     */
    public function get_column(string $name): ?Column
    {
        return $this->table->get_column($name);
    }

    /**
     * Get primary key columns.
     *
     * @return array<string>
     */
    public function get_primary_keys(): array
    {
        return $this->table->get_primary_keys();
    }

    /**
     * Magic getter for column access.
     *
     * @param string $name
     * @return Column|null
     */
    public function __get(string $name)
    {
        return $this->table->$name;
    }

    /**
     * Check if column exists.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->table->$name);
    }
}
