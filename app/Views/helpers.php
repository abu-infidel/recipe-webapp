<?php
declare(strict_types=1);

/**
 * Template helpers. Required once by the front controller; every function is
 * short enough to read at a glance from inside a template.
 */

use App\Core\Url;
use App\Support\PersianText;

/** Escape for HTML text and attribute context. Use on every dynamic value. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape for use inside a JavaScript string or a JSON island. */
function ejs(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?: 'null';
}

/** Persian numerals, for anything a reader sees. */
function fa(int|string $value): string
{
    return PersianText::toPersianDigits((string) $value);
}

function asset(string $path): string
{
    return Url::asset($path);
}

/** Gregorian date rendered in the Persian (Jalali) calendar. */
function jalali(?string $datetime, string $pattern = 'd MMMM y'): string
{
    return \App\Support\Jalali::format($datetime, $pattern);
}
