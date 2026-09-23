<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Support\PhoneNumber;
use App\Support\Sms\GhasedakSender;
use App\Support\Sms\KavenegarSender;
use App\Support\Sms\LogSender;
use App\Support\Sms\SmsGateway;
use App\Support\Sms\SmsIrSender;

/**
 * Phone numbers are accepted only as Iranian mobiles and stored only as a
 * peppered hash; each SMS gateway is called the way its API expects.
 */
final class SmsAndPhoneTest extends TestCase
{
    public function run(): void {}

    private function boot(): void
    {
        Config::load(__DIR__ . '/../../app/config.php', __DIR__ . '/../../app/config.local.php');
    }

    public function testIranianMobilesInEverySpellingNormalise(): void
    {
        foreach (['09123456789', '9123456789', '+989123456789', '00989123456789', '989123456789',
                  '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '0912 345 6789', '(0912) 345-6789', ' +98 912 345 6789 '] as $input) {
            $this->assertSame('+989123456789', PhoneNumber::normalize($input), "normalises \"{$input}\"");
        }
    }

    public function testEverythingElseIsRefused(): void
    {
        // Landlines, foreign numbers (the SMS-pumping target), short and long
        // numbers, and junk.
        foreach (['02112345678', '+442071234567', '+19175551234', '0912345678', '091234567890', '+98212345678', 'abc', '', '09123456789; DROP'] as $input) {
            $this->assertSame(null, PhoneNumber::normalize($input), "refuses \"{$input}\"");
        }
        $this->assertSame('09123456789', PhoneNumber::local('+989123456789'));
    }

    public function testTheHashNeedsThePepperAndDependsOnIt(): void
    {
        $this->boot();
        Config::set('security.phone_pepper', '');
        $threw = false;
        try {
            PhoneNumber::hash('+989123456789');
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'no pepper, no hash: a plain hash of a billion numbers is reversible');

        Config::set('security.phone_pepper', str_repeat('a', 32));
        $a = PhoneNumber::hash('+989123456789');
        Config::set('security.phone_pepper', str_repeat('b', 32));
        $b = PhoneNumber::hash('+989123456789');

        $this->assertSame(64, strlen($a));
        $this->assertFalse($a === $b, 'a different pepper gives a different identity');
        $this->assertFalse($a === hash('sha256', '+989123456789'), 'not a plain hash');
        $this->boot();
    }

    private function recorder(array &$calls, int $status, string $body): callable
    {
        return static function (string $method, string $url, array $headers, ?string $payload, int $timeout) use (&$calls, $status, $body): array {
            $calls[] = compact('method', 'url', 'headers', 'payload', 'timeout');
            return ['status' => $status, 'body' => $body, 'error' => ''];
        };
    }

    public function testKavenegarUsesTheVerifyLookupTemplate(): void
    {
        $calls = [];
        $sender = new KavenegarSender('KEY/1', 'login-code', 5, $this->recorder($calls, 200, '{"return":{"status":200,"message":"تایید شد"}}'));
        $result = $sender->sendCode('09123456789', '123456');

        $this->assertTrue($result->ok);
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('https://api.kavenegar.com/v1/KEY%2F1/verify/lookup.json?receptor=09123456789&token=123456&template=login-code', $calls[0]['url']);

        $calls = [];
        $failed = (new KavenegarSender('SECRETKEY', 't', 5, $this->recorder($calls, 200, '{"return":{"status":418,"message":"credit"}}')))->sendCode('09123456789', '1');
        $this->assertFalse($failed->ok);
        $this->assertStringNotContains('SECRETKEY', $failed->detail, 'the key never reaches a log line');
    }

    public function testSmsIrPostsJsonWithTheApiKeyHeader(): void
    {
        $calls = [];
        $sender = new SmsIrSender('K', 123456, 'CODE', 5, $this->recorder($calls, 200, '{"status":1,"message":"موفق"}'));

        $this->assertTrue($sender->sendCode('09123456789', '654321')->ok);
        $this->assertSame('https://api.sms.ir/v1/send/verify', $calls[0]['url']);
        $this->assertSame('K', $calls[0]['headers']['X-API-KEY']);
        $this->assertSame(
            ['mobile' => '09123456789', 'templateId' => 123456, 'parameters' => [['name' => 'CODE', 'value' => '654321']]],
            json_decode((string) $calls[0]['payload'], true)
        );

        $calls = [];
        $this->assertFalse((new SmsIrSender('K', 1, 'CODE', 5, $this->recorder($calls, 401, '{"status":0,"message":"bad key"}')))->sendCode('09123456789', '1')->ok);
        $this->assertFalse((new SmsIrSender('', 0))->sendCode('09123456789', '1')->ok, 'unconfigured is a failure, not a call');
    }

    public function testGhasedakPostsTheTemplateForm(): void
    {
        $calls = [];
        $sender = new GhasedakSender('K', 'otp', 5, $this->recorder($calls, 200, '{"result":{"code":200,"message":"success"}}'));

        $this->assertTrue($sender->sendCode('09123456789', '111222')->ok);
        $this->assertSame('https://api.ghasedak.me/v2/verification/send/simple', $calls[0]['url']);
        $this->assertSame('K', $calls[0]['headers']['apikey']);
        parse_str((string) $calls[0]['payload'], $form);
        $this->assertSame(['receptor' => '09123456789', 'type' => '1', 'template' => 'otp', 'param1' => '111222'], $form);
    }

    public function testTheLogDriverIsForDevelopmentOnly(): void
    {
        $this->boot();
        $file = sys_get_temp_dir() . '/sms-test-' . bin2hex(random_bytes(3)) . '.log';
        $this->assertTrue((new LogSender($file))->sendCode('09123456789', '999000')->ok);
        $this->assertStringContains("09123456789\t999000", (string) file_get_contents($file));
        unlink($file);

        Config::set('sms.driver', 'log');
        Config::set('debug', false);
        $threw = false;
        try {
            SmsGateway::sender();
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the log driver refuses to run with debug off: it writes numbers and codes to disk');
        $this->assertFalse(SmsGateway::isConfigured());
        $this->boot();
    }

    public function testConfiguredMeansCredentialsArePresent(): void
    {
        $this->boot();
        Config::set('sms.driver', 'kavenegar');
        Config::set('sms.kavenegar', ['api_key' => '', 'template' => '']);
        $this->assertFalse(SmsGateway::isConfigured());
        Config::set('sms.kavenegar', ['api_key' => 'k', 'template' => 't']);
        $this->assertTrue(SmsGateway::isConfigured());
        Config::set('sms.driver', 'carrier-pigeon');
        $this->assertFalse(SmsGateway::isConfigured());
        $this->boot();
    }
}
