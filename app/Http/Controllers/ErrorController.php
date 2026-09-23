<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Template\Theme;
use App\Http\Page;
use App\Http\ViewModels;

final class ErrorController
{
    /** Headline and message per status, shared with the UI API. */
    public const MESSAGES = [
        404 => ['این صفحه پیدا نشد', 'شاید نشانی را اشتباه وارد کرده‌اید، یا این مطلب هنوز نوشته نشده است.'],
        410 => ['این نوشته برداشته شده است', 'این صفحه دیگر در دسترس نیست. شاید در بخش‌های دیگر سایت مطلب مشابهی پیدا کنید.'],
        500 => ['خطایی رخ داد', 'مشکلی در سرور پیش آمده است. لطفاً چند لحظه بعد دوباره تلاش کنید.'],
    ];

    public static function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'not_found'], 404);
        }

        return Page::render('error', static fn(Theme $t) => ViewModels::error($t, 404, ...self::MESSAGES[404]), 404);
    }

    /**
     * 410 Gone: the page existed and was removed on purpose. Search engines
     * drop a 410 from their index far faster than a 404.
     */
    public static function gone(Request $request): Response
    {
        return Page::render('error', static fn(Theme $t) => ViewModels::error($t, 410, ...self::MESSAGES[410]), 410);
    }

    public static function serverError(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'server_error'], 500);
        }

        return Page::render('error', static fn(Theme $t) => ViewModels::error($t, 500, ...self::MESSAGES[500]), 500)->noCache();
    }
}
