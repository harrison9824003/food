import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { http, setUnauthenticatedHandler, tokenStorage } from '@/api/http'
import type { LoginResponse, User, UserRole } from '@/types/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(tokenStorage.get())
  const user = ref<User | null>(null)

  const isLoggedIn = computed(() => token.value !== null)
  const role = computed<UserRole | null>(() => user.value?.role ?? null)
  const canManageUsers = computed(() => role.value === 'hq_admin' || role.value === 'store_manager')

  function clear(): void {
    token.value = null
    user.value = null
    tokenStorage.clear()
  }

  async function login(email: string, password: string): Promise<void> {
    const { data } = await http.post<LoginResponse>('/admin/login', { email, password })
    token.value = data.token
    user.value = data.user
    tokenStorage.set(data.token)
  }

  /** 重新整理頁面後，token 還在但 user 不在記憶體，需要向後端取回。 */
  async function fetchMe(): Promise<void> {
    const { data } = await http.get<User>('/admin/me')
    user.value = data
  }

  async function logout(): Promise<void> {
    try {
      await http.post('/admin/logout')
    } catch {
      // token 可能早已失效；不論後端結果，本機一律登出
    } finally {
      clear()
    }
  }

  // token 失效時清狀態；導頁由 router 的 guard 負責
  setUnauthenticatedHandler(() => {
    clear()
    window.location.assign(`${import.meta.env.BASE_URL}login`)
  })

  return { token, user, isLoggedIn, role, canManageUsers, login, fetchMe, logout, clear }
})
