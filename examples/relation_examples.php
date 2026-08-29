<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - relations, worked
 *
 * The companion to `worked_examples.php`: that one is about SQL a person could
 * have written by hand, this one is about the thing the builder does *for* you
 * — fetching related rows without an N+1, and attaching them where they belong.
 *
 * Executed against a real database, so what is printed is what happens. The
 * README quotes these.
 *
 * Run: php examples/relation_examples.php
 *
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../../../vendor/autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    require_once __DIR__ . '/../src/autoload.php';
})();

use Italix\Orm\Relations\RelationalQueryBuilder;

use function Italix\Orm\Operators\{desc, eq, gte};
use function Italix\Orm\Relations\define_relations;
use function Italix\Orm\Schema\{decimal, integer, serial, sqlite_table, varchar};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, city TEXT)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, placed_on TEXT, total REAL)');
$pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, product_id INTEGER, qty INTEGER)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
$pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT)');
$pdo->exec('CREATE TABLE product_tags (product_id INTEGER, tag_id INTEGER)');
$pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INTEGER, name TEXT)');
$pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, about_type TEXT, about_id INTEGER, body TEXT)');

$pdo->exec("INSERT INTO customers VALUES (1,'Alice','Rome'),(2,'Bob','Milan'),(3,'Chen','Rome')");
$pdo->exec("INSERT INTO orders VALUES
    (1,1,'2026-08-01',120.0),(2,1,'2026-06-01',80.0),(3,1,'2026-08-12',450.0),(4,2,'2026-08-10',300.0)");
$pdo->exec('INSERT INTO order_items VALUES (1,1,1,2),(2,1,2,1),(3,3,3,1),(4,4,1,5)');
$pdo->exec("INSERT INTO products VALUES (1,'Keyboard',49.0),(2,'Monitor',220.0),(3,'Desk',450.0)");
$pdo->exec("INSERT INTO tags VALUES (1,'input'),(2,'display'),(3,'wooden')");
$pdo->exec('INSERT INTO product_tags VALUES (1,1),(2,2),(3,3)');
$pdo->exec("INSERT INTO categories VALUES (10,NULL,'Electronics'),(11,10,'Peripherals'),(12,11,'Keyboards'),(20,NULL,'Furniture')");
$pdo->exec("INSERT INTO notes VALUES
    (1,'customer',1,'prefers invoices by post'),(2,'customer',1,'VAT number pending'),
    (3,'product',2,'discontinued next year')");

$customers   = sqlite_table('customers', ['id' => serial(), 'name' => varchar(50), 'city' => varchar(50)]);
$orders      = sqlite_table('orders', ['id' => serial(), 'customer_id' => integer(),
                                       'placed_on' => varchar(10), 'total' => decimal(10, 2)]);
$order_items = sqlite_table('order_items', ['id' => serial(), 'order_id' => integer(),
                                            'product_id' => integer(), 'qty' => integer()]);
$products    = sqlite_table('products', ['id' => serial(), 'name' => varchar(50), 'price' => decimal(10, 2)]);
$tags        = sqlite_table('tags', ['id' => serial(), 'label' => varchar(30)]);
$product_tags = sqlite_table('product_tags', ['product_id' => integer(), 'tag_id' => integer()]);
$categories  = sqlite_table('categories', ['id' => serial(), 'parent_id' => integer(), 'name' => varchar(50)]);
$notes       = sqlite_table('notes', ['id' => serial(), 'about_type' => varchar(20),
                                      'about_id' => integer(), 'body' => varchar(100)]);

$registry = [
    'customers' => define_relations($customers, static function ($r) use ($customers, $orders, $notes) {
        return [
            'orders' => $r->many($orders, ['fields' => [$customers->id], 'references' => [$orders->customer_id]]),
            'notes'  => $r->many_polymorphic($notes, [
                'type_column' => $notes->about_type,
                'id_column'   => $notes->about_id,
                'type_value'  => 'customer',
                'references'  => [$customers->id],
            ]),
        ];
    }),
    'orders' => define_relations($orders, static function ($r) use ($customers, $orders, $order_items) {
        return [
            'customer' => $r->one($customers, ['fields' => [$orders->customer_id], 'references' => [$customers->id]]),
            'items'    => $r->many($order_items, ['fields' => [$orders->id], 'references' => [$order_items->order_id]]),
        ];
    }),
    'order_items' => define_relations($order_items, static function ($r) use ($order_items, $products) {
        return [
            'product' => $r->one($products, ['fields' => [$order_items->product_id], 'references' => [$products->id]]),
        ];
    }),
    'products' => define_relations($products, static function ($r) use ($products, $tags, $product_tags) {
        return [
            'tags' => $r->many($tags, [
                'through'           => $product_tags,
                'through_fields'    => [$product_tags->product_id],
                'target_fields'     => [$product_tags->tag_id],
                'fields'            => [$products->id],
                'target_references' => [$tags->id],
            ]),
        ];
    }),
    'categories' => define_relations($categories, static function ($r) use ($categories) {
        return [
            'children' => $r->many($categories, ['fields' => [$categories->id],
                                                 'references' => [$categories->parent_id]]),
            'parent'   => $r->one($categories, ['fields' => [$categories->parent_id],
                                                'references' => [$categories->id]]),
        ];
    }),
];

$q = new RelationalQueryBuilder($pdo, 'sqlite', $registry);

$n = 0;

$show = static function (string $title, string $note, $result, string $code) use (&$n): void {
    printf("\n%d. %s\n   %s\n\n%s\n\n   → %s\n",
        ++$n, $title, $note, $code, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

// -----------------------------------------------------------------------------

$show(
    'A list with its children',
    'One query for the customers, one for all their orders — not one per customer.',
    $q->query($customers)->columns(['name'])
      ->with(['orders' => ['columns' => ['placed_on', 'total']]])->find_many(),
    <<<'PHP'
   $q->query($customers)->columns(['name'])
     ->with(['orders' => ['columns' => ['placed_on', 'total']]])
     ->find_many();
PHP
);

$show(
    'A row with its parent',
    'The other direction. A "one" relation attaches a single row, not a list.',
    $q->query($orders)->columns(['placed_on', 'total'])
      ->with(['customer' => ['columns' => ['name', 'city']]])->find_first(),
    <<<'PHP'
   $q->query($orders)->columns(['placed_on', 'total'])
     ->with(['customer' => ['columns' => ['name', 'city']]])
     ->find_first();
PHP
);

$show(
    'Filtered, ordered, and capped children',
    'where, order_by and limit apply to the children of each parent, not to the parents.',
    $q->query($customers)->columns(['name'])
      ->with(['orders' => [
          'columns'  => ['placed_on', 'total'],
          'where'    => gte($orders->placed_on, '2026-08-01'),
          'order_by' => [desc($orders->total)],
          'limit'    => 1,
      ]])->find_many(),
    <<<'PHP'
   ->with(['orders' => [
       'columns'  => ['placed_on', 'total'],
       'where'    => gte($orders->placed_on, '2026-08-01'),
       'order_by' => [desc($orders->total)],
       'limit'    => 1,          // the biggest August order, per customer
   ]]);
PHP
);

$show(
    'Three levels deep',
    'with() nests. Customers, their orders, the items, and each item’s product.',
    $q->query($customers)->columns(['name'])
      ->with(['orders' => [
          'columns' => ['placed_on'],
          'with'    => ['items' => [
              'columns' => ['qty'],
              'with'    => ['product' => ['columns' => ['name']]],
          ]],
      ]])->find_first(),
    <<<'PHP'
   ->with(['orders' => ['columns' => ['placed_on'],
       'with' => ['items' => ['columns' => ['qty'],
           'with' => ['product' => ['columns' => ['name']]]]]]]);
PHP
);

$show(
    'Many-to-many through a junction table',
    'The junction is declared once, in the relation. Queries never mention it.',
    $q->query($products)->columns(['name'])
      ->with(['tags' => ['columns' => ['label']]])->find_many(),
    <<<'PHP'
   'tags' => $r->many($tags, [
       'through'        => $product_tags,
       'through_fields' => [$product_tags->product_id],
       'target_fields'  => [$product_tags->tag_id],
       // …declared once
   ]);

   $q->query($products)->columns(['name'])->with(['tags' => ['columns' => ['label']]])->find_many();
PHP
);

$show(
    'A table related to itself',
    'No special case: the target of the relation is the same table.',
    $q->query($categories)->columns(['name'])
      ->with(['children' => ['columns' => ['name']], 'parent' => ['columns' => ['name']]])
      ->find_many(),
    <<<'PHP'
   'children' => $r->many($categories, ['fields' => [$categories->id],
                                        'references' => [$categories->parent_id]]),
   'parent'   => $r->one($categories,  ['fields' => [$categories->parent_id],
                                        'references' => [$categories->id]]),
PHP
);

$show(
    'A polymorphic child — notes attached to anything',
    'One table of notes, several kinds of owner, told apart by a type column. Drizzle has no equivalent.',
    $q->query($customers)->columns(['name'])
      ->with(['notes' => ['columns' => ['body']]])->find_many(),
    <<<'PHP'
   'notes' => $r->many_polymorphic($notes, [
       'type_column' => $notes->about_type,
       'id_column'   => $notes->about_id,
       'type_value'  => 'customer',       // only the notes about customers
       'references'  => [$customers->id],
   ]);
PHP
);

$show(
    'Forget the with(), and the key is simply absent',
    'Not null-for-empty: the key is not there at all. There is no lazy loading to fall back on.',
    [
        'with with()'    => $q->query($customers)->columns(['name'])->with(['orders' => ['columns' => ['total']]])->find_first(),
        'without with()' => $q->query($customers)->columns(['name'])->find_first(),
    ],
    <<<'PHP'
   $with    = $q->query($customers)->columns(['name'])->with(['orders' => [...]])->find_first();
   $without = $q->query($customers)->columns(['name'])->find_first();

   array_key_exists('orders', $without);   // false — it was never asked for
PHP
);

$show(
    'A parent with no children gets an empty list',
    'Which is a different answer from "not loaded", and the reason the distinction is worth keeping.',
    $q->query($customers)->columns(['name'])
      ->with(['orders' => ['columns' => ['total'], 'where' => gte($orders->total, 1000)]])->find_many(),
    <<<'PHP'
   ->with(['orders' => ['where' => gte($orders->total, 1000)]]);   // nobody spent that much
PHP
);
