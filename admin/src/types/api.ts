export type UserRole = 'hq_admin' | 'store_manager' | 'staff'

export interface StoreSummary {
  id: number
  name: string
}

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  is_active: boolean
  store_id: number | null
  store?: StoreSummary | null
  created_at: string
}

export interface LoginResponse {
  token: string
  token_type: 'Bearer'
  expires_at: string
  user: User
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

/** 後端統一錯誤格式：{ message, code, errors } */
export interface ApiErrorBody {
  message: string
  code: string
  errors: Record<string, string[]>
}

export interface Store {
  id: number
  name: string
  address: string | null
  phone: string | null
  is_active: boolean
  advance_days: number
  cutoff_minutes: number
  slot_capacity_default: number
  deposit_per_person: number
}

/** 週日=0 … 週六=6，與後端一致 */
export interface BusinessHour {
  weekday: number
  is_closed: boolean
  open_time: string | null
  close_time: string | null
  slot_minutes: number
}

export interface ClosedDate {
  id: number
  closed_date: string
  reason: string | null
}

export interface DiningTable {
  id: number
  store_id: number
  code: string
  min_seats: number
  max_seats: number
  is_combinable: boolean
  is_active: boolean
}

export interface Slot {
  id: number
  slot_date: string
  start_time: string
  capacity: number
  booked: number
  remaining: number
}

export interface SlotSyncResult {
  created: number
  deleted: number
}

export type ReservationStatus = 'pending_payment' | 'confirmed' | 'arrived' | 'no_show' | 'cancelled' | 'expired'

export interface AdminReservation {
  id: number
  code: string
  status: ReservationStatus
  status_label: string
  /** 目前可手動變更成哪些狀態（後端依狀態轉換表算好） */
  allowed_statuses: Array<'arrived' | 'no_show' | 'cancelled'>
  payment_status: string
  deposit_amount: number
  customer_name: string
  phone: string
  email: string | null
  party_size: number
  note: string | null
  date: string
  time: string
  store: StoreSummary
  tables: string[]
  created_at: string
}

export const STATUS_OPTIONS: Array<{ value: ReservationStatus; label: string }> = [
  { value: 'pending_payment', label: '待付款' },
  { value: 'confirmed', label: '已確認' },
  { value: 'arrived', label: '已到' },
  { value: 'no_show', label: '未到' },
  { value: 'cancelled', label: '已取消' },
  { value: 'expired', label: '已逾時' },
]

export const STATUS_ACTION_LABELS = { arrived: '已到', no_show: '未到', cancelled: '取消' } as const

export const ROLE_LABELS: Record<UserRole, string> = {
  hq_admin: '總部管理員',
  store_manager: '店長',
  staff: '店員',
}

export const WEEKDAY_LABELS = ['週日', '週一', '週二', '週三', '週四', '週五', '週六'] as const
