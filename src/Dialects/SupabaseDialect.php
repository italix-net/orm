<?php
/**
 * Italix ORM - Supabase Dialect
 * 
 * @package Italix\Orm
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Italix\Orm\Dialects;

/**
 * Supabase database dialect implementation.
 * Supabase is based on PostgreSQL with additional features.
 */
class SupabaseDialect extends PostgresqlDialect
{
    /** @var string|null Supabase project reference */
    protected ?string $project_ref = null;
    
    /** @var string|null Supabase region */
    protected ?string $region = null;
    
    /** @var bool Use connection pooling */
    protected bool $pooling = true;

    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return 'supabase';
    }

    /**
     * {@inheritdoc}
     */
    public function build_dsn(array $config): string
    {
        // Support direct host specification
        if (!empty($config['host'])) {
            return parent::build_dsn($config);
        }
        
        // Build Supabase connection from project reference
        $project_ref = $config['project_ref'] ?? $this->project_ref;
        $region = $config['region'] ?? $this->region ?? 'us-east-1';
        $pooling = $config['pooling'] ?? $this->pooling;
        $database = $config['database'] ?? 'postgres';
        
        if (empty($project_ref)) {
            throw new \InvalidArgumentException('Supabase project_ref is required');
        }
        
        // Determine host based on pooling setting
        if ($pooling) {
            // Use Supavisor pooler
            $host = "aws-0-{$region}.pooler.supabase.com";
            $port = $config['port'] ?? 6543;
        } else {
            // Direct connection
            $host = "db.{$project_ref}.supabase.co";
            $port = $config['port'] ?? 5432;
        }
        
        return "pgsql:host={$host};port={$port};dbname={$database}";
    }

    /**
     * Set project reference
     */
    public function set_project_ref(string $project_ref): self
    {
        $this->project_ref = $project_ref;
        return $this;
    }

    /**
     * Set region
     */
    public function set_region(string $region): self
    {
        $this->region = $region;
        return $this;
    }

    /**
     * Enable/disable connection pooling
     */
    public function set_pooling(bool $pooling): self
    {
        $this->pooling = $pooling;
        return $this;
    }

    /**
     * Get SQL for creating Row Level Security policy
     */
    public function get_create_policy_sql(
        string $table_name,
        string $policy_name,
        string $operation,
        string $expression
    ): string {
        $table = $this->quote_identifier($table_name);
        $policy = $this->quote_identifier($policy_name);
        
        return "CREATE POLICY {$policy} ON {$table} FOR {$operation} USING ({$expression})";
    }

    /**
     * Get SQL for enabling RLS on a table
     */
    public function get_enable_rls_sql(string $table_name): string
    {
        $table = $this->quote_identifier($table_name);
        return "ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY";
    }

    /**
     * Get SQL for disabling RLS on a table
     */
    public function get_disable_rls_sql(string $table_name): string
    {
        $table = $this->quote_identifier($table_name);
        return "ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY";
    }

    /**
     * Build configuration for Supabase from credentials
     */
    public static function from_credentials(
        string $project_ref,
        string $password,
        string $database = 'postgres',
        string $region = 'us-east-1',
        bool $pooling = true
    ): array {
        return [
            'project_ref' => $project_ref,
            'database' => $database,
            'username' => $pooling ? "postgres.{$project_ref}" : 'postgres',
            'password' => $password,
            'region' => $region,
            'pooling' => $pooling,
        ];
    }
}
