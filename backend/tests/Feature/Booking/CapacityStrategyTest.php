<?php

namespace Tests\Feature\Booking;

use App\Booking\AtomicUpdateStrategy;
use App\Booking\CapacityStrategy;
use App\Booking\CapacityStrategyResolver;
use App\Booking\PessimisticLockStrategy;
use App\Booking\UnsafeStrategy;
use App\Exceptions\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

/**
 * 單執行緒下三種策略的「結果」必須一致；真正的差異（並發時誰會超賣）由 k6 壓測驗證，見 docs/loadtest.md。
 */
class CapacityStrategyTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable(capacity: 5);
    }

    /** @return array<string, array{class-string<CapacityStrategy>}> */
    public static function strategies(): array
    {
        return [
            'atomic' => [AtomicUpdateStrategy::class],
            'pessimistic' => [PessimisticLockStrategy::class],
            'unsafe' => [UnsafeStrategy::class],
        ];
    }

    #[DataProvider('strategies')]
    public function test_takes_seats_until_capacity_is_reached(string $class): void
    {
        $strategy = new $class;

        $this->assertTrue($strategy->take($this->slot->id, 3));
        $this->assertSame(3, $this->slot->fresh()->booked);

        $this->assertTrue($strategy->take($this->slot->id, 2), '剛好用完最後 2 個名額');
        $this->assertSame(5, $this->slot->fresh()->booked);

        $this->assertFalse($strategy->take($this->slot->id, 1), '已滿');
        $this->assertSame(5, $this->slot->fresh()->booked, '失敗時不可改動名額');
    }

    #[DataProvider('strategies')]
    public function test_rejects_a_party_bigger_than_the_remaining_seats(string $class): void
    {
        $strategy = new $class;
        $strategy->take($this->slot->id, 4);

        $this->assertFalse($strategy->take($this->slot->id, 2));
        $this->assertSame(4, $this->slot->fresh()->booked);
    }

    #[DataProvider('strategies')]
    public function test_returns_false_for_a_missing_slot(string $class): void
    {
        $this->assertFalse((new $class)->take(999999, 1));
    }

    public function test_default_strategy_is_atomic(): void
    {
        $this->assertInstanceOf(AtomicUpdateStrategy::class, app(CapacityStrategyResolver::class)->resolve());
    }

    public function test_config_selects_the_strategy(): void
    {
        config(['booking.strategy' => 'pessimistic']);

        $this->assertInstanceOf(PessimisticLockStrategy::class, app(CapacityStrategyResolver::class)->resolve());
    }

    public function test_requested_strategy_is_only_honoured_when_header_is_allowed(): void
    {
        $resolver = app(CapacityStrategyResolver::class);

        $this->assertInstanceOf(AtomicUpdateStrategy::class, $resolver->resolve('unsafe'), '未開啟時忽略');

        config(['booking.allow_strategy_header' => true]);
        $this->assertInstanceOf(UnsafeStrategy::class, $resolver->resolve('unsafe'));
    }

    public function test_unknown_strategy_name_is_rejected(): void
    {
        config(['booking.strategy' => 'nonsense']);

        $this->expectException(ApiException::class);
        app(CapacityStrategyResolver::class)->resolve();
    }

    public function test_unsafe_strategy_can_never_be_used_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['booking.strategy' => 'unsafe']);

        $this->expectException(LogicException::class);
        app(CapacityStrategyResolver::class)->resolve();
    }
}
