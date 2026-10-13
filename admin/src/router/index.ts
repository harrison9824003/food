import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { UserRole } from '@/types/api'

declare module 'vue-router' {
  interface RouteMeta {
    /** 需要登入才能進入 */
    requiresAuth?: boolean
    /** 限定角色；未設定代表登入即可 */
    roles?: UserRole[]
    title?: string
  }
}

const router = createRouter({
  // base 由 vite.config.ts 的 base: '/admin/' 決定
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { title: '登入' },
    },
    {
      path: '/',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { title: '總覽' },
        },
        {
          path: 'reservations',
          name: 'reservations',
          component: () => import('@/views/ReservationsView.vue'),
          meta: { title: '訂單' },
        },
        {
          path: 'stores',
          name: 'stores',
          component: () => import('@/views/StoresView.vue'),
          meta: { title: '門市' },
        },
        {
          path: 'stores/:id(\\d+)',
          name: 'store-settings',
          component: () => import('@/views/StoreSettingsView.vue'),
          meta: { title: '門市設定' },
        },
        {
          path: 'erp-sync',
          name: 'erp-sync',
          component: () => import('@/views/ErpSyncView.vue'),
          meta: { title: 'ERP 同步', roles: ['hq_admin', 'store_manager'] },
        },
        {
          path: 'users',
          name: 'users',
          component: () => import('@/views/UsersView.vue'),
          meta: { title: '帳號管理', roles: ['hq_admin', 'store_manager'] },
        },
      ],
    },
    {
      path: '/forbidden',
      name: 'forbidden',
      component: () => import('@/views/ForbiddenView.vue'),
      meta: { title: '沒有權限', requiresAuth: true },
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  document.title = to.meta.title ? `${to.meta.title} - 門市管理後台` : '門市管理後台'

  const needsAuth = to.matched.some((r) => r.meta.requiresAuth)

  if (needsAuth && !auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // 已登入就不必再看登入頁
  if (to.name === 'login' && auth.isLoggedIn) {
    return { name: 'dashboard' }
  }

  // 重新整理後只剩 token，先取回使用者資料才能判斷角色
  if (auth.isLoggedIn && !auth.user) {
    try {
      await auth.fetchMe()
    } catch {
      // 401 已由 axios 攔截器處理；其他錯誤（如網路中斷）一律視為未登入
      auth.clear()
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  // 前端的角色檢查只是為了體驗（隱藏選單、擋掉路由）；真正的權限由後端 Policy 把關
  const allowed = to.meta.roles ?? to.matched.flatMap((r) => r.meta.roles ?? [])
  if (allowed.length > 0 && (!auth.role || !allowed.includes(auth.role))) {
    return { name: 'forbidden' }
  }
})

export default router
