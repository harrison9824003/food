import { createRouter, createWebHistory } from 'vue-router'
import { useBookingStore } from '@/stores/booking'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    /** 進入這一步之前，前面的步驟必須已經完成 */
    requires?: 'store' | 'dateAndParty' | 'slot' | 'contact'
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'store', component: () => import('@/views/StoreSelectView.vue'), meta: { title: '選擇門市' } },
    {
      path: '/book/date',
      name: 'date',
      component: () => import('@/views/DatePartyView.vue'),
      meta: { title: '日期與人數', requires: 'store' },
    },
    {
      path: '/book/slot',
      name: 'slot',
      component: () => import('@/views/SlotView.vue'),
      meta: { title: '選擇時段', requires: 'dateAndParty' },
    },
    {
      path: '/book/info',
      name: 'info',
      component: () => import('@/views/ContactView.vue'),
      meta: { title: '聯絡資料', requires: 'slot' },
    },
    {
      path: '/book/confirm',
      name: 'confirm',
      component: () => import('@/views/ConfirmView.vue'),
      meta: { title: '確認訂位', requires: 'contact' },
    },
    { path: '/result', name: 'result', component: () => import('@/views/ResultView.vue'), meta: { title: '訂位完成' } },
    { path: '/lookup', name: 'lookup', component: () => import('@/views/LookupView.vue'), meta: { title: '我的訂位' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

/**
 * 不能跳步：直接輸入網址或重新整理到中間步驟時，若前面的資料還在（sessionStorage）就正常進入，
 * 否則退回到最早缺資料的那一步，而不是顯示一個壞掉的頁面。
 */
router.beforeEach((to) => {
  const booking = useBookingStore()

  document.title = to.meta.title ? `${to.meta.title} - 線上訂位` : '線上訂位'

  switch (to.meta.requires) {
    case 'store':
      return booking.hasStore ? true : { name: 'store' }
    case 'dateAndParty':
      return booking.hasDateAndParty ? true : { name: booking.hasStore ? 'date' : 'store' }
    case 'slot':
      return booking.hasSlot ? true : { name: booking.hasDateAndParty ? 'slot' : booking.hasStore ? 'date' : 'store' }
    case 'contact':
      return booking.hasContact ? true : { name: booking.hasSlot ? 'info' : booking.hasDateAndParty ? 'slot' : 'date' }
    default:
      return true
  }
})

export default router
