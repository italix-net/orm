<?php

/**
 * Multi-Database Compatibility Example
 *
 * This example demonstrates how to write code that works across
 * MySQL, PostgreSQL, SQLite, and Supabase.
 *
 * Run with: php multi_database_example.php [dialect]
 * Examples:
 *   php multi_database_example.php sqlite
 *   php multi_database_example.php mysql
 *   php multi_database_example.php postgresql
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../src/ActiveRow/functions.php';
require_once __DIR__ . '/TableFactory.php';
require_once __DIR__ . '/AppSchema.php';

use App\Schema\AppSchema;
use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\{Persistable, HasTimestamps, SoftDeletes, HasSlug};

use function Italix\Orm\{sqlite_memory, mysql, postgres};
use function Italix\Orm\Schema\{integer, varchar, text, boolean, timestamp, decimal};
use function Italix\Orm\Operators\{eq, gt, like, desc, asc};

// ============================================
// ROW CLASSES (Work with any database)
// ============================================

/**
 * User row class
 */
class UserRow extends ActiveRow
{
    use Persistable, HasTimestamps, SoftDeletes;

    // Valid roles (instead of ENUM)
    const ROLE_USER = 'user';
    const ROLE_ADMIN = 'admin';
    const ROLE_MODERATOR = 'moderator';

    const VALID_ROLES = [
        self::ROLE_USER,
        self::ROLE_ADMIN,
        self::ROLE_MODERATOR,
    ];

    /**
     * Get display name
     */
    public function display_name(): string
    {
        return $this['display_name'] ?: $this['email'];
    }

    /**
     * Check if user is admin
     */
    public function is_admin(): bool
    {
        return $this['role'] === self::ROLE_ADMIN;
    }

    /**
     * Get user settings (JSON stored as text)
     */
    public function get_settings(): array
    {
        $raw = $this['settings'];
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * Set user settings
     */
    public function set_settings(array $settings): self
    {
        $this['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /**
     * Get a specific setting
     */
    public function get_setting(string $key, $default = null)
    {
        return $this->get_settings()[$key] ?? $default;
    }

    /**
     * Validate before save
     */
    protected function before_save_validate(): void
    {
        // Validate email
        if (empty($this['email'])) {
            throw new \InvalidArgumentException('Email is required');
        }

        if (!filter_var($this['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        // Validate role
        if (!empty($this['role']) && !in_array($this['role'], self::VALID_ROLES)) {
            throw new \InvalidArgumentException('Invalid role: ' . $this['role']);
        }
    }
}

/**
 * Post row class
 */
class PostRow extends ActiveRow
{
    use Persistable, HasTimestamps, SoftDeletes, HasSlug;

    protected function get_slug_source(): string
    {
        return 'title';
    }

    // Valid statuses (instead of ENUM)
    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED = 'archived';

    const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Check if post is published
     */
    public function is_published(): bool
    {
        return $this['status'] === self::STATUS_PUBLISHED
            && $this['published_at'] !== null
            && strtotime($this['published_at']) <= time();
    }

    /**
     * Publish the post
     */
    public function publish(): self
    {
        $this['status'] = self::STATUS_PUBLISHED;
        $this['published_at'] = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * Get formatted published date (handle in PHP, not SQL)
     */
    public function published_date(string $format = 'F j, Y'): ?string
    {
        if (!$this['published_at']) {
            return null;
        }
        return date($format, strtotime($this['published_at']));
    }

    /**
     * Increment view count
     */
    public function increment_views(): self
    {
        $this['view_count'] = ($this['view_count'] ?? 0) + 1;
        return $this;
    }

    /**
     * Validate before save
     */
    protected function before_save_validate(): void
    {
        if (empty($this['title'])) {
            throw new \InvalidArgumentException('Title is required');
        }

        if (!empty($this['status']) && !in_array($this['status'], self::VALID_STATUSES)) {
            throw new \InvalidArgumentException('Invalid status: ' . $this['status']);
        }
    }
}

/**
 * Product row class
 */
class ProductRow extends ActiveRow
{
    use Persistable, HasTimestamps, SoftDeletes, HasSlug;

    protected function get_slug_source(): string
    {
        return 'name';
    }

    /**
     * Get metadata (JSON stored as text)
     */
    public function get_metadata(): array
    {
        $raw = $this['metadata'];
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * Set metadata
     */
    public function set_metadata(array $metadata): self
    {
        $this['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /**
     * Get formatted price (handle in PHP)
     */
    public function formatted_price(string $currency_symbol = '$'): string
    {
        return $currency_symbol . number_format((float) $this['price'], 2);
    }

    /**
     * Check if on sale
     */
    public function is_on_sale(): bool
    {
        return $this['compare_price'] !== null
            && (float) $this['compare_price'] > (float) $this['price'];
    }

    /**
     * Get discount percentage
     */
    public function discount_percentage(): ?int
    {
        if (!$this->is_on_sale()) {
            return null;
        }
        $discount = ((float) $this['compare_price'] - (float) $this['price'])
            / (float) $this['compare_price'] * 100;
        return (int) round($discount);
    }

    /**
     * Check if in stock
     */
    public function is_in_stock(): bool
    {
        return ($this['stock_quantity'] ?? 0) > 0;
    }

    /**
     * Validate before save
     */
    protected function before_save_validate(): void
    {
        if (empty($this['name'])) {
            throw new \InvalidArgumentException('Name is required');
        }

        if (empty($this['sku'])) {
            throw new \InvalidArgumentException('SKU is required');
        }

        if (!is_numeric($this['price']) || $this['price'] < 0) {
            throw new \InvalidArgumentException('Price must be a positive number');
        }

        // Validate stock is not negative
        if (isset($this['stock_quantity']) && $this['stock_quantity'] < 0) {
            throw new \InvalidArgumentException('Stock quantity cannot be negative');
        }
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Create database connection based on dialect
 */
function create_connection(string $dialect)
{
    switch ($dialect) {
        case 'sqlite':
            return sqlite_memory();

        case 'mysql':
            return mysql([
                'host' => getenv('MYSQL_HOST') ?: 'localhost',
                'database' => getenv('MYSQL_DATABASE') ?: 'test',
                'username' => getenv('MYSQL_USER') ?: 'root',
                'password' => getenv('MYSQL_PASSWORD') ?: '',
            ]);

        case 'postgresql':
            return postgres([
                'host' => getenv('POSTGRES_HOST') ?: 'localhost',
                'database' => getenv('POSTGRES_DATABASE') ?: 'test',
                'username' => getenv('POSTGRES_USER') ?: 'postgres',
                'password' => getenv('POSTGRES_PASSWORD') ?: '',
            ]);

        default:
            throw new \InvalidArgumentException("Unknown dialect: $dialect");
    }
}

// ============================================
// MAIN EXAMPLE
// ============================================

// Get dialect from command line or default to sqlite
$dialect = $argv[1] ?? 'sqlite';

echo "=== Multi-Database Compatibility Example ===\n";
echo "Dialect: $dialect\n\n";

try {
    // Create connection
    echo "1. Creating database connection...\n";
    $db = create_connection($dialect);
    echo "   ✓ Connected to $dialect\n\n";

    // Create schema
    echo "2. Creating schema...\n";
    $schema = new AppSchema($dialect);
    echo "   Tables: " . implode(', ', $schema->get_table_names()) . "\n";

    // Create only the tables we need for this example
    $tables_to_create = [
        $schema->get_table('users'),
        $schema->get_table('posts'),
        $schema->get_table('products'),
    ];
    $db->create_tables(...$tables_to_create);
    echo "   ✓ Tables created\n\n";

    // Setup persistence
    UserRow::set_persistence($db, $schema->get_table('users'));
    PostRow::set_persistence($db, $schema->get_table('posts'));
    ProductRow::set_persistence($db, $schema->get_table('products'));

    // ============================================
    // Create sample data
    // ============================================

    echo "3. Creating sample data...\n";

    // Create users
    $admin = UserRow::create([
        'email' => 'admin@example.com',
        'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'display_name' => 'Admin User',
        'role' => UserRow::ROLE_ADMIN,
    ]);
    $admin->set_settings(['theme' => 'dark', 'notifications' => true])->save();

    $user = UserRow::create([
        'email' => 'user@example.com',
        'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'display_name' => 'Regular User',
        'role' => UserRow::ROLE_USER,
    ]);

    echo "   ✓ Created users: {$admin->display_name()}, {$user->display_name()}\n";

    // Create posts
    $post1 = PostRow::create([
        'user_id' => $admin['id'],
        'title' => 'Getting Started with Multi-Database Development',
        'content' => 'This is a guide to writing portable database code...',
        'status' => PostRow::STATUS_DRAFT,
    ]);

    $post2 = PostRow::create([
        'user_id' => $admin['id'],
        'title' => 'Best Practices for ORM Usage',
        'content' => 'Learn how to use Italix ORM effectively...',
    ]);
    $post2->publish()->save();

    echo "   ✓ Created posts: {$post1['title']}, {$post2['title']}\n";
    echo "   Auto-generated slugs: {$post1['slug']}, {$post2['slug']}\n";

    // Create products
    $product1 = ProductRow::create([
        'sku' => 'PROD-001',
        'name' => 'Wireless Headphones',
        'description' => 'High-quality wireless headphones with noise cancellation',
        'price' => 99.99,
        'compare_price' => 149.99,
        'stock_quantity' => 50,
    ]);
    $product1->set_metadata([
        'color' => 'black',
        'weight' => '250g',
        'warranty' => '2 years',
    ])->save();

    $product2 = ProductRow::create([
        'sku' => 'PROD-002',
        'name' => 'USB-C Cable',
        'description' => 'Fast charging USB-C cable, 2 meters',
        'price' => 19.99,
        'stock_quantity' => 200,
    ]);

    echo "   ✓ Created products: {$product1['name']}, {$product2['name']}\n\n";

    // ============================================
    // Query examples (portable queries)
    // ============================================

    echo "4. Query examples (all portable)...\n";

    // Find active admins
    $admins = UserRow::find_all([
        'where' => eq($schema->get_table('users')->role, UserRow::ROLE_ADMIN),
    ]);
    echo "   Admin users: " . count($admins) . "\n";

    // Find published posts
    $published_posts = PostRow::find_all([
        'where' => eq($schema->get_table('posts')->status, PostRow::STATUS_PUBLISHED),
        'order_by' => desc($schema->get_table('posts')->published_at),
    ]);
    echo "   Published posts: " . count($published_posts) . "\n";

    // Find products on sale
    $on_sale = array_filter(ProductRow::find_all(), function ($p) {
        return $p->is_on_sale();
    });
    echo "   Products on sale: " . count($on_sale) . "\n";

    foreach ($on_sale as $product) {
        echo "     - {$product['name']}: {$product->formatted_price()} ";
        echo "(was \${$product['compare_price']}, {$product->discount_percentage()}% off)\n";
    }

    // ============================================
    // Demonstrate JSON handling
    // ============================================

    echo "\n5. JSON handling (stored as TEXT)...\n";

    $found_admin = UserRow::find($admin['id']);
    echo "   User settings: " . json_encode($found_admin->get_settings()) . "\n";
    echo "   Theme: " . $found_admin->get_setting('theme', 'light') . "\n";

    $found_product = ProductRow::find($product1['id']);
    echo "   Product metadata: " . json_encode($found_product->get_metadata()) . "\n";

    // ============================================
    // Demonstrate date handling
    // ============================================

    echo "\n6. Date handling (formatted in PHP)...\n";

    $found_post = PostRow::find($post2['id']);
    echo "   Post published: " . ($found_post->published_date() ?? 'Not published') . "\n";
    echo "   Is published: " . ($found_post->is_published() ? 'Yes' : 'No') . "\n";

    // ============================================
    // Soft delete
    // ============================================

    echo "\n7. Soft delete...\n";

    $post1->soft_delete();
    echo "   Post '{$post1['title']}' soft deleted\n";
    echo "   Is deleted: " . ($post1->is_deleted() ? 'Yes' : 'No') . "\n";

    $post1->restore();
    echo "   Post restored\n";
    echo "   Is deleted: " . ($post1->is_deleted() ? 'Yes' : 'No') . "\n";

    // ============================================
    // Cleanup
    // ============================================

    echo "\n8. Cleanup...\n";
    $db->drop_tables(...array_reverse($tables_to_create));
    echo "   ✓ Tables dropped\n";

    echo "\n=== Example completed successfully on $dialect ===\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
