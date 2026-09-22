<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AdminAuth;

/**
 * A cheap "is anyone signed in?" check for the write-through page cache.
 *
 * Caching a page rendered for a signed-in editor would serve their view to
 * everyone. The admin cookie is scoped to /admin so it is not normally sent
 * with a public page request at all, but the cache is permanent once written
 * and the cost of being wrong is high, so the presence of the cookie is
 * enough to skip caching — no database lookup needed.
 */
final class AdminAuthProbe
{
    public static function isAnonymous(): bool
    {
        return empty($_COOKIE[AdminAuth::COOKIE]);
    }
}
