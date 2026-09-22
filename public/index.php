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
use App\Http\Controllers\FieldController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\SearchController;
use App\Http\ContentResolver;
use App\Core\Url;

$request = Request::capture();
$router  = new Router();

// ---------------------------------------------------------------- site pages
$router->get('/', HomeController::index(...));
$router->get('/search', SearchController::index(...));
$router->get('/api/tree.json', HomeController::tree(...));
$router->get('/api/search.json', SearchController::json(...));

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
