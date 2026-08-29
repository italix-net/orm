<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Schema Differ
 * 
 * Compares database schemas and generates migration suggestions.
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\DataManager;
use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;

/**
 * Compares schemas and generates diffs for migration suggestions.
 */
class SchemaDiffer
{
    protected SchemaIntrospector $introspector;
    protected string $dialect;

    public function __construct(DataManager $dm)
    {
        $this->introspector = new SchemaIntrospector($dm);
        $this->dialect = $dm->get_driver()->get_dialect_name();
    }

    /**
     * Compare defined schema with database and generate diff
     * 
     * @param Table[] $tables Array of Table definitions
     * @return array Diff result with changes
     */
    public function diff(array $tables): array
    {
        $db_tables = $this->introspector->get_tables();
        $defined_tables = [];
        
        foreach ($tables as $table) {
            $defined_tables[$table->get_name()] = $table;
        }
        
        $diff = [
            'create_tables' => [],
            'drop_tables' => [],
            'alter_tables' => [],
        ];
        
        // Tables to create (in definition but not in DB)
        foreach ($defined_tables as $name => $table) {
            if (!in_array($name, $db_tables)) {
                $diff['create_tables'][] = $name;
            }
        }
        
        // Tables to potentially drop (in DB but not in definition)
        foreach ($db_tables as $name) {
            if (!isset($defined_tables[$name])) {
                $diff['drop_tables'][] = $name;
            }
        }
        
        // Tables to alter (exist in both)
        foreach ($defined_tables as $name => $table) {
            if (in_array($name, $db_tables)) {
                $table_diff = $this->diff_table($table);
                if (!empty($table_diff['add_columns']) || 
                    !empty($table_diff['drop_columns']) || 
                    !empty($table_diff['modify_columns']) ||
                    !empty($table_diff['add_indexes']) ||
                    !empty($table_diff['drop_indexes'])) {
                    $diff['alter_tables'][$name] = $table_diff;
                }
            }
        }
        
        return $diff;
    }

    /**
     * Diff a single table against database
     */
    public function diff_table(Table $table): array
    {
        $name = $table->get_name();
        $db_schema = $this->introspector->get_table_schema($name);
        
        $diff = [
            'add_columns' => [],
            'drop_columns' => [],
            'modify_columns' => [],
            'add_indexes' => [],
            'drop_indexes' => [],
            'add_foreign_keys' => [],
            'drop_foreign_keys' => [],
        ];
        
        // Get defined columns
        $defined_columns = [];
        foreach ($table->get_columns() as $col) {
            $defined_columns[$col->get_db_name()] = $col;
        }
        
        // Get DB columns as map
        $db_columns = [];
        foreach ($db_schema['columns'] as $col) {
            $db_columns[$col['name']] = $col;
        }
        
        // Columns to add
        foreach ($defined_columns as $name => $col) {
            if (!isset($db_columns[$name])) {
                $diff['add_columns'][] = $this->column_to_definition($col);
            }
        }
        
        // Columns to drop
        foreach ($db_columns as $name => $col) {
            if (!isset($defined_columns[$name])) {
                $diff['drop_columns'][] = $name;
            }
        }
        
        // Columns that may need modification
        foreach ($defined_columns as $name => $defined_col) {
            if (isset($db_columns[$name])) {
                $changes = $this->diff_column($defined_col, $db_columns[$name]);
                if (!empty($changes)) {
                    $diff['modify_columns'][$name] = $changes;
                }
            }
        }
        
        return $diff;
    }

    /**
     * Diff a single column
     */
    /**
     * What differs between a declared column and the one in the database.
     *
     * Both sides are put in the same terms first, which is most of the work:
     *
     *  - the **type** is compared as "what this declaration would be created as
     *    *here*", via {@see Column::sql_type()} and
     *    {@see SchemaIntrospector::canonical_type()}. `datetime()` is `DATETIME`
     *    on MySQL and `TIMESTAMP` on PostgreSQL; comparing the factory name
     *    against the server's name reports a change on every timestamp column,
     *    on every run, forever.
     *  - **nullability** counts a primary key as not-null on both sides.
     *    SQLite reports `notnull = 0` for `INTEGER PRIMARY KEY`, and a declared
     *    `primary_key()` does not set `not_null()` — so a schema that matched
     *    the database perfectly reported a change on its own primary key. It
     *    did, for every table, until this suite asked.
     *
     * @param Column $defined
     */
    protected function diff_column($defined, array $db_col): array
    {
        $changes = [];
        $dialect = $this->introspector->get_dialect();

        // The length comes out of the rendered type, not out of the
        // declaration: `boolean()` has no length of its own and renders as
        // `TINYINT(1)` on MySQL, which is the only thing that distinguishes it
        // there from a small integer.
        $rendered = $defined->sql_type($dialect);

        $defined_type = SchemaIntrospector::canonical_type(
            $this->strip_arguments($rendered),
            $this->argument_of($rendered) ?? $defined->get_length()
        );

        $db_type = SchemaIntrospector::canonical_type(
            $this->strip_arguments((string) $db_col['type']),
            $db_col['length'] === null ? null : (int) $db_col['length']
        );

        if ($defined_type !== $db_type && !$this->same_type_here($defined_type, $db_type, $dialect)) {
            $changes['type'] = ['from' => $db_col['type'], 'to' => $defined->get_type()];
        }

        if ($defined->get_length() !== null && $db_col['length'] !== null) {
            if ($defined->get_length() !== (int) $db_col['length']) {
                $changes['length'] = ['from' => (int) $db_col['length'], 'to' => $defined->get_length()];
            }
        }

        // PostgreSQL has no unsigned integer, so a declaration that asks for one
        // is dropped when the table is created there — and comparing it would
        // report a difference on every such column, on every run, that no
        // migration could ever close.
        if ($dialect !== 'postgresql' && $defined->is_unsigned() !== (bool) ($db_col['unsigned'] ?? false)) {
            $changes['unsigned'] = ['from' => (bool) ($db_col['unsigned'] ?? false), 'to' => $defined->is_unsigned()];
        }

        $defined_nullable = $defined->is_nullable() && !$defined->is_primary_key();
        $db_nullable      = $db_col['nullable'] && !($db_col['primary'] ?? false);

        if ($defined_nullable !== $db_nullable) {
            $changes['nullable'] = ['from' => $db_nullable, 'to' => $defined_nullable];
        }

        return $changes;
    }

    /**
     * Two canonical names that this particular server treats as one type.
     *
     * MariaDB implements `JSON` as `LONGTEXT` — with a `json_valid()` check
     * constraint, which is not in the column's type — and reports it as
     * `longtext`. So a `json()` column is exactly what was asked for and looks
     * like a text column from the outside; calling that a difference means
     * reporting one on every JSON column, forever, on the server most likely to
     * have them.
     */
    protected function same_type_here(string $a, string $b, string $dialect): bool
    {
        $pairs = [
            'mysql' => [['json', 'text'], ['jsonb', 'text']],
        ];

        foreach ($pairs[$dialect] ?? [] as [$left, $right]) {
            if (($a === $left && $b === $right) || ($a === $right && $b === $left)) {
                return true;
            }
        }

        return false;
    }

    /** The first number in `TINYINT(1)` or `VARCHAR(120)`, when there is one. */
    protected function argument_of(string $type): ?int
    {
        return preg_match('/\((\d+)/', $type, $matches) === 1 ? (int) $matches[1] : null;
    }

    /** `VARCHAR(120)` and `DECIMAL(10,2)` are a VARCHAR and a DECIMAL. */
    protected function strip_arguments(string $type): string
    {
        $position = strpos($type, '(');

        return $position === false ? trim($type) : trim(substr($type, 0, $position));
    }

    /**
     * Convert Column object to definition array
     */
    protected function column_to_definition($col): array
    {
        return [
            'name' => $col->get_db_name(),
            'type' => $col->get_type(),
            'length' => $col->get_length(),
            'nullable' => $col->is_nullable(),
            'default' => $col->get_default(),
            'primary' => $col->is_primary_key(),
            'auto_increment' => $col->is_auto_increment(),
            'unique' => $col->is_unique(),
        ];
    }

    /**
     * Generate migration code from diff
     */
    /**
     * A migration that would close this diff.
     *
     * The fix-forward half of `ix db:diff`. What comes out is a file to read and
     * then run, not one to trust: it is generated from what the database and the
     * declarations disagree about, and only one of those two is right.
     *
     * ## What it writes, and what it only proposes
     *
     * | | |
     * |---|---|
     * | a missing table | `Schema::create()` with **its actual columns**, taken from the declaration |
     * | a missing column | `$table->…()` with its type, nullability and default |
     * | a column the declaration dropped | commented out — it holds data, and a migration that removes it should be a sentence somebody wrote |
     * | a changed type or length | a comment saying what changed. Converting a column is dialect-specific and can lose data; half of one is worse than none |
     * | a table nobody declared | a comment. It may be another application's, or a library's — `ix_session` is nobody's model |
     *
     * `down()` reverses what `up()` actually did: the tables it created and the
     * columns it added. It cannot reverse what it only proposed, which is one
     * more reason those stay comments.
     *
     * @param array<string, mixed> $diff  from {@see diff()}
     * @param Table[]              $tables the declarations the diff was made against
     */
    public function generate_migration_from_diff(array $diff, array $tables = []): string
    {
        $declared = [];

        foreach ($tables as $table) {
            $declared[$table->get_name()] = $table;
        }

        $up   = [];
        $down = [];

        foreach ($diff['create_tables'] as $name) {
            if (!isset($declared[$name])) {
                // Without the declaration there is nothing to write but a stub,
                // and a stub that looks like a migration is worse than a note.
                $up[] = "// Table '{$name}' is declared but not in the database, and this generator";
                $up[] = '// was not given its definition. Pass the tables to generate_migration_from_diff().';
                $up[] = '';
                continue;
            }

            $up[] = "Schema::create('{$name}', function (Blueprint \$table) {";

            foreach ($declared[$name]->get_columns() as $column) {
                $up[] = '    ' . $this->blueprint_line($column) . ';';
            }

            $up[] = '});';
            $up[] = '';

            $down[] = "Schema::drop_if_exists('{$name}');";
        }

        foreach ($diff['drop_tables'] as $name) {
            $up[] = "// '{$name}' is in the database and not among the declarations passed here.";
            $up[] = "// It may belong to a library or another application: ix_session is nobody's model.";
            $up[] = "// If it is really gone: Schema::drop_if_exists('{$name}');";
            $up[] = '';
        }

        foreach ($diff['alter_tables'] as $name => $changes) {
            $body     = [];
            $comments = [];

            foreach ($changes['add_columns'] as $column) {
                $body[] = '    ' . $this->blueprint_line_from_definition($column) . ';';
            }

            foreach ($changes['drop_columns'] as $column) {
                $comments[] = "    // '{$column}' is in the database and not in the declaration. It holds data:";
                $comments[] = "    // \$table->drop_column('{$column}');";
            }

            foreach ($changes['modify_columns'] as $column => $modifications) {
                foreach ($modifications as $property => $change) {
                    $comments[] = sprintf(
                        '    // %s.%s %s: %s → %s (convert by hand: the dialect decides, and data can go)',
                        $name,
                        $column,
                        $property,
                        var_export($change['from'], true),
                        var_export($change['to'], true)
                    );
                }
            }

            if ($body === [] && $comments === []) {
                continue;
            }

            $up[] = "Schema::table('{$name}', function (Blueprint \$table) {";
            $up   = array_merge($up, $body, $comments);
            $up[] = '});';
            $up[] = '';

            if ($changes['add_columns'] !== []) {
                $down[] = "Schema::table('{$name}', function (Blueprint \$table) {";

                foreach ($changes['add_columns'] as $column) {
                    $down[] = "    \$table->drop_column('{$column['name']}');";
                }

                $down[] = '});';
            }
        }

        if ($up === []) {
            $up[] = '// Nothing to do: the declarations and the database agree.';
        }

        if ($down === []) {
            $down[] = '// Nothing to undo: up() only proposed changes, it did not make any.';
        }

        $class_name = 'SchemaDiff' . date('YmdHis');

        return <<<PHP
<?php
/**
 * Generated by `ix db:diff --migration` on {$this->now()}.
 *
 * **Read this before running it.** It was written from the difference between
 * the models and the database, and only one of those two is right — which one
 * is a question about your application, not about SQL.
 *
 * Anything that would remove a column, or convert one, is left commented out:
 * those hold data, and the decision is a sentence somebody should have written
 * on purpose.
 */

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

class {$class_name} extends Migration
{
    public function up(): void
    {
        {$this->indent_lines($up, 2)}
    }

    public function down(): void
    {
        {$this->indent_lines($down, 2)}
    }
}

PHP;
    }

    /** The moment this file was written. */
    protected function now(): string
    {
        return date('Y-m-d H:i');
    }

    /**
     * One declared column, as the Blueprint call that makes it.
     *
     * @param Column $column
     */
    protected function blueprint_line(Column $column): string
    {
        return $this->blueprint_line_from_definition($this->column_to_definition($column));
    }

    /**
     * One column definition, as the Blueprint call that makes it.
     *
     * @param array<string, mixed> $column
     */
    protected function blueprint_line_from_definition(array $column): string
    {
        $method = $this->type_to_method((string) $column['type']);
        $name   = $column['name'];

        if ($method === 'string' || $method === 'char') {
            $line = "\$table->{$method}('{$name}'" . ($column['length'] ? ', ' . (int) $column['length'] : '') . ')';
        } elseif ($method === 'decimal') {
            $line = "\$table->decimal('{$name}')";
        } else {
            $line = "\$table->{$method}('{$name}')";
        }

        if (!empty($column['primary']) && !empty($column['auto_increment'])) {
            // id() is the Blueprint's word for exactly this.
            return "\$table->id('{$name}')";
        }

        if (!empty($column['nullable'])) {
            $line .= '->nullable()';
        }

        if (!empty($column['unique'])) {
            $line .= '->unique()';
        }

        if (isset($column['default']) && $column['default'] !== null) {
            $default = $column['default'];
            $line   .= is_numeric($default)
                ? "->default({$default})"
                : "->default('" . addslashes((string) $default) . "')";
        }

        return $line;
    }

    protected function type_to_method(string $type): string
    {
        // The Blueprint's names for the same things, which are not this
        // package's factory names — `varchar()` is `string()` there, and a
        // missing entry used to fall to `string()`, quietly turning a `uuid` or
        // a `blob` into a VARCHAR(255) in a generated migration.
        $map = [
            'INTEGER'          => 'integer',
            'INT'              => 'integer',
            'SERIAL'           => 'integer',
            'BIGINT'           => 'big_integer',
            'BIGSERIAL'        => 'big_integer',
            'SMALLINT'         => 'small_integer',
            'VARCHAR'          => 'string',
            'CHAR'             => 'char',
            'TEXT'             => 'text',
            'BOOLEAN'          => 'boolean',
            'TIMESTAMP'        => 'timestamp',
            'DATETIME'         => 'datetime',
            'DATE'             => 'date',
            'TIME'             => 'time',
            'DECIMAL'          => 'decimal',
            'NUMERIC'          => 'decimal',
            'REAL'             => 'float',
            'FLOAT'            => 'float',
            'DOUBLE'           => 'double',
            'DOUBLE_PRECISION' => 'double',
            'JSON'             => 'json',
            'JSONB'            => 'jsonb',
            'UUID'             => 'uuid',
            'BLOB'             => 'blob',
            'BINARY'           => 'binary',
        ];

        $method = $map[strtoupper($type)] ?? null;

        if ($method === null) {
            throw new \RuntimeException(
                'No Blueprint method for the column type "' . $type . '". A generated migration that '
                . 'guessed would create the wrong column and look right doing it.'
            );
        }

        return $method;
    }

    /**
     * Indent lines of code
     */
    protected function indent_lines(array $lines, int $level): string
    {
        if (empty($lines)) {
            return '//';
        }
        
        $indent = str_repeat('    ', $level);
        $first = true;
        $result = '';
        
        foreach ($lines as $line) {
            if ($first) {
                $result .= $line;
                $first = false;
            } else {
                $result .= "\n" . $indent . $line;
            }
        }
        
        return $result;
    }

    /**
     * Get the introspector
     */
    public function get_introspector(): SchemaIntrospector
    {
        return $this->introspector;
    }

    /**
     * Check if database has any tables
     */
    public function has_tables(): bool
    {
        return !empty($this->introspector->get_tables());
    }

    /**
     * Get summary of differences
     */
    public function get_diff_summary(array $diff): array
    {
        $summary = [
            'tables_to_create' => count($diff['create_tables']),
            'tables_to_drop' => count($diff['drop_tables']),
            'tables_to_alter' => count($diff['alter_tables']),
            'total_changes' => 0,
        ];
        
        $summary['total_changes'] = $summary['tables_to_create'] + 
                                   $summary['tables_to_drop'] + 
                                   $summary['tables_to_alter'];
        
        foreach ($diff['alter_tables'] as $changes) {
            $summary['total_changes'] += count($changes['add_columns'] ?? []);
            $summary['total_changes'] += count($changes['drop_columns'] ?? []);
            $summary['total_changes'] += count($changes['modify_columns'] ?? []);
        }
        
        return $summary;
    }
}
