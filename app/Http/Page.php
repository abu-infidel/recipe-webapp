<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Response;
use App\Core\Template\TemplateError;
use App\Core\Template\Theme;

/**
 * Renders a public page through the active theme.
 *
 * If the theme itself is broken — a template edited into a syntax error, a
 * missing partial — the reader still gets a working page: a plain built-in
 * one carrying the same core <head>, so SEO tags, styles and scripts survive.
 * The theme error goes to the log for whoever broke it. A redesign must never
 * be able to take the site down.
 */
final class Page
{
    /** @param callable(Theme):array $model builds the view model for a theme */
    public static function render(string $pageType, callable $model, int $status = 200): Response
    {
        $theme = Theme::active();
        $data = $model($theme);

        try {
            return Response::html($theme->render($pageType, $data), $status);
        } catch (TemplateError $e) {
            error_log("Theme \"{$theme->name}\" failed on {$pageType}: " . $e->getMessage());

            return Response::html(self::fallback($data), $status === 200 ? 200 : $status);
        }
    }

    /**
     * A deliberately plain page. Only core-built values reach it, all escaped
     * or already SafeHtml.
     */
    private static function fallback(array $data): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '';
        if (isset($data['article']['body'])) {
            $body = '<h1>' . $e($data['article']['title']) . '</h1>' . $data['article']['body'];
        } elseif (isset($data['error'])) {
            $body = '<h1>' . $e($data['error']['headline']) . '</h1><p>' . $e($data['error']['message']) . '</p>';
        } else {
            $body = '<h1>' . $e($data['page']['title'] ?? '') . '</h1>';
        }

        return '<!DOCTYPE html><html lang="fa" dir="rtl"><head>' . ($data['page']['head'] ?? '')
            . '</head><body><main id="main" style="max-width:48rem;margin:2rem auto;padding:0 1rem">'
            . $body . '<p><a href="/">خانه</a></p></main>' . ($data['page']['foot'] ?? '') . '</body></html>';
    }
}
