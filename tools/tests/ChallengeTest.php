<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Http\Controllers\ChallengeController;

final class ChallengeTest extends TestCase
{
    public function run(): void {}

    private function configure(): void
    {
        Config::load(__DIR__ . '/../../app/config.php');
        Config::set('security.app_key', 'test-key-0123456789abcdef0123456789abcdef');
    }

    public function testIssuedNonceVerifiesForTheSameAddress(): void
    {
        $this->configure();
        $nonce = ChallengeController::issueNonce('203.0.113.7');

        $this->assertTrue(ChallengeController::isAuthentic($nonce, '203.0.113.7'));
    }

    public function testNonceIsBoundToTheAddressItWasIssuedTo(): void
    {
        // A solved nonce must not be redeemable from a different machine.
        $this->configure();
        $nonce = ChallengeController::issueNonce('203.0.113.7');

        $this->assertFalse(ChallengeController::isAuthentic($nonce, '198.51.100.9'));
    }

    public function testNonceExpires(): void
    {
        $this->configure();
        $issuedAt = 1_800_000_000;
        $nonce = ChallengeController::issueNonce('203.0.113.7', $issuedAt);

        $this->assertTrue(ChallengeController::isAuthentic($nonce, '203.0.113.7', $issuedAt + 299));
        $this->assertFalse(ChallengeController::isAuthentic($nonce, '203.0.113.7', $issuedAt + 301));
    }

    public function testNonceFromTheFutureIsRejected(): void
    {
        $this->configure();
        $nonce = ChallengeController::issueNonce('203.0.113.7', 1_800_000_100);

        $this->assertFalse(ChallengeController::isAuthentic($nonce, '203.0.113.7', 1_800_000_000));
    }

    public function testTamperedNonceIsRejected(): void
    {
        $this->configure();
        $nonce = ChallengeController::issueNonce('203.0.113.7');

        // Backdating the issue time to buy more solving time must break the signature.
        $parts = explode('.', $nonce);
        $parts[0] = (string) ((int) $parts[0] - 10);

        $this->assertFalse(ChallengeController::isAuthentic(implode('.', $parts), '203.0.113.7'));
    }

    public function testMalformedNoncesAreRejected(): void
    {
        $this->configure();

        foreach (['', 'abc', '1234567890.zz.yy', str_repeat('a', 200), "1800000000.0123456789abcdef.' OR 1=1"] as $bad) {
            $this->assertFalse(ChallengeController::isAuthentic($bad, '203.0.113.7'), 'must reject: ' . $bad);
        }
    }

    public function testSolutionCheckCountsRealBits(): void
    {
        // Find a solution for 8 bits by brute force, as the browser would.
        $nonce = 'fixed-nonce-for-test';
        $solution = null;
        for ($i = 0; $i < 100000; $i++) {
            if (ChallengeController::isValidSolution($nonce, (string) $i, 8)) {
                $solution = (string) $i;
                break;
            }
        }

        $this->assertTrue($solution !== null, 'an 8-bit solution must exist within 100k tries');
        $this->assertSame("\x00", hash('sha256', $nonce . $solution, true)[0], 'first byte must be zero');
    }

    public function testEmptyOrOversizedSolutionIsRejected(): void
    {
        $this->assertFalse(ChallengeController::isValidSolution('n', '', 1));
        $this->assertFalse(ChallengeController::isValidSolution('n', str_repeat('9', 65), 1));
    }
}
