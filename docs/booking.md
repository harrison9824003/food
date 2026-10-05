# 訂位流程與防超賣

對應里程碑 4。壓測結果見 [loadtest.md](loadtest.md)。

## 顧客流程（前台）

選門市 → 日期與人數 → 時段（顯示剩餘量）→ 聯絡資料 → 確認送出 → 結果頁；另有「我的訂位」以**訂位代號＋手機號碼**查詢與取消。

- 多步驟表單的資料存在 Pinia，並同步到 `sessionStorage`：按上一步資料還在、重新整理不會壞，關掉分頁才清除
  （不用 `localStorage`，手機號碼不該在關閉瀏覽器後還留在這台裝置上）。
- 路由守衛不允許跳步：直接輸入中間步驟的網址時，若前面的資料還在就正常進入，否則退回最早缺資料的那一步。
- 日期或人數改變時，原本選的時段會被清掉；重新進入選時段頁時，失效的選擇（客滿、過了截止時間）也會被清掉。
- 送出時按鈕進入 loading、不可重複點擊，避免連點送出兩筆；遇到客滿或桌位被搶走，引導回去重選時段。
- 選餐（選填）：只是讓門市提早備餐的「預點資訊」，**不計費**，訂金仍是「每人訂金 × 人數」。下單時只送品項 ID 與數量，
  品名與價格由伺服器查出並**快照**到 `reservation_items`，之後菜單改名改價不影響已成立的訂位；前端送來的價格一律忽略。
  品項不屬於該門市、已下架、在停用的分類下 → `422 INVALID_MENU_ITEM`；今日售完 → `422 MENU_ITEM_SOLD_OUT`；
  兩者都是整筆訂位被拒絕、不會佔名額。菜單的分類、圖片與售完機制見 [menu.md](menu.md)。
- 需要訂金的門市，訂位會建立成「待付款」並保留 10 分鐘，結果頁顯示倒數與付款按鈕（倒數用伺服器時間，不用手機時鐘）。
  付款、逾時釋放與對帳見 [payment.md](payment.md)。

## 一次訂位在做什麼

`POST /api/reservations` 由 `ReservationService` 處理，先做**不加鎖的預先檢查**（快速失敗），再進入**單一交易**：

```
預先檢查（不加鎖）：門市啟用？日期在開放期間內？非公休日？未過截止時間？人數、名額看起來夠？
   ↓
BEGIN（READ COMMITTED）
   1. 佔名額      CapacityStrategy::take()   ← 防超賣的核心，三種實作見下
   2. 分配桌位    TableAllocator::allocate() ← 挑桌、依 table_id 由小到大上鎖、再驗證
   3. 建立訂位    reservations + reservation_tables（UNIQUE(table_id, slot_id) 為最後防線）
COMMIT
```

任何一步失敗整筆回滾，名額不會白扣（有測試覆蓋：名額夠但沒桌子 → 回 `NO_TABLE_AVAILABLE`，`booked` 維持 0）。

預先檢查只是讓明顯不合規則的請求不必進交易搶鎖；**名額是否真的足夠，以交易內的 `take()` 為準**。

### 為什麼交易用 READ COMMITTED

MySQL 預設的 REPEATABLE READ 會在交易內第一次「一般讀取」時建立快照，之後的一般讀取都看那個快照，
看不到別的交易之後才提交的訂位；而且它的鎖定讀取會加間隙鎖（gap lock），會擋住不相干時段的寫入、也容易製造死鎖。
READ COMMITTED 每個敘述都讀最新已提交資料、沒有間隙鎖，符合訂位流程的需求。

`SET TRANSACTION ISOLATION LEVEL` 只影響下一個交易，且不能在交易進行中執行，所以只在交易層級為 0 時設定
（測試環境會把整個測試包在外層交易內，此時略過）。

## 三種防超賣寫法

同一個介面 `App\Booking\CapacityStrategy`，差別只在「怎麼判斷還有沒有名額」。

| 策略 | 做法 | 結果 |
|---|---|---|
| `atomic`（預設） | `UPDATE slots SET booked = booked + ? WHERE id = ? AND booked + ? <= capacity`，以 affected rows 判斷 | 正確 |
| `pessimistic` | `SELECT ... FOR UPDATE` 鎖列 → 應用層判斷 → `UPDATE` | 正確 |
| `unsafe` | `SELECT` 不加鎖 → 應用層判斷 → 用讀到的舊值算出絕對值 `UPDATE booked = :舊值+人數` | **會超賣**（壓測示範用） |

**為什麼 `atomic` 正確**：「檢查有沒有名額」和「扣名額」合成單一敘述，由資料庫在持有該列鎖的情況下一次完成，
沒有「讀 → 判斷 → 寫」之間的空窗。affected rows 為 1 代表搶到，0 代表條件不成立（沒有任何列被改）。

**為什麼 `unsafe` 會超賣**：典型 check-then-act 競態。兩個請求同時讀到 `booked = 18`，都判斷 `18 + 2 <= 20` 通過，
都寫入 `booked = 20`，兩筆訂位（共 4 人）都成功，但計數器只記了 20。這是 lost update，
所以資料表上的 `CHECK (booked <= capacity)` 也攔不住——`booked` 本身沒超過上限，超賣的是「訂位人數總和」。
驗證超賣要看 `SUM(reservations.party_size)`，不能只看 `slots.booked`。

### 策略怎麼切換

- 正式使用：`.env` 的 `BOOKING_STRATEGY`（預設 `atomic`）。
- 壓測時逐請求指定：標頭 `X-Booking-Strategy`。**必須同時滿足兩個條件才生效**：`BOOKING_ALLOW_STRATEGY_HEADER=true` 且 `APP_ENV` 不是 `production`。
- `unsafe` 在正式環境一律拒絕，連設定檔指定也一樣（`CapacityStrategyResolver` 會拋例外）。有測試保證。

### 多層防線

| 層級 | 機制 |
|---|---|
| 應用層 | 條件式原子 UPDATE（名額） |
| 桌位 | 鎖定桌子後用「目前讀」重新驗證仍空著；`UNIQUE(table_id, slot_id)` 擋同桌同時段重複佔用 |
| 資料庫 | `CHECK (booked <= capacity)` 擋計數器超過上限 |

## 桌位分配

`TableAllocator`：

1. 有單桌坐得下（`min_seats <= 人數 <= max_seats`）就選**最小的那張**，不浪費大桌。
2. 沒有單桌就併桌：只用標記「可併桌」的桌子，由大到小湊到座位數足夠，再把用不到的拿掉。

**先挑、再鎖、再驗證**：挑桌用一般讀取，讀完到上鎖之間別的交易可能剛好把同一張桌子訂走，
所以上鎖後用 `FOR UPDATE`（目前讀）重新確認這些桌子在該時段仍然空著，不空就重挑（最多 3 次）。

**鎖的順序一律依 `table_id` 由小到大。** 併桌會同時鎖多張桌子，若兩個交易各自以不同順序鎖同一批桌子
（A 先鎖 1 再鎖 2、B 先鎖 2 再鎖 1）就會形成循環等待造成死鎖；大家都由小到大就不會有循環。

**死鎖重試**：`DeadlockRetrier` 捕捉 SQLSTATE `40001` / 錯誤碼 `1213`，重跑**整段交易**（犧牲者的交易已被資料庫回滾，
只重跑失敗的那一行沒有意義），最多 3 次並帶隨機延遲。其他資料庫錯誤（如重複鍵）不重試。

> 假設：一組客人只佔用自己訂的那一個時段的桌位，不計算用餐時間跨越多個時段。

## 取消與狀態變更

所有狀態變更（顧客取消、後台標記已到／未到／取消、未來的逾時釋放）都走 `ReservationStatusService::transition()`，
確保「狀態變了，名額與桌位一定同步歸還」。

```
待付款 → 已確認（收到付款成功，系統流程） / 已取消 / 已逾時
已確認 → 已到 / 未到 / 已取消
已到、未到、已取消、已逾時 → （終態）
```

- **鎖住該筆訂位再判斷**：避免「顧客取消」與「店員標記已到」同時發生、或連點兩次取消，兩個請求都讀到舊狀態、名額被歸還兩次。
- **冪等**：已經是目標狀態就直接回傳，重複請求不會重複歸還。
- **歸還名額**：`UPDATE slots SET booked = booked - ? WHERE id = ? AND booked >= ?`。`booked` 是 UNSIGNED，扣成負數會直接報錯，所以加條件；
  若帳對不上（affected = 0）寫 error 日誌而不是讓取消失敗。
- **刪除 `reservation_tables`**：否則 `UNIQUE(table_id, slot_id)` 會讓這些桌子永遠無法再被訂。
- 已付訂金的訂位取消回 `409 REFUND_REQUIRED`（退款流程在里程碑 6）。
- 「未到」也會歸還名額與桌位，與 `ReservationStatus::holdsCapacity()` 一致。

## 顧客端 API

不需登入，依 IP 限流。

| 路由 | 限流 | 說明 |
|---|---|---|
| `GET /api/stores` | 120/分 | 啟用中的門市 |
| `GET /api/stores/{id}/calendar` | 120/分 | 開放期間內每天是否營業（排除公休日） |
| `GET /api/stores/{id}/availability?date=&party_size=` | 120/分 | 各時段 `remaining`、`bookable`、`reason` |
| `POST /api/reservations` | 10/分 | 建立訂位 |
| `POST /api/reservations/lookup` | 20/分 | `{ code, phone }` 查詢 |
| `POST /api/reservations/cancel` | 20/分 | `{ code, phone }` 取消 |
| `POST /api/reservations/pay` | 20/分 | `{ code, phone }` 建立付款，回傳要 POST 給綠界的表單（見 [payment.md](payment.md)） |
| `GET /api/stores/{id}/menu` | 120/分 | 依分類分組的菜單，含說明、圖片、是否售完（供預點，見 [menu.md](menu.md)） |

- 查詢與取消用 **POST body** 而不是網址，手機號碼才不會出現在 nginx access log 與瀏覽器歷史。
- 代號與手機號碼任一不符都回同樣的 `404 RESERVATION_NOT_FOUND`，不洩漏「這個代號存在」；代號不分大小寫。
- 訂位代號 8 碼，字元集去掉容易看錯的 `0/O/1/I`。
- 簡訊驗證碼在里程碑 6 加入後，查詢與取消會改為以驗證碼證明持有該手機。

`availability` 的 `bookable` 與實際下單共用同一份規則（`BookingRules`），所以「畫面說可以訂、送出卻被拒」的情況只會出現在
真正的並發搶位上。它也會檢查桌位：名額夠，但剩下的桌子湊不出來（例如全是 4 人桌不可併桌卻來了 6 個人）也標為不可訂（`NO_TABLE_AVAILABLE`）。

**給之後的里程碑**：公休日設定時，曾被取消訂位參照的時段無法刪除而會留在 `slots`，所以可訂查詢必須排除公休日，
不能只看 `slots` 有沒有列——目前已由 `BookingRules` 處理。

### 錯誤碼

| HTTP | code | 情境 |
|---|---|---|
| 404 | `SLOT_NOT_FOUND` | 找不到時段 |
| 404 | `RESERVATION_NOT_FOUND` | 代號與手機不符 |
| 409 | `SLOT_FULL` | 名額不足 |
| 409 | `NO_TABLE_AVAILABLE` | 沒有適合的桌位 |
| 409 | `INVALID_STATUS_TRANSITION` | 目前狀態不能這樣變更 |
| 409 | `REFUND_REQUIRED` | 已付訂金，取消需走退款流程 |
| 409 | `NOT_PAYABLE` | 這筆訂位目前不需要（或不能）付款 |
| 409 | `HOLD_EXPIRED` | 保留時間已過，不能再付款 |
| 422 | `INVALID_MENU_ITEM` | 預點的餐點不屬於該門市、已下架或在停用的分類下 |
| 422 | `MENU_ITEM_SOLD_OUT` | 預點的餐點今日已售完 |
| 422 | `OUT_OF_WINDOW` | 超出開放天數 |
| 422 | `DATE_CLOSED` | 該日公休 |
| 422 | `CUTOFF_PASSED` | 已過訂位截止時間 |
| 422 | `STORE_INACTIVE` | 門市未開放 |
| 422 | `INVALID_PARTY_SIZE` | 人數超出範圍 |
| 422 | `INVALID_STRATEGY` | 不支援的訂位策略（僅壓測標頭） |

## 後台訂單

| 路由 | 說明 |
|---|---|
| `GET /api/admin/reservations` | 分頁列表；篩選 `store_id`（僅總部）、`date`、`status`、`q`（姓名／手機／代號） |
| `PATCH /api/admin/reservations/{id}/status` | `{ status: arrived \| no_show \| cancelled }` |

- 總部看全部；店長、店員**固定只看自己門市**，即使帶了別間的 `store_id` 也無效。
- 店員也能標記已到／未到（現場作業）；變更別間門市的訂單回 `403`。
- 回傳的 `allowed_statuses` 由後端依狀態轉換表算好，前端只負責顯示按鈕，不重複實作規則。
- 後台只能手動標記這三種；「待付款」「已確認」由系統流程決定，「已逾時」由排程處理（見 [payment.md](payment.md)）。
- 列表會顯示付款狀態與預點餐點；`needs_refund=1` 可篩出「錢已收到但訂位無法成立」的訂單（晚到付款、重複付款），等退款流程處理。

## 設定

| 環境變數 | 預設 | 說明 |
|---|---|---|
| `BOOKING_STRATEGY` | `atomic` | 防超賣策略 |
| `BOOKING_ALLOW_STRATEGY_HEADER` | `false` | 是否接受 `X-Booking-Strategy` 標頭（僅壓測；正式環境無視） |
| `BOOKING_CREATE_RATE_LIMIT` | `10` | 建立訂位每 IP 每分鐘上限 |
| `BOOKING_PUBLIC_READ_RATE_LIMIT` | `120` | 查詢類 API 每 IP 每分鐘上限 |

其餘（單筆人數上限 12、暫時保留 10 分鐘、死鎖最多嘗試 3 次）在 `config/booking.php`。

## 測試

```bash
docker compose exec php-fpm php artisan test --filter=Booking
```

涵蓋訂位成功／驗證／客滿／回滾、三種策略的單執行緒行為與切換規則、桌位分配（單桌、併桌、鎖序）、
可訂時段、取消與冪等、後台權限與狀態轉換、死鎖重試。

**並發行為無法在單一 PHPUnit 行程內重現**，由 k6 壓測驗證，見 [loadtest.md](loadtest.md)。
