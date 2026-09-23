<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Support\UrlGuard;

final class UrlGuardTest extends TestCase
{
    public function run(): void {}

    public function testAcceptsOrdinaryUrls(): void
    {
        $this->assertTrue(UrlGuard::isHttpUrl('https://www.fsis.usda.gov/food-safety'));
        $this->assertTrue(UrlGuard::isHttpUrl('http://example.ir/page?x=1#top'));
    }

    public function testRejectsScriptAndDataSchemes(): void
    {
        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,<script>', 'vbscript:x', 'file:///etc/passwd'] as $url) {
            $this->assertFalse(UrlGuard::isHttpUrl($url), 'must reject ' . $url);
        }
    }

    public function testRejectsSchemeHidingTricks(): void
    {
        // Whitespace and control characters are how scheme filters get bypassed.
        $this->assertFalse(UrlGuard::isHttpUrl(" javascript:alert(1)"));
        $this->assertFalse(UrlGuard::isHttpUrl("java\tscript:alert(1)"));
        $this->assertFalse(UrlGuard::isHttpUrl("https://exa\nmple.com"));
    }

    public function testRejectsRelativeAndProtocolRelative(): void
    {
        $this->assertFalse(UrlGuard::isHttpUrl('/local/path'));
        $this->assertFalse(UrlGuard::isHttpUrl('//evil.test/x'));
        $this->assertFalse(UrlGuard::isHttpUrl('example.com'));
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $this->assertFalse(UrlGuard::isHttpUrl('https://bank.example@evil.test/login'));
    }

    public function testRejectsHostWithoutADot(): void
    {
        $this->assertFalse(UrlGuard::isHttpUrl('http://localhost/admin'));
    }

    public function testMediaPathAcceptsWhatMediaStoreWrites(): void
    {
        $this->assertTrue(UrlGuard::isMediaPath('2026/09/ab12cd34-960.webp'));
        $this->assertTrue(UrlGuard::isMediaPath('hero.jpg'));
    }

    public function testMediaPathRejectsTraversalAndAbsolutePaths(): void
    {
        foreach (['../app/config.local.php', '2026/../../app', '/etc/passwd', '.htaccess', 'a//b.jpg', 'https://x.test/a.jpg', ''] as $path) {
            $this->assertFalse(UrlGuard::isMediaPath($path), 'must reject ' . var_export($path, true));
        }
    }
}
