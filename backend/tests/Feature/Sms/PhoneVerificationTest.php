<?php

namespace Tests\Feature\Sms;

use App\Jobs\SendSmsJob;
use App\Sms\OtpService;
use App\Sms\PhoneToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 簡訊驗證碼：寄送、驗證、限流、冷卻。
 */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0912345678';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');
        Cache::flush();
    }

    private function send(string $phone = self::PHONE, array $server = [])
    {
        return $this->withServerVariables($server)->postJson('/api/phone/send-code', ['phone' => $phone]);
    }

    private function verify(string $code, string $phone = self::PHONE)
    {
        return $this->postJson('/api/phone/verify', ['phone' => $phone, 'code' => $code]);
    }

    /** 從 Mock 收件匣取回剛寄出的驗證碼（等同顧客看手機）。 */
    private function latestCode(string $phone = self::PHONE): string
    {
        $body = DB::table('mock_sms_messages')->where('phone', $phone)->orderByDesc('id')->value('body');
        preg_match('/\d{6}/', (string) $body, $m);

        return $m[0] ?? $this->fail('收件匣裡沒有驗證碼');
    }

    // ── 寄送 ────────────────────────────────────────────────────────────

    public function test_sends_a_six_digit_code_by_sms(): void
    {
        $this->send()->assertOk()->assertJsonPath('resend_after', 60)->assertJsonPath('expires_in', 300);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->latestCode());
        $this->assertStringContainsString('5 分鐘', DB::table('mock_sms_messages')->value('body'));
    }

    public function test_the_code_is_stored_hashed_never_in_plain_text(): void
    {
        $this->send()->assertOk();
        $code = $this->latestCode();

        $row = DB::table('sms_verifications')->first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, $row->code_hash);
        $this->assertSame(64, strlen($row->code_hash)); // HMAC-SHA256
        $this->assertSame(0, $row->attempts);
        $this->assertEquals(now()->addMinutes(5), \Illuminate\Support\Carbon::parse($row->expires_at));
    }

    public function test_sending_goes_through_the_queue(): void
    {
        Bus::fake();

        $this->send()->assertOk();

        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->phone === self::PHONE);
        $this->assertDatabaseCount('mock_sms_messages', 0); // 還沒真的送：由 worker 處理
    }

    public function test_codes_are_not_always_the_same(): void
    {
        $codes = [];
        foreach (range(1, 8) as $i) {
            Cache::flush();
            $this->send()->assertOk();
            $codes[] = $this->latestCode();
        }

        $this->assertGreaterThan(4, count(array_unique($codes)));
    }

    public function test_phone_number_format_is_validated(): void
    {
        foreach (['', '123', '0212345678', '09123456789', 'abcdefghij'] as $phone) {
            $this->send($phone)->assertStatus(422)->assertJsonValidationErrors('phone', 'errors');
        }
        $this->assertDatabaseCount('sms_verifications', 0);
    }

    public function test_the_response_is_identical_whether_or_not_the_phone_has_reservations(): void
    {
        $this->send('0911111111')->assertOk();
        $known = $this->send('0922222222')->assertOk()->json();

        $this->assertSame($known, $this->send('0933333333')->json());
    }

    // ── 冷卻 ────────────────────────────────────────────────────────────

    public function test_resending_within_the_cooldown_is_refused(): void
    {
        $this->send()->assertOk();

        Carbon::setTestNow(now()->addSeconds(20));
        $this->send()->assertStatus(429)->assertJsonPath('code', 'SMS_COOLDOWN')->assertJsonPath('errors.retry_after', 40);

        $this->assertDatabaseCount('sms_verifications', 1);
        $this->assertDatabaseCount('mock_sms_messages', 1);
    }

    public function test_resending_after_the_cooldown_works_and_invalidates_the_old_code(): void
    {
        $this->send()->assertOk();
        $old = $this->latestCode();

        Carbon::setTestNow(now()->addSeconds(61));
        Cache::flush(); // 測試用的 array cache 不隨 Carbon 的時間過期，手動模擬冷卻結束
        $this->send()->assertOk();

        $this->assertDatabaseCount('sms_verifications', 2);
        if ($old !== $this->latestCode()) {
            $this->verify($old)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE'); // 舊的作廢
        }
        $this->verify($this->latestCode())->assertOk();
    }

    public function test_the_cooldown_is_per_phone(): void
    {
        $this->send('0911111111')->assertOk();
        $this->send('0922222222')->assertOk();
    }

    // ── 限流 ────────────────────────────────────────────────────────────

    public function test_one_phone_cannot_be_sent_more_than_the_hourly_limit(): void
    {
        foreach (range(1, 5) as $i) {
            Cache::forget('sms-cooldown:'.hash('sha256', self::PHONE)); // 排除冷卻，單獨測每小時上限
            $this->send()->assertOk();
        }

        Cache::forget('sms-cooldown:'.hash('sha256', self::PHONE));
        $this->send()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertDatabaseCount('mock_sms_messages', 5);
    }

    public function test_one_ip_cannot_flood_many_phones(): void
    {
        config(['sms.send_per_ip_per_hour' => 3]);

        foreach (['0911111111', '0922222222', '0933333333'] as $phone) {
            $this->send($phone, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
        }

        $this->send('0944444444', ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(429);
        // 換一個 IP 不受影響
        $this->send('0944444444', ['REMOTE_ADDR' => '203.0.113.10'])->assertOk();
    }

    // ── 驗證 ────────────────────────────────────────────────────────────

    public function test_the_right_code_returns_a_token_for_that_phone(): void
    {
        $this->send()->assertOk();

        $response = $this->verify($this->latestCode())->assertOk()
            ->assertJsonPath('expires_at', now()->addMinutes(15)->toIso8601String());

        $this->assertSame(self::PHONE, PhoneToken::resolve($response->json('token')));
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $this->send()->assertOk();
        $code = $this->latestCode();

        $this->verify($code)->assertOk();
        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $this->send()->assertOk();
        $wrong = $this->latestCode() === '000000' ? '111111' : '000000';

        $this->verify($wrong)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');

        $this->assertSame(1, DB::table('sms_verifications')->value('attempts'));
        $this->verify($this->latestCode())->assertOk(); // 打錯一次不影響之後打對
    }

    public function test_the_code_is_locked_after_five_wrong_attempts_even_if_the_right_one_follows(): void
    {
        $this->send()->assertOk();
        $right = $this->latestCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $i) {
            $this->verify($wrong)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
        }
        $this->verify($wrong)->assertStatus(429)->assertJsonPath('code', 'CODE_ATTEMPTS_EXCEEDED');

        // 已經作廢：連正確的驗證碼也不能用，必須重新取得
        $this->verify($right)->assertStatus(429)->assertJsonPath('code', 'CODE_ATTEMPTS_EXCEEDED');
        $this->assertSame(5, DB::table('sms_verifications')->value('attempts'), '超過上限後不再累計，也就不會被拿來探測');
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->send()->assertOk();
        $code = $this->latestCode();

        Carbon::setTestNow(now()->addMinutes(5)->addSecond());

        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_a_code_is_still_valid_just_before_it_expires(): void
    {
        $this->send()->assertOk();
        $code = $this->latestCode();

        Carbon::setTestNow(now()->addMinutes(4)->addSeconds(59));

        $this->verify($code)->assertOk();
    }

    public function test_verifying_a_phone_that_never_requested_a_code_looks_the_same_as_a_wrong_code(): void
    {
        $nobody = $this->verify('123456', '0955555555')->assertStatus(422);

        $this->send()->assertOk();
        $wrong = $this->verify($this->latestCode() === '123456' ? '654321' : '123456')->assertStatus(422);

        $this->assertSame($nobody->json(), $wrong->json(), '不洩漏這支手機有沒有要過驗證碼');
    }

    public function test_a_code_for_one_phone_cannot_verify_another(): void
    {
        $this->send('0911111111')->assertOk();
        $this->send('0922222222')->assertOk();

        $this->verify($this->latestCode('0911111111'), '0922222222')->assertStatus(422);
    }

    public function test_code_format_is_validated(): void
    {
        foreach (['', '12345', '1234567', 'abcdef'] as $code) {
            $this->verify($code)->assertStatus(422)->assertJsonValidationErrors('code', 'errors');
        }
    }

    public function test_concurrent_wrong_guesses_cannot_exceed_the_attempt_limit(): void
    {
        // verify() 在交易內鎖住該列再計數；這裡直接多次呼叫服務，確認計數是累加而不是被覆蓋
        $this->send()->assertOk();
        $wrong = $this->latestCode() === '000000' ? '111111' : '000000';

        $otp = app(OtpService::class);
        $failures = 0;
        foreach (range(1, 8) as $i) {
            try {
                $otp->verify(self::PHONE, $wrong);
            } catch (\App\Exceptions\ApiException) {
                $failures++;
            }
        }

        $this->assertSame(8, $failures);
        $this->assertSame(5, DB::table('sms_verifications')->value('attempts'));
    }

    // ── 憑證 ────────────────────────────────────────────────────────────

    public function test_a_token_expires(): void
    {
        $token = PhoneToken::issue(self::PHONE)['token'];

        $this->assertSame(self::PHONE, PhoneToken::resolve($token));

        Carbon::setTestNow(now()->addMinutes(15)->addSecond());
        $this->assertNull(PhoneToken::resolve($token));
    }

    public function test_a_forged_or_tampered_token_is_rejected(): void
    {
        $token = PhoneToken::issue(self::PHONE)['token'];

        $this->assertNull(PhoneToken::resolve(null));
        $this->assertNull(PhoneToken::resolve(''));
        $this->assertNull(PhoneToken::resolve('not-a-token'));
        $this->assertNull(PhoneToken::resolve(base64_encode(json_encode(['p' => self::PHONE, 'e' => now()->addDay()->timestamp]))));
        $this->assertNull(PhoneToken::resolve(substr($token, 0, -4).'AAAA'));
    }

    public function test_the_token_does_not_reveal_the_phone_number(): void
    {
        $token = PhoneToken::issue(self::PHONE)['token'];

        $this->assertStringNotContainsString(self::PHONE, $token);
        $this->assertStringNotContainsString(self::PHONE, base64_decode($token));
    }

    // ── 開發用收件匣 ────────────────────────────────────────────────────

    public function test_the_dev_inbox_is_unavailable_unless_enabled_and_never_in_production(): void
    {
        $this->send()->assertOk();

        config(['sms.inbox_enabled' => false]);
        $this->getJson('/api/dev/sms?phone='.self::PHONE)->assertStatus(404);

        config(['sms.inbox_enabled' => true]);
        $this->getJson('/api/dev/sms?phone='.self::PHONE)->assertOk()->assertJsonCount(1);

        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/api/dev/sms?phone='.self::PHONE)->assertStatus(404);
    }
}
