<?php

namespace Tests\Feature\Booking;

use App\Booking\TableAllocator;
use App\Exceptions\ApiException;
use App\Models\DiningTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class TableAllocatorTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    private TableAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable(tables: 0); // 桌子由各測試自己建立
        $this->allocator = app(TableAllocator::class);
    }

    private function table(string $code, int $min, int $max, bool $combinable = false): DiningTable
    {
        return DiningTable::create([
            'store_id' => $this->store->id, 'code' => $code,
            'min_seats' => $min, 'max_seats' => $max, 'is_combinable' => $combinable,
        ]);
    }

    public function test_picks_the_smallest_table_that_fits(): void
    {
        $two = $this->table('A', 1, 2);
        $four = $this->table('B', 1, 4);
        $this->table('C', 1, 6);

        $this->assertSame([$two->id], $this->allocator->allocate($this->slot, 2));
        $this->assertSame([$four->id], $this->allocator->allocate($this->slot, 3), '3 人坐 2 人桌不行，選 4 人桌而不是 6 人桌');
    }

    public function test_respects_minimum_seats_of_a_table(): void
    {
        $this->table('A', 3, 6); // 至少 3 人才能坐

        $this->expectException(ApiException::class);
        $this->allocator->allocate($this->slot, 1);
    }

    public function test_skips_tables_already_taken_in_the_same_slot(): void
    {
        $first = $this->table('A', 1, 4);
        $second = $this->table('B', 1, 4);
        $this->occupy($this->slot, $first);

        $this->assertSame([$second->id], $this->allocator->allocate($this->slot, 2));
    }

    public function test_a_table_taken_in_another_slot_is_still_free_here(): void
    {
        $table = $this->table('A', 1, 4);
        $this->occupy($this->makeSlot($this->store, '2026-10-06', '18:00:00'), $table);

        $this->assertSame([$table->id], $this->allocator->allocate($this->slot, 2));
    }

    public function test_inactive_tables_are_never_used(): void
    {
        $table = $this->table('A', 1, 4);
        $table->update(['is_active' => false]);

        $this->expectException(ApiException::class);
        $this->allocator->allocate($this->slot, 2);
    }

    public function test_combines_combinable_tables_when_no_single_table_fits(): void
    {
        $a = $this->table('A', 1, 4, true);
        $b = $this->table('B', 1, 4, true);
        $this->table('C', 1, 2, true);

        $ids = $this->allocator->allocate($this->slot, 7);

        $this->assertSame([$a->id, $b->id], $ids);
    }

    public function test_does_not_combine_tables_that_are_not_combinable(): void
    {
        $this->table('A', 1, 4);
        $this->table('B', 1, 4);

        $this->expectException(ApiException::class);
        $this->allocator->allocate($this->slot, 6);
    }

    public function test_combined_table_ids_are_returned_in_ascending_order(): void
    {
        // 併桌要依 table_id 由小到大加鎖以避免死鎖；這裡故意讓「座位大的桌子」id 較大，
        // 演算法會先挑大桌，但回傳（也是加鎖）的順序必須是由小到大
        $small = $this->table('A', 1, 2, true);
        $big = $this->table('B', 1, 4, true);

        $this->assertLessThan($big->id, $small->id);
        $this->assertSame([$small->id, $big->id], $this->allocator->allocate($this->slot, 6));
    }

    public function test_removes_tables_that_are_not_needed_when_combining(): void
    {
        $this->table('A', 1, 2, true);
        $big = $this->table('B', 1, 6, true);
        $this->table('C', 1, 2, true);

        // 單桌 B 坐得下 6 人，不需要併桌
        $this->assertSame([$big->id], $this->allocator->allocate($this->slot, 6));
    }

    public function test_throws_when_even_all_tables_together_are_too_small(): void
    {
        $this->table('A', 1, 4, true);
        $this->table('B', 1, 4, true);

        try {
            $this->allocator->allocate($this->slot, 9);
            $this->fail('應該丟出 NO_TABLE_AVAILABLE');
        } catch (ApiException $e) {
            $this->assertSame('NO_TABLE_AVAILABLE', $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_can_seat_many_reports_each_slot_separately(): void
    {
        $table = $this->table('A', 1, 4);
        $other = $this->makeSlot($this->store, '2026-10-06', '18:00:00');
        $this->occupy($this->slot, $table); // 12:00 這張唯一的桌子被訂走了

        $result = $this->allocator->canSeatMany($this->store, collect([$this->slot, $other]), 2);

        $this->assertSame([$this->slot->id => false, $other->id => true], $result);
    }
}
