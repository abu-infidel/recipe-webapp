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
if (!str_starts_with($request->path, '/admin')
    && $request->path !== '/api/verify'
    && !ChallengeController::hasPass($request->ip)) {
    $gate = \App\Support\BotGate::inspect($request);

    if ($gate['verdict'] === \App\Support\BotGate::BLOCK) {
        Response::text("Too many requests.\n", 429)
            ->withHeader('Retry-After', (string) max(1, $gate['retry_after']))
            ->noCache()
            ->send();
        exit;
    }

    if ($gate['verdict'] === \App\Support\BotGate::CHALLENGE) {
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

// The honeypot is hidden from readers and from screen readers, so only
// something following every href in the markup ever reaches it.
$router->get(
    \App\Core\Config::string('security.bot_gate.honeypot_path', '/archive/all-entries'),
    static function (Request $request): Response {
        \App\Support\BotGate::block($request->ip, 'honeypot');
        return Response::text("Not found\n", 404)->noCache();
    }
);

// ------------------------------------------------------------------- admin
// English, LTR, behind a session cookie. The only cookie this site sets, and
// it is scoped to /admin.
$router->get('/admin/login', \App\Http\Controllers\Admin\AuthController::loginForm(...));
$router->post('/admin/login', \App\Http\Controllers\Admin\AuthController::login(...));
$router->post('/admin/logout', \App\Http\Controllers\Admin\AuthController::logout(...));

$router->get('/admin', \App\Http\Controllers\Admin\DashboardController::index(...));

$router->get('/admin/articles', \App\Http\Controllers\Admin\ArticleController::index(...));
$router->post('/admin/articles/commission', \App\Http\Controllers\Admin\ArticleController::commission(...));
$router->get('/admin/articles/{id}', \App\Http\Controllers\Admin\ArticleController::review(...));
$router->post('/admin/articles/{id}/save', \App\Http\Controllers\Admin\ArticleController::save(...));
$router->post('/admin/articles/{id}/publish', \App\Http\Controllers\Admin\ArticleController::publish(...));
$router->post('/admin/articles/{id}/unpublish', \App\Http\Controllers\Admin\ArticleController::unpublish(...));

$router->get('/admin/fields', \App\Http\Controllers\Admin\FieldController::index(...));
$router->post('/admin/fields/create', \App\Http\Controllers\Admin\FieldController::create(...));
$router->post('/admin/fields/{id}/update', \App\Http\Controllers\Admin\FieldController::update(...));
$router->post('/admin/fields/{id}/delete', \App\Http\Controllers\Admin\FieldController::delete(...));

$router->get('/admin/settings', \App\Http\Controllers\Admin\SettingsController::index(...));
$router->post('/admin/settings/ad-slot/{id}', \App\Http\Controllers\Admin\SettingsController::updateAdSlot(...));
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
$router->get('/sitemap.xml', ManifestController::sitemap(...));

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
        ContentResolver::TYPE_REDIRECT => Response::redirect($match['to'], $match['status']),
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

$response->send();
