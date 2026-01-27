<?php
/**
 * Italix ORM - Schema.org Multi-Author Relations Example
 *
 * Demonstrates the cleanest pattern for:
 * - Multiple polymorphic authors (Person or Organization)
 * - Multiple polymorphic creators
 * - Efficient querying with proper indexing
 * - Scalable to thousands of rows
 *
 * Pattern: Polymorphic Junction Table with Roles
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, timestamp};
use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Operators\{eq, desc, and_};

// ============================================
// 1. Schema Design for Multi-Author Support
// ============================================

/*
 * KEY INSIGHT: Instead of storing author_type/author_id directly on CreativeWork,
 * we use a junction table that supports:
 *   - Multiple contributors per work
 *   - Different roles (author, creator, editor, translator, etc.)
 *   - Polymorphic targets (Person or Organization)
 *   - Efficient indexing for both directions of the relationship
 *
 * This follows the "Polymorphic Many-to-Many" pattern.
 */

// Thing -> Person
$persons = sqlite_table('persons', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'email' => varchar(255),
    'url' => varchar(500),
]);

// Thing -> Organization
$organizations = sqlite_table('organizations', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'url' => varchar(500),
]);

// Thing -> CreativeWork (base for Article, Book, etc.)
$creative_works = sqlite_table('creative_works', [
    'id' => integer()->primary_key()->auto_increment(),
    'type' => varchar(50)->not_null(),           // 'article', 'book', 'video', etc.
    'name' => varchar(255)->not_null(),
    'description' => text(),
    'date_published' => timestamp(),
    'date_created' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

/*
 * POLYMORPHIC JUNCTION TABLE: creative_work_contributors
 *
 * This is the key table that enables:
 *   - Multiple authors/creators per work
 *   - Different contributor roles
 *   - Polymorphic contributors (Person or Organization)
 *
 * Indexes for performance:
 *   - (work_id, role) - Find all authors/creators of a work
 *   - (contributor_type, contributor_id) - Find all works by a contributor
 *   - (contributor_type, contributor_id, role) - Find works where contributor has specific role
 */
$creative_work_contributors = sqlite_table('creative_work_contributors', [
    'id' => integer()->primary_key()->auto_increment(),
    'work_id' => integer()->not_null(),
    'contributor_type' => varchar(50)->not_null(),  // 'person' or 'organization'
    'contributor_id' => integer()->not_null(),
    'role' => varchar(50)->not_null(),              // 'author', 'creator', 'editor', 'translator'
    'position' => integer()->default(0),             // For ordering (first author, second author, etc.)
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// ============================================
// 2. Define Relations
// ============================================

// Person relations
$persons_relations = define_relations($persons, function($r) use ($persons, $creative_works, $creative_work_contributors) {
    return [
        // All works where this person is a contributor (any role)
        'contributed_works' => $r->many($creative_works, [
            'fields' => [$persons->id],
            'through' => $creative_work_contributors,
            'through_fields' => [$creative_work_contributors->contributor_id],
            'target_fields' => [$creative_work_contributors->work_id],
            'target_references' => [$creative_works->id],
        ]),

        // Direct access to the junction records (for role filtering)
        'contributions' => $r->many_polymorphic($creative_work_contributors, [
            'type_column' => $creative_work_contributors->contributor_type,
            'id_column' => $creative_work_contributors->contributor_id,
            'type_value' => 'person',
            'references' => [$persons->id],
        ]),
    ];
});

// Organization relations
$organizations_relations = define_relations($organizations, function($r) use ($organizations, $creative_works, $creative_work_contributors) {
    return [
        // All works where this organization is a contributor
        'contributed_works' => $r->many($creative_works, [
            'fields' => [$organizations->id],
            'through' => $creative_work_contributors,
            'through_fields' => [$creative_work_contributors->contributor_id],
            'target_fields' => [$creative_work_contributors->work_id],
            'target_references' => [$creative_works->id],
        ]),

        // Direct access to contributions
        'contributions' => $r->many_polymorphic($creative_work_contributors, [
            'type_column' => $creative_work_contributors->contributor_type,
            'id_column' => $creative_work_contributors->contributor_id,
            'type_value' => 'organization',
            'references' => [$organizations->id],
        ]),
    ];
});

// CreativeWork relations
$creative_works_relations = define_relations($creative_works, function($r) use ($creative_works, $creative_work_contributors) {
    return [
        // All contributor records for this work
        'contributor_records' => $r->many($creative_work_contributors, [
            'fields' => [$creative_works->id],
            'references' => [$creative_work_contributors->work_id],
        ]),
    ];
});

// Contributor junction relations (for navigating to the actual Person/Organization)
$contributors_relations = define_relations($creative_work_contributors, function($r) use ($creative_work_contributors, $persons, $organizations, $creative_works) {
    return [
        // The contributor (Person or Organization)
        'contributor' => $r->one_polymorphic([
            'type_column' => $creative_work_contributors->contributor_type,
            'id_column' => $creative_work_contributors->contributor_id,
            'targets' => [
                'person' => $persons,
                'organization' => $organizations,
            ],
        ]),

        // The work this contribution is for
        'work' => $r->one($creative_works, [
            'fields' => [$creative_work_contributors->work_id],
            'references' => [$creative_works->id],
        ]),
    ];
});

// ============================================
// 3. Create Database with Indexes
// ============================================

$db = sqlite_memory();
$db->create_tables($persons, $organizations, $creative_works, $creative_work_contributors);

// Create indexes for performance (critical for thousands of rows)
$db->sql('CREATE INDEX idx_contributors_work_role ON creative_work_contributors(work_id, role)')->execute();
$db->sql('CREATE INDEX idx_contributors_type_id ON creative_work_contributors(contributor_type, contributor_id)')->execute();
$db->sql('CREATE INDEX idx_contributors_type_id_role ON creative_work_contributors(contributor_type, contributor_id, role)')->execute();
$db->sql('CREATE INDEX idx_works_type ON creative_works(type)')->execute();
$db->sql('CREATE INDEX idx_works_date ON creative_works(date_published)')->execute();

// ============================================
// 4. Seed Sample Data
// ============================================

// Persons
$db->insert($persons)->values([
    ['name' => 'John Doe', 'email' => 'john@example.com'],
    ['name' => 'Jane Smith', 'email' => 'jane@example.com'],
    ['name' => 'Bob Wilson', 'email' => 'bob@example.com'],
    ['name' => 'Alice Brown', 'email' => 'alice@example.com'],
])->execute();

// Organizations
$db->insert($organizations)->values([
    ['name' => 'TechCorp', 'url' => 'https://techcorp.example.com'],
    ['name' => 'Research Institute', 'url' => 'https://research.example.com'],
    ['name' => 'OpenSource Foundation', 'url' => 'https://opensource.example.org'],
])->execute();

// CreativeWorks
$db->insert($creative_works)->values([
    ['type' => 'article', 'name' => 'Introduction to Machine Learning', 'description' => 'A comprehensive guide...', 'date_published' => '2024-01-15 10:00:00'],
    ['type' => 'article', 'name' => 'Advanced PHP Patterns', 'description' => 'Design patterns in PHP...', 'date_published' => '2024-02-20 14:00:00'],
    ['type' => 'book', 'name' => 'The Complete Guide to APIs', 'description' => 'Everything about APIs...', 'date_published' => '2024-03-01 09:00:00'],
    ['type' => 'video', 'name' => 'Database Design Tutorial', 'description' => 'Learn database design...', 'date_published' => '2024-03-15 16:00:00'],
])->execute();

// Contributors (multiple authors/creators per work)
$db->insert($creative_work_contributors)->values([
    // Article 1: Two person authors + one organization creator
    ['work_id' => 1, 'contributor_type' => 'person', 'contributor_id' => 1, 'role' => 'author', 'position' => 1],
    ['work_id' => 1, 'contributor_type' => 'person', 'contributor_id' => 2, 'role' => 'author', 'position' => 2],
    ['work_id' => 1, 'contributor_type' => 'organization', 'contributor_id' => 2, 'role' => 'creator', 'position' => 1],

    // Article 2: One person author + one organization author
    ['work_id' => 2, 'contributor_type' => 'person', 'contributor_id' => 3, 'role' => 'author', 'position' => 1],
    ['work_id' => 2, 'contributor_type' => 'organization', 'contributor_id' => 1, 'role' => 'author', 'position' => 2],

    // Book: Three person authors + organization creator + editor
    ['work_id' => 3, 'contributor_type' => 'person', 'contributor_id' => 1, 'role' => 'author', 'position' => 1],
    ['work_id' => 3, 'contributor_type' => 'person', 'contributor_id' => 2, 'role' => 'author', 'position' => 2],
    ['work_id' => 3, 'contributor_type' => 'person', 'contributor_id' => 4, 'role' => 'author', 'position' => 3],
    ['work_id' => 3, 'contributor_type' => 'organization', 'contributor_id' => 3, 'role' => 'creator', 'position' => 1],
    ['work_id' => 3, 'contributor_type' => 'person', 'contributor_id' => 3, 'role' => 'editor', 'position' => 1],

    // Video: Organization author + person creator
    ['work_id' => 4, 'contributor_type' => 'organization', 'contributor_id' => 1, 'role' => 'author', 'position' => 1],
    ['work_id' => 4, 'contributor_type' => 'person', 'contributor_id' => 4, 'role' => 'creator', 'position' => 1],
])->execute();

// ============================================
// 5. Query Examples
// ============================================

echo "=== Schema.org Multi-Author Relations Example ===\n\n";

// ----------------------------------------
// Example 1: Get a work with all its contributors
// ----------------------------------------
echo "1. CreativeWork with all contributors:\n";
$work = $db->query_table($creative_works)
    ->with([
        'contributor_records' => [
            'with' => ['contributor' => true],
            'order_by' => [$creative_work_contributors->role, $creative_work_contributors->position],
        ]
    ])
    ->where(eq($creative_works->id, 3))
    ->find_first();

echo "   Work: \"{$work['name']}\" ({$work['type']})\n";
echo "   Contributors:\n";

// Group by role for display
$by_role = [];
foreach ($work['contributor_records'] as $record) {
    $role = $record['role'];
    if (!isset($by_role[$role])) {
        $by_role[$role] = [];
    }
    $by_role[$role][] = $record;
}

foreach ($by_role as $role => $contributors) {
    echo "   - " . ucfirst($role) . "s:\n";
    foreach ($contributors as $c) {
        $type = ucfirst($c['contributor_type']);
        $name = $c['contributor']['name'];
        echo "     * {$name} ({$type})\n";
    }
}
echo "\n";

// ----------------------------------------
// Example 2: Get all works by a specific person
// ----------------------------------------
echo "2. All works by John Doe:\n";
$person = $db->query_table($persons)
    ->with([
        'contributions' => [
            'with' => ['work' => true]
        ]
    ])
    ->where(eq($persons->name, 'John Doe'))
    ->find_first();

echo "   Person: {$person['name']}\n";
foreach ($person['contributions'] as $contribution) {
    echo "   - \"{$contribution['work']['name']}\" as {$contribution['role']}\n";
}
echo "\n";

// ----------------------------------------
// Example 3: Get all works by an organization
// ----------------------------------------
echo "3. All works by TechCorp:\n";
$org = $db->query_table($organizations)
    ->with([
        'contributions' => [
            'with' => ['work' => true]
        ]
    ])
    ->where(eq($organizations->name, 'TechCorp'))
    ->find_first();

echo "   Organization: {$org['name']}\n";
foreach ($org['contributions'] as $contribution) {
    echo "   - \"{$contribution['work']['name']}\" as {$contribution['role']}\n";
}
echo "\n";

// ----------------------------------------
// Example 4: Filter by role - Get only authors
// ----------------------------------------
echo "4. All authors of 'The Complete Guide to APIs':\n";
$work_authors = $db->query_table($creative_works)
    ->with([
        'contributor_records' => [
            'with' => ['contributor' => true],
            'where' => eq($creative_work_contributors->role, 'author'),
            'order_by' => [$creative_work_contributors->position],
        ]
    ])
    ->where(eq($creative_works->id, 3))
    ->find_first();

echo "   Work: \"{$work_authors['name']}\"\n";
echo "   Authors (in order):\n";
foreach ($work_authors['contributor_records'] as $i => $record) {
    $pos = $i + 1;
    $type = ucfirst($record['contributor_type']);
    echo "     {$pos}. {$record['contributor']['name']} ({$type})\n";
}
echo "\n";

// ----------------------------------------
// Example 5: Efficient batch loading for lists
// ----------------------------------------
echo "5. All works with authors (batch loaded):\n";
$all_works = $db->query_table($creative_works)
    ->with([
        'contributor_records' => [
            'with' => ['contributor' => true],
            'where' => eq($creative_work_contributors->role, 'author'),
            'order_by' => [$creative_work_contributors->position],
        ]
    ])
    ->order_by(desc($creative_works->date_published))
    ->find_many();

foreach ($all_works as $work) {
    $author_names = array_map(
        fn($r) => $r['contributor']['name'],
        $work['contributor_records']
    );
    $authors_str = implode(', ', $author_names);
    echo "   - \"{$work['name']}\" by {$authors_str}\n";
}
echo "\n";

// ----------------------------------------
// Example 6: Helper function for cleaner API
// ----------------------------------------
echo "6. Using helper functions for cleaner code:\n";

/**
 * Helper: Get authors of a work (returns array of contributor data)
 */
function get_work_authors(array $work): array {
    return array_filter(
        $work['contributor_records'] ?? [],
        fn($r) => $r['role'] === 'author'
    );
}

/**
 * Helper: Get creators of a work
 */
function get_work_creators(array $work): array {
    return array_filter(
        $work['contributor_records'] ?? [],
        fn($r) => $r['role'] === 'creator'
    );
}

/**
 * Helper: Format author names for display
 */
function format_authors(array $work): string {
    $authors = get_work_authors($work);
    $names = array_map(fn($r) => $r['contributor']['name'], $authors);

    if (count($names) === 0) return 'Unknown';
    if (count($names) === 1) return $names[0];
    if (count($names) === 2) return $names[0] . ' and ' . $names[1];

    $last = array_pop($names);
    return implode(', ', $names) . ', and ' . $last;
}

// Use the helpers
$work = $db->query_table($creative_works)
    ->with([
        'contributor_records' => [
            'with' => ['contributor' => true],
        ]
    ])
    ->where(eq($creative_works->id, 3))
    ->find_first();

echo "   \"{$work['name']}\"\n";
echo "   Authors: " . format_authors($work) . "\n";
echo "   Creators: " . count(get_work_creators($work)) . "\n";
echo "\n";

// ============================================
// 6. Performance Considerations
// ============================================

echo "=== Performance Notes ===\n\n";
echo "For thousands of rows, this pattern is efficient because:\n\n";
echo "1. INDEXES: We created indexes on:\n";
echo "   - (work_id, role) - Fast lookup of authors/creators by work\n";
echo "   - (contributor_type, contributor_id) - Fast lookup of works by contributor\n";
echo "   - (contributor_type, contributor_id, role) - Fast filtered lookup\n\n";
echo "2. BATCH LOADING: The 'with' clause loads all relations in 2-3 queries total,\n";
echo "   not N+1 queries. Even with 1000 works, it's still just a few queries.\n\n";
echo "3. SELECTIVE LOADING: Use 'columns' to load only needed fields:\n";
echo "   ->with(['contributor_records' => ['columns' => ['role', 'position']]])\n\n";
echo "4. PAGINATION: Use limit/offset for large result sets:\n";
echo "   ->limit(20)->offset(40) for page 3 of 20 items\n\n";

echo "=== Example Complete ===\n";
