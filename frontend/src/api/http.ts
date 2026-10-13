import axios from 'axios'
import { useBookingStore } from '@/stores/booking'
import type { ApiErrorBody } from '@/types/api'

export const http = axios.create({
  // 與 nginx 同源，走相對路徑；需要時可用 VITE_API_BASE_URL 覆蓋
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

// 有簡訊驗證憑證就一律帶上：訂位、查詢、取消、付款都靠它證明持有手機
http.interceptors.request.use((config) => {
  const { phoneToken } = useBookingStore()
  if (phoneToken) {
    config.headers['X-Phone-Token'] = phoneToken
  }
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    // 憑證過期或無效：清掉，畫面會自然回到「請先驗證手機」
    if (errorCode(error) === 'PHONE_NOT_VERIFIED') {
      useBookingStore().clearVerification()
    }
    return Promise.reject(error)
  },
)

/** 取出後端的錯誤代碼（如 SLOT_FULL），沒有則回傳 null。 */
export function errorCode(error: unknown): string | null {
  return axios.isAxiosError<ApiErrorBody>(error) ? (error.response?.data?.code ?? null) : null
}

/** 重送冷卻中時，後端會告訴還要等幾秒。 */
export function retryAfterSeconds(error: unknown): number | null {
  if (!axios.isAxiosError<ApiErrorBody & { errors: { retry_after?: number } }>(error)) {
    return null
  }
  const value = error.response?.data?.errors?.retry_after
  return typeof value === 'number' ? value : null
}

/** 取出給使用者看的錯誤訊息。 */
export function errorMessage(error: unknown, fallback = '發生錯誤，請稍後再試'): string {
  if (axios.isAxiosError<ApiErrorBody>(error)) {
    if (!error.response) {
      return '無法連線到伺服器，請檢查網路後再試'
    }
    const body = error.response.data
    // 驗證失敗時，直接顯示第一個欄位的具體原因
    const firstFieldError = body?.code === 'VALIDATION_FAILED' ? Object.values(body.errors ?? {})[0]?.[0] : undefined
    return firstFieldError ?? body?.message ?? fallback
  }
  return fallback
}
