<?php

/**
 * Application Schema Definition
 *
 * Centralized schema definition that works across all supported databases.
 * This pattern ensures consistent table structure regardless of the database used.
 */

namespace App\Schema;

use function Italix\Orm\Schema\{integer, bigint, varchar, text, boolean, timestamp, decimal};

class AppSchema
{
    /**
     * @var TableFactory
     */
    private $factory;

    /**
     * @var array Table definitions
     */
    private $tables = [];

    /**
     * Create the schema for the given dialect
     *
     * @param string $dialect Database dialect
     */
    public function __construct(string $dialect)
    {
        $this->factory = new TableFactory($dialect);
        $this->define_tables();
    }

    /**
     * Define all application tables
     */
    private function define_tables(): void
    {
        $this->define_users_tables();
        $this->define_content_tables();
        $this->define_ecommerce_tables();
    }

    /**
     * Define user-related tables
     */
    private function define_users_tables(): void
    {
        // Users table
        $this->tables['users'] = $this->factory->create_table('users', [
            'id' => integer()->primary_key()->auto_increment(),
            'email' => varchar(255)->not_null()->unique(),
            'password_hash' => varchar(255)->not_null(),
            'display_name' => varchar(100),
            'role' => varchar(20)->default('user'),
            'is_active' => boolean()->default(true),
            'email_verified_at' => timestamp(),
            'settings' => text(),  // JSON stored as text for compatibility
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
            'deleted_at' => timestamp(),
        ]);

        // User profiles table
        $this->tables['user_profiles'] = $this->factory->create_table('user_profiles', [
            'id' => integer()->primary_key()->auto_increment(),
            'user_id' => integer()->not_null()->unique(),
            'first_name' => varchar(100),
            'last_name' => varchar(100),
            'bio' => text(),
            'avatar_url' => varchar(500),
            'website' => varchar(255),
            'location' => varchar(100),
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ]);
    }

    /**
     * Define content-related tables
     */
    private function define_content_tables(): void
    {
        // Posts table
        $this->tables['posts'] = $this->factory->create_table('posts', [
            'id' => integer()->primary_key()->auto_increment(),
            'user_id' => integer()->not_null(),
            'title' => varchar(255)->not_null(),
            'slug' => varchar(255)->unique(),
            'excerpt' => text(),
            'content' => text(),
            'status' => varchar(20)->default('draft'),  // draft, published, archived
            'featured_image' => varchar(500),
            'view_count' => integer()->default(0),
            'published_at' => timestamp(),
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
            'deleted_at' => timestamp(),
        ]);

        // Categories table
        $this->tables['categories'] = $this->factory->create_table('categories', [
            'id' => integer()->primary_key()->auto_increment(),
            'name' => varchar(100)->not_null(),
            'slug' => varchar(100)->not_null()->unique(),
            'description' => text(),
            'parent_id' => integer(),
            'sort_order' => integer()->default(0),
            'created_at' => timestamp(),
        ]);

        // Post-Category junction table
        $this->tables['post_categories'] = $this->factory->create_table('post_categories', [
            'post_id' => integer()->not_null(),
            'category_id' => integer()->not_null(),
        ]);

        // Tags table
        $this->tables['tags'] = $this->factory->create_table('tags', [
            'id' => integer()->primary_key()->auto_increment(),
            'name' => varchar(50)->not_null()->unique(),
            'slug' => varchar(50)->not_null()->unique(),
            'created_at' => timestamp(),
        ]);

        // Post-Tag junction table
        $this->tables['post_tags'] = $this->factory->create_table('post_tags', [
            'post_id' => integer()->not_null(),
            'tag_id' => integer()->not_null(),
        ]);

        // Comments table
        $this->tables['comments'] = $this->factory->create_table('comments', [
            'id' => integer()->primary_key()->auto_increment(),
            'post_id' => integer()->not_null(),
            'user_id' => integer(),
            'parent_id' => integer(),
            'author_name' => varchar(100),
            'author_email' => varchar(255),
            'content' => text()->not_null(),
            'is_approved' => boolean()->default(false),
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ]);
    }

    /**
     * Define e-commerce related tables
     */
    private function define_ecommerce_tables(): void
    {
        // Products table
        $this->tables['products'] = $this->factory->create_table('products', [
            'id' => integer()->primary_key()->auto_increment(),
            'sku' => varchar(50)->not_null()->unique(),
            'name' => varchar(255)->not_null(),
            'slug' => varchar(255)->not_null()->unique(),
            'description' => text(),
            'price' => decimal(10, 2)->not_null(),
            'compare_price' => decimal(10, 2),
            'cost_price' => decimal(10, 2),
            'stock_quantity' => integer()->default(0),
            'is_active' => boolean()->default(true),
            'metadata' => text(),  // JSON stored as text
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
            'deleted_at' => timestamp(),
        ]);

        // Orders table
        $this->tables['orders'] = $this->factory->create_table('orders', [
            'id' => integer()->primary_key()->auto_increment(),
            'user_id' => integer(),
            'order_number' => varchar(50)->not_null()->unique(),
            'status' => varchar(20)->default('pending'),  // pending, processing, shipped, delivered, cancelled
            'subtotal' => decimal(10, 2)->not_null(),
            'tax_amount' => decimal(10, 2)->default(0),
            'shipping_amount' => decimal(10, 2)->default(0),
            'total_amount' => decimal(10, 2)->not_null(),
            'currency' => varchar(3)->default('USD'),
            'shipping_address' => text(),  // JSON stored as text
            'billing_address' => text(),   // JSON stored as text
            'notes' => text(),
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ]);

        // Order items table
        $this->tables['order_items'] = $this->factory->create_table('order_items', [
            'id' => integer()->primary_key()->auto_increment(),
            'order_id' => integer()->not_null(),
            'product_id' => integer()->not_null(),
            'quantity' => integer()->not_null(),
            'unit_price' => decimal(10, 2)->not_null(),
            'total_price' => decimal(10, 2)->not_null(),
            'created_at' => timestamp(),
        ]);
    }

    /**
     * Get a specific table by name
     *
     * @param string $name Table name
     * @return mixed Table object
     * @throws \InvalidArgumentException If table not found
     */
    public function get_table(string $name)
    {
        if (!isset($this->tables[$name])) {
            throw new \InvalidArgumentException("Unknown table: $name");
        }
        return $this->tables[$name];
    }

    /**
     * Get all tables
     *
     * @return array
     */
    public function get_all_tables(): array
    {
        return array_values($this->tables);
    }

    /**
     * Get table names
     *
     * @return array
     */
    public function get_table_names(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Get the factory's dialect
     *
     * @return string
     */
    public function get_dialect(): string
    {
        return $this->factory->get_dialect();
    }

    /**
     * Get tables in reverse order (for dropping with foreign key constraints)
     *
     * @return array
     */
    public function get_tables_for_drop(): array
    {
        return array_reverse($this->get_all_tables());
    }
}
