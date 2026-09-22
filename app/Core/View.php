<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Plain PHP templates.
 *
 * No template language: PHP is already one, the host runs it natively, and it
 * means no compilation step and no cache directory to go stale on a shared
 * host. Escaping is explicit via e() — see app/Views/helpers.php.
 */
final class View
{
    private static array $shared = [];

    /** Data made available to every template (site name, nav state, …). */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = []): string
    {
        $file = dirname(__DIR__) . '/Views/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $scope = [...self::$shared, ...$data];

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $__scope): void {
                extract($__scope, EXTR_SKIP);
                require $__file;
            })($file, $scope);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            // Discard the half-built output rather than leaking a broken page.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Render a template inside a layout. The layout receives the rendered
     * template as $content.
     */
    public static function page(string $template, string $layout, array $data = []): string
    {
        $content = self::render($template, $data);

        return self::render($layout, [...$data, 'content' => $content]);
    }
}
