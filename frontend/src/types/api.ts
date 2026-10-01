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

export interface Reservation {
  code: string
  status: 'pending_payment' | 'confirmed' | 'arrived' | 'no_show' | 'cancelled' | 'expired'
  status_label: string
  payment_status: string
  deposit_amount: number
  hold_expires_at: string | null
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
