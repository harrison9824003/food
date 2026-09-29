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

export const ROLE_LABELS: Record<UserRole, string> = {
  hq_admin: '總部管理員',
  store_manager: '店長',
  staff: '店員',
}
