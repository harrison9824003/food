# 綠界金流、暫時保留與冪等回呼

對應里程碑 5。訂位本身的流程見 [booking.md](booking.md)。

## 整體流程

```
建立訂位（需訂金）→ pending_payment，名額與桌位保留 10 分鐘（hold_expires_at）
   │
   │ 顧客按「前往付款」
   ▼
POST /api/reservations/pay          建立一筆 payments（pending），回傳要 POST 給綠界的表單
   │ 前端動態建立隱藏表單，瀏覽器被導向綠界付款頁
   ▼
顧客在綠界付款
   ├─ 背景通知  POST /api/payments/ecpay/notify   ← 訂單狀態以這個為準
   └─ 瀏覽器導回 POST /api/payments/ecpay/return  → 轉去前台 /payment/return（只顯示、不改任何狀態）

保險機制（Scheduler）
   ├─ payments:reconcile            每 5 分鐘：向綠界查詢「建立超過 3 分鐘仍沒結果」的付款，補單
   └─ reservations:release-expired  每分鐘：釋放逾時（到期 + 2 分鐘寬限）的保留，釋放前先對帳
```

## 為什麼訂單狀態只認背景通知

瀏覽器導回（OrderResultURL）是顧客的瀏覽器發出的請求：可以被偽造、可以被重複送、顧客也可能付完款就關掉分頁而根本不會發生。
背景通知是綠界的伺服器打給我們，而且有簽章。所以導回頁面只負責把人轉去前台，前台的結果頁也是反覆向**我們自己的 API** 查訂位狀態，
而不是相信導回請求帶來的任何參數。（有測試保證：導回請求即使宣稱付款成功，也不會改變任何狀態。）

## 背景通知的處理

`POST /api/payments/ecpay/notify`，回應純文字 `1|OK`（綠界沒收到 `1|OK` 會在一段時間內一直重送）。

1. **完整記錄原文**到 `payment` 日誌（見下）。
2. **驗 CheckMacValue**（`hash_equals` 固定時間比較）。不對 → 回 `0|CheckMacValue Error`，記錄後拒絕，**不碰任何資料**。
3. 以 `MerchantTradeNo` 找到付款；找不到 → `0|Trade not found`。
4. 在交易內鎖定後處理（`PaymentSettlement`）：
   - **已經是已付款 → 什麼都不做，仍回 `1|OK`**（冪等：綠界重送、對帳同時撿到都安全）。
   - **金額必須和我們建立付款時記錄的一致**，不信任通知裡的金額欄位本身；不符 → `0|Amount mismatch`。
   - 付款成功 → 訂位 `pending_payment → confirmed`、`payment_status = paid`、清掉 `hold_expires_at`。名額與桌位在訂位時就保留了，**不會重複扣**。
   - `RtnCode != 1`（授權失敗…）→ 付款標記失敗並記錄原因；訂位維持待付款，保留時間內可以重試。
   - 失敗通知晚於成功通知到達 → 不會把已付款改回失敗。

**鎖的順序一律「先訂位、再付款」**：逾時釋放排程是先鎖訂位再碰付款，兩邊順序一致才不會互相等待造成死鎖。

綠界交易編號 `payments.ecpay_trade_no` 有 `UNIQUE` 約束，是冪等的最後一道防線。

## 晚到的付款

最棘手的情況：訂位已因逾時釋放（名額還給別人了），**之後才**收到「付款成功」。錢已經收了，不能假裝沒發生。

| 情況 | 處理 |
|---|---|
| 名額與桌位仍然空著 | **補回來**：用和一般訂位相同的條件式原子 UPDATE 重新佔名額、再分配桌位，訂位恢復 `confirmed`（不會超賣） |
| 名額已被別人訂走，或桌子都被占了 | 訂位維持 `expired`；付款標記 `needs_refund = true`、`refund_reason = LATE_PAYMENT_SLOT_TAKEN`。佔名額成功但分配桌位失敗時，用巢狀交易（savepoint）把名額退回去 |
| 顧客取消之後才付款 | `needs_refund`，`PAID_AFTER_CANCEL` |
| 這張訂位已經付過一筆（顧客開了兩個分頁各付一次） | 後到的一筆 `needs_refund`，`DUPLICATE_PAYMENT`，訂位不重複確認 |

`needs_refund` 的付款目前只是標示：後台訂單列表會顯示紅色「待退款」並可以篩選；**實際退款流程在里程碑 6**。

## 逾時釋放與對帳補償

**`reservations:release-expired`（每分鐘）**

1. 找出 `pending_payment` 且 `hold_expires_at` 已過**超過 2 分鐘寬限**的訂位（顧客可能剛好正在綠界頁面輸入卡號）。
2. **釋放之前，先向綠界對帳**這張訂位所有進行中的付款：查到其實付了 → 走同一套 `PaymentSettlement` 確認訂位，**不放掉座位**。
3. 還是沒付 → 轉 `expired`，歸還名額與桌位。
4. 綠界查不到（故障、回應簽章不符）→ **不知道有沒有付，不當成沒付**：先不放，下一輪再試。但最多多等 30 分鐘，
   之後照樣釋放，否則綠界出問題時名額會永遠被占住；晚到的付款由上面的補救流程處理。

釋放後付款保持 `pending`（不立刻標失敗），這樣對帳排程仍能撿到晚到的付款。

**`payments:reconcile`（每 5 分鐘）**：對建立超過 3 分鐘仍是 `pending` 的付款呼叫 `QueryTradeInfo`。
查到已付款就走**和背景通知同一個** `PaymentSettlement`，所以規則只有一份，冪等性也一樣。
超過 120 分鐘仍未付款視為放棄（標記失敗，不再對帳）。

對帳會驗證**綠界回應的簽章**：否則中間有人竄改回應，就能讓我們把沒付款的訂單當成已付款。

## CheckMacValue 與實際驗證

演算法：排除 `CheckMacValue`、參數依名稱不分大小寫排序 → 組成 `HashKey=…&k=v&…&HashIV=…` → URL encode 並轉小寫 →
還原 .NET 不編碼的字元（`- _ . ! * ( )`）→ SHA256 → 轉大寫。實作在 `app/Payments/CheckMacValue.php`。

我用綠界**測試環境**實際驗證過，不是只和自己一致：

- 打 `QueryTradeInfo`：綠界接受我們產生的簽章，且**回應裡綠界產生的簽章，我們的演算法驗證通過**（被竄改的回應驗證失敗）。
  這筆真實回應已錄成測試向量（`tests/Unit/CheckMacValueTest.php`）。
- 把我們的結帳表單 POST 給 `AioCheckOut`：簽章正確 → 進入「選擇支付方式」頁；
  簽章錯誤或金額被改 → 「交易失敗」＋錯誤碼 `10200073`（CheckMacValue 錯誤）。含中文的品名也通過。

**尚未驗證的部分**：綠界真正的「背景通知」打到我們的伺服器。這需要公開網址（見下），我沒有實際做過。
通知的處理邏輯由模擬器與 Feature Test 覆蓋，模擬器送出的是同樣格式、同樣簽章的請求。

## 本機開發：付款模擬器

綠界打不到 `localhost`，所以本機收不到真正的背景通知。`.env` 設 `ECPAY_SIMULATOR=true` 後，
前台付款面板會出現「開發用付款模擬器」按鈕（前端另需 `VITE_PAYMENT_SIMULATOR=true`，docker-compose 已設定）：

- **模擬付款成功／失敗**
- **連發 3 次成功通知**：示範冪等——三次都回 `1|OK`，但訂位只確認一次、名額只扣一次

`POST /api/dev/ecpay/simulate` 在**伺服器端**組出簽好章的通知，再用 HTTP **真的 POST 到我們自己的 notify 網址**
（容器內部的 `http://nginx/api/payments/ecpay/notify`），所以驗章、冪等、狀態轉換、日誌走的是和真實通知完全相同的程式碼路徑；
唯一的差別是請求帶有 `X-Payment-Simulator` 標頭，只用來在 `payments.confirmed_via` 標註來源，不影響任何判斷。

**只在非正式環境、且明確開啟時可用**，否則回 404（有測試保證）。

## 接真正的綠界測試環境（選用）

1. 用 [ngrok](https://ngrok.com/) 或 cloudflared 取得指向本機 80 埠的公開網址，例如 `https://abc123.ngrok-free.app`。
2. `backend/.env`：
   ```
   ECPAY_NOTIFY_URL=https://abc123.ngrok-free.app/api/payments/ecpay/notify
   ECPAY_ORDER_RESULT_URL=https://abc123.ngrok-free.app/api/payments/ecpay/return
   FRONTEND_URL=https://abc123.ngrok-free.app
   ```
   然後 `docker compose up -d --force-recreate php-fpm queue scheduler` 讓設定生效。
3. 用公開網址開前台、訂位後按「前往付款」。測試信用卡請依綠界官方文件（測試卡號 `4311-9522-2222-2222`，有效期任一未來月份，安全碼 `222`，3D 驗證碼 `1234`；以官方最新文件為準）。

測試商店 `2000132` 是所有開發者共用的，不要在上面做任何敏感的事。

## 日誌

金流專用的 `payment` channel（`storage/logs/payment-YYYY-MM-DD.log`，保留 60 天），和一般日誌分開，
對帳或爭議時只需要翻這個檔案。每行自帶 `request_id`。會記錄：

- 通知原文與來源 IP、驗章失敗（含完整內容）
- 付款建立、處理結果（`outcome`）、冪等略過、金額不符、失敗原因
- 晚到付款與待退款原因、對帳查詢失敗、逾時釋放

```bash
# 某筆訂位的完整金流軌跡
docker compose exec php-fpm sh -c "grep F123T storage/logs/payment-*.log"
```

測試環境不會寫入這個檔案（`phpunit.xml` 把等級設為 `emergency`）。

## 設定

| 環境變數 | 預設 | 說明 |
|---|---|---|
| `ECPAY_MERCHANT_ID` / `ECPAY_HASH_KEY` / `ECPAY_HASH_IV` | 綠界公開的測試值 | 商店代號與金鑰 |
| `ECPAY_ENV` | `test` | `test` 或 `production` |
| `ECPAY_NOTIFY_URL` | `{APP_URL}/api/payments/ecpay/notify` | 背景通知網址（必須是綠界連得到的公開網址） |
| `ECPAY_ORDER_RESULT_URL` | `{APP_URL}/api/payments/ecpay/return` | 瀏覽器導回網址 |
| `FRONTEND_URL` | `APP_URL` | 付款完成後導回的前台網址 |
| `ECPAY_SIMULATOR` | `false` | 本機付款模擬器 |
| `PAYMENT_GRACE_MINUTES` | `2` | 保留到期後的寬限 |
| `PAYMENT_RECONCILE_AFTER_MINUTES` | `3` | 付款建立多久後對帳排程才會去查 |
| `PAYMENT_ABANDON_AFTER_MINUTES` | `120` | 多久仍未付款視為放棄 |
| `PAYMENT_MAX_UNVERIFIED_HOLD_MINUTES` | `30` | 綠界查不到時，最多額外保留多久 |

## 已知限制

- 只接了信用卡（一次付清）；ATM、超商代碼等需要 `PaymentInfoURL` 等額外流程，未實作。
- 每次按「前往付款」都建立新的付款（新的商店交易編號），因為綠界不接受重複的編號。
  前一筆其實付成功時，後到的會被標為重複付款待退款，而不是重複確認。
- 退款流程（申請 → 簽核 → 呼叫退款 API → 回寫）在里程碑 6；在那之前，已付訂金的訂位取消會回 `409 REFUND_REQUIRED`。
- 沒有實際用公開網址接過綠界的背景通知（見上）。

## 測試

```bash
docker compose exec php-fpm php artisan test --filter=Payments
docker compose exec php-fpm php artisan test --filter=CheckMacValue
```

涵蓋：簽章（含綠界真實回應向量）、結帳、通知的各種情況（成功、驗章失敗、金額不符、未知交易、重複、失敗、亂序、重複付款、
三種晚到付款、取消後付款、不限流）、對帳（含回應被竄改、綠界故障、金額不符）、逾時釋放（寬限、先對帳、等待上限）、
瀏覽器導回不改狀態且不會被拿來做開放式重導向、模擬器的啟用條件。
