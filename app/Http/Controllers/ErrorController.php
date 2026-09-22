<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class ErrorController
{
    public static function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $html = View::page('public.error', 'public.layout', [
            'code'      => 404,
            'headline'  => 'این صفحه پیدا نشد',
            'message'   => 'شاید نشانی را اشتباه وارد کرده‌اید، یا این مطلب هنوز نوشته نشده است.',
            'pageTitle' => 'صفحه پیدا نشد',
            'bodyClass' => 'page-error',
        ]);

        return Response::html($html, 404);
    }

    public static function serverError(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'server_error'], 500);
        }

        $html = View::page('public.error', 'public.layout', [
            'code'      => 500,
            'headline'  => 'خطایی رخ داد',
            'message'   => 'مشکلی در سرور پیش آمده است. لطفاً چند لحظه بعد دوباره تلاش کنید.',
            'pageTitle' => 'خطای سرور',
            'bodyClass' => 'page-error',
        ]);

        return Response::html($html, 500)->noCache();
    }
}
