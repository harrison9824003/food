# 取消與退款

訂金退款牽涉金錢，設計上的優先順序是：**寧可讓人工多看一眼，也不要退兩次**。

## 取消政策

| 誰取消 | 時間 | 結果 |
|---|---|---|
| 顧客 | 用餐前 ≥ 24 小時（含剛好 24 小時） | 訂金全額退還（需簽核） |
| 顧客 | 用餐前 < 24 小時 | **訂金不退**；顧客必須明確確認（`acknowledge_forfeit`），否則回 `409 FORFEIT_CONFIRMATION_REQUIRED` |
| 門市（店長／店員改狀態為取消） | 任何時間 | 一律全額退還，因為是店家的決定 |
| 系統（付款異常） | — | 逾時後才付款且座位被訂走、重複付款、取消後才付款 → 自動產生退款申請 |

- 24 小時、重試次數等參數集中在 `config/refund.php`，政策判斷只在 `App\Refunds\CancellationPolicy`。
- 前台在「按下取消之前」就顯示規則（`cancellation` 預覽），確認視窗會明說「退還」或「不退還」。
- 確認視窗開著的期間若剛好越過期限，伺服器用**送出當下**的時間重算，回 409 要求重新確認，不會照舊畫面扣錢。
- 退款金額一律等於該筆付款金額，不支援部分退款。

## 狀態機

```
requested ──核准──▶ approved ──成功──▶ completed
    │                  │  └─不確定（逾時/5xx）─▶ approved（等 next_attempt_at 再試）
    │                  └─明確拒絕，或不確定達上限─▶ failed ──人工重試──▶ approved
    └──駁回──▶ rejected
```

- `requested`：等簽核，**此時不碰金流商**。
- `approved`：已核准，由 `ProcessRefundJob`（核准的交易提交後才派送）或每分鐘的 `refunds:process` 執行；
  後者同時撿回「佇列遺失」與「到了重試時間」的退款。
- `failed`：需要人看。後台會顯示原因，確認綠界後台**沒有**退過之後按「重新退款」。
- 駁回：訂金不退，付款狀態回到 `paid`，並釋出「同一筆付款同時只能有一筆有效退款」的名額。

## 防止重複退款

1. **資料庫 UNIQUE**：`refunds.active_payment_id` 只在退款「有效」時有值（requested / approved / failed / completed），
   同一筆付款同時只能存在一筆。駁回後設為 NULL，才允許重新申請。這是最後一道防線，應用層的檢查有漏洞時它仍會擋下。
2. **加鎖順序固定**：reservation → payment → refund，避免兩個流程反向等待造成死鎖。
3. **執行時持鎖**：`execute()` 在鎖內檢查狀態再呼叫金流商，連點「核准」或排程與佇列同時撞到，只有一個會真的送出。
4. **結果未知 ≠ 失敗**：逾時或 5xx 時我們不知道綠界有沒有退。這時**不標失敗**，維持 `approved` 並依 60 / 300 / 900 秒退避再試；
   達到 3 次仍未知，轉 `failed` 並在原因寫明「結果未知，請先到金流商後台確認」。`EcpayGateway::refund()` 本身不重試，
   由資料庫裡的狀態決定要不要再送。
5. **明確拒絕**（`RtnCode ≠ 1`）：重試不會改變結果，直接 `failed` 且只呼叫一次。

## 職責分離

- 店員可以取消訂位（因而產生退款申請），但**不能核准**；店長（限自己的門市）與總部可以。
- **自己申請的退款不能自己核准**（`403 SELF_APPROVAL`），後台按鈕會停用並說明。顧客或系統申請的，任何有權限者都可核准。
- 所有決定都記錄操作者與時間；退款狀態每次變更都寫入 outbox（`reservation.refund_changed`），ERP 依序收到 requested → approved → completed。

## 綠界退款 API

`POST /CreditDetail/DoAction`，`Action=R`（要關帳後才能退；未關帳需先 `N` 取消授權，本專案目前只做退刷）。

- 請求格式與 CheckMacValue 已對綠界**測試環境**驗證：不存在的交易回「訂單不存在」，簽章錯誤回 10200073，代表我們的請求與簽章被正確解析。
- **成功路徑未對真實交易驗證**：需要一筆真的在綠界測試環境完成付款並關帳的交易，本機模擬器的付款在綠界不存在。成功與拒絕的分類以 `Http::fake()` 測試。
- **本機模擬**：由模擬器產生的付款（`confirmed_via = simulator`）退款時不呼叫綠界，直接標記完成。
  只在非正式環境、且 `ECPAY_SIMULATOR=true` 時生效（`refund.simulate_for_simulated_payments`），真實付款永遠走真實 API。

## 後台操作

「退款簽核」頁（店長、總部）：待簽核 / 處理中 / 失敗 / 已退款 / 已駁回，每 15 秒自動更新。

- 核准前會顯示金額與對象，並提醒「核准後立即呼叫金流商，無法撤回」。
- 駁回必須填原因。
- 重新退款前提醒先到綠界後台確認沒有重複退款。
- 訂單列表會顯示退款進度標籤。

## API

| 方法 | 路徑 | 說明 |
|---|---|---|
| POST | `/api/reservations/cancel` | 顧客取消；`acknowledge_forfeit` 確認不退訂金 |
| GET | `/api/admin/refunds?status=` | 清單與各狀態數量（依門市範圍） |
| POST | `/api/admin/refunds/{id}/approve` | 核准並立即執行 |
| POST | `/api/admin/refunds/{id}/reject` | 駁回，`reason` 必填 |
| POST | `/api/admin/refunds/{id}/retry` | 失敗後人工重試（嘗試次數歸零） |

錯誤碼：`FORFEIT_CONFIRMATION_REQUIRED`(409)、`INVALID_REFUND_STATE`(409)、`SELF_APPROVAL`(403)。

## 測試

`tests/Feature/Refunds/`：取消政策（邊界、沒收確認、門市取消、原子性、ERP 事件順序）、簽核流程（核准／駁回／重試／權限／門市範圍／自我核准）、
結果未知與退避、排程補撿、模擬器邊界、重複退款的資料庫防線、綠界回應分類（`EcpayRefundGatewayTest`）。

另做過一次真實 HTTP e2e：顧客驗證手機 → 訂位 → 模擬付款 → 取消（退款待簽核）→ 店長核准 → 退款完成，付款與訂位同步為 `refunded`。
