<?php

namespace App\Booking;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Erp\OutboxRecorder;
use App\Exceptions\ApiException;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Slot;
use App\Models\Store;
use App\Support\DeadlockRetrier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    public function __construct(
        private readonly CapacityStrategyResolver $strategies,
        private readonly BookingRules $rules,
        private readonly TableAllocator $tables,
        private readonly DeadlockRetrier $retrier,
        private readonly OutboxRecorder $outbox,
    ) {}

    /**
     * 建立訂位。
     *
     * @param  array{slot_id: int, party_size: int, customer_name: string, phone: string, email?: string|null, note?: string|null}  $data
     * @param  string|null  $requestedStrategy  壓測用的策略覆蓋（僅非正式環境且設定允許時生效）
     *
     * @throws ApiException
     */
    public function create(array $data, ?string $requestedStrategy = null): Reservation
    {
        $strategy = $this->strategies->resolve($requestedStrategy);
        $guests = (int) $data['party_size'];

        if ($guests < 1 || $guests > config('booking.max_party_size')) {
            throw new ApiException('訂位人數超出範圍', 'INVALID_PARTY_SIZE', 422);
        }

        $slot = Slot::with('store')->find($data['slot_id'])
            ?? throw new ApiException('找不到此時段', 'SLOT_NOT_FOUND', 404);
        $store = $slot->store;

        // 不加鎖的預先檢查：讓明顯不合規則的請求（公休、超過截止時間…）快速被擋下，不必進交易搶鎖。
        // 這只是快速失敗；名額是否真的足夠，以交易內的 CapacityStrategy::take() 為準。
        if ($block = $this->rules->blockReason($store, $slot, $guests, $this->rules->isDateClosed($store->id, $slot->slot_date->toDateString()))) {
            throw new ApiException($block->message, $block->code, $block->httpStatus());
        }

        $items = $this->resolveItems($store, $data['items'] ?? []);

        $reservation = $this->retrier->run(fn () => $this->inTransaction(
            fn () => $this->book($store, $slot, $guests, $data, $items, $strategy),
        ));

        return $reservation->load(['store', 'slot', 'tables', 'items']);
    }

    /**
     * 核對預點的餐點：必須屬於這間門市且上架中，並把「現在的價格」記下來當快照。
     * 不能相信前端送來的價格或品項名稱，只收品項 ID 與數量。
     *
     * @param  list<array{menu_item_id: int, quantity: int}>  $requested
     * @return list<array{menu_item_id: int, name: string, quantity: int, unit_price: int}>
     *
     * @throws ApiException
     */
    private function resolveItems(Store $store, array $requested): array
    {
        if ($requested === []) {
            return [];
        }

        $menu = MenuItem::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            // 與顧客看得到的菜單一致：停用分類底下的品項在菜單上看不到，也就不能預點
            ->where(fn ($q) => $q->whereNull('category_id')
                ->orWhereHas('category', fn ($c) => $c->where('is_active', true)))
            ->whereIn('id', collect($requested)->pluck('menu_item_id'))
            ->get()
            ->keyBy('id');

        return collect($requested)->map(function (array $item) use ($menu) {
            $menuItem = $menu->get($item['menu_item_id'])
                ?? throw new ApiException('預點的餐點已下架或不存在，請重新選擇', 'INVALID_MENU_ITEM', 422);

            // 菜單頁面載入之後才被標示售完的品項：以伺服器現在的狀態為準，不信任顧客手上那份舊菜單
            if ($menuItem->is_sold_out) {
                throw new ApiException("「{$menuItem->name}」今日已售完，請重新選擇", 'MENU_ITEM_SOLD_OUT', 422);
            }

            return [
                'menu_item_id' => $menuItem->id,
                // 名稱與價格都快照：之後菜單改名或改價，已成立的訂位仍顯示當時預點的內容
                'name' => $menuItem->name,
                'quantity' => (int) $item['quantity'],
                'unit_price' => $menuItem->price,
            ];
        })->values()->all();
    }

    /**
     * 交易內的三個步驟（任何一步失敗整筆回滾，名額不會白扣）：
     *  1. 佔名額   2. 分配桌位   3. 建立訂位與桌位關聯
     */
    private function book(Store $store, Slot $slot, int $guests, array $data, array $items, CapacityStrategy $strategy): Reservation
    {
        if (! $strategy->take($slot->id, $guests)) {
            throw new ApiException('該時段名額已滿', 'SLOT_FULL', 409);
        }

        $tableIds = $this->tables->allocate($slot, $guests);

        $deposit = $store->deposit_per_person * $guests;
        $needsPayment = $deposit > 0;

        $reservation = Reservation::create([
            'code' => $this->generateCode(),
            'store_id' => $store->id,
            'slot_id' => $slot->id,
            'customer_name' => $data['customer_name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'party_size' => $guests,
            'note' => $data['note'] ?? null,
            'phone_verified_at' => ($data['phone_verified'] ?? false) ? now() : null,
            // 需要訂金：先「暫時保留」名額，限時付款（逾時釋放在里程碑 5）；免訂金：直接確認
            'status' => $needsPayment ? ReservationStatus::PendingPayment : ReservationStatus::Confirmed,
            'payment_status' => $needsPayment ? PaymentStatus::Unpaid : PaymentStatus::NotRequired,
            'deposit_amount' => $deposit,
            'hold_expires_at' => $needsPayment ? now()->addMinutes(config('booking.hold_minutes')) : null,
            'request_id' => request()->header('X-Request-Id'),
        ]);

        if ($items !== []) {
            $reservation->items()->createMany($items);
        }

        try {
            $reservation->tables()->attach(
                collect($tableIds)->mapWithKeys(fn (int $id) => [$id => ['slot_id' => $slot->id]])->all()
            );
        } catch (QueryException $e) {
            // UNIQUE(table_id, slot_id) 是最後防線：上面的鎖若有疏漏，資料庫仍會拒絕同桌同時段重複佔用
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ApiException('該時段已沒有適合的桌位', 'NO_TABLE_AVAILABLE', 409);
            }
            throw $e;
        }

        // 與上面的名額、桌位、訂位寫在同一個交易：訂位成立，ERP 的通知就一定在；訂位回滾，通知也一起消失（Outbox Pattern）
        $this->outbox->record($reservation, OutboxRecorder::CREATED);

        return $reservation;
    }

    /**
     * 以 READ COMMITTED 執行交易。
     *
     * MySQL 預設的 REPEATABLE READ 會在交易內第一次「一般讀取」時建立快照，之後的一般讀取都看那個快照，
     * 也就看不到別的交易之後才提交的訂位；訂位流程需要看到最新的桌位佔用狀態，而且不希望被間隙鎖（gap lock）
     * 擋住不相干的時段。READ COMMITTED 每個敘述都讀最新已提交資料，也沒有間隙鎖。
     *
     * SET TRANSACTION 只影響「下一個」交易，且不能在交易進行中執行（測試環境會把整個測試包在外層交易裡）。
     */
    private function inTransaction(callable $callback): mixed
    {
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($callback, 1);
    }

    /** 去掉容易看錯的字元（0/O、1/I），方便顧客口頭或手抄。 */
    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($i = 0; $i < 5; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (! Reservation::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new ApiException('系統忙碌，請稍後再試', 'CODE_GENERATION_FAILED', 503);
    }
}
