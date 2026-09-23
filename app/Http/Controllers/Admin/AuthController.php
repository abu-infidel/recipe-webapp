<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\AdminAuth;
use App\Support\RateLimiter;

final class AuthController extends AdminController
{
    public static function loginForm(Request $request): Response
    {
        if (AdminAuth::user($request) !== null) {
            return Response::redirect('/admin');
        }

        $html = View::page('admin.login', 'admin.layout_bare', [
            'error'     => $request->query('e'),
            'pageTitle' => 'Sign in',
            'csrf'      => AdminAuth::loginToken(),
        ]);

        return Response::html($html)->noCache();
    }

    public static function login(Request $request): Response
    {
        // Rate limited by IP as well as by account, so an attacker cannot work
        // through a list of addresses from one machine.
        $limit = RateLimiter::check($request->ip, 'api');
        if (!$limit['allowed']) {
            return Response::text('Too many attempts. Wait a minute.', 429)
                ->withHeader('Retry-After', (string) max(1, $limit['retry_after']));
        }

        if (!AdminAuth::checkLoginToken($request)) {
            return Response::redirect('/admin/login?e=' . rawurlencode('Your session expired. Please try again.'));
        }

        $result = AdminAuth::attempt(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            $request
        );

        if (!$result['ok']) {
            AdminAuth::audit(null, 'login_failed', null, null, $request, [
                'email' => mb_substr((string) $request->input('email', ''), 0, 190, 'UTF-8'),
            ]);

            return Response::redirect('/admin/login?e=' . rawurlencode($result['error']));
        }

        $cookie = AdminAuth::startSession($result['user'], $request);

        $response = Response::redirect('/admin');

        // The only cookie this site sets. Scoped to /admin, HttpOnly, and
        // SameSite=Strict so it is never sent from another origin.
        setcookie(AdminAuth::COOKIE, $cookie, [
            'expires'  => time() + Config::int('security.admin.session_minutes', 240) * 60,
            'path'     => '/admin',
            'secure'   => Config::string('site.scheme') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return $response;
    }

    public static function logout(Request $request): Response
    {
        $cookie = $_COOKIE[AdminAuth::COOKIE] ?? '';
        if (is_string($cookie) && $cookie !== '') {
            AdminAuth::destroySession($cookie);
        }

        setcookie(AdminAuth::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/admin',
            'secure'   => Config::string('site.scheme') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return Response::redirect('/admin/login');
    }
}
