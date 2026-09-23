<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Paths;
use App\Core\Request;
use App\Core\Response;
use App\Core\Template\TemplateError;
use App\Core\Template\Theme;
use App\Core\Template\ThemeChecker;
use App\Domain\AdminAuth;
use App\Domain\FieldRepository;
use App\Domain\ThemeInstaller;
use App\Http\PageResolver;
use App\Http\UiContract;

/**
 * Themes: upload, check, preview, activate. The way a redesign reaches the
 * live site without git.
 */
final class ThemeController extends AdminController
{
    public static function index(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        return self::page($request);
    }

    public static function upload(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $file = $_FILES['theme'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $reason = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts (upload_max_filesize).',
                UPLOAD_ERR_NO_FILE => 'Choose a .zip file first.',
                default => 'The upload did not complete. Try again.',
            };

            return self::page($request, ['title' => 'Upload failed', 'ok' => false, 'errors' => [$reason], 'warnings' => []]);
        }

        $result = ThemeInstaller::installZip((string) $file['tmp_name'], $request->input('replace') !== null);

        AdminAuth::audit(
            AdminAuth::user($request)['id'] ?? null,
            $result['ok'] ? 'theme_install' : 'theme_install_rejected', 'theme', null, $request,
            ['name' => $result['name'], 'errors' => count($result['errors'])]
        );

        return self::page($request, [
            'title'    => $result['ok'] ? "Installed \"{$result['name']}\". Preview it, then activate it." : 'The theme was not installed',
            ...$result,
        ]);
    }

    public static function activate(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $name = (string) ($params['name'] ?? '');
        $result = ThemeInstaller::activate($name);

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'theme_activate', 'theme', null, $request, ['name' => $name, 'ok' => $result['ok']]);

        if (!$result['ok']) {
            return self::page($request, ['title' => "\"{$name}\" was not activated", ...$result]);
        }

        // Pages are cacheable in browsers for up to an hour, and those copies
        // still point at the previous theme's stylesheet.
        return self::redirectWith('/admin/themes', "\"{$name}\" is now live and the page cache was cleared."
            . ' Readers\' browsers may show the previous theme for up to an hour, so keep it installed for a day before deleting it.');
    }

    public static function delete(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $name = (string) ($params['name'] ?? '');
        $error = ThemeInstaller::delete($name);

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'theme_delete', 'theme', null, $request, ['name' => $name, 'ok' => $error === null]);

        return $error === null
            ? self::redirectWith('/admin/themes', "Removed \"{$name}\".")
            : self::redirectWith('/admin/themes', $error, 'error');
    }

    /**
     * A public page rendered with any installed theme, live or not.
     *   ?path=/ashpazi        real data for that URL
     *   ?fixture=article      the contract's sample data
     *
     * Served under /admin so the admin cookie authorises it; links inside go
     * to the live site.
     */
    public static function preview(Request $request, array $params): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $name = (string) ($params['name'] ?? '');
        try {
            $theme = Theme::load($name);
        } catch (TemplateError $e) {
            return Response::text($e->getMessage(), 404)->noCache();
        }

        $fixture = (string) $request->query('fixture', '');
        try {
            if ($fixture !== '') {
                if (!in_array($fixture, Theme::PAGE_TYPES, true)) {
                    return Response::text('Unknown page type.', 400)->noCache();
                }
                $html = $theme->render($fixture, UiContract::fixture($fixture, $theme));
            } else {
                $resolved = PageResolver::resolve((string) $request->query('path', '/'), $theme);
                if ($resolved['model'] === null) {
                    return Response::text("That URL redirects to {$resolved['redirect']}.", 200)->noCache();
                }
                $html = $theme->render($resolved['page_type'], $resolved['model']);
            }
        } catch (TemplateError $e) {
            return Response::text("The theme failed to render this page:\n\n" . $e->getMessage(), 500)->noCache();
        }

        return Response::html($html)->noCache()->withHeader('X-Robots-Tag', 'noindex');
    }

    private static function page(Request $request, ?array $report = null): Response
    {
        $themes = [];
        foreach (ThemeInstaller::all() as $theme) {
            $themes[] = [...$theme, 'check' => ThemeChecker::check(Paths::themes() . '/' . $theme['name'], $theme['name'])];
        }

        $field = FieldRepository::tree()[0] ?? null;
        $article = Database::first(
            "SELECT a.slug, f.path FROM articles a JOIN fields f ON f.id = a.field_id
             WHERE a.status = 'published' ORDER BY a.published_at DESC LIMIT 1"
        );

        $samples = ['Home' => '/'];
        if ($field !== null) {
            $samples['A field'] = '/' . $field['path'];
        }
        if ($article !== null) {
            $samples['Latest article'] = '/' . $article['path'] . '/' . $article['slug'];
        }
        $samples['Search'] = '/search?q=' . rawurlencode('برنج');
        $samples['Not found'] = '/this-page-does-not-exist';

        return self::render($request, 'admin.themes', [
            'pageTitle' => 'Themes',
            'nav'       => 'themes',
            'themes'    => $themes,
            'samples'   => $samples,
            'report'    => $report,
            'maxUpload' => ini_get('upload_max_filesize') ?: '?',
        ]);
    }
}
