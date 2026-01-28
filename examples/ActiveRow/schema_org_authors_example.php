<?php

/**
 * Schema.org CreativeWork with Polymorphic Authors Example
 *
 * This example demonstrates the ActiveRow system with schema.org-inspired
 * data modeling, specifically CreativeWork with multiple authors that can
 * be either Person or Organization.
 *
 * Key patterns demonstrated:
 * - CanBeAuthor trait for shared author behavior
 * - Polymorphic author wrapping
 * - Getting authors as typed ActiveRow instances
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;
use Italix\Orm\ActiveRow\Traits\CanBeAuthor;

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text};
use function Italix\Orm\Operators\{eq, asc};
use function Italix\Orm\Relations\define_relations;

// ============================================
// ROW CLASSES
// ============================================

/**
 * Person row - can be an author
 */
class PersonRow extends ActiveRow
{
    use Persistable, HasTimestamps, CanBeAuthor;

    /**
     * Display name implementation (required by CanBeAuthor)
     */
    public function display_name(): string
    {
        return trim($this['given_name'] . ' ' . $this['family_name']);
    }

    /**
     * Citation name (e.g., "Doe, J.")
     */
    public function citation_name(): string
    {
        $initial = mb_substr($this['given_name'] ?? '', 0, 1);
        return $this['family_name'] . ', ' . $initial . '.';
    }

    /**
     * Get email domain
     */
    public function email_domain(): ?string
    {
        $email = $this['email'] ?? '';
        if (empty($email)) return null;
        $parts = explode('@', $email);
        return $parts[1] ?? null;
    }

    /**
     * Get ORCID if available
     */
    public function orcid(): ?string
    {
        return $this['orcid'] ?? null;
    }
}

/**
 * Organization row - can be an author
 */
class OrganizationRow extends ActiveRow
{
    use Persistable, HasTimestamps, CanBeAuthor;

    /**
     * Display name implementation (required by CanBeAuthor)
     */
    public function display_name(): string
    {
        return $this['name'];
    }

    /**
     * Citation name (organizations use full name)
     */
    public function citation_name(): string
    {
        return $this['name'];
    }

    /**
     * Get abbreviated name (e.g., "World Health Organization" -> "WHO")
     */
    public function abbreviated_name(): string
    {
        $name = $this['name'];
        $words = explode(' ', $name);

        if (count($words) <= 2) {
            return $name;
        }

        // Create acronym
        $acronym = '';
        foreach ($words as $word) {
            // Skip common words
            if (in_array(strtolower($word), ['of', 'the', 'and', 'for'])) {
                continue;
            }
            $acronym .= strtoupper(mb_substr($word, 0, 1));
        }

        return $acronym;
    }

    /**
     * Check if nonprofit
     */
    public function is_nonprofit(): bool
    {
        return ($this['organization_type'] ?? '') === 'nonprofit';
    }
}

/**
 * CreativeWork row
 */
class CreativeWorkRow extends ActiveRow
{
    use Persistable, HasTimestamps;

    /**
     * Get authors as properly typed ActiveRow instances
     * Handles polymorphic author types (Person or Organization)
     */
    public function authors(): array
    {
        $authorships = $this['authorships'] ?? [];

        if (empty($authorships)) {
            return [];
        }

        $authors = [];
        foreach ($authorships as $authorship) {
            $author = $authorship['author'] ?? null;
            if (!$author) continue;

            $type = $authorship['author_type'] ?? null;

            // Already wrapped?
            if ($author instanceof ActiveRow) {
                $authors[] = $author;
                continue;
            }

            // Wrap in appropriate row class based on type
            if ($type === 'person') {
                $authors[] = PersonRow::wrap($author);
            } elseif ($type === 'organization') {
                $authors[] = OrganizationRow::wrap($author);
            }
        }

        return $authors;
    }

    /**
     * Get primary author (first in order)
     */
    public function primary_author()
    {
        $authors = $this->authors();
        return $authors[0] ?? null;
    }

    /**
     * Get only person authors
     */
    public function person_authors(): array
    {
        return array_filter($this->authors(), fn($a) => $a instanceof PersonRow);
    }

    /**
     * Get only organization authors
     */
    public function organization_authors(): array
    {
        return array_filter($this->authors(), fn($a) => $a instanceof OrganizationRow);
    }

    /**
     * Check if work has institutional author
     */
    public function has_institutional_author(): bool
    {
        return !empty($this->organization_authors());
    }

    /**
     * Format author names for display
     */
    public function author_names(): string
    {
        $authors = $this->authors();

        if (empty($authors)) {
            return 'Unknown';
        }

        $names = array_map(fn($a) => $a->display_name(), $authors);

        if (count($names) === 1) {
            return $names[0];
        }

        if (count($names) === 2) {
            return $names[0] . ' and ' . $names[1];
        }

        $last = array_pop($names);
        return implode(', ', $names) . ', and ' . $last;
    }

    /**
     * Format for academic citation
     */
    public function citation(): string
    {
        $authors = $this->authors();
        $year = date('Y', strtotime($this['date_published'] ?? 'now'));

        $authorParts = array_map(fn($a) => $a->citation_name(), $authors);

        return implode(', ', $authorParts) . ' (' . $year . '). ' . $this['title'] . '.';
    }

    /**
     * Get publication year
     */
    public function year(): int
    {
        return (int) date('Y', strtotime($this['date_published'] ?? 'now'));
    }
}

// ============================================
// HELPER: Factory for wrapping polymorphic results
// ============================================

class AuthorFactory
{
    /**
     * Wrap an author based on type
     */
    public static function wrap(array $data, string $type): ActiveRow
    {
        switch ($type) {
            case 'person':
                return PersonRow::wrap($data);
            case 'organization':
                return OrganizationRow::wrap($data);
            default:
                throw new \InvalidArgumentException("Unknown author type: $type");
        }
    }

    /**
     * Process query results and wrap authors appropriately
     */
    public static function wrap_works_with_authors(array $works): array
    {
        return array_map(function ($work) {
            // Wrap the work
            $workRow = CreativeWorkRow::wrap($work);

            // Process authorships if present
            if (isset($work['authorships'])) {
                $authorships = [];
                foreach ($work['authorships'] as $authorship) {
                    if (isset($authorship['author']) && isset($authorship['author_type'])) {
                        $authorship['author'] = self::wrap(
                            $authorship['author'],
                            $authorship['author_type']
                        );
                    }
                    $authorships[] = $authorship;
                }
                $workRow['authorships'] = $authorships;
            }

            return $workRow;
        }, $works);
    }
}

// ============================================
// EXAMPLE USAGE
// ============================================

echo "=== Schema.org CreativeWork with Polymorphic Authors ===\n\n";

// Create in-memory database
$db = sqlite_memory();

// Define tables (simplified for this example)
$persons = sqlite_table('persons', [
    'id' => integer()->primary_key()->auto_increment(),
    'given_name' => varchar(100)->not_null(),
    'family_name' => varchar(100)->not_null(),
    'email' => varchar(255),
    'orcid' => varchar(50),
    'created_at' => text(),
    'updated_at' => text(),
]);

$organizations = sqlite_table('organizations', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'organization_type' => varchar(50),
    'website' => varchar(255),
    'created_at' => text(),
    'updated_at' => text(),
]);

$creative_works = sqlite_table('creative_works', [
    'id' => integer()->primary_key()->auto_increment(),
    'title' => varchar(500)->not_null(),
    'description' => text(),
    'work_type' => varchar(50),
    'date_published' => text(),
    'created_at' => text(),
    'updated_at' => text(),
]);

$authorships = sqlite_table('authorships', [
    'id' => integer()->primary_key()->auto_increment(),
    'work_id' => integer()->not_null(),
    'author_type' => varchar(50)->not_null(),
    'author_id' => integer()->not_null(),
    'position' => integer()->default(0),
]);

// Create tables
$db->create_tables($persons, $organizations, $creative_works, $authorships);

// Set up persistence
PersonRow::set_persistence($db, $persons);
OrganizationRow::set_persistence($db, $organizations);
CreativeWorkRow::set_persistence($db, $creative_works);

// ============================================
// Create sample data
// ============================================

echo "Creating sample data...\n\n";

// Create persons
$person1 = PersonRow::create([
    'given_name' => 'John',
    'family_name' => 'Smith',
    'email' => 'john.smith@university.edu',
    'orcid' => '0000-0002-1234-5678',
]);

$person2 = PersonRow::create([
    'given_name' => 'Jane',
    'family_name' => 'Doe',
    'email' => 'jane.doe@research.org',
]);

// Create organizations
$org1 = OrganizationRow::create([
    'name' => 'World Health Organization',
    'organization_type' => 'nonprofit',
    'website' => 'https://who.int',
]);

$org2 = OrganizationRow::create([
    'name' => 'National Institute of Health',
    'organization_type' => 'government',
    'website' => 'https://nih.gov',
]);

// Create creative works with authorships
$work1_id = $db->insert($creative_works)->values([
    'title' => 'Climate Change Impact on Global Health',
    'description' => 'A comprehensive study on climate change effects...',
    'work_type' => 'Article',
    'date_published' => '2024-03-15',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
])->execute();
$work1_id = $db->last_insert_id();

// Add authorships for work 1 (person + organization)
$db->insert($authorships)->values([
    'work_id' => $work1_id,
    'author_type' => 'person',
    'author_id' => $person1['id'],
    'position' => 1,
])->execute();

$db->insert($authorships)->values([
    'work_id' => $work1_id,
    'author_type' => 'organization',
    'author_id' => $org1['id'],
    'position' => 2,
])->execute();

$db->insert($authorships)->values([
    'work_id' => $work1_id,
    'author_type' => 'person',
    'author_id' => $person2['id'],
    'position' => 3,
])->execute();

// Create another work
$work2_id = $db->insert($creative_works)->values([
    'title' => 'Modern PHP Design Patterns',
    'description' => 'An exploration of design patterns in PHP 8...',
    'work_type' => 'Book',
    'date_published' => '2024-01-10',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
])->execute();
$work2_id = $db->last_insert_id();

// Work 2 has only person authors
$db->insert($authorships)->values([
    'work_id' => $work2_id,
    'author_type' => 'person',
    'author_id' => $person1['id'],
    'position' => 1,
])->execute();

$db->insert($authorships)->values([
    'work_id' => $work2_id,
    'author_type' => 'person',
    'author_id' => $person2['id'],
    'position' => 2,
])->execute();

// ============================================
// Load works with authors (simulating eager loading)
// ============================================

echo "Loading works with authors...\n\n";

// In a real scenario, you'd use the relations system
// Here we manually join the data to demonstrate the wrapping

$rawWorks = $db->select()->from($creative_works)->execute();

// For each work, load its authorships with author data
$worksWithAuthors = [];
foreach ($rawWorks as $work) {
    $work['authorships'] = [];

    // Get authorships for this work
    $workAuthorships = $db->sql(
        'SELECT * FROM authorships WHERE work_id = ? ORDER BY position',
        [$work['id']]
    )->all();

    foreach ($workAuthorships as $authorship) {
        // Load the author based on type
        if ($authorship['author_type'] === 'person') {
            $author = $db->sql(
                'SELECT * FROM persons WHERE id = ?',
                [$authorship['author_id']]
            )->one();
        } else {
            $author = $db->sql(
                'SELECT * FROM organizations WHERE id = ?',
                [$authorship['author_id']]
            )->one();
        }

        $authorship['author'] = $author;
        $work['authorships'][] = $authorship;
    }

    $worksWithAuthors[] = $work;
}

// Wrap with AuthorFactory
$works = AuthorFactory::wrap_works_with_authors($worksWithAuthors);

// ============================================
// Display works with their authors
// ============================================

echo str_repeat('=', 60) . "\n";

foreach ($works as $work) {
    echo "TITLE: " . $work['title'] . "\n";
    echo "TYPE: " . $work['work_type'] . "\n";
    echo "YEAR: " . $work->year() . "\n";
    echo "AUTHORS: " . $work->author_names() . "\n";

    if ($work->has_institutional_author()) {
        echo "[Has Institutional Author]\n";
    }

    echo "\nCITATION:\n";
    echo "  " . $work->citation() . "\n";

    echo "\nAUTHOR DETAILS:\n";
    foreach ($work->authors() as $i => $author) {
        $num = $i + 1;
        echo "  $num. " . $author->display_name();
        echo " [" . $author->author_type() . "]";

        if ($author instanceof PersonRow) {
            if ($author->email_domain()) {
                echo " - " . $author->email_domain();
            }
            if ($author->orcid()) {
                echo " (ORCID: " . $author->orcid() . ")";
            }
        }

        if ($author instanceof OrganizationRow) {
            echo " (" . $author->abbreviated_name() . ")";
            if ($author->is_nonprofit()) {
                echo " [nonprofit]";
            }
        }

        echo "\n";
    }

    echo "\n" . str_repeat('-', 60) . "\n\n";
}

// ============================================
// Demonstrate CanBeAuthor trait methods
// ============================================

echo "=== CanBeAuthor Trait Methods ===\n\n";

$firstWork = $works[0];
$primaryAuthor = $firstWork->primary_author();

echo "Primary author of '{$firstWork['title']}':\n";
echo "  display_name(): " . $primaryAuthor->display_name() . "\n";
echo "  author_type(): " . $primaryAuthor->author_type() . "\n";
echo "  citation_name(): " . $primaryAuthor->citation_name() . "\n";
echo "  initials(): " . $primaryAuthor->initials() . "\n";
echo "  is_person(): " . ($primaryAuthor->is_person() ? 'Yes' : 'No') . "\n";
echo "  is_organization(): " . ($primaryAuthor->is_organization() ? 'Yes' : 'No') . "\n";
echo "  author_meta(): " . json_encode($primaryAuthor->author_meta()) . "\n";

echo "\n=== Example Complete ===\n";
