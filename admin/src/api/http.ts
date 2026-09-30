import axios, { AxiosError } from 'axios'
import type { ApiErrorBody } from '@/types/api'

const TOKEN_KEY = 'admin_token'

/** localStorage 在隱私模式或被封鎖時會拋例外，一律包起來，讀不到就當作沒登入。 */
export const tokenStorage = {
  get(): string | null {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set(token: string): void {
    try {
      localStorage.setItem(TOKEN_KEY, token)
    } catch {
      /* 忽略：此次登入只在記憶體中有效 */
    }
  },
  clear(): void {
    try {
      localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* 忽略 */
    }
  },
}

export const http = axios.create({
  // 與 nginx 同源，預設走相對路徑；需要時可用 VITE_API_BASE_URL 覆蓋
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

http.interceptors.request.use((config) => {
  const token = tokenStorage.get()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/**
 * token 失效（過期、被撤銷）時，由 auth store 註冊的處理函式負責清狀態並導回登入頁。
 * 用註冊的方式而不是直接 import store，避免 api 與 store 互相依賴。
 */
let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler
}

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorBody>) => {
    // 只有 UNAUTHENTICATED 才代表 token 失效；登入失敗的 INVALID_CREDENTIALS 也是 401，不能被誤判
    if (error.response?.data?.code === 'UNAUTHENTICATED') {
      onUnauthenticated?.()
    }
    return Promise.reject(error)
  },
)

/** 取出給使用者看的錯誤訊息。 */
export function errorMessage(error: unknown, fallback = '發生錯誤，請稍後再試'): string {
  if (axios.isAxiosError<ApiErrorBody>(error)) {
    if (!error.response) {
      return '無法連線到伺服器'
    }
    const body = error.response.data
    // 驗證失敗時，「資料驗證失敗」幫助不大，直接顯示第一個欄位的具體原因
    const firstFieldError = body?.code === 'VALIDATION_FAILED' ? Object.values(body.errors ?? {})[0]?.[0] : undefined
    return firstFieldError ?? body?.message ?? fallback
  }
  return fallback
}
