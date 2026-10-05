/** 後端統一錯誤格式：{ message, code, errors } */
export interface ApiErrorBody {
  message: string
  code: string
  errors: Record<string, string[]>
}

export interface StoreInfo {
  id: number
  name: string
  address: string | null
  phone: string | null
  deposit_per_person: number
  advance_days: number
}

export interface CalendarDay {
  date: string
  open: boolean
}

export interface Calendar {
  from: string
  to: string
  dates: CalendarDay[]
}

export interface SlotAvailability {
  id: number
  start_time: string
  remaining: number
  bookable: boolean
  reason: string | null
}

export interface MenuImage {
  url: string
  thumb_url: string
}

export interface MenuItem {
  id: number
  name: string
  description: string | null
  price: number
  /** 今日售完：仍顯示在菜單上，但不能預點 */
  is_sold_out: boolean
  /** 第一張是封面 */
  images: MenuImage[]
}

export interface MenuCategoryGroup {
  /** 沒有分類的品項歸在最後的「其他」，此時 id 為 null */
  id: number | null
  name: string
  items: MenuItem[]
}

/** 預點的餐點（購物車一列）：名稱與單價只用來顯示，送出訂位時只送 id 與數量。 */
export interface CartLine {
  id: number
  name: string
  price: number
  quantity: number
}

/** 要由瀏覽器 POST 給綠界的表單 */
export interface PayResponse {
  action: string
  fields: Record<string, string>
  merchant_trade_no: string
}

export const PAYMENT_STATUS_LABELS: Record<string, string> = {
  not_required: '免訂金',
  unpaid: '未付款',
  pending: '付款處理中',
  paid: '已付款',
  failed: '付款失敗',
  refunding: '退款中',
  refunded: '已退款',
}

export interface Reservation {
  code: string
  status: 'pending_payment' | 'confirmed' | 'arrived' | 'no_show' | 'cancelled' | 'expired'
  status_label: string
  payment_status: string
  deposit_amount: number
  hold_expires_at: string | null
  /** 伺服器的現在時間，倒數計時以它為準，不用手機的時鐘 */
  server_time: string
  can_pay: boolean
  items?: Array<{ name: string; quantity: number; unit_price: number }>
  customer_name: string
  party_size: number
  note: string | null
  date: string
  time: string
  store: { id: number; name: string; address: string | null; phone: string | null }
}

/** 時段不可訂的原因代碼 → 顯示文字 */
export const BLOCK_REASON_LABELS: Record<string, string> = {
  SLOT_FULL: '客滿',
  NO_TABLE_AVAILABLE: '無適合桌位',
  CUTOFF_PASSED: '已截止',
  DATE_CLOSED: '公休',
  OUT_OF_WINDOW: '未開放',
  STORE_INACTIVE: '未開放',
}
