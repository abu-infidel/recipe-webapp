<?php
declare(strict_types=1);

/**
 * Front controller.
 *
 * On a cache hit LiteSpeed serves the generated HTML directly and this file is
 * never reached (see .htaccess), so everything below runs only on a miss or on
 * a dynamic route.
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Views/helpers.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\SearchController;
use App\Http\ContentResolver;
use App\Core\Url;

$request = Request::capture();

// ---------------------------------------------------------------- bot gate
// Runs before routing so a blocked client costs one indexed lookup rather
// than a rendered page. Manifest paths (/llms.txt and friends) are never
// gated: assistants should learn what the site covers cheaply, and it is
// bulk crawling the host cannot absorb, not being described.
//
// A solved challenge is only looked up when the gate would otherwise
// challenge, so the ordinary request pays nothing for it. A pass never lifts
// a block: something that hit the honeypot stays blocked however much work
// it is willing to do.
if (!str_starts_with($request->path, '/admin') && $request->path !== '/api/verify') {
    $gate = \App\Support\BotGate::inspect($request);

    if ($gate['verdict'] === \App\Support\BotGate::BLOCK) {
        Response::text("Too many requests.\n", 429)
            ->withHeader('Retry-After', (string) max(1, $gate['retry_after']))
            ->noCache()
            ->send();
        exit;
    }

    if ($gate['verdict'] === \App\Support\BotGate::THROTTLE) {
        Response::text("Temporarily unavailable. Please retry later.\n", 503)
            ->withHeader('Retry-After', (string) max(1, $gate['retry_after']))
            ->noCache()
            ->send();
        exit;
    }

    if ($gate['verdict'] === \App\Support\BotGate::CHALLENGE && !ChallengeController::hasPass($request->ip)) {
        ChallengeController::show($request, $gate['reason'], $gate['retry_after'])->send();
        exit;
    }
}

$router  = new Router();

// ---------------------------------------------------------------- site pages
$router->get('/', HomeController::index(...));
$router->get('/search', SearchController::index(...));
$router->get('/api/tree.json', HomeController::tree(...));
$router->get('/api/search.json', SearchController::json(...));
$router->post('/api/verify', ChallengeController::verify(...));
$router->post('/api/beacon', \App\Http\Controllers\BeaconController::record(...));
$router->get('/api/ad/{id}/go', \App\Http\Controllers\BeaconController::adClick(...));

// UI API v1: the data every template receives, for building a theme without
// the repository. docs/UI-CONTRACT.md explains it; /api/v1/schema describes it.
$router->get('/api/v1', \App\Http\Controllers\UiApiController::schema(...));
$router->get('/api/v1/schema', \App\Http\Controllers\UiApiController::schema(...));
$router->get('/api/v1/page', \App\Http\Controllers\UiApiController::page(...));
$router->get('/api/v1/fixture', \App\Http\Controllers\UiApiController::fixture(...));
$router->get('/api/v1/tree', \App\Http\Controllers\UiApiController::tree(...));
$router->get('/api/v1/search', \App\Http\Controllers\UiApiController::search(...));
$router->get('/api/v1/theme', \App\Http\Controllers\UiApiController::theme(...));

// The honeypot is hidden from readers and from screen readers, so only
// something following every href in the markup ever reaches it.
$router->get(
    \App\Core\Config::string('security.bot_gate.honeypot_path', '/archive/all-entries'),
    static function (Request $request): Response {
        if (!\App\Support\BotGate::isVerifiedCrawler($request)) {
            \App\Support\BotGate::block($request->ip, 'honeypot');
        }
        return Response::text("Not found\n", 404)->noCache();
    }
);

// ----------------------------------------------------------- contributors
// Sign in with an SMS code and send articles for review. The one cookie here
// is scoped to /account, so content pages stay cookie-free and cacheable.
$router->get('/account', \App\Http\Controllers\AccountController::index(...));
$router->post('/account/code', \App\Http\Controllers\AccountController::requestCode(...));
$router->post('/account/verify', \App\Http\Controllers\AccountController::verify(...));
$router->post('/account/logout', \App\Http\Controllers\AccountController::logout(...));
$router->post('/account/profile', \App\Http\Controllers\AccountController::profile(...));
$router->get('/account/new', \App\Http\Controllers\AccountController::create(...));
$router->post('/account/new', \App\Http\Controllers\AccountController::submit(...));
$router->post('/account/media', \App\Http\Controllers\AccountController::upload(...));
$router->get('/account/submissions/{id}', \App\Http\Controllers\AccountController::show(...));
$router->post('/account/submissions/{id}', \App\Http\Controllers\AccountController::submit(...));
$router->post('/account/submissions/{id}/withdraw', \App\Http\Controllers\AccountController::withdraw(...));

// ------------------------------------------------------------------- admin
// English, LTR, behind a session cookie. The only cookie this site sets, and
// it is scoped to /admin.
$router->get('/admin/login', \App\Http\Controllers\Admin\AuthController::loginForm(...));
$router->post('/admin/login', \App\Http\Controllers\Admin\AuthController::login(...));
$router->post('/admin/logout', \App\Http\Controllers\Admin\AuthController::logout(...));

$router->get('/admin', \App\Http\Controllers\Admin\DashboardController::index(...));

$router->get('/admin/articles', \App\Http\Controllers\Admin\ArticleController::index(...));
$router->post('/admin/articles/commission', \App\Http\Controllers\Admin\ArticleController::commission(...));
// The composer: articles written in a structured form. /new must come
// before /{id} so it is not read as an article id.
$router->get('/admin/articles/new', \App\Http\Controllers\Admin\ComposeController::create(...));
$router->post('/admin/articles/new', \App\Http\Controllers\Admin\ComposeController::save(...));
$router->get('/admin/articles/{id}/edit', \App\Http\Controllers\Admin\ComposeController::edit(...));
$router->post('/admin/articles/{id}/edit', \App\Http\Controllers\Admin\ComposeController::save(...));
$router->post('/admin/media', \App\Http\Controllers\Admin\ComposeController::upload(...));
$router->get('/admin/articles/{id}', \App\Http\Controllers\Admin\ArticleController::review(...));
$router->post('/admin/articles/{id}/save', \App\Http\Controllers\Admin\ArticleController::save(...));
$router->post('/admin/articles/{id}/publish', \App\Http\Controllers\Admin\ArticleController::publish(...));
$router->post('/admin/articles/{id}/unpublish', \App\Http\Controllers\Admin\ArticleController::unpublish(...));

$router->get('/admin/submissions', \App\Http\Controllers\Admin\SubmissionController::index(...));
$router->get('/admin/submissions/{id}', \App\Http\Controllers\Admin\SubmissionController::show(...));
$router->post('/admin/submissions/{id}/approve', \App\Http\Controllers\Admin\SubmissionController::approve(...));
$router->post('/admin/submissions/{id}/decide', \App\Http\Controllers\Admin\SubmissionController::decide(...));
$router->post('/admin/contributors/{id}/suspend', \App\Http\Controllers\Admin\SubmissionController::suspend(...));

$router->get('/admin/fields', \App\Http\Controllers\Admin\FieldController::index(...));
$router->post('/admin/fields/create', \App\Http\Controllers\Admin\FieldController::create(...));
$router->post('/admin/fields/{id}/update', \App\Http\Controllers\Admin\FieldController::update(...));
$router->post('/admin/fields/{id}/delete', \App\Http\Controllers\Admin\FieldController::delete(...));

$router->get('/admin/themes', \App\Http\Controllers\Admin\ThemeController::index(...));
$router->post('/admin/themes/upload', \App\Http\Controllers\Admin\ThemeController::upload(...));
$router->post('/admin/themes/{name}/activate', \App\Http\Controllers\Admin\ThemeController::activate(...));
$router->post('/admin/themes/{name}/delete', \App\Http\Controllers\Admin\ThemeController::delete(...));
$router->get('/admin/themes/{name}/preview', \App\Http\Controllers\Admin\ThemeController::preview(...));

$router->get('/admin/settings', \App\Http\Controllers\Admin\SettingsController::index(...));
$router->post('/admin/settings/ad-slot/{id}', \App\Http\Controllers\Admin\SettingsController::updateAdSlot(...));
$router->post('/admin/settings/migrate', \App\Http\Controllers\Admin\SettingsController::migrate(...));
$router->post('/admin/settings/contributions', \App\Http\Controllers\Admin\SettingsController::updateContributions(...));
$router->post('/admin/settings/flush-cache', \App\Http\Controllers\Admin\SettingsController::flushCache(...));
$router->post('/admin/settings/unblock', \App\Http\Controllers\Admin\SettingsController::unblock(...));

// ------------------------------------------------------------- worker API
// The VPS worker pulls from here; the site never calls out. Authenticated by
// bearer token plus an HMAC over the body with a timestamp and a nonce.
$router->post('/api/worker/jobs/next', \App\Http\Controllers\WorkerApiController::nextJob(...));
$router->post('/api/worker/jobs/{id}/result', \App\Http\Controllers\WorkerApiController::completeJob(...));
$router->post('/api/worker/jobs/{id}/heartbeat', \App\Http\Controllers\WorkerApiController::heartbeat(...));
$router->post('/api/worker/validate', \App\Http\Controllers\WorkerApiController::validateDraft(...));

// ------------------------------------------------- machine-readable manifests
// Always served, never rate limited. A chatbot learns what the site covers in
// one cheap request instead of crawling thousands of pages.
$router->get('/llms.txt', ManifestController::llmsTxt(...));
$router->get('/.well-known/ai-manifest.json', ManifestController::aiManifest(...));
$router->get('/about-for-ai', ManifestController::aboutForAi(...));
$router->get('/robots.txt', ManifestController::robots(...));
$router->get('/sw.js', \App\Http\Controllers\ServiceWorkerController::script(...));
$router->get('/offline', static fn(Request $r) => \App\Http\Page::render('offline', \App\Http\ViewModels::offline(...))->cacheFor(86400));
$router->get('/sitemap.xml', ManifestController::sitemap(...));
$router->get('/feed.xml', \App\Http\Controllers\FeedController::atom(...));

// ------------------------------------------------------------ content lookup
// Anything else is a field or an article. Prefix-free URLs keep Persian paths
// short and make the later switch to subdomains a config change.
$router->fallback(static function (Request $request): ?Response {
    $contentPath = Url::contentPathFor($request);
    if ($contentPath === null || $contentPath === '') {
        return null;
    }

    $match = ContentResolver::resolve($contentPath);
    if ($match === null) {
        return null;
    }

    return match ($match['type']) {
        ContentResolver::TYPE_FIELD    => FieldController::show($request, $match['field']),
        ContentResolver::TYPE_ARTICLE  => ArticleController::show($request, $match['field'], $match['article']),
        ContentResolver::TYPE_REDIRECT => $match['status'] === 410
            ? \App\Http\Controllers\ErrorController::gone($request)
            : Response::redirect($match['to'], $match['status']),
        default => null,
    };
});

$router->notFound(static fn(Request $request) => \App\Http\Controllers\ErrorController::notFound($request));

try {
    $response = $router->dispatch($request);
} catch (\Throwable $e) {
    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    $response = Config::bool('debug')
        ? Response::text(
            $e->getMessage() . "\n\n" . $e->getTraceAsString(),
            500
          )
        : \App\Http\Controllers\ErrorController::serverError($request);
}

// ------------------------------------------------------- write-through cache
// A published page is written to disk so the next request for it is served by
// the web server without starting PHP at all (see .htaccess). Only plain GETs
// of public pages are cached; admin, the worker API and anything with a query
// string never are.
if (
    $request->isGet()
    && $response->status() === 200
    && $request->query === []
    && !str_starts_with($request->path, '/admin')
    && !str_starts_with($request->path, '/account')
    && !str_starts_with($request->path, '/api/')
    && $request->path !== '/search'
    && \App\Http\Controllers\Admin\AdminAuthProbe::isAnonymous()
    && str_contains($response->headers()['cache-control'] ?? '', 'public')
    // HTML only. The web server serves a cached entry as index.html, so a
    // cached robots.txt, sitemap.xml or llms.txt would go out as text/html
    // from the second request on — search consoles reject a sitemap served
    // that way, and the AI manifest stops being JSON.
    && str_starts_with($response->headers()['content-type'] ?? '', 'text/html')
) {
    \App\Core\PageCache::put(
        Config::string('site.domain'),
        $request->path,
        $response->body()
    );
}

$response->send();
