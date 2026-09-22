<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\AdminAuth;

/**
 * Shared behaviour for every admin screen: the auth gate, the CSRF check and
 * the layout wrapper.
 *
 * The admin is in English while the public site is Persian, so it has its own
 * LTR layout rather than fighting the RTL one.
 */
abstract class AdminController
{
    /**
     * Returns a Response when the request must not proceed (not signed in,
     * IP not allowed, bad CSRF token), or null to continue.
     */
    protected static function guard(Request $request, bool $requirePost = false): ?Response
    {
        $allowlist = (array) Config::get('security.admin.ip_allowlist', []);
        if ($allowlist !== [] && !in_array($request->ip, $allowlist, true)) {
            return Response::text("Not found\n", 404);
        }

        $user = AdminAuth::user($request);
        if ($user === null) {
            return Response::redirect('/admin/login');
        }

        if ($requirePost) {
            if (!$request->isPost()) {
                return Response::text('Method not allowed', 405);
            }
            if (!AdminAuth::checkCsrf($request)) {
                return Response::text('Invalid CSRF token. Reload the page and try again.', 419);
            }
        }

        return null;
    }

    protected static function render(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        $html = View::page($template, 'admin.layout', [
            ...$data,
            'user'  => AdminAuth::user($request),
            'csrf'  => AdminAuth::csrfToken(),
            'nav'   => $data['nav'] ?? '',
        ]);

        // Admin pages are never cached: they show live queue state and drafts.
        return Response::html($html, $status)->noCache();
    }

    /** Flash a message through a redirect without a session store. */
    protected static function redirectWith(string $path, string $message, string $type = 'ok'): Response
    {
        $query = http_build_query(['m' => $message, 't' => $type]);

        return Response::redirect($path . (str_contains($path, '?') ? '&' : '?') . $query);
    }
}
