<?php
/**
 * Italix ORM - Migration Squasher
 * 
 * Combines multiple migration files into a single consolidated migration.
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Migration;

use Italix\Orm\IxOrm;

/**
 * Squashes multiple migrations into a single migration file.
 */
class MigrationSquasher
{
    protected IxOrm $db;
    protected SchemaIntrospector $introspector;
    protected string $migrations_path;

    public function __construct(IxOrm $db, string $migrations_path)
    {
        $this->db = $db;
        $this->introspector = new SchemaIntrospector($db);
        $this->migrations_path = rtrim($migrations_path, '/');
    }

    /**
     * Squash all migrations up to a certain point
     * 
     * This creates a new migration that recreates the current database state,
     * then archives the old migration files.
     * 
     * @param string|null $up_to Migration name to squash up to (null = all)
     * @return array Result with 'squashed_file', 'archived_files', 'archived_count'
     */
    public function squash(?string $up_to = null): array
    {
        $files = $this->get_migration_files();
        
        if (empty($files)) {
            throw new \RuntimeException('No migrations to squash');
        }
        
        // Determine which files to squash
        $to_squash = [];
        foreach ($files as $name => $path) {
            $to_squash[$name] = $path;
            if ($up_to !== null && $name === $up_to) {
                break;
            }
        }
        
        if (empty($to_squash)) {
            throw new \RuntimeException('No migrations found to squash');
        }
        
        // Generate squashed migration content
        $content = $this->generate_squashed_migration();
        
        // Create archive directory
        $archive_dir = $this->migrations_path . '/archived_' . date('Y_m_d_His');
        if (!mkdir($archive_dir, 0755, true)) {
            throw new \RuntimeException("Failed to create archive directory: {$archive_dir}");
        }
        
        // Archive old migrations
        $archived = [];
        foreach ($to_squash as $name => $path) {
            $archive_path = $archive_dir . '/' . basename($path);
            if (rename($path, $archive_path)) {
                $archived[] = $name;
            }
        }
        
        // Write squashed migration
        $timestamp = date('Y_m_d_His');
        $squashed_file = $this->migrations_path . "/{$timestamp}_squashed_schema.php";
        file_put_contents($squashed_file, $content);
        
        // Create a marker file in archive
        $marker = "# Squashed Migrations\n\n";
        $marker .= "These migrations were squashed on " . date('Y-m-d H:i:s') . "\n\n";
        $marker .= "Squashed into: {$timestamp}_squashed_schema.php\n\n";
        $marker .= "## Archived migrations:\n";
        foreach ($archived as $name) {
            $marker .= "- {$name}\n";
        }
        file_put_contents($archive_dir . '/README.md', $marker);
        
        return [
            'squashed_file' => $squashed_file,
            'archive_dir' => $archive_dir,
            'archived_files' => $archived,
            'archived_count' => count($archived),
        ];
    }

    /**
     * Generate a migration that recreates the current database state
     */
    public function generate_squashed_migration(): string
    {
        $tables = $this->introspector->get_tables();
        $up_code = [];
        $down_code = [];
        
        foreach ($tables as $table) {
            $up_code[] = $this->introspector->generate_migration_code($table);
            $up_code[] = '';
            $down_code[] = "Schema::drop_if_exists('{$table}');";
        }
        
        $up_body = implode("\n        ", $up_code);
        $down_body = implode("\n        ", array_reverse($down_code));
        
        return <<<PHP
<?php

use Italix\Orm\Migration\Migration;
use Italix\Orm\Migration\Schema;
use Italix\Orm\Migration\Blueprint;

/**
 * Squashed migration - recreates entire database schema.
 * Generated on: {$this->now()}
 * 
 * This migration consolidates all previous migrations into a single file
 * that recreates the database from scratch.
 */
class SquashedSchema extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        {$up_body}
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        {$down_body}
    }
}

PHP;
    }

    /**
     * Preview what would be squashed
     */
    public function preview(?string $up_to = null): array
    {
        $files = $this->get_migration_files();
        $to_squash = [];
        
        foreach ($files as $name => $path) {
            $to_squash[] = $name;
            if ($up_to !== null && $name === $up_to) {
                break;
            }
        }
        
        $tables = $this->introspector->get_tables();
        
        return [
            'migrations_to_squash' => $to_squash,
            'count' => count($to_squash),
            'tables_in_schema' => $tables,
            'tables_count' => count($tables),
        ];
    }

    /**
     * Get migration files sorted by name
     */
    protected function get_migration_files(): array
    {
        if (!is_dir($this->migrations_path)) {
            return [];
        }
        
        $files = glob($this->migrations_path . '/*.php');
        $migrations = [];
        
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $migrations[$name] = $file;
        }
        
        ksort($migrations);
        return $migrations;
    }

    /**
     * Get current timestamp
     */
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
