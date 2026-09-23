<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Core\Paths;
use App\Core\Template\SafeHtml;
use App\Core\Template\Theme;
use App\Core\Template\ThemeChecker;
use App\Domain\ThemeInstaller;
use App\Http\PageResolver;
use App\Http\UiContract;
use App\Http\ViewModels;

/**
 * The UI contract holds only if the schema, the sample data and the real
 * view models agree. This keeps them agreeing, and checks that the theme
 * checker and installer refuse what they must.
 */
final class UiContractTest extends TestCase
{
    public function run(): void {}

    private string $scratch = '';

    private function boot(): void
    {
        Config::load(__DIR__ . '/../../app/config.php', __DIR__ . '/../../app/config.local.php');
        Config::set('paths.public', '');
        Paths::reset();
        Theme::use(null);
    }

    /** A throwaway web root holding a copy of the default theme. */
    private function scratchRoot(): string
    {
        $this->scratch = sys_get_temp_dir() . '/ui-contract-' . bin2hex(random_bytes(4));
        mkdir($this->scratch . '/themes', 0o777, true);
        exec('cp -R ' . escapeshellarg(__DIR__ . '/../../public/themes/default') . ' ' . escapeshellarg($this->scratch . '/themes/default'));
        Config::set('paths.public', $this->scratch);
        Paths::reset();
        Theme::use(null);

        return $this->scratch;
    }

    /** A copy of the default theme under another name, optionally altered. */
    private function variant(string $name, array $edits = [], array $extraFiles = []): string
    {
        $dir = $this->scratch . '/themes/' . $name;
        exec('cp -R ' . escapeshellarg($this->scratch . '/themes/default') . ' ' . escapeshellarg($dir));
        $manifest = json_decode((string) file_get_contents($dir . '/theme.json'), true);
        $manifest['name'] = $name;
        file_put_contents($dir . '/theme.json', json_encode($manifest));

        foreach ($edits as $file => [$search, $replace]) {
            file_put_contents($dir . '/' . $file, str_replace($search, $replace, (string) file_get_contents($dir . '/' . $file)));
        }
        foreach ($extraFiles as $file => $content) {
            file_put_contents($dir . '/' . $file, $content);
        }

        return $dir;
    }

    private function cleanup(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
        $this->scratch = '';
        Config::set('paths.public', '');
        Paths::reset();
        Theme::use(null);
    }

    private function errorsMatching(array $report, string $needle): int
    {
        return count(array_filter($report['errors'], static fn(string $e) => str_contains($e, $needle)));
    }

    // ---- the schema itself --------------------------------------------------

    public function testEveryPageTypeIsDescribed(): void
    {
        $this->boot();
        $schema = UiContract::schema();

        foreach (Theme::PAGE_TYPES as $type) {
            $this->assertTrue(isset($schema['pages'][$type]), "schema describes {$type}");
            $this->assertTrue(isset($schema['templates']['pages'][$type]), "schema names the {$type} template");
        }
        $this->assertSame(Theme::REQUIRED_LAYOUT_TAGS, $schema['templates']['layout']['required_tags'], 'layout tags come from Theme');
        $this->assertSame(1, $schema['api']);
    }

    public function testEveryTypeInTheSchemaResolves(): void
    {
        $this->boot();
        $schema = UiContract::schema();
        $known = ['string', 'int', 'bool', 'html', 'url', 'href', 'object', ...array_keys($schema['shapes'])];
        $unknown = [];

        $walk = static function (array $fields, string $where) use (&$walk, $known, &$unknown): void {
            foreach ($fields as $key => $spec) {
                $type = rtrim($spec[0], '?');
                if (str_starts_with($type, 'list<')) {
                    $type = substr($type, 5, -1);
                }
                if (!in_array($type, $known, true)) {
                    $unknown[] = "{$where}.{$key}: {$spec[0]}";
                }
                if (isset($spec[2])) {
                    $walk($spec[2], "{$where}.{$key}");
                }
            }
        };
        foreach ($schema['pages'] as $page => $fields) {
            $walk($fields, $page);
        }
        foreach ($schema['shapes'] as $shape => $fields) {
            $walk($fields, $shape);
        }

        $this->assertSame([], $unknown, 'every type is a scalar, object, list or a defined shape');
    }

    public function testSchemaIsValidJson(): void
    {
        $this->boot();
        $json = json_encode(UiContract::schema(), JSON_UNESCAPED_UNICODE);
        $this->assertTrue(is_string($json) && strlen($json) > 1000, 'schema encodes');
    }

    // ---- fixtures -------------------------------------------------------------

    public function testFixturesConformToTheSchema(): void
    {
        $this->boot();
        $theme = Theme::load('default');

        foreach (Theme::PAGE_TYPES as $type) {
            $this->assertSame([], UiContract::validate($type, UiContract::fixture($type, $theme)), "{$type} fixture conforms");
        }
    }

    public function testFixturesFillEveryOptionalBranch(): void
    {
        $this->boot();
        $theme = Theme::load('default');
        $article = UiContract::fixture('article', $theme);

        foreach (['hero', 'recipe'] as $key) {
            $this->assertTrue($article[$key] !== null, "article fixture has {$key}");
        }
        foreach (['has_toc', 'has_references', 'has_related', 'has_neighbours'] as $flag) {
            $this->assertTrue($article[$flag], "article fixture sets {$flag}");
        }
        $this->assertTrue($article['recipe']['yield'] !== null, 'recipe fixture has a yield');

        $amounts = array_column($article['recipe']['ingredients'], 'amount_text');
        $this->assertTrue(in_array(null, $amounts, true) && count(array_filter($amounts)) > 0, 'both numeric and textual amounts');
        $refs = array_column($article['references'], 'href');
        $this->assertTrue(in_array(null, $refs, true), 'a reference without a safe link is represented');
    }

    // ---- validation -----------------------------------------------------------

    public function testValidateReportsEachKindOfDrift(): void
    {
        $this->boot();
        $theme = Theme::load('default');
        $model = UiContract::fixture('error', $theme);

        $missing = $model;
        unset($missing['error']['headline']);
        $this->assertSame(['error.error.headline: missing'], UiContract::validate('error', $missing));

        $extra = $model;
        $extra['error']['stack'] = 'x';
        $this->assertSame(['error.error.stack: not in the schema'], UiContract::validate('error', $extra));

        $wrong = $model;
        $wrong['error']['code'] = '404';
        $this->assertStringContains('expected int', implode("\n", UiContract::validate('error', $wrong)));

        $null = $model;
        $null['page']['head'] = null;
        $this->assertStringContains('null but not nullable', implode("\n", UiContract::validate('error', $null)));

        $raw = $model;
        $raw['page']['head'] = '<meta>';
        $this->assertStringContains('expected html', implode("\n", UiContract::validate('error', $raw)), 'plain string is not html');

        $relative = $model;
        $relative['site']['url'] = '/';
        $this->assertStringContains('expected url', implode("\n", UiContract::validate('error', $relative)), 'url must be absolute');
    }

    public function testValidateDescendsIntoListsAndShapes(): void
    {
        $this->boot();
        $theme = Theme::load('default');
        $model = UiContract::fixture('home', $theme);
        $model['tree'][0]['children'][1]['article_count'] = 'many';

        $errors = UiContract::validate('home', $model);
        $this->assertSame(1, count($errors));
        $this->assertStringContains('tree[0].children[1].article_count', $errors[0] ?? '');
    }

    // ---- real models (needs the development database) ------------------------

    public function testRealViewModelsConform(): void
    {
        $this->boot();
        try {
            \App\Core\Database::connect();
        } catch (\Throwable) {
            $this->assertTrue(true);
            return;
        }

        $theme = Theme::load('default');
        $models = [
            'home'      => ViewModels::home($theme),
            'about'     => ViewModels::about($theme),
            'offline'   => ViewModels::offline($theme),
            'error'     => ViewModels::error($theme, 404, 'a', 'b'),
            'search'    => ViewModels::search($theme, 'برنج', \App\Domain\SearchIndex::search('برنج', 5, 0), 1, true),
            'challenge' => ViewModels::challenge($theme, 'n', 16, 60),
        ];

        foreach (\App\Core\Database::all(
            "SELECT f.path, a.id FROM articles a JOIN fields f ON f.id = a.field_id WHERE a.status = 'published' ORDER BY a.id LIMIT 5"
        ) as $row) {
            $field = \App\Domain\FieldRepository::findByPath($row['path']);
            $article = \App\Core\Database::first('SELECT * FROM articles WHERE id = :id', ['id' => $row['id']]);
            $models['field:' . $row['path']] = ViewModels::field($theme, $field);
            $models['article:' . $row['id']] = ViewModels::article($theme, $field, $article);
        }

        foreach ($models as $label => $model) {
            $type = explode(':', $label)[0];
            $this->assertSame([], UiContract::validate($type, $model), "real {$label} conforms");
        }
    }

    public function testResolverFollowsTheRouter(): void
    {
        $this->boot();
        try {
            \App\Core\Database::connect();
        } catch (\Throwable) {
            $this->assertTrue(true);
            return;
        }

        $theme = Theme::load('default');
        $this->assertSame('home', PageResolver::resolve('/', $theme)['page_type']);
        $this->assertSame('about', PageResolver::resolve('/about-for-ai', $theme)['page_type']);

        $missing = PageResolver::resolve('/no/such/page', $theme);
        $this->assertSame(['error', 404], [$missing['page_type'], $missing['status']]);

        $search = PageResolver::resolve('/search?q=' . rawurlencode('برنج') . '&page=2', $theme);
        $this->assertSame(['search', 'برنج'], [$search['page_type'], $search['model']['query']]);

        $field = \App\Domain\FieldRepository::findByPath('ashpazi');
        if ($field !== null) {
            $this->assertSame('field', PageResolver::resolve('/ashpazi', $theme)['page_type']);
            $this->assertSame('field', PageResolver::resolve('/ashpazi/', $theme)['page_type'], 'trailing slash');
        }

        $this->assertSame(429, PageResolver::forType('challenge', $theme)['status']);
    }

    // ---- the document ---------------------------------------------------------

    public function testContractDocumentIsUpToDate(): void
    {
        $this->boot();
        $doc = (string) file_get_contents(__DIR__ . '/../../docs/UI-CONTRACT.md');
        $begin = '<!-- BEGIN GENERATED REFERENCE: php tools/ui-contract-doc.php -->';
        $end = '<!-- END GENERATED REFERENCE -->';
        $start = strpos($doc, $begin);
        $stop = strpos($doc, $end);

        $this->assertTrue($start !== false && $stop !== false, 'markers present');
        $current = substr($doc, $start + strlen($begin), $stop - $start - strlen($begin));
        $this->assertTrue(
            trim($current) === trim(UiContract::markdownReference()),
            'docs/UI-CONTRACT.md matches the schema — run php tools/ui-contract-doc.php'
        );
    }

    public function testMinimalExampleThemePasses(): void
    {
        $this->boot();
        $report = ThemeChecker::check(__DIR__ . '/../../docs/examples/minimal', 'minimal');

        $this->assertSame([], $report['errors'], 'the documented starting point is a valid theme');
        $this->assertSame([], $report['warnings']);
    }

    // ---- the checker ----------------------------------------------------------

    public function testDefaultThemePassesTheChecker(): void
    {
        $this->boot();
        $report = ThemeChecker::check(Paths::themes() . '/default', 'default');

        $this->assertSame([], $report['errors'], 'no errors');
        $this->assertSame([], $report['warnings'], 'no warnings');
        $this->assertSame(Theme::PAGE_TYPES, array_keys($report['rendered']), 'every page type rendered');
    }

    public function testCheckerRefusesForeignOriginsAndCspBreakers(): void
    {
        $this->boot();
        $this->scratchRoot();
        try {
            $dir = $this->variant('bad', [
                'layout.mustache' => ['{{page.head}}', '{{page.head}}<link rel="stylesheet" href="https://fonts.googleapis.com/css"><script>track()</script><img src="//cdn.example.com/p.gif">'],
                'theme.css'       => [':root {', "@import url('https://cdn.example.com/a.css');\n:root {"],
                'site.js'         => ["'use strict';", "'use strict'; fetch('https://api.example.com/x');"],
                'home.mustache'   => ['<a class="orbit__node"', '<a onclick="go()" class="orbit__node"'],
            ]);
            $report = ThemeChecker::check($dir, 'bad');

            $this->assertFalse($report['ok']);
            $this->assertSame(1, $this->errorsMatching($report, 'fonts.googleapis.com'), 'external stylesheet');
            $this->assertSame(1, $this->errorsMatching($report, '//cdn.example.com/p.gif'), 'protocol-relative image');
            $this->assertSame(1, $this->errorsMatching($report, 'cdn.example.com/a.css'), 'css @import');
            $this->assertSame(1, $this->errorsMatching($report, 'api.example.com'), 'script URL');
            $this->assertSame(1, $this->errorsMatching($report, 'inline <script>'), 'inline script');
            $this->assertSame(1, $this->errorsMatching($report, 'onclick='), 'inline handler');
        } finally {
            $this->cleanup();
        }
    }

    public function testCheckerAllowsWhatIsHarmless(): void
    {
        $this->boot();
        $this->scratchRoot();
        try {
            $dir = $this->variant('fine', [
                // outbound navigation, an SVG namespace, a JSON island, a URL in a comment
                'layout.mustache' => ['{{page.foot}}', '<a href="https://example.org/">x</a><svg xmlns="http://www.w3.org/2000/svg"></svg><script type="application/json">{}</script>{{! see https://example.com }}{{page.foot}}'],
                'theme.css'       => [':root {', "/* from https://example.com/palette */\n:root {"],
            ]);
            $report = ThemeChecker::check($dir, 'fine');

            $this->assertSame([], $report['errors'], 'links, namespaces, data islands and comments are not loads');
        } finally {
            $this->cleanup();
        }
    }

    public function testCheckerRequiresTheCoreTagsToActuallyRender(): void
    {
        $this->boot();
        $this->scratchRoot();
        try {
            $missing = $this->variant('nohead', ['layout.mustache' => ['{{page.head}}', '']]);
            $this->assertSame(1, $this->errorsMatching(ThemeChecker::check($missing, 'nohead'), 'must contain {{page.head}}'));

            $hidden = $this->variant('hidden', ['layout.mustache' => ['{{page.foot}}', '{{#never}}{{page.foot}}{{/never}}']]);
            $report = ThemeChecker::check($hidden, 'hidden');
            $this->assertSame(count(Theme::PAGE_TYPES), $this->errorsMatching($report, 'no core scripts'), 'a foot that never renders is caught on every page');

            $challenge = $this->variant('nochallenge', ['challenge.mustache' => ['data-challenge-status', 'data-status']]);
            $this->assertSame(1, $this->errorsMatching(ThemeChecker::check($challenge, 'nochallenge'), 'data-challenge-status'));
        } finally {
            $this->cleanup();
        }
    }

    public function testCheckerRefusesExecutableAndHiddenFiles(): void
    {
        $this->boot();
        $this->scratchRoot();
        try {
            $dir = $this->variant('files', [], [
                'shell.php' => '<?php echo 1;',
                '.htaccess' => 'AddHandler x .css',
                'icon.svg'  => '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"></svg>',
            ]);
            $report = ThemeChecker::check($dir, 'files');

            $this->assertSame(1, $this->errorsMatching($report, 'shell.php'), '.php refused');
            $this->assertSame(1, $this->errorsMatching($report, '.htaccess'), 'hidden file refused');
            $this->assertSame(1, $this->errorsMatching($report, 'icon.svg'), 'scripted SVG refused');
        } finally {
            $this->cleanup();
        }
    }

    public function testCheckerCatchesMissingPartialsAndNameMismatch(): void
    {
        $this->boot();
        $this->scratchRoot();
        try {
            $dir = $this->variant('parts', ['home.mustache' => ['<nav', '{{> nowhere}}<nav']]);
            $manifest = json_decode((string) file_get_contents($dir . '/theme.json'), true);
            $manifest['name'] = 'other';
            file_put_contents($dir . '/theme.json', json_encode($manifest));

            $report = ThemeChecker::check($dir, 'parts');
            $this->assertSame(1, $this->errorsMatching($report, 'partials/nowhere.mustache'));
            $this->assertSame(1, $this->errorsMatching($report, 'folder is "parts"'));
        } finally {
            $this->cleanup();
        }
    }

    public function testPhysicalCssIsAWarningUnlessMarked(): void
    {
        $this->boot();
        $this->scratchRoot();
        try {
            $dir = $this->variant('phys', ['theme.css' => [':root {', ".a { margin-left: 1rem; }\n.b { left: 0; /* physical */ }\n:root {"]]);
            $report = ThemeChecker::check($dir, 'phys');

            $this->assertSame([], $report['errors']);
            $this->assertSame(1, count($report['warnings']));
            $this->assertStringContains('1 physical', $report['warnings'][0] ?? '', 'the marked line is not counted');
        } finally {
            $this->cleanup();
        }
    }

    // ---- the installer --------------------------------------------------------

    private function zip(array $files): string
    {
        $path = $this->scratch . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** The default theme's files as a zip map, renamed. */
    private function themeFiles(string $name, string $prefix = ''): array
    {
        $files = [];
        $root = Paths::themes() . '/default';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $files[$prefix . $relative] = (string) file_get_contents($file->getPathname());
        }
        $manifest = json_decode($files[$prefix . 'theme.json'], true);
        $manifest['name'] = $name;
        $files[$prefix . 'theme.json'] = json_encode($manifest);

        return $files;
    }

    public function testInstallerInstallsACleanThemeFromAFolderInTheZip(): void
    {
        $this->boot();
        if (!class_exists(\ZipArchive::class)) {
            $this->assertTrue(true);
            return;
        }
        $this->scratchRoot();
        try {
            $result = ThemeInstaller::installZip($this->zip([...$this->themeFiles('dawn', 'dawn/'), '__MACOSX/._x' => 'junk']), false);

            $this->assertTrue($result['ok'], 'installed: ' . implode('; ', $result['errors']));
            $this->assertTrue(is_file(Paths::themes() . '/dawn/layout.mustache'), 'files in place');
            $this->assertFalse(is_dir(Paths::themes() . '/dawn/__MACOSX'), 'archive litter skipped');

            $again = ThemeInstaller::installZip($this->zip($this->themeFiles('dawn')), false);
            $this->assertStringContains('already installed', $again['errors'][0] ?? '', 'no silent overwrite');
            $this->assertTrue(ThemeInstaller::installZip($this->zip($this->themeFiles('dawn')), true)['ok'], 'replace when asked');
        } finally {
            $this->cleanup();
        }
    }

    public function testInstallerRefusesUnsafeArchivesAndLeavesNoTrace(): void
    {
        $this->boot();
        if (!class_exists(\ZipArchive::class)) {
            $this->assertTrue(true);
            return;
        }
        $this->scratchRoot();
        try {
            $cases = [
                'traversal' => [...$this->themeFiles('t1'), '../../escape.php' => '<?php'],
                'php'       => [...$this->themeFiles('t2'), 'x.php' => '<?php'],
                'default'   => $this->themeFiles('default'),
                'badname'   => $this->themeFiles('../evil'),
                'nomanifest' => ['layout.mustache' => ''],
                'foreign'   => (function () {
                    $files = $this->themeFiles('t3');
                    $files['theme.css'] = "@import url('https://cdn.example.com/x.css');\n" . $files['theme.css'];
                    return $files;
                })(),
            ];

            foreach ($cases as $label => $files) {
                $result = ThemeInstaller::installZip($this->zip($files), true);
                $this->assertFalse($result['ok'], "{$label} refused");
            }

            $installed = array_map('basename', glob(Paths::themes() . '/*', GLOB_ONLYDIR) ?: []);
            $this->assertSame(['default'], $installed, 'nothing refused reached the themes folder');
            $this->assertFalse(is_file($this->scratch . '/escape.php') || is_file(dirname($this->scratch) . '/escape.php'), 'nothing escaped');
            $this->assertSame([], glob(sys_get_temp_dir() . '/theme-upload-*') ?: [], 'staging cleaned up');
        } finally {
            $this->cleanup();
        }
    }
}
