# ER 圖與資料表設計

對應 migration：`backend/database/migrations/2025_01_01_0000*`。

```mermaid
erDiagram
    stores ||--o{ store_business_hours : "每週營業時段"
    stores ||--o{ store_closed_dates : "特定公休日"
    stores ||--o{ users : "所屬員工（總部為 NULL）"
    stores ||--o{ dining_tables : "桌位"
    stores ||--o{ slots : "時段"
    stores ||--o{ menu_items : "菜單"
    stores ||--o{ reservations : ""

    slots ||--o{ reservations : "訂位佔用名額"
    reservations ||--o{ reservation_tables : ""
    dining_tables ||--o{ reservation_tables : ""
    slots ||--o{ reservation_tables : "冗餘欄位，供 UNIQUE"

    reservations ||--o{ reservation_items : ""
    menu_items ||--o{ reservation_items : ""

    reservations ||--o{ payments : ""
    payments ||--o{ refunds : ""
    users ||--o{ refunds : "approved_by"

    stores {
        bigint id PK
        string name
        smallint advance_days "提前開放天數"
        smallint cutoff_minutes "訂位截止（分鐘）"
        smallint slot_capacity_default
        int deposit_per_person "0 = 免訂金"
    }
    store_business_hours {
        bigint store_id FK
        tinyint weekday "UNIQUE(store_id, weekday)"
        time open_time
        time close_time
        smallint slot_minutes
        bool is_closed
    }
    store_closed_dates {
        bigint store_id FK
        date closed_date "UNIQUE(store_id, closed_date)"
    }
    users {
        bigint id PK
        string role "hq_admin / store_manager / staff"
        bigint store_id FK "nullable"
        bool is_active
    }
    dining_tables {
        bigint id PK
        bigint store_id FK
        string code "UNIQUE(store_id, code)"
        tinyint min_seats
        tinyint max_seats
        bool is_combinable
    }
    slots {
        bigint id PK
        bigint store_id FK
        date slot_date
        time start_time "UNIQUE(store_id, slot_date, start_time)"
        smallint capacity
        smallint booked "CHECK booked <= capacity"
    }
    reservations {
        bigint id PK
        string code UK
        bigint store_id FK
        bigint slot_id FK
        string phone
        tinyint party_size
        string status
        string payment_status
        int deposit_amount
        timestamp hold_expires_at
        string request_id
    }
    reservation_tables {
        bigint reservation_id FK
        bigint table_id FK
        bigint slot_id FK "UNIQUE(table_id, slot_id)"
    }
    reservation_items {
        bigint reservation_id FK
        bigint menu_item_id FK
        smallint quantity
        int unit_price "下單價格快照"
    }
    payments {
        bigint id PK
        bigint reservation_id FK
        string merchant_trade_no UK
        string ecpay_trade_no UK "冪等依據"
        int amount
        string status
    }
    refunds {
        bigint id PK
        bigint payment_id FK
        bigint reservation_id FK
        string status
        bigint approved_by FK
    }
    sms_verifications {
        string phone
        string code_hash
        tinyint attempts
        timestamp expires_at
        timestamp last_sent_at
    }
    outbox_events {
        bigint id PK
        string aggregate_type
        bigint aggregate_id
        string event_type
        json payload
        string status
        smallint attempts
        timestamp next_attempt_at
    }
```

`sms_verifications` 與 `outbox_events` 不設外鍵：前者依手機號碼關聯、尚無訂位；後者以 `aggregate_type + aggregate_id` 多型關聯，方便日後擴充其他事件。

## 防超賣的三層防線

| 層級 | 機制 | 位置 |
|---|---|---|
| 1. 應用層 | `UPDATE slots SET booked = booked + ? WHERE id = ? AND booked + ? <= capacity`，以 affected rows 判斷 | 里程碑 4 實作 |
| 2. 桌位 | `UNIQUE(table_id, slot_id)`：同桌同時段不可被兩張有效訂位佔用 | `reservation_tables` |
| 3. DB 約束 | `CHECK (booked <= capacity)`：應用層有 bug 時 DB 仍拒絕超賣 | `slots` |

`reservation_tables` 的列必須在訂位取消／逾時釋放時一併刪除，否則會誤擋後續訂位。

## 關鍵索引

| 索引 | 用途 |
|---|---|
| `reservations(status, hold_expires_at)` | Scheduler 每分鐘釋放逾時保留 |
| `reservations(phone)` | 顧客「我的訂位」查詢 |
| `payments(status, created_at)` | 對帳排程掃描 pending 訂單 |
| `outbox_events(status, next_attempt_at)` | Worker 撈取待重試事件 |

## 設計取捨

- **狀態欄位用 `string` 而非 MySQL `ENUM`**：新增狀態不需 `ALTER TABLE`；合法值由 `app/Enums/*` 的 PHP Enum 約束。
- **桌位資料表命名為 `dining_tables`**：避免與 SQL 保留字 `tables` 混淆。
- **訂金為每人固定金額**：設定在 `stores.deposit_per_person`，訂位建立時算出 `reservations.deposit_amount` 並凍結，之後改門市設定不影響舊單。
- **菜單價格快照**：`reservation_items.unit_price` 記錄下單當下價格。
