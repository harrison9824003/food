<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_tests_run_against_the_testing_database(): void
    {
        // 防呆：若設定失效，RefreshDatabase 會清掉開發用資料庫
        $this->assertSame('food_testing', DB::connection()->getDatabaseName());
    }

    public function test_tests_do_not_touch_the_real_redis(): void
    {
        // 限流、快取若落到開發用 Redis，計數會跨測試累積造成時好時壞
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/no-such-route');

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_valid_incoming_request_id_is_reused(): void
    {
        $id = (string) Str::uuid();

        $this->withHeader('X-Request-Id', $id)
            ->getJson('/api/no-such-route')
            ->assertHeader('X-Request-Id', $id);
    }

    public function test_malformed_incoming_request_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-Id', "evil\nid")->getJson('/api/no-such-route');

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_unknown_api_route_uses_unified_error_format(): void
    {
        $this->getJson('/api/no-such-route')
            ->assertStatus(404)
            ->assertExactJson(['message' => '找不到資源', 'code' => 'NOT_FOUND', 'errors' => []]);
    }
}
