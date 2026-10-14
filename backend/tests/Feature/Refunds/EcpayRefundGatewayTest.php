<?php

namespace Tests\Feature\Refunds;

use App\Models\Payment;
use App\Payments\EcpayGateway;
use App\Payments\PaymentGatewayException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesRefunds;
use Tests\TestCase;

/**
 * EcpayGateway::refund() 對各種回應的分類。
 * 關鍵區分：「金流商明確答覆」（成功或拒絕）vs「沒拿到答覆」（結果未知，可能已經退了）。
 */
class EcpayRefundGatewayTest extends TestCase
{
    use CreatesRefunds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRefunds();
    }

    private function payment(): Payment
    {
        return $this->paymentOf($this->paidReservation());
    }

    public function test_return_code_1_is_success(): void
    {
        $this->refundApiReturns(1, '交易成功');

        $result = app(EcpayGateway::class)->refund($this->payment(), 400);

        $this->assertTrue($result->success);
        $this->assertSame('交易成功', $result->message);
    }

    public function test_any_other_return_code_is_a_definite_refusal_not_an_error(): void
    {
        foreach ([0 => '訂單不存在', 10200073 => 'CheckMacValue Error.', 10200047 => '已退款'] as $code => $message) {
            $this->refundApiReturns($code, $message);

            $result = app(EcpayGateway::class)->refund($this->payment(), 400);

            $this->assertFalse($result->success, "RtnCode {$code}");
            $this->assertSame($message, $result->message);
        }
    }

    public function test_server_errors_mean_the_result_is_unknown(): void
    {
        $this->refundApiIsUnreachable();

        $this->expectException(PaymentGatewayException::class);
        app(EcpayGateway::class)->refund($this->payment(), 400);
    }

    public function test_a_connection_failure_means_the_result_is_unknown(): void
    {
        $this->refundApiNextCallable(fn () => throw new ConnectionException('timeout'));

        $this->expectException(PaymentGatewayException::class);
        app(EcpayGateway::class)->refund($this->payment(), 400);
    }

    public function test_an_unrecognisable_response_means_the_result_is_unknown(): void
    {
        $this->refundApiNextCallable(fn () => Http::response('<html>maintenance</html>'));

        $this->expectException(PaymentGatewayException::class);
        app(EcpayGateway::class)->refund($this->payment(), 400);
    }

    public function test_a_payment_without_a_gateway_trade_number_is_refused_without_calling_the_gateway(): void
    {
        $payment = $this->payment();
        $payment->update(['ecpay_trade_no' => null]);
        $before = $this->refundApiCalls();

        $result = app(EcpayGateway::class)->refund($payment->fresh(), 400);

        $this->assertFalse($result->success);
        $this->assertSame($before, $this->refundApiCalls());
    }

    public function test_the_gateway_never_retries_on_its_own(): void
    {
        $this->refundApiIsUnreachable();
        $before = $this->refundApiCalls();

        try {
            app(EcpayGateway::class)->refund($this->payment(), 400);
        } catch (PaymentGatewayException) {
            // expected
        }

        // 退款會改變金錢：逾時後自動重送可能退兩次，要不要再試由呼叫端依資料庫裡的狀態決定
        $this->assertSame($before + 1, $this->refundApiCalls());
    }

    private function refundApiNextCallable(callable $next): void
    {
        $this->refundApiNext = $next;
    }
}
