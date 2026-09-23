<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Development seed data.
 *
 *   php tools/seed.php          add seed content
 *   php tools/seed.php --fresh  wipe content tables first
 *
 * Content here is representative rather than authoritative — it exists so the
 * layout, the menu density tiers, the search index and the citation rendering
 * can all be exercised against something that looks like the real thing.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Domain\FieldRepository;
use App\Domain\SearchIndex;
use App\Support\PersianText;
use App\Support\Slug;
use App\Support\Toc;

if (!\App\Core\Config::bool('debug') && getenv('ALLOW_SEED') !== '1') {
    fwrite(STDERR, "Refusing to seed outside debug mode. Set ALLOW_SEED=1 to override.\n");
    exit(1);
}

$fresh = in_array('--fresh', array_slice($argv, 1), true);

if ($fresh) {
    echo "Clearing content...\n";
    Database::connect()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['search_tokens', 'search_docs', 'citations', 'article_sources', 'article_links',
              'topic_aliases', 'article_versions', 'articles', 'fields', 'sources', 'media'] as $table) {
        Database::connect()->exec("TRUNCATE TABLE `{$table}`");
    }
    Database::connect()->exec('SET FOREIGN_KEY_CHECKS=1');
}

// ---------------------------------------------------------------- the tree

$tree = [
    ['slug' => 'ashpazi', 'title_fa' => 'آشپزی', 'icon' => '🍲', 'accent_color' => '#b4541f',
     'blurb_fa' => 'دستورهای پخت ایرانی و جهانی، از خورش‌های سنتی تا نان و شیرینی.',
     'children' => [
        ['slug' => 'khoresh', 'title_fa' => 'خورش‌ها', 'icon' => '🥘', 'accent_color' => '#9c4221',
         'blurb_fa' => 'خورش‌های ایرانی با مواد و زمان‌های دقیق.'],
        ['slug' => 'berenj', 'title_fa' => 'برنج و پلو', 'icon' => '🍚', 'accent_color' => '#b7791f',
         'blurb_fa' => 'روش‌های دم‌کشیدن، کته و پلوهای مجلسی.'],
        ['slug' => 'nan', 'title_fa' => 'نان', 'icon' => '🥖', 'accent_color' => '#975a16',
         'blurb_fa' => 'نان‌های خانگی و سنتی، با خمیر ترش و بدون آن.'],
        ['slug' => 'shirini', 'title_fa' => 'شیرینی و دسر', 'icon' => '🍰', 'accent_color' => '#b83280'],
        ['slug' => 'soup', 'title_fa' => 'سوپ و آش', 'icon' => '🍜', 'accent_color' => '#2c7a7b'],
     ]],
    ['slug' => 'negahdari', 'title_fa' => 'نگهداری مواد غذایی', 'icon' => '🫙', 'accent_color' => '#2f855a',
     'blurb_fa' => 'روش‌های ماندگار کردن خوراک: خشک‌کردن، ترشی، کنسرو و فریز.',
     'children' => [
        ['slug' => 'torshi', 'title_fa' => 'ترشی و شور', 'icon' => '🥒', 'accent_color' => '#38a169'],
        ['slug' => 'khoshk-kardan', 'title_fa' => 'خشک‌کردن', 'icon' => '☀️', 'accent_color' => '#d69e2e'],
     ]],
    ['slug' => 'rahnama', 'title_fa' => 'راهنماهای عملی', 'icon' => '🛠', 'accent_color' => '#2b6cb0',
     'blurb_fa' => 'راهنماهای گام‌به‌گام برای کارهای روزمره خانه و آشپزخانه.',
     'children' => [
        ['slug' => 'abzar', 'title_fa' => 'ابزار آشپزخانه', 'icon' => '🔪', 'accent_color' => '#3182ce'],
        ['slug' => 'tamirat', 'title_fa' => 'تعمیرات خانگی', 'icon' => '🔧', 'accent_color' => '#4a5568'],
     ]],
    ['slug' => 'tandorosti', 'title_fa' => 'تغذیه و تندرستی', 'icon' => '🌿', 'accent_color' => '#319795',
     'blurb_fa' => 'اطلاعات پایه درباره مواد غذایی و تغذیه، با ارجاع به منابع.'],
];

echo "Creating fields...\n";
$fieldIds = [];

function seedFields(array $nodes, ?int $parentId, array &$fieldIds, int $sort = 0): void
{
    foreach ($nodes as $i => $node) {
        $id = FieldRepository::create([
            'parent_id'    => $parentId,
            'slug'         => $node['slug'],
            'title_fa'     => $node['title_fa'],
            'blurb_fa'     => $node['blurb_fa'] ?? null,
            'icon'         => $node['icon'] ?? null,
            'accent_color' => $node['accent_color'] ?? null,
            'sort_order'   => $i,
            'is_published' => 1,
        ]);
        $fieldIds[$node['slug']] = $id;

        if (!empty($node['children'])) {
            seedFields($node['children'], $id, $fieldIds);
        }
    }
}

seedFields($tree, null, $fieldIds);
printf("  %d fields\n", count($fieldIds));

// ------------------------------------------------------------- the sources

echo "Creating sources...\n";

$sourceRows = [
    ['url' => 'https://www.seriouseats.com/persian-rice-tahdig-recipe',
     'domain' => 'seriouseats.com', 'title' => 'The Science of Persian Rice and Tahdig',
     'author' => 'J. Kenji López-Alt', 'published_date' => '2021-03-15', 'trust_tier' => 1,
     'extracted_text' => 'Parboiling the rice for 5 to 7 minutes before steaming is what produces separate, elongated grains. The starch that would otherwise make the grains stick is rinsed away and then diluted in the boiling water.'],
    ['url' => 'https://fdc.nal.usda.gov/fdc-app.html#/food-details/169756',
     'domain' => 'fdc.nal.usda.gov', 'title' => 'Rice, white, long-grain — FoodData Central',
     'author' => 'USDA', 'published_date' => '2019-04-01', 'trust_tier' => 1,
     'extracted_text' => 'Long-grain white rice, raw: 130 kcal per 100 g cooked, 28.2 g carbohydrate, 2.7 g protein.'],
    ['url' => 'https://www.cooksillustrated.com/articles/braising-fundamentals',
     'domain' => 'cooksillustrated.com', 'title' => 'Braising Fundamentals: Why Low and Slow Works',
     'author' => 'Cook\'s Illustrated', 'published_date' => '2020-09-02', 'trust_tier' => 2,
     'extracted_text' => 'Collagen begins converting to gelatin at around 71C, but the process is slow; holding the meat between 85C and 95C for two to three hours produces the most tender result.'],
    ['url' => 'https://www.fsis.usda.gov/food-safety/safe-food-handling-and-preparation',
     'domain' => 'fsis.usda.gov', 'title' => 'Safe Minimum Internal Temperature Chart',
     'author' => 'USDA FSIS', 'published_date' => '2023-05-11', 'trust_tier' => 1,
     'extracted_text' => 'Beef, pork, veal and lamb steaks and roasts: 145F (63C) with a three-minute rest. Ground meats: 160F (71C). All poultry: 165F (74C).'],
    ['url' => 'https://nchfp.uga.edu/how/can_02_acid_foods.html',
     'domain' => 'nchfp.uga.edu', 'title' => 'Preserving Acid Foods — National Center for Home Food Preservation',
     'author' => 'University of Georgia', 'published_date' => '2022-06-20', 'trust_tier' => 1,
     'extracted_text' => 'Pickled products require a brine of at least 5 percent acidity vinegar. Do not dilute the vinegar unless the recipe specifies it, as the acidity is what makes the product safe.'],
    ['url' => 'https://www.kingarthurbaking.com/learn/guides/sourdough',
     'domain' => 'kingarthurbaking.com', 'title' => 'Sourdough Baking Guide',
     'author' => 'King Arthur Baking', 'published_date' => '2023-01-30', 'trust_tier' => 2,
     'extracted_text' => 'A mature starter doubles in volume within 4 to 8 hours at 21C. Bulk fermentation is complete when the dough has risen by 50 to 75 percent and holds a dome.'],
];

$sourceIds = [];
foreach ($sourceRows as $row) {
    $row['url_hash'] = sha1($row['url']);
    $row['content_hash'] = sha1($row['extracted_text']);
    $sourceIds[] = Database::insert('sources', $row);
}
printf("  %d sources\n", count($sourceIds));

// ------------------------------------------------------------- the articles

require __DIR__ . '/seed_articles.php';

echo "Recounting fields...\n";
FieldRepository::recountAll();

printf(
    "\nDone. %d fields, %d articles, %d sources.\n",
    (int) Database::value('SELECT COUNT(*) FROM fields', [], 0),
    (int) Database::value('SELECT COUNT(*) FROM articles', [], 0),
    (int) Database::value('SELECT COUNT(*) FROM sources', [], 0)
);
