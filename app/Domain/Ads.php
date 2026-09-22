<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;

/**
 * Ad slots.
 *
 * Three providers, switchable per slot from the admin panel with no code
 * change:
 *
 *   none    — renders nothing and collapses, leaving no gap
 *   house   — a creative from this site's own database, server-rendered,
 *             no JavaScript, no cookies, targeted by topic only
 *   network — a third-party script
 *
 * 'house' is the default. Turning a slot to 'network' loads foreign
 * JavaScript that sets tracking cookies and will fail during an international
 * blackout; the admin screen says so in those words before you confirm it.
 * Nothing here ever records anything per visitor — counts are daily
 * aggregates.
 */
final class Ads
{
    /**
     * Pick a creative for a slot.
     *
     * Contextual targeting only: a creative may be tied to a field, never to
     * anything about the person reading. Selection is weighted-random so a
     * rotation is possible without storing which ad a visitor last saw.
     */
    public static function creativeFor(string $slotKey, ?int $fieldId = null): ?array
    {
        $slot = self::slot($slotKey);
        if ($slot === null || !$slot['is_active'] || $slot['provider'] !== 'house') {
            return null;
        }

        $candidates = Database::all(
            'SELECT c.*, m.path AS media_path, m.alt_fa AS media_alt
             FROM ad_creatives c
             LEFT JOIN media m ON m.id = c.media_id
             WHERE c.slot_id = :slot
               AND c.is_active = 1
               AND (c.starts_at IS NULL OR c.starts_at <= NOW())
               AND (c.ends_at IS NULL OR c.ends_at >= NOW())
               AND (c.field_id IS NULL OR c.field_id = :field)
             ORDER BY c.field_id IS NULL ASC',
            ['slot' => (int) $slot['id'], 'field' => $fieldId ?? 0]
        );

        if ($candidates === []) {
            return null;
        }

        $total = array_sum(array_map(static fn($c) => max(1, (int) $c['weight']), $candidates));
        $roll = random_int(1, $total);

        foreach ($candidates as $candidate) {
            $roll -= max(1, (int) $candidate['weight']);
            if ($roll <= 0) {
                self::recordImpression((int) $candidate['id']);
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /** @return array{provider:string, network:?array}|null */
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

        return Config::string('ads.network.script_url') ?: null;
    }

    /**
     * Daily aggregate counters. There is no per-visitor row, no identifier,
     * and nothing that could reconstruct one visitor's history.
     */
    public static function recordImpression(int $creativeId): void
    {
        Database::run(
            'INSERT INTO ad_stats_daily (creative_id, day, impressions, clicks)
             VALUES (:id, CURDATE(), 1, 0)
             ON DUPLICATE KEY UPDATE impressions = impressions + 1',
            ['id' => $creativeId]
        );
    }

    public static function recordClick(int $creativeId): void
    {
        Database::run(
            'INSERT INTO ad_stats_daily (creative_id, day, impressions, clicks)
             VALUES (:id, CURDATE(), 0, 1)
             ON DUPLICATE KEY UPDATE clicks = clicks + 1',
            ['id' => $creativeId]
        );
    }
}
