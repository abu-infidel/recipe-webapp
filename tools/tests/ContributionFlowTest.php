<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Core\Database;
use App\Domain\ContributorAuth;
use App\Domain\Submissions;
use App\Support\PhoneNumber;
use App\Support\Settings;
use App\Support\Sms\SmsGateway;
use App\Support\Sms\SmsResult;
use App\Support\Sms\SmsSender;

/** Captures codes instead of sending them. */
final class RecordingSender implements SmsSender
{
    public array $sent = [];
    public bool $fail = false;

    public function sendCode(string $local, string $code): SmsResult
    {
        $this->sent[] = [$local, $code];

        return $this->fail ? SmsResult::failed('down') : SmsResult::sent();
    }

    public function name(): string
    {
        return 'recording';
    }
}

/**
 * Sign-in by code and the life of a submission, against the development
 * database. Skipped when there is none. Every row it creates is removed.
 */
final class ContributionFlowTest extends TestCase
{
    public function run(): void {}

    private RecordingSender $sms;
    /** @var list<string> */
    private array $phones = [];
    /** @var list<int> */
    private array $articles = [];

    private function boot(): bool
    {
        Config::load(__DIR__ . '/../../app/config.php', __DIR__ . '/../../app/config.local.php');
        Config::set('security.phone_pepper', 'test-pepper-0123456789abcdef0123456789');
        Config::set('sms.driver', 'log');
        Config::set('debug', true);
        Settings::reset();
        ContributorAuth::reset();
        $this->sms = new RecordingSender();
        SmsGateway::use($this->sms);

        try {
            Database::connect();
            Database::value('SELECT 1 FROM submissions LIMIT 1');
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** A number no real test run will share, in the 0999 range. */
    private function phone(): string
    {
        $phone = '0999' . str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
        $this->phones[] = $phone;

        return $phone;
    }

    private function cleanup(): void
    {
        foreach ($this->articles as $id) {
            \App\Domain\SearchIndex::remove($id);
            Database::run('DELETE FROM articles WHERE id = :id', ['id' => $id]);
        }
        foreach ($this->phones as $phone) {
            $hash = PhoneNumber::hash((string) PhoneNumber::normalize($phone));
            Database::run('DELETE FROM contributors WHERE phone_hash = :h', ['h' => $hash]);
            Database::run('DELETE FROM otp_requests WHERE phone_hash = :h', ['h' => $hash]);
        }
        Database::run("DELETE FROM jobs WHERE type = 'judge' AND payload LIKE '%\"title\":\"آزمون مشارکت%'");
        Database::run("DELETE FROM sources WHERE url LIKE 'https://contribution-test.example/%'");
        if ($this->articles !== []) {
            \App\Domain\FieldRepository::recountAll();
            \App\Core\PageCache::flush();
        }
        $this->phones = [];
        $this->articles = [];
        SmsGateway::use(null);
        // The judge test flips this stored setting; put it back to "not
        // overridden" so the development site follows config again.
        Settings::forget('contributions.judge_can_publish');
    }

    private function signIn(): array
    {
        $phone = $this->phone();
        $sent = ContributorAuth::requestCode($phone, '203.0.113.' . random_int(1, 250));
        $code = end($this->sms->sent)[1];

        return ContributorAuth::verifyCode($sent['token'], $code)['contributor'];
    }

    // ---- codes --------------------------------------------------------------

    public function testACodeSignsInAndCreatesTheAccountOnce(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $phone = $this->phone();
            $sent = ContributorAuth::requestCode($phone, '203.0.113.5');

            $this->assertTrue($sent['ok']);
            $this->assertSame(PhoneNumber::local((string) PhoneNumber::normalize($phone)), $this->sms->sent[0][0], 'sent to the local form');
            $this->assertSame(1, preg_match('/^09\d{2}•••\d{4}$/', $sent['masked']));

            $code = $this->sms->sent[0][1];
            $this->assertSame(1, preg_match('/^\d{6}$/', $code));

            // Nothing stored reveals the number or the code.
            $row = Database::first('SELECT * FROM otp_requests ORDER BY id DESC LIMIT 1');
            $this->assertStringNotContains(substr($phone, 1), json_encode($row));
            $this->assertStringNotContains($code, (string) $row['code_hash']);

            $verified = ContributorAuth::verifyCode($sent['token'], \App\Support\PersianText::toPersianDigits($code));
            $this->assertTrue($verified['ok'], 'Persian digits are accepted');

            $again = ContributorAuth::verifyCode($sent['token'], $code);
            $this->assertSame('expired', $again['error'] ?? null, 'a code works once');

            $count = (int) Database::value('SELECT COUNT(*) FROM contributors WHERE phone_hash = :h', ['h' => PhoneNumber::hash((string) PhoneNumber::normalize($phone))]);
            $this->assertSame(1, $count);
        } finally {
            $this->cleanup();
        }
    }

    public function testWrongGuessesRunOut(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $sent = ContributorAuth::requestCode($this->phone(), '203.0.113.6');
            $real = $this->sms->sent[0][1];
            $wrong = $real === '000000' ? '111111' : '000000';

            $first = ContributorAuth::verifyCode($sent['token'], $wrong);
            $this->assertSame(['wrong_code', 4], [$first['error'], $first['remaining']]);
            for ($i = 0; $i < 3; $i++) {
                ContributorAuth::verifyCode($sent['token'], $wrong);
            }
            $this->assertSame('expired', ContributorAuth::verifyCode($sent['token'], $wrong)['error'], 'fifth wrong guess ends it');
            $this->assertSame('expired', ContributorAuth::verifyCode($sent['token'], $real)['error'], 'even the right code is now refused');
            $this->assertSame('expired', ContributorAuth::verifyCode(str_repeat('a', 32), $real)['error'], 'an unknown token is refused');
        } finally {
            $this->cleanup();
        }
    }

    public function testCodesExpire(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $sent = ContributorAuth::requestCode($this->phone(), '203.0.113.7');
            Database::run('UPDATE otp_requests SET expires_at = :t WHERE token_hash = :h', ['t' => time() - 1, 'h' => hash('sha256', $sent['token'])]);

            $this->assertSame('expired', ContributorAuth::verifyCode($sent['token'], $this->sms->sent[0][1])['error']);
        } finally {
            $this->cleanup();
        }
    }

    public function testSendingIsLimited(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $phone = $this->phone();
            $this->assertTrue(ContributorAuth::requestCode($phone, '203.0.113.8')['ok']);

            $soon = ContributorAuth::requestCode($phone, '203.0.113.8');
            $this->assertSame('too_soon', $soon['error']);
            $this->assertTrue($soon['wait'] > 0 && $soon['wait'] <= 60);

            // Pretend the earlier codes were sent long enough ago to resend,
            // then use up the day's allowance for the number.
            $hash = PhoneNumber::hash((string) PhoneNumber::normalize($phone));
            for ($i = 0; $i < 4; $i++) {
                Database::run('UPDATE otp_requests SET created_at = created_at - 120 WHERE phone_hash = :h', ['h' => $hash]);
                ContributorAuth::requestCode($phone, '203.0.113.8');
            }
            Database::run('UPDATE otp_requests SET created_at = created_at - 120 WHERE phone_hash = :h', ['h' => $hash]);
            $this->assertSame('phone_limit', ContributorAuth::requestCode($phone, '203.0.113.8')['error'] ?? null);

            Config::set('otp.per_ip_per_hour', 1);
            $this->assertSame('ip_limit', ContributorAuth::requestCode($this->phone(), '203.0.113.8')['error'] ?? null, 'per address');

            Config::set('otp.global_per_hour', 1);
            $this->assertSame('busy', ContributorAuth::requestCode($this->phone(), '198.51.100.1')['error'] ?? null, 'site-wide ceiling');

            $this->assertSame('invalid_phone', ContributorAuth::requestCode('+442071234567', '198.51.100.2')['error']);
        } finally {
            $this->cleanup();
        }
    }

    public function testAFailedSendIsSpentNotUsable(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $this->sms->fail = true;
            $result = ContributorAuth::requestCode($this->phone(), '203.0.113.9');

            $this->assertSame('send_failed', $result['error']);
            $this->assertSame(1, (int) Database::value('SELECT consumed FROM otp_requests ORDER BY id DESC LIMIT 1'));
        } finally {
            $this->cleanup();
        }
    }

    public function testSuspendedAccountsCannotSignIn(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $contributor = $this->signIn();
            Database::run("UPDATE contributors SET status = 'suspended' WHERE id = :id", ['id' => $contributor['id']]);
            $phone = end($this->phones);
            Database::run('UPDATE otp_requests SET created_at = created_at - 120 WHERE phone_hash = :h', ['h' => $contributor['phone_hash']]);

            $this->assertSame('suspended', ContributorAuth::requestCode($phone, '203.0.113.10')['error'] ?? null);
        } finally {
            $this->cleanup();
        }
    }

    // ---- submissions -----------------------------------------------------------

    private function field(): array
    {
        return Database::first('SELECT * FROM fields WHERE is_published = 1 ORDER BY depth DESC, id LIMIT 1');
    }

    private function doc(bool $withReference = true): array
    {
        return [
            'intro' => [['type' => 'paragraph', 'text' => 'برای هر پیمانه برنج ۱٫۵ پیمانه آب لازم است' . ($withReference ? ' [1].' : '.')]],
            'sections' => [['heading' => 'نکته', 'blocks' => [['type' => 'tip', 'text' => 'در قابلمه را باز نکنید.']]]],
            'references' => $withReference ? [['url' => 'https://contribution-test.example/rice', 'title' => 'Rice', 'quote' => 'Use 1.5 cups of water.']] : [],
            'recipe' => ['ingredients' => [['quantity' => '2', 'unit' => 'پیمانه', 'name' => 'برنج']], 'steps' => [['text' => 'بپزید.']]],
        ];
    }

    private function meta(string $title = 'آزمون مشارکت'): array
    {
        return ['field_id' => $this->field()['id'], 'kind' => 'recipe', 'title' => $title . ' ' . random_int(1000, 9999), 'summary' => 'خلاصه'];
    }

    public function testASubmissionIsCheckedAndQueuedForTheJudge(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $contributor = $this->signIn();

            $invented = $this->doc();
            $invented['intro'][] = ['type' => 'paragraph', 'text' => 'دمای روغن ۱۸۰ درجه [7].'];
            $refused = Submissions::submit($contributor, null, $this->meta(), $invented);
            $this->assertFalse($refused['ok']);
            $this->assertStringContains('[۷]', implode("\n", $refused['errors']), 'an invented reference is refused at the door, in Persian');

            $foreignImage = $this->doc();
            $foreignImage['intro'][] = ['type' => 'image', 'media_id' => 999999, 'text' => ''];
            $this->assertFalse(Submissions::submit($contributor, null, $this->meta(), $foreignImage)['ok'], 'someone else\'s image is refused');

            $ok = Submissions::submit($contributor, null, $this->meta(), $this->doc());
            $this->assertTrue($ok['ok'], implode('; ', $ok['errors'] ?? []));

            $job = Database::first("SELECT * FROM jobs WHERE type = 'judge' ORDER BY id DESC LIMIT 1");
            $payload = json_decode((string) $job['payload'], true);
            $this->assertSame($ok['id'], $payload['submission_id']);
            $this->assertSame(1, $payload['revision']);
            $this->assertStringContains('۱٫۵ پیمانه آب', $payload['body']);

            Config::set('contributions.max_pending', 1);
            $this->assertFalse(Submissions::submit($contributor, null, $this->meta(), $this->doc())['ok'], 'open submissions are capped');
        } finally {
            $this->cleanup();
        }
    }

    public function testTheJudgePublishesOnlyAConfidentCleanApproval(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $contributor = $this->signIn();
            Database::run("UPDATE contributors SET display_name = 'مریم آزمون' WHERE id = :id", ['id' => $contributor['id']]);
            // This test sends more than a real person may in a day.
            Config::set('contributions.max_per_day', 100);
            $good = ['verdict' => 'approve', 'score' => 92, 'food_safety_ok' => true, 'summary' => 'fine', 'issues' => [], 'model' => 'test'];

            $cases = [
                'setting off'    => [$good, static fn() => Settings::set('contributions.judge_can_publish', false)],
                'low score'      => [[...$good, 'score' => 60], null],
                'revise'         => [[...$good, 'verdict' => 'revise'], null],
                'safety unknown' => [[...$good, 'food_safety_ok' => false], null],
                'major issue'    => [[...$good, 'issues' => [['severity' => 'major', 'message' => 'x']]], null],
            ];
            foreach ($cases as $label => [$verdict, $setup]) {
                Settings::set('contributions.judge_can_publish', true);
                if ($setup !== null) {
                    $setup();
                }
                Database::run("UPDATE submissions SET status = 'withdrawn' WHERE contributor_id = :c", ['c' => $contributor['id']]);
                $id = Submissions::submit($contributor, null, $this->meta(), $this->doc())['id'];
                $outcome = Submissions::recordJudgement($id, 1, $verdict);

                $this->assertTrue($outcome['stored'], "{$label}: verdict stored");
                $this->assertFalse($outcome['published'], "{$label}: waits for the owner");
                $this->assertSame('pending', Database::value('SELECT status FROM submissions WHERE id = :id', ['id' => $id]), "{$label}: still pending");
            }

            Settings::set('contributions.judge_can_publish', true);
            Database::run("UPDATE submissions SET status = 'withdrawn' WHERE contributor_id = :c", ['c' => $contributor['id']]);
            $noRefs = Submissions::submit($contributor, null, $this->meta(), $this->doc(false))['id'];
            $this->assertFalse(Submissions::recordJudgement($noRefs, 1, $good)['published'], 'no references: waits');

            Database::run("UPDATE submissions SET status = 'withdrawn' WHERE contributor_id = :c", ['c' => $contributor['id']]);
            $id = Submissions::submit($contributor, null, $this->meta(), $this->doc())['id'];
            $this->assertSame('stale', Submissions::recordJudgement($id, 2, $good)['reason'], 'a verdict for an older revision is ignored');
            $this->assertSame('malformed verdict', Submissions::recordJudgement($id, 1, ['verdict' => 'yes'])['reason']);

            $outcome = Submissions::recordJudgement($id, 1, $good);
            $this->assertTrue($outcome['published'], 'confident, clean, referenced: published');

            $submission = Submissions::find($id);
            $this->articles[] = (int) $submission['article_id'];
            $article = Database::first('SELECT * FROM articles WHERE id = :id', ['id' => $submission['article_id']]);
            $this->assertSame(['approved', 'judge'], [$submission['status'], $submission['decided_by']]);
            $this->assertSame('published', $article['status']);
            $this->assertSame('مریم آزمون', $article['author_display'], 'the byline is the contributor\'s chosen name');
            $this->assertSame((int) $contributor['id'], (int) $article['author_contributor_id']);
        } finally {
            $this->cleanup();
        }
    }

    public function testTheOwnerCanReturnRejectAndApproveAsDraft(): void
    {
        if (!$this->boot()) {
            $this->assertTrue(true);
            return;
        }
        try {
            $contributor = $this->signIn();
            $id = Submissions::submit($contributor, null, $this->meta(), $this->doc())['id'];

            $this->assertTrue(Submissions::decide($id, 'needs_changes', null, 'منبع دوم را اضافه کنید.'));
            $resubmitted = Submissions::submit($contributor, $id, $this->meta(), $this->doc());
            $this->assertTrue($resubmitted['ok']);
            $this->assertSame(['pending', 2], [Database::value('SELECT status FROM submissions WHERE id = :id', ['id' => $id]), (int) Database::value('SELECT revision FROM submissions WHERE id = :id', ['id' => $id])]);

            $approved = Submissions::approve($id, false, null);
            $this->assertTrue($approved['ok']);
            $this->articles[] = $approved['article_id'];
            $this->assertSame('draft', Database::value('SELECT status FROM articles WHERE id = :id', ['id' => $approved['article_id']]), 'approve as draft does not publish');
            $this->assertFalse(Submissions::submit($contributor, $id, $this->meta(), $this->doc())['ok'], 'a decided submission cannot be edited');

            $other = Submissions::submit($contributor, null, $this->meta(), $this->doc())['id'];
            $this->assertTrue(Submissions::decide($other, 'rejected', null, ''));
            $this->assertSame(1, (int) Database::value('SELECT rejected_count FROM contributors WHERE id = :id', ['id' => $contributor['id']]));
            $this->assertFalse(Submissions::decide($other, 'approved', null, ''), 'decide() never approves');
        } finally {
            $this->cleanup();
        }
    }
}
