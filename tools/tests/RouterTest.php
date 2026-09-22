<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

final class RouterTest extends TestCase
{
    public function run(): void {}

    private function router(): Router
    {
        $router = new Router();
        $router->get('/', fn() => Response::text('home'));
        $router->get('/search', fn() => Response::text('search'));
        $router->get('/article/{slug}', fn($r, $p) => Response::text('article:' . $p['slug']));
        $router->get('/admin/{path*}', fn($r, $p) => Response::text('admin:' . $p['path']));
        $router->post('/admin/login', fn() => Response::text('login'));

        return $router;
    }

    public function testMatchesStaticRoute(): void
    {
        $this->assertSame('home', $this->router()->dispatch(Request::fake('GET', '/'))->body());
        $this->assertSame('search', $this->router()->dispatch(Request::fake('GET', '/search'))->body());
    }

    public function testCapturesSingleSegmentParameter(): void
    {
        $response = $this->router()->dispatch(Request::fake('GET', '/article/ghormeh-sabzi'));
        $this->assertSame('article:ghormeh-sabzi', $response->body());
    }

    public function testSingleSegmentParameterDoesNotSwallowSlashes(): void
    {
        // /article/a/b must not match /article/{slug}
        $this->assertSame(404, $this->router()->dispatch(Request::fake('GET', '/article/a/b'))->status());
    }

    public function testWildcardParameterCapturesRestOfPath(): void
    {
        $response = $this->router()->dispatch(Request::fake('GET', '/admin/articles/42/edit'));
        $this->assertSame('admin:articles/42/edit', $response->body());
    }

    public function testPersianSlugMatches(): void
    {
        $response = $this->router()->dispatch(Request::fake('GET', '/article/قورمه-سبزی'));
        $this->assertSame('article:قورمه-سبزی', $response->body());
    }

    public function testWrongMethodReturns405NotFound(): void
    {
        $this->assertSame(405, $this->router()->dispatch(Request::fake('POST', '/search'))->status());
    }

    public function testHeadIsServedByGetHandler(): void
    {
        $this->assertSame(200, $this->router()->dispatch(Request::fake('HEAD', '/'))->status());
    }

    public function testFallbackHandlesUnmatchedPaths(): void
    {
        $router = $this->router();
        $router->fallback(fn($r) => Response::text('resolved:' . $r->path));

        $this->assertSame('resolved:/cooking/stews', $router->dispatch(Request::fake('GET', '/cooking/stews'))->body());
    }

    public function testFallbackReturningNullFallsThroughToNotFound(): void
    {
        $router = $this->router();
        $router->fallback(fn() => null);
        $router->notFound(fn() => Response::text('missing', 404));

        $this->assertSame('missing', $router->dispatch(Request::fake('GET', '/nope'))->body());
    }

    public function testDotsInPathAreMatchedLiterally(): void
    {
        $router = new Router();
        $router->get('/llms.txt', fn() => Response::text('manifest'));

        $this->assertSame('manifest', $router->dispatch(Request::fake('GET', '/llms.txt'))->body());
        // A regex-unescaped dot would let /llmsXtxt through.
        $this->assertSame(404, $router->dispatch(Request::fake('GET', '/llmsXtxt'))->status());
    }
}
