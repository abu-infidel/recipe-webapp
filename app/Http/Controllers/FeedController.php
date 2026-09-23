<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;

/**
 * The Atom feed at /feed.xml.
 *
 * Feed readers and aggregators still drive a meaningful share of discovery,
 * and several search engines use feeds to notice new pages quickly.
 *
 * Summaries only, never full text: a feed of complete articles would be the
 * one-request bulk export the rest of the site is built to prevent.
 */
final class FeedController
{
    private const ENTRIES = 30;

    public static function atom(Request $request): Response
    {
        $rows = Database::all(
            "SELECT a.id, a.slug, a.title_fa, a.summary_fa, a.published_at, a.updated_at,
                    f.path AS field_path, f.title_fa AS field_title
             FROM articles a INNER JOIN fields f ON f.id = a.field_id
             WHERE a.status = 'published' AND f.is_published = 1
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT " . self::ENTRIES
        );

        $home = Url::home();
        $host = (string) (parse_url($home, PHP_URL_HOST) ?: 'localhost');
        $updated = $rows === [] ? date('c') : self::iso($rows[0]['updated_at'] ?? $rows[0]['published_at']);
        $x = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="fa">' . "\n";
        $xml .= '  <title>' . $x(Config::string('site.name_fa')) . '</title>' . "\n";
        $xml .= '  <subtitle>' . $x(Config::string('site.tagline_fa')) . '</subtitle>' . "\n";
        $xml .= '  <link href="' . $x(Url::base() . '/feed.xml') . '" rel="self" type="application/atom+xml"/>' . "\n";
        $xml .= '  <link href="' . $x($home) . '" rel="alternate" type="text/html"/>' . "\n";
        $xml .= '  <id>' . $x($home) . '</id>' . "\n";
        $xml .= '  <updated>' . $x($updated) . '</updated>' . "\n";
        $xml .= '  <author><name>' . $x(Config::string('site.name_fa')) . '</name></author>' . "\n";
        $xml .= '  <icon>' . $x(Url::base() . '/assets/img/icon.svg') . '</icon>' . "\n";
        $xml .= '  <logo>' . $x(Url::base() . '/assets/img/logo-512.png') . '</logo>' . "\n";

        foreach ($rows as $row) {
            $url = Url::article((string) $row['field_path'], (string) $row['slug']);
            $published = self::iso($row['published_at']);

            $xml .= "  <entry>\n";
            $xml .= '    <title>' . $x($row['title_fa']) . '</title>' . "\n";
            $xml .= '    <link href="' . $x($url) . '" rel="alternate" type="text/html"/>' . "\n";
            // A tag: URI, not the URL, so readers do not show an entry again
            // if its slug ever changes. Stable because the id never does.
            $xml .= '    <id>tag:' . $x($host) . ',' . substr($published, 0, 4) . ':article-' . (int) $row['id'] . '</id>' . "\n";
            $xml .= '    <published>' . $x($published) . '</published>' . "\n";
            $xml .= '    <updated>' . $x(self::iso($row['updated_at'] ?? $row['published_at'])) . '</updated>' . "\n";
            $xml .= '    <category term="' . $x((string) $row['field_path']) . '" label="' . $x($row['field_title']) . '"/>' . "\n";
            if (!empty($row['summary_fa'])) {
                $xml .= '    <summary type="text">' . $x(trim(strip_tags((string) $row['summary_fa']))) . '</summary>' . "\n";
            }
            $xml .= "  </entry>\n";
        }

        $xml .= "</feed>\n";

        return Response::xml($xml)
            ->withHeader('Content-Type', 'application/atom+xml; charset=UTF-8')
            ->cacheFor(1800);
    }

    private static function iso(?string $datetime): string
    {
        $timestamp = $datetime !== null ? strtotime($datetime) : false;

        return date('c', $timestamp === false ? time() : $timestamp);
    }
}
