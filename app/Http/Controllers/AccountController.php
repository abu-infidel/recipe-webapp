<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Domain\ArticleComposer;
use App\Domain\ContributorAuth;
use App\Domain\FieldRepository;
use App\Domain\MediaStore;
use App\Domain\Submissions;
use App\Http\Page;
use App\Support\Ephemeral;
use App\Support\PersianText;

/**
 * The contributor area: sign in with an SMS code, send articles, follow
 * what happened to them. Persian, inside the theme's layout, never cached
 * and never indexed. Its one cookie is scoped to /account.
 */
final class AccountController
{
    private const ERRORS = [
        'unavailable'   => 'ورود با پیامک فعلاً در دسترس نیست.',
        'invalid_phone' => 'شماره همراه ایرانی را به شکل ۰۹۱۲۳۴۵۶۷۸۹ وارد کنید.',
        'suspended'     => 'این حساب غیرفعال شده است.',
        'too_soon'      => 'کد قبلی تازه فرستاده شده است. لطفاً %d ثانیه دیگر دوباره تلاش کنید.',
        'phone_limit'   => 'امروز برای این شماره کد زیادی فرستاده شده است. لطفاً فردا تلاش کنید.',
        'ip_limit'      => 'درخواست‌های زیادی از این شبکه رسیده است. لطفاً کمی بعد تلاش کنید.',
        'busy'          => 'سامانه پیامک این لحظه شلوغ است. لطفاً چند دقیقه بعد تلاش کنید.',
        'send_failed'   => 'فرستادن پیامک ممکن نشد. لطفاً چند دقیقه بعد دوباره تلاش کنید.',
        'expired'       => 'این کد منقضی شده یا چند بار اشتباه وارد شده است. کد تازه بگیرید.',
        'wrong_code'    => 'کد درست نیست. %d بار دیگر می‌توانید تلاش کنید.',
        'pow'           => 'بررسی مرورگر انجام نشد. صفحه را دوباره بارگذاری کنید.',
    ];

    // ---------------------------------------------------------------- pages

    public static function index(Request $request): Response
    {
        self::maybeSweep();

        $contributor = ContributorAuth::current($request);
        if ($contributor === null) {
            return self::loginPage($request);
        }

        return self::page('حساب من', 'account.dashboard', [
            'contributor' => $contributor,
            'submissions' => Submissions::forContributor((int) $contributor['id']),
            'flash'       => self::flash($request),
        ], 200, ['/assets/core/account.js']);
    }

    public static function requestCode(Request $request): Response
    {
        if (!ContributorAuth::available()) {
            return self::loginPage($request, self::ERRORS['unavailable'], 503);
        }

        // The proof of work is what makes sending codes expensive for a
        // script. The nonce is bound to this address and single use.
        $nonce = (string) $request->input('nonce', '');
        $solution = (string) $request->input('solution', '');
        if (!ChallengeController::isAuthentic($nonce, $request->ip)
            || !ChallengeController::isValidSolution($nonce, $solution, Config::int('security.bot_gate.pow_difficulty', 16))
            || !Ephemeral::add('powused:' . sha1($nonce), '1', 900)) {
            return self::loginPage($request, self::ERRORS['pow'], 400);
        }

        $phone = mb_substr((string) $request->input('phone', ''), 0, 40, 'UTF-8');
        $result = ContributorAuth::requestCode($phone, $request->ip);

        if (!$result['ok']) {
            $message = sprintf(self::ERRORS[$result['error']] ?? self::ERRORS['send_failed'], $result['wait'] ?? 0);
            return self::loginPage($request, PersianText::toPersianDigits($message), 429, $phone);
        }

        return self::page('ورود', 'account.code', [
            'token'  => $result['token'],
            'masked' => $result['masked'],
            'ttl'    => Config::int('otp.ttl_seconds', 120),
            'error'  => null,
        ]);
    }

    public static function verify(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        $result = ContributorAuth::verifyCode($token, (string) $request->input('code', ''));

        if (!$result['ok']) {
            if ($result['error'] === 'wrong_code') {
                return self::page('ورود', 'account.code', [
                    'token'  => $token,
                    'masked' => '',
                    'ttl'    => 0,
                    'error'  => PersianText::toPersianDigits(sprintf(self::ERRORS['wrong_code'], $result['remaining'] ?? 0)),
                ], 422);
            }
            return self::loginPage($request, self::ERRORS[$result['error']] ?? self::ERRORS['expired'], 422);
        }

        $cookie = ContributorAuth::startSession($result['contributor'], $request);
        ContributorAuth::setCookie($cookie, time() + Config::int('otp.session_days', 30) * 86400);

        return Response::redirect('/account')->noCache();
    }

    public static function logout(Request $request): Response
    {
        if (ContributorAuth::current($request) !== null && ContributorAuth::checkCsrf($request)) {
            ContributorAuth::endSession();
        }

        return Response::redirect('/account')->noCache();
    }

    public static function profile(Request $request): Response
    {
        $contributor = self::requireSignedIn($request, true);
        if ($contributor instanceof Response) {
            return $contributor;
        }

        $name = PersianText::display(trim((string) $request->input('display_name', '')));
        $name = preg_replace('/[<>\x00-\x1F]/u', '', $name) ?? '';
        Database::run('UPDATE contributors SET display_name = :n WHERE id = :id', [
            'n' => mb_substr($name, 0, 80, 'UTF-8'), 'id' => $contributor['id'],
        ]);

        return self::back('/account', 'profile');
    }

    // ---------------------------------------------------------- submissions

    public static function create(Request $request): Response
    {
        $contributor = self::requireSignedIn($request);
        if ($contributor instanceof Response) {
            return $contributor;
        }

        return self::editor($request, null, [
            'field_id' => (int) ($request->query('field') ?? 0),
            'kind'     => in_array($request->query('kind'), ['recipe', 'guide'], true) ? $request->query('kind') : 'recipe',
            'title'    => '', 'summary' => '', 'hero_media_id' => null,
        ], ArticleComposer::blank(($request->query('kind') ?? 'recipe') === 'recipe'));
    }

    public static function show(Request $request, array $params): Response
    {
        $contributor = self::requireSignedIn($request);
        if ($contributor instanceof Response) {
            return $contributor;
        }

        $submission = Submissions::findFor((int) $contributor['id'], (int) ($params['id'] ?? 0));
        if ($submission === null) {
            return self::page('پیدا نشد', 'account.message', ['message' => 'این نوشته پیدا نشد.'], 404);
        }

        if (in_array($submission['status'], Submissions::OPEN, true)) {
            return self::editor($request, $submission, [
                'field_id' => (int) $submission['field_id'],
                'kind'     => $submission['kind'],
                'title'    => $submission['title_fa'],
                'summary'  => (string) ($submission['summary_fa'] ?? ''),
                'hero_media_id' => $submission['hero_media_id'],
            ], json_decode((string) $submission['doc'], true) ?: ArticleComposer::blank());
        }

        $doc = json_decode((string) $submission['doc'], true) ?: [];

        return self::page($submission['title_fa'], 'account.submission', [
            'submission' => $submission,
            'preview'    => ArticleComposer::render($doc, MediaStore::byIds(ArticleComposer::mediaIds($doc))),
            'articleUrl' => self::articleUrl($submission),
        ], 200, ['/assets/core/account.js']);
    }

    /** POST /account/new and /account/submissions/{id} */
    public static function submit(Request $request, array $params = []): Response
    {
        $contributor = self::requireSignedIn($request, true);
        if ($contributor instanceof Response) {
            return $contributor;
        }

        $id = isset($params['id']) ? (int) $params['id'] : null;
        $meta = [
            'field_id'      => (int) $request->input('field_id', '0'),
            'kind'          => (string) $request->input('kind', ''),
            'title'         => (string) $request->input('title', ''),
            'summary'       => (string) $request->input('summary', ''),
            'hero_media_id' => (int) $request->input('hero_media_id', '0') ?: null,
        ];
        $posted = json_decode((string) $request->input('doc', ''), true);
        if ($meta['kind'] !== 'recipe' && is_array($posted)) {
            $posted['recipe'] = null;
        }

        $result = Submissions::submit($contributor, $id, $meta, $posted);

        if (!$result['ok']) {
            $existing = $id !== null ? Submissions::findFor((int) $contributor['id'], $id) : null;
            return self::editor($request, $existing, $meta, ArticleComposer::normalize($posted, 'fa')['doc'], $result['errors']);
        }

        return self::back('/account', $id === null ? 'sent' : 'resent');
    }

    public static function withdraw(Request $request, array $params): Response
    {
        $contributor = self::requireSignedIn($request, true);
        if ($contributor instanceof Response) {
            return $contributor;
        }

        Submissions::withdraw((int) $contributor['id'], (int) ($params['id'] ?? 0));

        return self::back('/account', 'withdrawn');
    }

    /** POST /account/media — one image, JSON for the editor script. */
    public static function upload(Request $request): Response
    {
        $contributor = ContributorAuth::current($request);
        if ($contributor === null || !ContributorAuth::checkCsrf($request)) {
            return Response::json(['ok' => false, 'error' => 'برای بارگذاری باید وارد شده باشید.'], 403);
        }

        $today = (int) Database::value(
            'SELECT COUNT(*) FROM media WHERE contributor_id = :c AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
            ['c' => $contributor['id']], 0
        );
        if ($today >= Config::int('contributions.uploads_per_day', 20)) {
            return Response::json(['ok' => false, 'error' => 'امروز به سقف بارگذاری تصویر رسیده‌اید.'], 429);
        }

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return Response::json(['ok' => false, 'error' => 'تصویری دریافت نشد، یا حجم آن بیش از حد مجاز است.'], 422);
        }

        $result = MediaStore::store((string) $file['tmp_name'], [
            'kind'   => (string) $request->input('kind', 'inline') === 'hero' ? 'hero' : 'inline',
            'alt_fa' => (string) $request->input('alt', ''),
        ]);
        if (!$result['ok']) {
            // MediaStore's messages are English for the admin; a contributor
            // gets one plain Persian sentence.
            return Response::json(['ok' => false, 'error' => 'این فایل پذیرفته نشد. یک عکس JPEG، PNG یا WebP با عرض دست‌کم ۲۰۰ پیکسل بفرستید.'], 422);
        }

        Database::run('UPDATE media SET contributor_id = :c WHERE id = :id', ['c' => $contributor['id'], 'id' => $result['id']]);

        return Response::json([...$result, 'url' => '/media/' . $result['path']]);
    }

    // -------------------------------------------------------------- helpers

    private static function loginPage(Request $request, ?string $error = null, int $status = 200, string $phone = ''): Response
    {
        return self::page('ورود یا ثبت‌نام', 'account.login', [
            'available'  => ContributorAuth::available(),
            'nonce'      => ChallengeController::issueNonce($request->ip),
            'difficulty' => Config::int('security.bot_gate.pow_difficulty', 16),
            'error'      => $error,
            'phone'      => $phone,
        ], $status, ['/assets/core/account.js']);
    }

    private static function editor(Request $request, ?array $submission, array $meta, array $doc, array $errors = []): Response
    {
        $contributor = ContributorAuth::current($request);

        $fields = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$fields): void {
            foreach ($nodes as $node) {
                $fields[] = ['id' => (int) $node['id'], 'title' => (string) $node['title_fa'], 'depth' => $depth];
                $walk($node['children'] ?? [], $depth + 1);
            }
        };
        $walk(FieldRepository::tree(), 0);

        $mediaIds = ArticleComposer::mediaIds($doc);
        if (!empty($meta['hero_media_id'])) {
            $mediaIds[] = (int) $meta['hero_media_id'];
        }
        $media = [];
        foreach (MediaStore::byIds($mediaIds) as $id => $row) {
            $media[$id] = ['url' => '/media/' . $row['path']];
        }

        return self::page($submission === null ? 'نوشته تازه' : 'ویرایش نوشته', 'account.compose', [
            'submission' => $submission,
            'meta'       => $meta,
            'fields'     => $fields,
            'errors'     => $errors,
            'csrf'       => ContributorAuth::csrfToken(),
            'contributor' => $contributor,
            'editorData' => ['doc' => $doc, 'media' => $media, 'upload' => '/account/media', 'strings' => self::strings()],
        ], $errors === [] ? 200 : 422, ['/assets/core/composer.js', '/assets/core/account.js']);
    }

    /** @return array|Response the signed-in contributor, or where to go instead */
    private static function requireSignedIn(Request $request, bool $post = false): array|Response
    {
        $contributor = ContributorAuth::current($request);
        if ($contributor === null) {
            return Response::redirect('/account')->noCache();
        }
        if ($post && (!$request->isPost() || !ContributorAuth::checkCsrf($request))) {
            return self::page('خطا', 'account.message', ['message' => 'نشست شما منقضی شده است. صفحه را دوباره باز کنید و دوباره تلاش کنید.'], 419);
        }

        return $contributor;
    }

    private static function page(string $title, string $view, array $data, int $status = 200, array $scripts = []): Response
    {
        $html = View::render($view, [...$data, 'csrf' => $data['csrf'] ?? ContributorAuth::csrfToken()]);

        return Page::core($title, $html, $scripts, $status);
    }

    /**
     * Messages shown after a redirect. Only these keys: free text in the URL
     * would let anyone link to this page with words of their choosing on it.
     */
    private const FLASH = [
        'sent'      => 'نوشته شما فرستاده شد. پس از بررسی، نتیجه را همین‌جا می‌بینید.',
        'resent'    => 'تغییرات فرستاده شد و نوشته دوباره بررسی می‌شود.',
        'withdrawn' => 'نوشته پس گرفته شد.',
        'profile'   => 'نام نمایشی ذخیره شد.',
    ];

    private static function back(string $path, string $key): Response
    {
        return Response::redirect($path . '?m=' . rawurlencode($key))->noCache();
    }

    private static function flash(Request $request): ?string
    {
        return self::FLASH[(string) $request->query('m', '')] ?? null;
    }

    private static function articleUrl(array $submission): ?string
    {
        if (empty($submission['article_id'])) {
            return null;
        }
        $row = Database::first(
            "SELECT a.slug, f.path FROM articles a JOIN fields f ON f.id = a.field_id WHERE a.id = :id AND a.status = 'published'",
            ['id' => $submission['article_id']]
        );

        return $row === null ? null : Url::href(Url::article((string) $row['path'], (string) $row['slug']));
    }

    /** One visit in a hundred tidies spent codes and dead sessions. */
    private static function maybeSweep(): void
    {
        if (random_int(1, 100) === 1) {
            ContributorAuth::sweep();
        }
    }

    private static function strings(): array
    {
        return [
            'dir' => 'rtl',
            'intro' => 'مقدمه (پیش از نخستین عنوان)',
            'sections' => 'بخش‌ها',
            'section' => 'بخش',
            'heading' => 'عنوان بخش',
            'add_section' => 'افزودن بخش',
            'add_block' => 'افزودن',
            'type' => 'نوع قطعه',
            'remove' => 'حذف',
            'up' => 'بالاتر',
            'down' => 'پایین‌تر',
            'types' => [
                'paragraph' => 'بند', 'subheading' => 'عنوان فرعی', 'list' => 'فهرست',
                'ordered' => 'فهرست شماره‌دار', 'tip' => 'نکته', 'note' => 'یادداشت', 'warning' => 'هشدار',
                'quote' => 'نقل‌قول', 'image' => 'تصویر',
            ],
            'placeholders' => [
                'paragraph' => 'متن. برای بند تازه یک خط خالی بگذارید. **پررنگ** — برای ارجاع به منبع: [۱]',
                'list' => 'هر مورد در یک خط.',
                'ordered' => 'هر مرحله در یک خط.',
                'image' => 'زیرنویس تصویر (اختیاری)',
            ],
            'references' => 'منابع',
            'reference_hint' => 'در متن با شماره به منبع ارجاع دهید، مثلاً [۱]. اگر عدد یا مقداری (دما، زمان، وزن) می‌نویسید، جمله‌ای از منبع را که آن را می‌گوید در «نقل‌قول» بیاورید.',
            'add_reference' => 'افزودن منبع',
            'url' => 'نشانی (https://…)',
            'title' => 'عنوان منبع',
            'author' => 'نویسنده',
            'date' => 'تاریخ انتشار (YYYY-MM-DD)',
            'quote' => 'نقل‌قول پشتیبان از منبع (اختیاری)',
            'recipe' => 'دستور پخت',
            'yield' => 'برای چند نفر',
            'yield_unit' => 'واحد',
            'prep' => 'آماده‌سازی (دقیقه)',
            'cook' => 'پخت (دقیقه)',
            'difficulty' => 'سختی',
            'ingredients' => 'مواد لازم',
            'add_ingredient' => 'افزودن ماده',
            'quantity' => 'مقدار',
            'unit' => 'واحد',
            'name' => 'ماده',
            'note' => 'توضیح',
            'steps' => 'مراحل',
            'add_step' => 'افزودن مرحله',
            'step' => 'مرحله',
            'upload' => 'بارگذاری تصویر',
            'uploading' => 'در حال بارگذاری…',
            'replace' => 'جایگزینی تصویر',
            'upload_failed' => 'بارگذاری ناموفق بود',
        ];
    }
}
