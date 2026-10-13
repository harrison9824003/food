<?php

namespace Tests\Feature\Sms;

use App\Jobs\SendSmsJob;
use App\Sms\Every8dDriver;
use App\Sms\MockSmsDriver;
use App\Sms\SmsDriver;
use App\Sms\SmsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

/**
 * 簡訊發送：驅動選擇、Every8d 請求格式與回應處理、重試與日誌。
 *
 * Every8d 部分全部以 Http::fake() 測試「我們送出的請求」與「對各種回應的處理」，
 * 沒有對真實服務驗證過（沒有帳號），見 docs/sms.md。
 */
class SmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.every8d.com/API21/HTTP/sendSMS.ashx';

    private function every8d(): Every8dDriver
    {
        config(['sms.every8d.uid' => 'user1', 'sms.every8d.pwd' => 'secret1']);

        return new Every8dDriver;
    }

    // ── 驅動選擇 ────────────────────────────────────────────────────────

    public function test_the_driver_follows_configuration(): void
    {
        config(['sms.driver' => 'mock']);
        $this->assertInstanceOf(MockSmsDriver::class, app(SmsDriver::class));

        config(['sms.driver' => 'every8d']);
        $this->assertInstanceOf(Every8dDriver::class, app(SmsDriver::class));
    }

    public function test_the_mock_driver_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['sms.driver' => 'mock']);

        $this->expectException(LogicException::class);
        app(SmsDriver::class);
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        config(['sms.driver' => 'carrier-pigeon']);

        $this->expectException(LogicException::class);
        app(SmsDriver::class);
    }

    public function test_the_mock_driver_stores_the_message_in_its_inbox(): void
    {
        (new MockSmsDriver)->send('0912345678', '測試內容');

        $this->assertDatabaseHas('mock_sms_messages', ['phone' => '0912345678', 'body' => '測試內容']);
    }

    // ── Every8d ────────────────────────────────────────────────────────

    public function test_every8d_sends_the_documented_request(): void
    {
        Http::fake([self::URL => Http::response('99.0,1,1.0,0,batch-123')]);

        $this->every8d()->send('0912345678', '您的驗證碼是 123456');

        Http::assertSent(fn ($request) => $request->url() === self::URL
            && $request->method() === 'POST'
            && $request['UID'] === 'user1'
            && $request['PWD'] === 'secret1'
            && $request['DEST'] === '0912345678'
            && $request['MSG'] === '您的驗證碼是 123456');
    }

    public function test_every8d_error_codes_are_failures(): void
    {
        foreach (['-300', '-100', '', '   '] as $body) {
            Http::fake([self::URL => Http::response($body)]);

            try {
                $this->every8d()->send('0912345678', 'x');
                $this->fail("回應 '{$body}' 應該算失敗");
            } catch (SmsException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every8d_reports_unsent_messages_as_failures(): void
    {
        Http::fake([self::URL => Http::response('99.0,0,0.0,1,batch-1')]); // 第 4 欄「未發送」= 1

        $this->expectException(SmsException::class);
        $this->every8d()->send('0912345678', 'x');
    }

    public function test_every8d_http_errors_and_timeouts_are_failures(): void
    {
        Http::fake([self::URL => Http::response('oops', 503)]);
        try {
            $this->every8d()->send('0912345678', 'x');
            $this->fail('HTTP 503 應該算失敗');
        } catch (SmsException) {
            $this->addToAssertionCount(1);
        }

        Http::fake([self::URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->expectException(SmsException::class);
        $this->every8d()->send('0912345678', 'x');
    }

    public function test_every8d_without_credentials_fails_without_calling_the_api(): void
    {
        config(['sms.every8d.uid' => null, 'sms.every8d.pwd' => null]);
        Http::fake();

        try {
            (new Every8dDriver)->send('0912345678', 'x');
            $this->fail('沒有帳號應該失敗');
        } catch (SmsException) {
            Http::assertNothingSent();
        }
    }

    // ── 工作（重試）────────────────────────────────────────────────────

    public function test_the_job_sends_through_the_configured_driver(): void
    {
        SendSmsJob::dispatchSync('0912345678', '內容');

        $this->assertDatabaseHas('mock_sms_messages', ['phone' => '0912345678', 'body' => '內容']);
    }

    public function test_the_job_retries_with_exponential_backoff_and_gives_up(): void
    {
        $job = new SendSmsJob('0912345678', 'x');

        $this->assertSame(4, $job->tries, '第一次加上三次重試');
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job, '驗證碼不能以明文放進 Redis');
    }

    public function test_the_request_id_travels_with_the_job_so_worker_logs_can_be_traced(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\Bus::fake();

        $this->withHeader('X-Request-Id', $id)->postJson('/api/phone/send-code', ['phone' => '0912345678'])->assertOk();

        \Illuminate\Support\Facades\Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->requestId === $id);
    }

    public function test_a_failing_driver_makes_the_job_throw_so_the_queue_retries(): void
    {
        $this->app->instance(SmsDriver::class, new class implements SmsDriver
        {
            public function send(string $phone, string $message): void
            {
                throw new SmsException('vendor down');
            }
        });

        $this->expectException(SmsException::class);
        (new SendSmsJob('0912345678', 'x'))->handle(app(SmsDriver::class));
    }

    // ── 日誌 ────────────────────────────────────────────────────────────

    public function test_logs_never_contain_the_code_or_the_full_phone_number(): void
    {
        $lines = [];
        Log::shouldReceive('channel')->with('sms')->andReturnSelf();
        Log::shouldReceive('info', 'warning', 'error')->andReturnUsing(function (...$args) use (&$lines) {
            $lines[] = json_encode($args);
        });
        Log::shouldReceive('shareContext')->andReturnNull();

        app(\App\Sms\OtpService::class)->send('0912345678', '203.0.113.5');
        $code = preg_match('/\d{6}/', (string) \DB::table('mock_sms_messages')->value('body'), $m) ? $m[0] : '';

        $this->assertNotSame('', $code);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString($code, $line, '日誌不可出現驗證碼');
            $this->assertStringNotContainsString('0912345678', $line, '日誌只留手機末三碼');
            $this->assertStringContainsString('*******678', $line);
        }
    }
}
