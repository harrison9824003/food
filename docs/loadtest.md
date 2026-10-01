# 壓測：三種防超賣策略

用 [k6](https://k6.io/) 驗證 [booking.md](booking.md) 中三種寫法在並發下的正確性與效能。
k6 在 Docker Compose 內執行，不必另外安裝。

## 怎麼跑

```powershell
# 超賣測試：依序用 unsafe / atomic / pessimistic 搶同一個時段（約 1 分鐘，首次會拉 k6 映像）
.\loadtest\run-oversell.ps1
.\loadtest\run-oversell.ps1 -Capacity 50 -Total 400 -Vus 100 -Party 2

# 讀寫混合的吞吐量與延遲：atomic vs pessimistic
.\loadtest\run-mixed.ps1
```

腳本會做這些事：

1. 以 `docker-compose.loadtest.yml` 疊加設定重建 `php-fpm`：開放用標頭切換策略、關閉訂位與查詢的限流、關閉除錯。
   **這些設定只在加上 `-f docker-compose.loadtest.yml` 時存在**，平常開發不會帶著。
2. `php artisan loadtest:prepare`：建立／重置專用的「[壓測] 壓測門市」與時段（只清這間門市，不碰真實資料）。
3. k6 腳本 `loadtest/scripts/oversell.js` / `mixed.js` 發送請求。
4. `php artisan loadtest:report <slot>`：從資料庫算出超賣人數。

跑完後請用 `docker compose up -d --force-recreate php-fpm` 回復正常設定。

判斷超賣看 **`SUM(reservations.party_size)` 是否大於 `capacity`**，不是看 `slots.booked`：
無防護版本的 lost update 會讓 `booked` 被蓋小，看起來剛好客滿，實際訂位人數卻已超過名額。

## 測試一：搶同一個時段

100 個並發使用者、共 400 筆請求，搶一個只有 **50 個名額**的時段（每筆訂 2 人，所以最多只該成功 25 筆）。
壓測門市有 100 張桌子。

| 策略 | 成功訂位 | 訂位人數總和 | 名額 | **超賣人數** | 計數器漂移* | 被拒：客滿 | 被拒：無桌 | req/s | p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `unsafe` | 100 | 200 | 50 | **150** | 178 | 0 | 300 | 47.9 | 3306 ms |
| `atomic` | 25 | 50 | 50 | **0** | 0 | 375 | 0 | 97.0 | 1682 ms |
| `pessimistic` | 25 | 50 | 50 | **0** | 0 | 375 | 0 | 97.7 | 1782 ms |

\* 計數器漂移 = 訂位人數總和 − `slots.booked`。非 0 代表 `booked` 被 lost update 蓋掉。
`unsafe` 最後 `booked` 只記了 22，實際卻有 200 人。

**怎麼解讀**

- **`unsafe` 嚴重超賣**：名額 50 卻訂了 200 人（成功 100 筆）。它成功筆數停在 100，純粹是因為桌子只有 100 張——
  多一層桌位限制剛好擋住了更大的超賣，而不是名額判斷有效；`unsafe` 的 300 筆被拒全是「沒有桌子」，沒有任何一筆是因為客滿被擋下。
- **`atomic` 與 `pessimistic` 結果完全正確**：恰好 25 筆（50 人）成功，其餘 375 筆以 `SLOT_FULL` 拒絕，沒有超賣、計數器精確。
- `unsafe` 的 req/s 比較低，是因為它有 100 筆真的走完「分配桌位＋寫入」，另外兩者有 375 筆在第一步就被拒絕，工作量小得多——
  **不能拿這個數字說 `unsafe` 比較慢，兩邊做的事不一樣。**

## 測試二：讀寫混合

尖峰 50 個並發，80% 查詢可訂時段、20% 送出訂位；訂位分散在 20 個時段（名額很大、桌位充足，所以量的是吞吐量而不是客滿後的拒絕），
每個時段 60 張桌子。持續約 45 秒。

| 策略 | 請求數 | req/s | 成功訂位 | 錯誤 | 查詢 p95 | 訂位平均 | 訂位 p95 |
|---|---:|---:|---:|---:|---:|---:|---:|
| `atomic` | 2112 | 46.9 | 424 | 0 | 1472 ms | 748 ms | 1254 ms |
| `pessimistic` | 2213 | 49.0 | 417 | 0 | 1457 ms | 685 ms | 1292 ms |

**兩者在這個情境下沒有可分辨的差異。**

## 結論與限制（請誠實看待這些數字）

**正確性是明確的**：`unsafe` 會超賣，`atomic` 與 `pessimistic` 不會。這是這份壓測最有說服力的結果。

**效能差異在這個環境量不出來**，原因：

- 測試環境是 Docker Desktop（Windows）上的單一 MySQL 容器，PHP 程式碼走 bind mount、php-fpm 只有 40 個 worker，
  瓶頸在 PHP 處理與容器層，不在資料庫鎖；絕對數字（p95 超過一秒）不能代表正式環境，只適合做相對比較。
- 測試一裡 `atomic` 與 `pessimistic` 大部分請求都在第一步就被拒絕，工作量極小，兩者的差異被淹沒。
- 測試二把訂位分散到 20 個時段，鎖爭用很低，兩種做法的持鎖時間差異用不上。
- 每種只跑了一次，沒有做多次取平均與統計檢定；`unsafe` 的超賣量每次都會不同（取決於請求交錯的時機），但「會超賣」是穩定的。

**理論上的差異**（本次未量到，不要當成實測結論）：`pessimistic` 多一次往返（`SELECT` + `UPDATE`），鎖從 `SELECT` 開始持有到交易結束，
持鎖時間比 `atomic` 長；搶同一列且大多數請求都會成功的高爭用情境下，排隊時間會拉長。`atomic` 不需要應用層持有鎖，是預設選擇的理由。
想量這個差異，需要「單一熱門時段、名額充足讓大多數請求都成功」的情境，並且在更接近正式環境的機器上跑。

## 原始資料

`loadtest/results/` 內的 `*-summary.json` 已納入版本控制；每次執行的細項（`oversell-*.json`、`mixed-*.json`、`report-*.json`）不納入。
