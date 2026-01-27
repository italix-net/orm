<?php
/**
 * Italix ORM - Schema.org Inspired Relations Example
 *
 * This example demonstrates polymorphic relations using schema.org patterns:
 * - CreativeWork with polymorphic author (Person or Organization)
 * - Review with polymorphic author and itemReviewed
 * - Comment with polymorphic author
 * - MediaObject attached to any content type
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use function Italix\Orm\sqlite_memory;
use function Italix\Orm\Schema\{sqlite_table, integer, varchar, text, timestamp, boolean, decimal};
use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Operators\{eq, desc, gte};

// ============================================
// 1. Define Schema.org-Inspired Tables
// ============================================

// Thing -> Person
$persons = sqlite_table('persons', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'email' => varchar(255),
    'job_title' => varchar(100),
    'url' => varchar(500),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> Organization
$organizations = sqlite_table('organizations', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'legal_name' => varchar(255),
    'url' => varchar(500),
    'founding_date' => varchar(10), // YYYY-MM-DD
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> CreativeWork -> Article
$articles = sqlite_table('articles', [
    'id' => integer()->primary_key()->auto_increment(),
    'headline' => varchar(255)->not_null(),
    'article_body' => text(),
    'word_count' => integer(),
    'author_type' => varchar(50)->not_null(),    // 'person' or 'organization'
    'author_id' => integer()->not_null(),
    'date_published' => timestamp(),
    'date_modified' => timestamp(),
    'is_accessible_for_free' => boolean()->default(true),
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> CreativeWork -> Book
$books = sqlite_table('books', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'isbn' => varchar(20),
    'number_of_pages' => integer(),
    'author_type' => varchar(50)->not_null(),
    'author_id' => integer()->not_null(),
    'publisher_id' => integer(),                  // FK to organizations
    'date_published' => varchar(10),
    'book_format' => varchar(50),                 // 'Hardcover', 'Paperback', 'EBook'
    'created_at' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> CreativeWork -> Review
// itemReviewed can be Article, Book, Organization, etc.
$reviews = sqlite_table('reviews', [
    'id' => integer()->primary_key()->auto_increment(),
    'review_body' => text()->not_null(),
    'review_rating' => decimal(2, 1),             // 1.0 - 5.0
    'author_type' => varchar(50)->not_null(),    // 'person' or 'organization'
    'author_id' => integer()->not_null(),
    'item_reviewed_type' => varchar(50)->not_null(), // 'article', 'book', 'organization'
    'item_reviewed_id' => integer()->not_null(),
    'date_published' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> CreativeWork -> Comment
// Can be on Article, Book, Review, etc.
$comments = sqlite_table('comments', [
    'id' => integer()->primary_key()->auto_increment(),
    'text' => text()->not_null(),
    'author_type' => varchar(50)->not_null(),
    'author_id' => integer()->not_null(),
    'about_type' => varchar(50)->not_null(),      // 'article', 'book', 'review'
    'about_id' => integer()->not_null(),
    'parent_comment_id' => integer(),             // For nested comments
    'date_created' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> MediaObject (polymorphic attachment)
$media_objects = sqlite_table('media_objects', [
    'id' => integer()->primary_key()->auto_increment(),
    'content_url' => varchar(500)->not_null(),
    'encoding_format' => varchar(50),             // 'image/jpeg', 'video/mp4', etc.
    'name' => varchar(255),
    'description' => text(),
    'content_size' => integer(),                  // bytes
    'associated_type' => varchar(50)->not_null(), // Any content type
    'associated_id' => integer()->not_null(),
    'upload_date' => timestamp()->default('CURRENT_TIMESTAMP'),
]);

// Thing -> CreativeWork -> WebPage (for categorization)
$web_pages = sqlite_table('web_pages', [
    'id' => integer()->primary_key()->auto_increment(),
    'name' => varchar(255)->not_null(),
    'url' => varchar(500)->not_null(),
    'description' => text(),
]);

// Junction: Article <-> WebPage (article can appear on multiple pages)
$article_pages = sqlite_table('article_pages', [
    'article_id' => integer()->not_null(),
    'web_page_id' => integer()->not_null(),
    'position' => integer()->default(0),          // Order on page
]);

// ============================================
// 2. Define Relations (Schema.org patterns)
// ============================================

// Person relations
$persons_relations = define_relations($persons, function($r) use ($persons, $articles, $books, $reviews, $comments) {
    return [
        // Polymorphic has-many: Person is author of many Articles
        'authored_articles' => $r->many_polymorphic($articles, [
            'type_column' => $articles->author_type,
            'id_column' => $articles->author_id,
            'type_value' => 'person',
            'references' => [$persons->id],
        ]),

        // Polymorphic has-many: Person is author of many Books
        'authored_books' => $r->many_polymorphic($books, [
            'type_column' => $books->author_type,
            'id_column' => $books->author_id,
            'type_value' => 'person',
            'references' => [$persons->id],
        ]),

        // Polymorphic has-many: Person has many Reviews
        'reviews' => $r->many_polymorphic($reviews, [
            'type_column' => $reviews->author_type,
            'id_column' => $reviews->author_id,
            'type_value' => 'person',
            'references' => [$persons->id],
        ]),

        // Polymorphic has-many: Person has many Comments
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->author_type,
            'id_column' => $comments->author_id,
            'type_value' => 'person',
            'references' => [$persons->id],
        ]),
    ];
});

// Organization relations
$organizations_relations = define_relations($organizations, function($r) use ($organizations, $articles, $books, $reviews, $media_objects) {
    return [
        // Polymorphic has-many: Organization is author of many Articles
        'authored_articles' => $r->many_polymorphic($articles, [
            'type_column' => $articles->author_type,
            'id_column' => $articles->author_id,
            'type_value' => 'organization',
            'references' => [$organizations->id],
        ]),

        // Polymorphic has-many: Organization is author of many Books
        'authored_books' => $r->many_polymorphic($books, [
            'type_column' => $books->author_type,
            'id_column' => $books->author_id,
            'type_value' => 'organization',
            'references' => [$organizations->id],
        ]),

        // One-to-many: Organization publishes many Books
        'published_books' => $r->many($books, [
            'fields' => [$organizations->id],
            'references' => [$books->publisher_id],
        ]),

        // Polymorphic has-many: Organization has many Reviews (as reviewer)
        'reviews' => $r->many_polymorphic($reviews, [
            'type_column' => $reviews->author_type,
            'id_column' => $reviews->author_id,
            'type_value' => 'organization',
            'references' => [$organizations->id],
        ]),

        // Polymorphic has-many: Reviews about this Organization
        'received_reviews' => $r->many_polymorphic($reviews, [
            'type_column' => $reviews->item_reviewed_type,
            'id_column' => $reviews->item_reviewed_id,
            'type_value' => 'organization',
            'references' => [$organizations->id],
        ]),

        // Polymorphic has-many: Media attached to Organization
        'media' => $r->many_polymorphic($media_objects, [
            'type_column' => $media_objects->associated_type,
            'id_column' => $media_objects->associated_id,
            'type_value' => 'organization',
            'references' => [$organizations->id],
        ]),
    ];
});

// Article relations
$articles_relations = define_relations($articles, function($r) use ($articles, $persons, $organizations, $reviews, $comments, $media_objects, $web_pages, $article_pages) {
    return [
        // Polymorphic belongs-to: Article has author (Person or Organization)
        'author' => $r->one_polymorphic([
            'type_column' => $articles->author_type,
            'id_column' => $articles->author_id,
            'targets' => [
                'person' => $persons,
                'organization' => $organizations,
            ],
        ]),

        // Polymorphic has-many: Article has many Reviews
        'reviews' => $r->many_polymorphic($reviews, [
            'type_column' => $reviews->item_reviewed_type,
            'id_column' => $reviews->item_reviewed_id,
            'type_value' => 'article',
            'references' => [$articles->id],
        ]),

        // Polymorphic has-many: Article has many Comments
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->about_type,
            'id_column' => $comments->about_id,
            'type_value' => 'article',
            'references' => [$articles->id],
        ]),

        // Polymorphic has-many: Article has many MediaObjects
        'media' => $r->many_polymorphic($media_objects, [
            'type_column' => $media_objects->associated_type,
            'id_column' => $media_objects->associated_id,
            'type_value' => 'article',
            'references' => [$articles->id],
        ]),

        // Many-to-many: Article appears on WebPages
        'web_pages' => $r->many($web_pages, [
            'fields' => [$articles->id],
            'through' => $article_pages,
            'through_fields' => [$article_pages->article_id],
            'target_fields' => [$article_pages->web_page_id],
            'target_references' => [$web_pages->id],
        ]),
    ];
});

// Book relations
$books_relations = define_relations($books, function($r) use ($books, $persons, $organizations, $reviews, $comments, $media_objects) {
    return [
        // Polymorphic belongs-to: Book has author (Person or Organization)
        'author' => $r->one_polymorphic([
            'type_column' => $books->author_type,
            'id_column' => $books->author_id,
            'targets' => [
                'person' => $persons,
                'organization' => $organizations,
            ],
        ]),

        // Many-to-one: Book has publisher (Organization)
        'publisher' => $r->one($organizations, [
            'fields' => [$books->publisher_id],
            'references' => [$organizations->id],
        ]),

        // Polymorphic has-many: Book has many Reviews
        'reviews' => $r->many_polymorphic($reviews, [
            'type_column' => $reviews->item_reviewed_type,
            'id_column' => $reviews->item_reviewed_id,
            'type_value' => 'book',
            'references' => [$books->id],
        ]),

        // Polymorphic has-many: Book has many Comments
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->about_type,
            'id_column' => $comments->about_id,
            'type_value' => 'book',
            'references' => [$books->id],
        ]),

        // Polymorphic has-many: Book has many MediaObjects (cover, etc.)
        'media' => $r->many_polymorphic($media_objects, [
            'type_column' => $media_objects->associated_type,
            'id_column' => $media_objects->associated_id,
            'type_value' => 'book',
            'references' => [$books->id],
        ]),
    ];
});

// Review relations
$reviews_relations = define_relations($reviews, function($r) use ($reviews, $persons, $organizations, $articles, $books, $comments) {
    return [
        // Polymorphic belongs-to: Review has author (Person or Organization)
        'author' => $r->one_polymorphic([
            'type_column' => $reviews->author_type,
            'id_column' => $reviews->author_id,
            'targets' => [
                'person' => $persons,
                'organization' => $organizations,
            ],
        ]),

        // Polymorphic belongs-to: Review reviews an item (Article, Book, Organization)
        'item_reviewed' => $r->one_polymorphic([
            'type_column' => $reviews->item_reviewed_type,
            'id_column' => $reviews->item_reviewed_id,
            'targets' => [
                'article' => $articles,
                'book' => $books,
                'organization' => $organizations,
            ],
        ]),

        // Polymorphic has-many: Review has many Comments
        'comments' => $r->many_polymorphic($comments, [
            'type_column' => $comments->about_type,
            'id_column' => $comments->about_id,
            'type_value' => 'review',
            'references' => [$reviews->id],
        ]),
    ];
});

// Comment relations
$comments_relations = define_relations($comments, function($r) use ($comments, $persons, $organizations, $articles, $books, $reviews) {
    return [
        // Polymorphic belongs-to: Comment has author (Person or Organization)
        'author' => $r->one_polymorphic([
            'type_column' => $comments->author_type,
            'id_column' => $comments->author_id,
            'targets' => [
                'person' => $persons,
                'organization' => $organizations,
            ],
        ]),

        // Polymorphic belongs-to: Comment is about something (Article, Book, Review)
        'about' => $r->one_polymorphic([
            'type_column' => $comments->about_type,
            'id_column' => $comments->about_id,
            'targets' => [
                'article' => $articles,
                'book' => $books,
                'review' => $reviews,
            ],
        ]),

        // Self-referential: Comment has parent comment (for nested comments)
        'parent' => $r->one($comments, [
            'fields' => [$comments->parent_comment_id],
            'references' => [$comments->id],
        ]),

        // Self-referential: Comment has replies
        'replies' => $r->many($comments, [
            'fields' => [$comments->id],
            'references' => [$comments->parent_comment_id],
        ]),
    ];
});

// ============================================
// 3. Create Database and Seed Data
// ============================================

$db = sqlite_memory();
$db->create_tables(
    $persons,
    $organizations,
    $articles,
    $books,
    $reviews,
    $comments,
    $media_objects,
    $web_pages,
    $article_pages
);

// Seed Persons
$db->insert($persons)->values([
    ['name' => 'John Doe', 'email' => 'john@example.com', 'job_title' => 'Software Engineer'],
    ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'job_title' => 'Technical Writer'],
    ['name' => 'Bob Wilson', 'email' => 'bob@example.com', 'job_title' => 'Reviewer'],
])->execute();

// Seed Organizations
$db->insert($organizations)->values([
    ['name' => 'TechCorp', 'legal_name' => 'TechCorp Inc.', 'url' => 'https://techcorp.example.com', 'founding_date' => '2010-01-15'],
    ['name' => 'O\'Reilly Media', 'legal_name' => 'O\'Reilly Media, Inc.', 'url' => 'https://oreilly.com', 'founding_date' => '1978-01-01'],
    ['name' => 'Acme Reviews', 'legal_name' => 'Acme Reviews LLC', 'url' => 'https://acmereviews.example.com', 'founding_date' => '2015-06-01'],
])->execute();

// Seed Articles (mix of person and organization authors)
$db->insert($articles)->values([
    ['headline' => 'Introduction to PHP 8', 'article_body' => 'PHP 8 brings many new features...', 'word_count' => 1500, 'author_type' => 'person', 'author_id' => 1, 'date_published' => '2024-01-15 10:00:00', 'is_accessible_for_free' => true],
    ['headline' => 'Building Modern APIs', 'article_body' => 'REST APIs are essential...', 'word_count' => 2000, 'author_type' => 'person', 'author_id' => 2, 'date_published' => '2024-02-20 14:30:00', 'is_accessible_for_free' => true],
    ['headline' => 'TechCorp Annual Report 2024', 'article_body' => 'This year we achieved...', 'word_count' => 5000, 'author_type' => 'organization', 'author_id' => 1, 'date_published' => '2024-03-01 09:00:00', 'is_accessible_for_free' => false],
])->execute();

// Seed Books
$db->insert($books)->values([
    ['name' => 'Learning PHP Design Patterns', 'isbn' => '978-1-234567-89-0', 'number_of_pages' => 350, 'author_type' => 'person', 'author_id' => 1, 'publisher_id' => 2, 'date_published' => '2023-06-15', 'book_format' => 'Paperback'],
    ['name' => 'Official TechCorp Developer Guide', 'isbn' => '978-1-234567-89-1', 'number_of_pages' => 500, 'author_type' => 'organization', 'author_id' => 1, 'publisher_id' => 2, 'date_published' => '2024-01-01', 'book_format' => 'EBook'],
])->execute();

// Seed Reviews (of different item types, by different author types)
$db->insert($reviews)->values([
    // Person reviews an Article
    ['review_body' => 'Excellent introduction to PHP 8!', 'review_rating' => 4.5, 'author_type' => 'person', 'author_id' => 3, 'item_reviewed_type' => 'article', 'item_reviewed_id' => 1],
    // Person reviews a Book
    ['review_body' => 'Comprehensive and well-written.', 'review_rating' => 5.0, 'author_type' => 'person', 'author_id' => 2, 'item_reviewed_type' => 'book', 'item_reviewed_id' => 1],
    // Organization reviews an Organization
    ['review_body' => 'TechCorp provides excellent services.', 'review_rating' => 4.0, 'author_type' => 'organization', 'author_id' => 3, 'item_reviewed_type' => 'organization', 'item_reviewed_id' => 1],
    // Person reviews another Book
    ['review_body' => 'Great developer resource.', 'review_rating' => 4.5, 'author_type' => 'person', 'author_id' => 3, 'item_reviewed_type' => 'book', 'item_reviewed_id' => 2],
])->execute();

// Seed Comments (on different content types, with nested replies)
$db->insert($comments)->values([
    // Comments on Article 1
    ['text' => 'This helped me understand PHP 8!', 'author_type' => 'person', 'author_id' => 2, 'about_type' => 'article', 'about_id' => 1, 'parent_comment_id' => null],
    ['text' => 'Thanks for the great article!', 'author_type' => 'person', 'author_id' => 3, 'about_type' => 'article', 'about_id' => 1, 'parent_comment_id' => null],
    // Reply to first comment
    ['text' => 'Glad it helped!', 'author_type' => 'person', 'author_id' => 1, 'about_type' => 'article', 'about_id' => 1, 'parent_comment_id' => 1],
    // Comments on Book 1
    ['text' => 'Best PHP book I\'ve read!', 'author_type' => 'person', 'author_id' => 3, 'about_type' => 'book', 'about_id' => 1, 'parent_comment_id' => null],
    // Comment on Review
    ['text' => 'I agree with this review.', 'author_type' => 'person', 'author_id' => 2, 'about_type' => 'review', 'about_id' => 1, 'parent_comment_id' => null],
])->execute();

// Seed MediaObjects
$db->insert($media_objects)->values([
    ['content_url' => '/images/php8-article-hero.jpg', 'encoding_format' => 'image/jpeg', 'name' => 'PHP 8 Article Hero Image', 'content_size' => 150000, 'associated_type' => 'article', 'associated_id' => 1],
    ['content_url' => '/images/book-cover-php-patterns.jpg', 'encoding_format' => 'image/jpeg', 'name' => 'Book Cover', 'content_size' => 200000, 'associated_type' => 'book', 'associated_id' => 1],
    ['content_url' => '/images/techcorp-logo.png', 'encoding_format' => 'image/png', 'name' => 'TechCorp Logo', 'content_size' => 50000, 'associated_type' => 'organization', 'associated_id' => 1],
])->execute();

// Seed WebPages and Article-Page relationships
$db->insert($web_pages)->values([
    ['name' => 'Home', 'url' => '/', 'description' => 'Homepage'],
    ['name' => 'PHP Tutorials', 'url' => '/tutorials/php', 'description' => 'PHP tutorial collection'],
    ['name' => 'API Development', 'url' => '/tutorials/api', 'description' => 'API development guides'],
])->execute();

$db->insert($article_pages)->values([
    ['article_id' => 1, 'web_page_id' => 1, 'position' => 1],
    ['article_id' => 1, 'web_page_id' => 2, 'position' => 1],
    ['article_id' => 2, 'web_page_id' => 1, 'position' => 2],
    ['article_id' => 2, 'web_page_id' => 3, 'position' => 1],
])->execute();

// ============================================
// 4. Query Examples
// ============================================

echo "=== Schema.org Relations Example ===\n\n";

// ----------------------------------------
// Example 1: Articles with polymorphic authors
// ----------------------------------------
echo "1. Articles with authors (Person or Organization):\n";
$all_articles = $db->query_table($articles)
    ->with(['author' => true])
    ->order_by(desc($articles->date_published))
    ->find_many();

foreach ($all_articles as $article) {
    $author_type = ucfirst($article['author_type']);
    $author_name = $article['author']['name'];
    echo "   - \"{$article['headline']}\"\n";
    echo "     Author ({$author_type}): {$author_name}\n";
}
echo "\n";

// ----------------------------------------
// Example 2: Books with author, publisher, and reviews
// ----------------------------------------
echo "2. Books with full relations:\n";
$all_books = $db->query_table($books)
    ->with([
        'author' => true,
        'publisher' => true,
        'reviews' => [
            'with' => ['author' => true]
        ],
        'media' => true,
    ])
    ->find_many();

foreach ($all_books as $book) {
    $author_type = ucfirst($book['author_type']);
    echo "   Book: \"{$book['name']}\" (ISBN: {$book['isbn']})\n";
    echo "   - Author ({$author_type}): {$book['author']['name']}\n";
    echo "   - Publisher: {$book['publisher']['name']}\n";
    echo "   - Format: {$book['book_format']}, Pages: {$book['number_of_pages']}\n";
    echo "   - Reviews: " . count($book['reviews']) . "\n";
    foreach ($book['reviews'] as $review) {
        $reviewer_type = ucfirst($review['author_type']);
        echo "     * {$review['review_rating']}/5 by {$review['author']['name']} ({$reviewer_type})\n";
        echo "       \"{$review['review_body']}\"\n";
    }
    echo "   - Media: " . count($book['media']) . " files\n";
    foreach ($book['media'] as $media) {
        echo "     * {$media['name']} ({$media['encoding_format']})\n";
    }
    echo "\n";
}

// ----------------------------------------
// Example 3: Person with all authored content
// ----------------------------------------
echo "3. Person with all their authored content:\n";
$person = $db->query_table($persons)
    ->with([
        'authored_articles' => true,
        'authored_books' => true,
        'reviews' => true,
        'comments' => true,
    ])
    ->where(eq($persons->id, 1))
    ->find_first();

echo "   Person: {$person['name']} ({$person['job_title']})\n";
echo "   - Authored Articles: " . count($person['authored_articles']) . "\n";
foreach ($person['authored_articles'] as $article) {
    echo "     * \"{$article['headline']}\"\n";
}
echo "   - Authored Books: " . count($person['authored_books']) . "\n";
foreach ($person['authored_books'] as $book) {
    echo "     * \"{$book['name']}\"\n";
}
echo "   - Reviews written: " . count($person['reviews']) . "\n";
echo "   - Comments made: " . count($person['comments']) . "\n";
echo "\n";

// ----------------------------------------
// Example 4: Organization with published books and received reviews
// ----------------------------------------
echo "4. Organization profile:\n";
$org = $db->query_table($organizations)
    ->with([
        'authored_articles' => true,
        'published_books' => [
            'with' => ['author' => true]
        ],
        'received_reviews' => [
            'with' => ['author' => true]
        ],
        'media' => true,
    ])
    ->where(eq($organizations->name, 'TechCorp'))
    ->find_first();

echo "   Organization: {$org['name']} ({$org['legal_name']})\n";
echo "   Founded: {$org['founding_date']}\n";
echo "   URL: {$org['url']}\n";
echo "   - Authored Articles: " . count($org['authored_articles']) . "\n";
echo "   - Published Books: " . count($org['published_books']) . "\n";
foreach ($org['published_books'] as $book) {
    $author_type = ucfirst($book['author_type']);
    echo "     * \"{$book['name']}\" by {$book['author']['name']} ({$author_type})\n";
}
echo "   - Reviews received: " . count($org['received_reviews']) . "\n";
foreach ($org['received_reviews'] as $review) {
    echo "     * {$review['review_rating']}/5 - \"{$review['review_body']}\"\n";
}
echo "   - Media: " . count($org['media']) . " files\n";
echo "\n";

// ----------------------------------------
// Example 5: Reviews with polymorphic itemReviewed
// ----------------------------------------
echo "5. Reviews with what they review:\n";
$all_reviews = $db->query_table($reviews)
    ->with([
        'author' => true,
        'item_reviewed' => true,
    ])
    ->where(gte($reviews->review_rating, 4.0))
    ->order_by(desc($reviews->review_rating))
    ->find_many();

foreach ($all_reviews as $review) {
    $author_type = ucfirst($review['author_type']);
    $item_type = ucfirst($review['item_reviewed_type']);
    $item_name = $review['item_reviewed']['name'] ?? $review['item_reviewed']['headline'] ?? 'Unknown';

    echo "   Rating: {$review['review_rating']}/5\n";
    echo "   - By: {$review['author']['name']} ({$author_type})\n";
    echo "   - Reviewing ({$item_type}): \"{$item_name}\"\n";
    echo "   - \"{$review['review_body']}\"\n\n";
}

// ----------------------------------------
// Example 6: Article with comments (including nested)
// ----------------------------------------
echo "6. Article with comments and replies:\n";
$article_with_comments = $db->query_table($articles)
    ->with([
        'author' => true,
        'comments' => [
            'with' => [
                'author' => true,
                'replies' => [
                    'with' => ['author' => true]
                ]
            ]
        ],
        'media' => true,
        'web_pages' => true,
    ])
    ->where(eq($articles->id, 1))
    ->find_first();

echo "   Article: \"{$article_with_comments['headline']}\"\n";
echo "   - Author: {$article_with_comments['author']['name']}\n";
echo "   - Published on pages: ";
$page_names = array_column($article_with_comments['web_pages'], 'name');
echo implode(', ', $page_names) . "\n";
echo "   - Media: " . count($article_with_comments['media']) . " files\n";
echo "   - Comments:\n";

foreach ($article_with_comments['comments'] as $comment) {
    if ($comment['parent_comment_id'] === null) {
        echo "     * {$comment['author']['name']}: \"{$comment['text']}\"\n";
        // Show replies
        foreach ($comment['replies'] as $reply) {
            echo "       └─ {$reply['author']['name']}: \"{$reply['text']}\"\n";
        }
    }
}
echo "\n";

// ----------------------------------------
// Example 7: Using aliases for clearer semantics
// ----------------------------------------
echo "7. Using aliases for semantic clarity:\n";
$org_profile = $db->query_table($organizations)
    ->with([
        'written_content:authored_articles' => true,  // Alias
        'books_we_published:published_books' => true, // Alias
        'customer_feedback:received_reviews' => true, // Alias
    ])
    ->where(eq($organizations->id, 1))
    ->find_first();

echo "   Organization: {$org_profile['name']}\n";
echo "   - Written Content: " . count($org_profile['written_content']) . " articles\n";
echo "   - Books We Published: " . count($org_profile['books_we_published']) . " books\n";
echo "   - Customer Feedback: " . count($org_profile['customer_feedback']) . " reviews\n";
echo "\n";

echo "=== Schema.org Example Complete ===\n";
