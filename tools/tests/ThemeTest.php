<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Core\Paths;
use App\Core\Template\SafeHtml;
use App\Core\Template\TemplateError;
use App\Core\Template\Theme;
use App\Http\Page;
use App\Http\ViewModels;

/**
 * The theme system, end to end: loading, rendering, the safety boundary, and
 * graceful degradation. The integration half renders every page type of the
 * default theme against real view models from the development database.
 */
final class ThemeTest extends TestCase
{
    public function run(): void {}

    private string $scratch = '';

    private function boot(): void
    {
        Config::load(__DIR__ . '/../../app/config.php', __DIR__ . '/../../app/config.local.php');
        Paths::reset();
        Theme::use(null);
    }

    /** A throwaway web root holding one hand-made theme. */
    private function tempTheme(array $files, array $manifest = []): Theme
    {
        $this->scratch = sys_get_temp_dir() . '/theme-test-' . bin2hex(random_bytes(4));
        $dir = $this->scratch . '/themes/probe';
        mkdir($dir . '/partials', 0o777, true);

        file_put_contents($dir . '/theme.json', json_encode([
            'name' => 'probe', 'api' => 1, 'stylesheets' => [], 'scripts' => ['*' => []], ...$manifest,
        ]));
        foreach ($files as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }

        Config::set('paths.public', $this->scratch);
        Paths::reset();

        return Theme::load('probe');
    }

    private function cleanup(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
        Config::set('paths.public', '');
        Paths::reset();
        Theme::use(null);
    }

    // ---- loading ------------------------------------------------------

    public function testRejectsThemeNamesThatCouldTraverse(): void
    {
        $this->boot();
        foreach (['../app', 'a/b', 'Default', ''] as $bad) {
            $threw = false;
            try {
                Theme::load($bad);
            } catch (TemplateError) {
                $threw = true;
            }
            $this->assertTrue($threw, 'must reject theme name ' . var_export($bad, true));
        }
    }

    public function testRejectsAThemeTargetingAnotherApiVersion(): void
    {
        $this->boot();
        $threw = false;
        try {
            $this->tempTheme(['layout.mustache' => ''], ['api' => 2]);
        } catch (TemplateError $e) {
            $threw = str_contains($e->getMessage(), 'API');
        }
        $this->cleanup();
        $this->assertTrue($threw, 'a theme built for a different contract must not load');
    }

    public function testAssetPathsCannotEscapeTheThemeFolder(): void
    {
        $this->boot();
        $theme = $this->tempTheme(['layout.mustache' => '']);
        foreach (['../../app/config.local.php', '/etc/passwd', 'https://cdn.example/x.js', 'a/../../b.css'] as $bad) {
            $threw = false;
            try {
                $theme->assetUrl($bad);
            } catch (TemplateError) {
                $threw = true;
            }
            $this->assertTrue($threw, 'must refuse asset path ' . $bad);
        }
        $this->cleanup();
    }

    // ---- the safety boundary -------------------------------------------

    public function testThemeCannotEmitRawHtmlFromData(): void
    {
        // Even a template that deliberately asks for unescaped output gets
        // escaped text unless core handed it a SafeHtml.
        $this->boot();
        $theme = $this->tempTheme([
            'layout.mustache' => '{{page.head}}{{content}}{{page.foot}}',
            'home.mustache'   => '<h1>{{{page.title}}}</h1><div>{{&page.title}}</div>',
        ]);
        $html = $theme->render('home', [
            'site' => [],
            'page' => ['title' => '<img src=x onerror=alert(1)>', 'head' => new SafeHtml(''), 'foot' => new SafeHtml('')],
        ]);
        $this->cleanup();

        $this->assertStringNotContains('<img', $html);
        $this->assertStringContains('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testCoreSafeHtmlPassesThrough(): void
    {
        $this->boot();
        $theme = $this->tempTheme([
            'layout.mustache' => '<head>{{page.head}}</head>{{content}}',
            'home.mustache'   => 'x',
        ]);
        $html = $theme->render('home', ['page' => ['head' => new SafeHtml('<meta name="t" content="1">')]]);
        $this->cleanup();

        $this->assertStringContains('<meta name="t" content="1">', $html);
    }

    public function testBrokenTemplateDegradesToAPlainPageInsteadOfFailing(): void
    {
        $this->boot();
        $theme = $this->tempTheme([
            'layout.mustache' => '{{page.head}}{{content}}{{page.foot}}',
            'error.mustache'  => '{{#unclosed}}oops',
        ]);
        Theme::use($theme);

        $response = Page::render('error', static fn(Theme $t) => ViewModels::error($t, 404, 'پیدا نشد', 'پیام'), 404);
        $this->cleanup();

        $this->assertSame(404, $response->status(), 'the status must survive the fallback');
        $this->assertStringContains('پیدا نشد', $response->body(), 'the reader still gets the message');
        $this->assertStringContains('<meta name="robots"', $response->body(), 'core head survives the fallback');
    }

    // ---- integration: the default theme, real data ------------------------

    public function testDefaultThemeRendersEveryPageTypeWithRealData(): void
    {
        $this->boot();
        try {
            \App\Core\Database::connect();
        } catch (\Throwable) {
            $this->assertTrue(true);   // no database here; the unit tests above still ran
            return;
        }

        $theme = Theme::load('default');
        $field = \App\Domain\FieldRepository::findByPath('ashpazi/khoresh');
        $article = $field !== null
            ? \App\Core\Database::first("SELECT * FROM articles WHERE field_id = :f AND status = 'published' LIMIT 1", ['f' => $field['id']])
            : null;

        $models = [
            'home'      => ViewModels::home($theme),
            'about'     => ViewModels::about($theme),
            'offline'   => ViewModels::offline($theme),
            'error'     => ViewModels::error($theme, 404, 'پیدا نشد', 'پیام'),
            'search'    => ViewModels::search($theme, 'برنج', [], 1, false),
            'challenge' => ViewModels::challenge($theme, '1800000000.0123456789abcdef.0123456789abcdef0123456789abcdef', 16, 60),
        ];
        if ($field !== null) {
            $models['field'] = ViewModels::field($theme, $field);
        }
        if ($field !== null && $article !== null) {
            $models['article'] = ViewModels::article($theme, $field, $article);
        }

        foreach ($models as $type => $model) {
            $html = $theme->render($type, $model);

            $this->assertStringContains('<html lang="fa" dir="rtl">', $html, "{$type}: document language and direction");
            $this->assertStringContains('<meta name="robots"', $html, "{$type}: core head present");
            $this->assertStringContains('/assets/core/core.js', $html, "{$type}: core scripts present");
            $this->assertStringContains('<main id="main">', $html, "{$type}: main landmark");
            $this->assertStringNotContains('{{', $html, "{$type}: no unrendered tags");
        }
    }

    /**
     * article.js finds the contents with querySelector('[data-toc]'). When the
     * layout wrapper also carried data-toc (for which side the contents go
     * on), the script matched the wrapper first and, on phones, hid the whole
     * article. A hook attribute must name exactly one element.
     */
    public function testArticleScriptHooksAreUnambiguous(): void
    {
        $this->boot();
        $theme = Theme::load('default');
        $html = $theme->render('article', \App\Http\UiContract::fixture('article', $theme));

        foreach (['data-toc', 'data-toc-toggle', 'data-scaler', 'data-steps', 'data-steps-reset'] as $hook) {
            $count = preg_match_all('/\s' . preg_quote($hook, '/') . '(?=[\s>=])/', $html);
            $this->assertSame(1, $count, "exactly one element carries {$hook}");
        }
    }
}
