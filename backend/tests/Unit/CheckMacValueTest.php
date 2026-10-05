<?php

namespace Tests\Unit;

use App\Payments\CheckMacValue;
use PHPUnit\Framework\TestCase;

class CheckMacValueTest extends TestCase
{
    private const KEY = '5294y06JbISpM5x9';

    private const IV = 'v77hoKGq4kWxNNIS';

    /**
     * 這是綠界測試環境（payment-stage.ecpay.com.tw）QueryTradeInfo 的**真實回應**，
     * 簽章由綠界的伺服器產生。能驗證通過，代表我們的演算法與綠界一致（不是只和自己一致）。
     */
    private const RECORDED_ECPAY_RESPONSE = 'HandlingCharge=0&ItemName=&MerchantID=2000132&MerchantTradeNo=NOSUCHTRADE0000000001&PaymentDate=&PaymentType=&PaymentTypeChargeFee=0&TradeAmt=0&TradeDate=&TradeNo=&TradeStatus=10200047&CheckMacValue=8F8C5A2E219A4C0040A2D0A302A60117C145DA7232D8737C57E76B06A1BDE87A';

    /** @return array<string, string> */
    private function recorded(): array
    {
        parse_str(self::RECORDED_ECPAY_RESPONSE, $data);

        return $data;
    }

    public function test_reproduces_a_signature_generated_by_the_real_ecpay_server(): void
    {
        $data = $this->recorded();

        $this->assertSame(
            '8F8C5A2E219A4C0040A2D0A302A60117C145DA7232D8737C57E76B06A1BDE87A',
            CheckMacValue::generate($data, self::KEY, self::IV),
        );
        $this->assertTrue(CheckMacValue::verify($data, self::KEY, self::IV));
    }

    public function test_a_tampered_value_fails_verification(): void
    {
        $data = $this->recorded();
        $data['TradeAmt'] = '1';

        $this->assertFalse(CheckMacValue::verify($data, self::KEY, self::IV));
    }

    public function test_wrong_keys_fail_verification(): void
    {
        $this->assertFalse(CheckMacValue::verify($this->recorded(), 'wrong-key', self::IV));
        $this->assertFalse(CheckMacValue::verify($this->recorded(), self::KEY, 'wrong-iv'));
    }

    public function test_missing_or_empty_signature_fails_verification(): void
    {
        $data = $this->recorded();

        $this->assertFalse(CheckMacValue::verify(array_diff_key($data, ['CheckMacValue' => 1]), self::KEY, self::IV));
        $this->assertFalse(CheckMacValue::verify([...$data, 'CheckMacValue' => ''], self::KEY, self::IV));
    }

    public function test_signature_comparison_is_case_insensitive_on_the_received_value(): void
    {
        $data = $this->recorded();
        $data['CheckMacValue'] = strtolower($data['CheckMacValue']);

        $this->assertTrue(CheckMacValue::verify($data, self::KEY, self::IV));
    }

    public function test_parameter_order_does_not_matter(): void
    {
        $data = $this->recorded();

        $this->assertSame(
            CheckMacValue::generate($data, self::KEY, self::IV),
            CheckMacValue::generate(array_reverse($data, true), self::KEY, self::IV),
        );
    }

    public function test_the_signature_field_itself_is_excluded_from_the_calculation(): void
    {
        $params = ['MerchantID' => '2000132', 'TotalAmount' => '100'];
        $signed = [...$params, 'CheckMacValue' => 'ANYTHING'];

        $this->assertSame(
            CheckMacValue::generate($params, self::KEY, self::IV),
            CheckMacValue::generate($signed, self::KEY, self::IV),
        );
    }

    public function test_sorting_ignores_case(): void
    {
        // 排序若分大小寫（ASCII），'a' 會排在 'B' 之後；綠界要求不分大小寫排序，結果會不同
        $lower = ['alpha' => '1', 'Bravo' => '2'];
        $sameWithReversedInsertion = ['Bravo' => '2', 'alpha' => '1'];

        $this->assertSame(
            CheckMacValue::generate($lower, self::KEY, self::IV),
            CheckMacValue::generate($sameWithReversedInsertion, self::KEY, self::IV),
        );

        // 並且確實是 alpha 在前：和手動依 alpha、Bravo 順序算出來的字串吻合
        $expectedRaw = 'HashKey='.self::KEY.'&alpha=1&Bravo=2&HashIV='.self::IV;
        $expected = strtoupper(hash('sha256', strtolower(urlencode($expectedRaw))));
        $this->assertSame($expected, CheckMacValue::generate($lower, self::KEY, self::IV));
    }

    public function test_dotnet_url_encoding_exceptions_are_restored(): void
    {
        // .NET 的 UrlEncode 不會編碼 - _ . ! * ( )，PHP 的 urlencode 會（! * ( ) 變成 %21 %2a %28 %29）。
        // 值含這些字元時，兩邊要算出同一個結果，所以必須還原。
        $params = ['ItemName' => 'Apple (iPhone) 7 - case_v1.0!*'];

        $raw = 'HashKey='.self::KEY.'&ItemName=Apple (iPhone) 7 - case_v1.0!*&HashIV='.self::IV;
        $dotnetStyle = strtolower(urlencode($raw));
        $dotnetStyle = str_replace(['%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29'], ['-', '_', '.', '!', '*', '(', ')'], $dotnetStyle);

        $this->assertStringContainsString('apple+(iphone)+7+-+case_v1.0!*', $dotnetStyle);
        $this->assertSame(strtoupper(hash('sha256', $dotnetStyle)), CheckMacValue::generate($params, self::KEY, self::IV));
    }
}
