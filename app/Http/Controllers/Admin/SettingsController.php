<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\PageCache;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AdminAuth;

final class SettingsController extends AdminController
{
    public static function index(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        return self::render($request, 'admin.settings', [
            'pageTitle' => 'Settings',
            'nav'       => 'settings',
            'adSlots'   => Database::all('SELECT * FROM ad_slots ORDER BY key_name'),
            'blocked'   => Database::all(
                'SELECT reason, hits, blocked_until FROM blocklist
                 WHERE blocked_until > NOW() ORDER BY blocked_until DESC LIMIT 25'
            ),
            'tokens'    => Database::all(
                'SELECT id, name, scopes, last_used_at, created_at, is_active FROM api_tokens ORDER BY id DESC'
            ),
            'networkEnabled' => Config::bool('ads.network.enabled'),
            'contributions' => [
                'enabled'           => \App\Support\Settings::bool('contributions.enabled', true),
                'judge_can_publish' => \App\Support\Settings::bool('contributions.judge_can_publish', true),
                'judge_min_score'   => Config::int('contributions.judge_min_score', 80),
                'pepper'            => \App\Support\PhoneNumber::isConfigured(),
                'sms_driver'        => Config::string('sms.driver', 'kavenegar'),
                'sms_ready'         => \App\Support\Sms\SmsGateway::isConfigured(),
            ],
            'cacheFiles' => self::countCachedPages(),
        ]);
    }

    /** Change a slot's provider. */
    public static function updateAdSlot(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $provider = (string) $request->input('provider', 'none');
        if (!in_array($provider, ['none', 'house', 'network'], true)) {
            return self::redirectWith('/admin/settings', 'Unknown ad provider.', 'error');
        }

        Database::update('ad_slots', [
            'provider'  => $provider,
            'is_active' => $request->input('is_active') !== null ? 1 : 0,
        ], 'id = :id', ['id' => (int) ($params['id'] ?? 0)]);

        AdminAuth::audit(
            AdminAuth::user($request)['id'] ?? null,
            'ad_slot_update', 'ad_slot', (int) ($params['id'] ?? 0), $request,
            ['provider' => $provider]
        );

        PageCache::flush();

        $message = 'Ad slot updated.';
        if ($provider === 'network') {
            // Said plainly, because it is a real trade and it is reversible.
            $message .= ' Note: network mode loads third-party JavaScript that sets'
                . ' tracking cookies and will fail during an international blackout.'
                . ' It also needs ads.network.enabled = true in config.local.php.';
        }

        return self::redirectWith('/admin/settings', $message);
    }

    /** The two contribution switches, stored as runtime settings. */
    public static function updateContributions(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $enabled = $request->input('enabled') !== null;
        $judge = $request->input('judge_can_publish') !== null;
        \App\Support\Settings::set('contributions.enabled', $enabled);
        \App\Support\Settings::set('contributions.judge_can_publish', $judge);

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'contributions_settings', null, null, $request, ['enabled' => $enabled, 'judge_can_publish' => $judge]);

        return self::redirectWith('/admin/settings', 'Contribution settings saved.');
    }

    public static function flushCache(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $removed = PageCache::flush();
        \App\Domain\Publisher::regenerateTree();

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'cache_flush', null, null, $request);

        return self::redirectWith('/admin/settings', "Cleared {$removed} cached page(s).");
    }

    public static function unblock(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $removed = Database::run('DELETE FROM blocklist')->rowCount();

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'blocklist_clear', null, null, $request);

        return self::redirectWith('/admin/settings', "Cleared {$removed} block(s).");
    }

    private static function countCachedPages(): int
    {
        $root = \App\Core\Paths::pages();
        if (!is_dir($root)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getFilename() === 'index.html') {
                $count++;
            }
        }

        return $count;
    }
}
