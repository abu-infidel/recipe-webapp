<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A small pattern router.
 *
 * Explicit routes are matched first; anything left over falls through to the
 * content resolver, which decides whether a path like
 * /cooking/persian/stews/ghormeh-sabzi is a field or an article. Content URLs
 * therefore carry no prefix, which keeps them short, readable in Persian, and
 * trivially convertible to subdomains later.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,names:list<string>,handler:callable}> */
    private array $routes = [];

    /** @var callable|null */
    private $fallback = null;

    /** @var callable|null */
    private $notFound = null;

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** Matches any method. Used for routes that accept both GET and POST. */
    public function any(string $pattern, callable $handler): void
    {
        $this->add('*', $pattern, $handler);
    }

    /** Handles anything no explicit route claimed. */
    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    public function notFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $allowedButWrongMethod = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== '*' && $route['method'] !== $request->method) {
                // HEAD is served by the GET handler; the body is discarded by PHP.
                if (!($route['method'] === 'GET' && $request->method === 'HEAD')) {
                    $allowedButWrongMethod = true;
                    continue;
                }
            }

            $params = [];
            foreach ($route['names'] as $name) {
                $params[$name] = isset($matches[$name]) ? rawurldecode($matches[$name]) : '';
            }

            return ($route['handler'])($request, $params);
        }

        if ($allowedButWrongMethod) {
            return Response::text('Method not allowed', 405);
        }

        if ($this->fallback !== null) {
            $response = ($this->fallback)($request);
            if ($response instanceof Response) {
                return $response;
            }
        }

        return $this->notFound !== null
            ? ($this->notFound)($request)
            : Response::text('Not found', 404);
    }

    /**
     * Compile "/article/{slug}" into a regex.
     *
     * {name}  matches a single path segment
     * {name*} matches the rest of the path, slashes included
     *
     * Literal text is escaped with preg_quote and placeholders are spliced in
     * afterwards, so a dot in a route like /llms.txt stays literal and cannot
     * match an arbitrary character.
     */
    private function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = '';
        $offset = 0;

        if (preg_match_all('/\{(\w+)(\*?)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                [$placeholder, $position] = $match[0];

                $regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');

                $name = $match[1][0];
                $names[] = $name;
                $regex .= '(?P<' . $name . '>' . ($match[2][0] === '*' ? '.+' : '[^/]+') . ')';

                $offset = $position + strlen($placeholder);
            }
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'names'   => $names,
            'handler' => $handler,
        ];
    }
}
