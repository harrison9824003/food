# 多門市餐廳線上訂位系統（面試作品）

## 專案目的
這是一份求職作品，目標職缺為「集團線上訂位系統全端工程師」。
作品必須展示：PHP 框架開發、MySQL 交易與鎖（防超賣）、Vue 單頁應用、
第三方金流／簡訊串接（含失敗重試與冪等）、Linux 部署與日誌排錯。
程式碼品質、commit 紀錄、README 與測試都會被面試官檢視。

## 技術棧
- 後端：Laravel 11、PHP 8.2+、Sanctum（後台登入）
- 資料庫：MySQL 8（InnoDB）
- 快取／佇列：Redis + Laravel Queue、Task Scheduling
- 前台：Vue 3 + Vite + Pinia + Vue Router + Axios
- 後台：Vue 3 + Element Plus
- 金流：綠界 ECPay 測試環境
- 簡訊：SmsDriver 介面，實作 MockSmsDriver 與一家真實廠商（三竹或 Every8d）
- 部署：Docker Compose（nginx、php-fpm、mysql、redis、queue worker、scheduler）
- 壓測：k6

## 目錄結構
```
/backend        Laravel API
/frontend       顧客前台（Vue 3）
/admin          門市管理後台（Vue 3）
/mock-erp       假 POS/ERP 服務（接收訂單同步、可故意關閉測試重試）
/loadtest       k6 壓測腳本與結果
/docs           ER 圖、架構圖、故障排除手冊、門市操作說明
docker-compose.yml
```

## 功能範圍
### 顧客前台
- 選門市 → 日期 → 人數 → 時段（顯示剩餘量）→ 選餐 → 訂金付款 → 簡訊/Email 驗證碼
- 我的訂位：查詢、取消（有訂金則走退款流程）
- 多步驟表單：上一頁資料保留、重新整理不會壞

### 門市管理後台
- 訂單列表／搜尋／狀態變更（已到、未到、取消）
- 桌位設定：桌號、人數上下限、可否併桌
- 時段與開放規則：公休日、提前開放天數、訂位截止時間、每時段上限
- 帳號與權限：總部管理員 / 店長 / 店員（Policy/Gate 實作）
- 同步失敗清單：可手動重送至 ERP

## 核心設計原則（實作時務必遵守）

### 防超賣
- 時段容量使用條件式原子更新：
  `UPDATE slots SET booked = booked + ? WHERE id = ? AND booked + ? <= capacity`，
  以 affected rows 判斷成功與否
- 另實作悲觀鎖版本（`SELECT ... FOR UPDATE`）作為比較，兩者都要有壓測結果
- 保留 `/loadtest` 中「無防護版本」的超賣示範
- 併桌：reservation 與 table 為多對多，鎖定多張桌子時一律依 table_id 由小到大加鎖，避免死鎖
- 捕捉死鎖（SQLSTATE 40001 / 1213）後有限次重試
- UNIQUE 約束作為最後防線

### 暫時保留
- 需訂金的訂位先建立 status=pending_payment，並記錄 hold_expires_at（10 分鐘）
- Scheduler 每分鐘釋放逾時保留並歸還名額

### 金流
- 訂單狀態以綠界背景通知（ReturnURL）為準，前景跳轉只做顯示
- 驗證 CheckMacValue，驗證失敗記錄並拒絕
- 以綠界交易編號做冪等：重複通知不重複處理，仍回應 `1|OK`
- 排程對 pending 訂單呼叫查詢 API 做對帳補償
- 退款流程：申請 → 簽核（模擬）→ 呼叫退款 API → 回寫狀態

### 簡訊驗證碼
- 驗證碼雜湊後儲存，有效期 5 分鐘
- 錯誤次數上限、重送冷卻 60 秒、依手機號碼與 IP 做 Rate Limit
- 發送走 Queue，失敗以指數退避重試

### 外部系統介接
- Outbox Pattern：訂單異動與 outbox 事件寫在同一交易
- Worker 讀取 outbox 送往 mock-erp，失敗指數退避，超過上限進入失敗清單

### 日誌
- 每個請求產生 request_id，寫入所有日誌與回應 header
- 金流、簡訊、ERP 同步各自有獨立 log channel

## 程式碼慣例
- 使用 PHP 8.2 Enum 表示訂單狀態、付款狀態
- Controller 保持精簡，業務邏輯放在 Service / Action 類別
- 驗證使用 Form Request
- 第三方服務一律透過介面注入，測試時以 `Http::fake()` 或 Mock 實作替換
- API 錯誤格式統一：`{ "message": "...", "code": "SLOT_FULL", "errors": {} }`
- Commit 訊息使用 Conventional Commits（feat / fix / refactor / test / docs）
- 每個功能完成時附 Feature Test

## 開發里程碑
1. [x] Docker Compose 環境、Laravel 骨架、資料表 Migration、ER 圖
2. [x] 後台登入、角色權限
3. [x] 門市管理、桌位、時段、開放規則設定（後台）；後台帳號管理表單（設計與規則見 `docs/store-settings.md`）
4. [ ] 前台訂位流程 + 防超賣 + k6 壓測（展示點 A）
5. [ ] 綠界金流、暫時保留與逾時釋放、冪等回呼（展示點 B）
6. [ ] 簡訊驗證碼、mock-erp、Outbox、退款流程（展示點 C）
7. [ ] 部署到 Linux、日誌、故障排除手冊、門市操作說明
8. [ ] 補測試、README、Demo 影片

## 給 Claude 的工作方式
- 一次只推進一個里程碑，完成後更新上方勾選狀態
- 動手前先說明設計與要建立的檔案，確認後再寫
- 牽涉交易與鎖的程式碼，要在註解中說明為什麼這樣寫
- 每完成一段，提醒我可以 commit，並建議 commit 訊息
