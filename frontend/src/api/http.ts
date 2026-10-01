import axios from 'axios'
import type { ApiErrorBody } from '@/types/api'

export const http = axios.create({
  // 與 nginx 同源，走相對路徑；需要時可用 VITE_API_BASE_URL 覆蓋
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

/** 取出後端的錯誤代碼（如 SLOT_FULL），沒有則回傳 null。 */
export function errorCode(error: unknown): string | null {
  return axios.isAxiosError<ApiErrorBody>(error) ? (error.response?.data?.code ?? null) : null
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
