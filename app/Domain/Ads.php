<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Support\UrlGuard;

/**
 * Ad slots.
 *
 * Three providers, switchable per slot from the admin panel:
 *
 *   none    — renders nothing and collapses, leaving no gap
 *   house   — creatives from this site's own database, no third-party script,
 *             no cookies, targeted by topic only
 *   network — a third-party script
 *
 * Pages are cached as static files, so a creative chosen on the server would
 * be frozen into the cached page and shown to every reader until the cache
 * cleared. Instead the page carries the slot's eligible creatives and the
 * browser picks one. Impressions are counted when a creative is actually on
 * screen, by an anonymous beacon — nothing is written while a page renders,
 * and nothing identifies the reader.
 */
final class Ads
{
    /**
     * Every creative currently eligible for a slot, validated and ready to
     * embed. Contextual targeting only: a creative may be tied to a field,
     * never to anything about the person reading.
     *
     * @return list<array{id:int,title:string,body:string,href:string,image:?string,alt:string,weight:int}>
     */
    public static function eligibleCreatives(string $slotKey, ?int $fieldId = null): array
    {
        $slot = self::slot($slotKey);
        if ($slot === null || !$slot['is_active'] || $slot['provider'] !== 'house') {
            return [];
        }

        $rows = Database::all(
            'SELECT c.id, c.title_fa, c.body_fa, c.target_url, c.weight, m.path AS media_path, m.alt_fa AS media_alt
             FROM ad_creatives c
             LEFT JOIN media m ON m.id = c.media_id
             WHERE c.slot_id = :slot
               AND c.is_active = 1
               AND (c.starts_at IS NULL OR c.starts_at <= NOW())
               AND (c.ends_at IS NULL OR c.ends_at >= NOW())
               AND (c.field_id IS NULL OR c.field_id = :field)
             ORDER BY c.id
             LIMIT 20',
            ['slot' => (int) $slot['id'], 'field' => $fieldId ?? 0]
        );

        $creatives = [];
        foreach ($rows as $row) {
            // A creative whose link is not plain http(s) is dropped rather
            // than rendered: escaping alone does not stop javascript: URLs.
            if (!UrlGuard::isHttpUrl((string) $row['target_url'])) {
                continue;
            }

            $image = UrlGuard::isMediaPath($row['media_path'] ?? null) ? '/media/' . $row['media_path'] : null;

            $creatives[] = [
                'id'     => (int) $row['id'],
                'title'  => (string) $row['title_fa'],
                'body'   => (string) ($row['body_fa'] ?? ''),
                // Clicks go through our own redirect so they can be counted
                // without a script on the sponsor's side.
                'href'   => '/api/ad/' . (int) $row['id'] . '/go',
                'image'  => $image,
                'alt'    => (string) ($row['media_alt'] ?? ''),
                'weight' => max(1, (int) $row['weight']),
            ];
        }

        return $creatives;
    }

    /** @return array<string,mixed>|null */
    public static function slot(string $key): ?array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            foreach (Database::all('SELECT * FROM ad_slots') as $row) {
                $cache[(string) $row['key_name']] = $row;
            }
        }

        return $cache[$key] ?? null;
    }

    public static function networkScriptFor(string $slotKey): ?string
    {
        $slot = self::slot($slotKey);

        if ($slot === null || $slot['provider'] !== 'network' || !$slot['is_active']) {
            return null;
        }
        if (!Config::bool('ads.network.enabled')) {
            return null;
        }

        $url = Config::string('ads.network.script_url');

        return UrlGuard::isHttpUrl($url) ? $url : null;
    }

    /** The validated destination for a click, or null if the creative is gone. */
    public static function clickTarget(int $creativeId): ?string
    {
        $url = Database::value(
            'SELECT target_url FROM ad_creatives WHERE id = :id AND is_active = 1',
            ['id' => $creativeId]
        );

        return UrlGuard::safeHref($url === null ? null : (string) $url);
    }

    /**
     * Daily aggregate counters. There is no per-visitor row, no identifier,
     * and nothing that could reconstruct one reader's history.
     */
    public static function recordImpression(int $creativeId): void
    {
        self::bump($creativeId, 'impressions');
    }

    public static function recordClick(int $creativeId): void
    {
        self::bump($creativeId, 'clicks');
    }

    private static function bump(int $creativeId, string $column): void
    {
        // The column name is interpolated below, so it is allow-listed here
        // even though only this class calls it.
        if (!in_array($column, ['impressions', 'clicks'], true)) {
            return;
        }

        // Only for a creative that exists, so a beacon cannot invent rows.
        if (Database::value('SELECT 1 FROM ad_creatives WHERE id = :id', ['id' => $creativeId]) === null) {
            return;
        }

        $impressions = $column === 'impressions' ? 1 : 0;
        $clicks = $column === 'clicks' ? 1 : 0;

        Database::run(
            "INSERT INTO ad_stats_daily (creative_id, day, impressions, clicks)
             VALUES (:id, CURDATE(), :i, :c)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
            ['id' => $creativeId, 'i' => $impressions, 'c' => $clicks]
        );
    }
}
