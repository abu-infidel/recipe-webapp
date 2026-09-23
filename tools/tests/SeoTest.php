<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Core\Paths;
use App\Core\Template\Theme;
use App\Http\Seo\Head;
use App\Http\Seo\StructuredData;

final class SeoTest extends TestCase
{
    public function run(): void {}

    private function boot(): Theme
    {
        Config::load(__DIR__ . '/../../app/config.php');
        Config::set('site.domain', 'example.ir');
        Config::set('site.scheme', 'https');
        Paths::reset();

        return Theme::load('default');
    }

    private function recipeArticle(): array
    {
        return [
            'id' => 1, 'title_fa' => 'قورمه سبزی', 'summary_fa' => 'خورش سبزی.', 'kind' => 'recipe',
            'published_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-02 10:00:00',
        ];
    }

    // ---- structured data ---------------------------------------------------

    public function testRecipeYieldComesFromTheFieldsTheDataActuallyHas(): void
    {
        // Regression: it read a "yield" key the data never had, so was always null.
        $this->boot();
        $graph = StructuredData::article($this->recipeArticle(), ['title_fa' => 'خورش‌ها'], 'https://example.ir/a',
            ['yield_number' => 6, 'yield_unit' => 'نفر', 'prep_minutes' => 30, 'cook_minutes' => 180], null, []);

        $this->assertSame('Recipe', $graph['@type']);
        $this->assertSame('6 نفر', $graph['recipeYield'] ?? null);
        $this->assertSame('PT30M', $graph['prepTime'] ?? null);
        $this->assertSame('PT3H', $graph['cookTime'] ?? null);
        $this->assertSame('PT3H30M', $graph['totalTime'] ?? null, 'total derived from prep + cook when absent');
    }

    public function testGuidesAreArticleNotHowTo(): void
    {
        $this->boot();
        $graph = StructuredData::article([...$this->recipeArticle(), 'kind' => 'guide'], ['title_fa' => 'ابزار'],
            'https://example.ir/g', [], null, []);

        $this->assertSame('Article', $graph['@type'], 'Google no longer shows HowTo rich results');
    }

    public function testSourcesBecomeCitationsAndUnsafeUrlsAreDropped(): void
    {
        $this->boot();
        $graph = StructuredData::article($this->recipeArticle(), ['title_fa' => 'خورش‌ها'], 'https://example.ir/a', [], null, [
            ['url' => 'https://www.fsis.usda.gov/x', 'title' => 'USDA'],
            ['url' => 'javascript:alert(1)', 'title' => 'bad'],
        ]);

        $this->assertSame(1, count($graph['citation'] ?? []));
        $this->assertSame('https://www.fsis.usda.gov/x', $graph['citation'][0]['url']);
    }

    public function testNoNullValuesAreEmitted(): void
    {
        $this->boot();
        $graph = StructuredData::article($this->recipeArticle(), ['title_fa' => 'خورش‌ها'], 'https://example.ir/a', [], null, []);

        foreach ($graph as $key => $value) {
            $this->assertTrue($value !== null && $value !== [], "{$key} must be omitted, not null");
        }
    }

    public function testBreadcrumbPositionsStartAtOne(): void
    {
        $graph = StructuredData::breadcrumbs([['title' => 'خانه', 'url' => 'https://example.ir/'], ['title' => 'آشپزی', 'url' => 'https://example.ir/ashpazi']]);

        $this->assertSame(1, $graph['itemListElement'][0]['position']);
        $this->assertSame(2, $graph['itemListElement'][1]['position']);
    }

    // ---- head ----------------------------------------------------------------

    public function testArticleHeadCarriesEverythingSearchAndSocialNeed(): void
    {
        $theme = $this->boot();
        $head = (string) Head::build([
            'type' => 'article', 'title' => 'قورمه سبزی', 'description' => 'خورش سبزی.',
            'canonical' => 'https://example.ir/a', 'og_type' => 'article', 'article_id' => 3,
            'json_ld' => [['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'x']],
        ], $theme);

        $this->assertStringContains('<title>قورمه سبزی — ', $head, 'page title with the site name');
        $this->assertStringContains('<link rel="canonical" href="https://example.ir/a">', $head);
        $this->assertStringContains('max-image-preview:large', $head, 'large previews explicitly allowed');
        $this->assertStringContains('<meta property="og:type" content="article">', $head);
        $this->assertStringContains('<meta name="article-id" content="3">', $head, 'hook for the view beacon');
        $this->assertStringContains('application/atom+xml', $head, 'feed discoverable');
        $this->assertStringContains('<script type="application/ld+json">', $head);
    }

    public function testNoindexPagesSayNoindex(): void
    {
        $theme = $this->boot();
        $head = (string) Head::build(['type' => 'search', 'title' => 'جست‌وجو', 'noindex' => true], $theme);

        $this->assertStringContains('content="noindex, follow"', $head);
        $this->assertStringNotContains('max-image-preview', $head);
    }

    public function testStructuredDataCannotCloseTheScriptElement(): void
    {
        // A title containing </script> must not end the JSON-LD block early.
        $json = Head::json(['name' => '</script><script>alert(1)</script>']);

        $this->assertStringNotContains('</script>', $json);
        $this->assertStringNotContains('<script>', $json);
    }

    public function testVerificationCodesAreValidatedBeforeOutput(): void
    {
        $theme = $this->boot();
        Config::set('seo.verification.google', 'abcDEF123_-xyz');
        Config::set('seo.verification.bing', '"><script>alert(1)</script>');
        $head = (string) Head::build(['type' => 'home', 'title' => 'x'], $theme);

        $this->assertStringContains('<meta name="google-site-verification" content="abcDEF123_-xyz">', $head);
        $this->assertStringNotContains('msvalidate', $head, 'a malformed code is dropped, not escaped into the page');
    }

    public function testFootCarriesTheHoneypotAndDeferredCoreScripts(): void
    {
        $theme = $this->boot();
        $foot = (string) Head::foot('article', $theme);

        $this->assertStringContains('/assets/core/core.js', $foot);
        $this->assertStringContains(' defer></script>', $foot);
        $this->assertStringContains('clip-path:inset(50%)', $foot, 'honeypot present and clipped');
    }

    public function testEveryRobotsGroupExcludesTheHoneypotAndApi(): void
    {
        $this->boot();
        $body = \App\Http\Controllers\ManifestController::robots(\App\Core\Request::fake('GET', '/robots.txt'))->body();
        $honeypot = \App\Core\Config::string('security.bot_gate.honeypot_path', '/archive/all-entries');

        // A crawler reads only the group that names it most specifically, so
        // each group that allows crawling must carry the exclusions itself.
        foreach (preg_split('/\n\s*\n/', trim($body)) as $group) {
            if (!str_contains($group, 'Allow: /')) {
                continue;
            }
            $agent = strtok($group, "\n");
            $this->assertStringContains('Disallow: ' . $honeypot, $group, "{$agent}: honeypot disallowed");
            $this->assertStringContains('Disallow: /api/', $group, "{$agent}: API disallowed");
            $this->assertStringContains('Disallow: /admin', $group, "{$agent}: admin disallowed");
        }
    }
}
