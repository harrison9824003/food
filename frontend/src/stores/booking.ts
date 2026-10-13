import { defineStore } from 'pinia'
import { computed, reactive, toRefs, watch } from 'vue'
import type { CartLine, Reservation } from '@/types/api'

const STORAGE_KEY = 'booking_draft_v1'

interface Draft {
  storeId: number | null
  storeName: string
  depositPerPerson: number
  date: string
  partySize: number
  slotId: number | null
  slotTime: string
  customerName: string
  phone: string
  email: string
  note: string
  /** 預點的餐點（選填、不計費） */
  cart: CartLine[]
  /** 簡訊驗證憑證：證明這個瀏覽器分頁持有 verifiedPhone 這支手機（到期後失效，需重新驗證） */
  phoneToken: string
  phoneTokenExpiresAt: string
  verifiedPhone: string
  /** 訂位成功後的結果，供結果頁顯示；重新整理後仍在 */
  result: Reservation | null
}

function emptyDraft(): Draft {
  return {
    storeId: null,
    storeName: '',
    depositPerPerson: 0,
    date: '',
    partySize: 2,
    slotId: null,
    slotTime: '',
    customerName: '',
    phone: '',
    email: '',
    note: '',
    cart: [],
    phoneToken: '',
    phoneTokenExpiresAt: '',
    verifiedPhone: '',
    result: null,
  }
}

/** sessionStorage 在隱私模式或被封鎖時會拋例外，讀不到就當作全新開始。 */
function load(): Draft {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    return raw ? { ...emptyDraft(), ...(JSON.parse(raw) as Partial<Draft>) } : emptyDraft()
  } catch {
    return emptyDraft()
  }
}

function save(draft: Draft): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(draft))
  } catch {
    /* 存不了就算了：只是重新整理後要重填，不影響流程 */
  }
}

/**
 * 多步驟訂位表單的暫存資料。
 *
 * 存在 sessionStorage：上一頁時資料還在、重新整理頁面也不會清空，關掉分頁才消失。
 * （不用 localStorage：訂位資料含手機號碼，不該在關閉瀏覽器後還留在這台裝置上。）
 */
export const useBookingStore = defineStore('booking', () => {
  const draft = reactive<Draft>(load())

  watch(draft, () => save(draft), { deep: true })

  /** 已填到哪一步；路由守衛用它判斷使用者能不能直接進入某個步驟。 */
  const hasStore = computed(() => draft.storeId !== null)
  const hasDateAndParty = computed(() => hasStore.value && draft.date !== '' && draft.partySize >= 1)
  const hasSlot = computed(() => hasDateAndParty.value && draft.slotId !== null)
  const hasContact = computed(() => hasSlot.value && draft.customerName.trim() !== '' && draft.phone !== '')

  const depositTotal = computed(() => draft.depositPerPerson * draft.partySize)

  /** 憑證是否仍有效（前端只做提示；真正的判斷在伺服器，過期時伺服器會回 401 PHONE_NOT_VERIFIED）。 */
  const hasValidToken = computed(
    () => draft.phoneToken !== '' && Date.parse(draft.phoneTokenExpiresAt) > Date.now(),
  )

  /** 填寫的手機號碼是否就是已驗證的那一支。改了號碼就要重新驗證。 */
  const phoneVerified = computed(() => hasValidToken.value && draft.verifiedPhone === draft.phone)

  function setVerification(phone: string, token: string, expiresAt: string): void {
    draft.verifiedPhone = phone
    draft.phoneToken = token
    draft.phoneTokenExpiresAt = expiresAt
  }

  function clearVerification(): void {
    draft.verifiedPhone = ''
    draft.phoneToken = ''
    draft.phoneTokenExpiresAt = ''
  }

  function selectStore(id: number, name: string, depositPerPerson: number): void {
    // 換門市：日期、時段都要重選；聯絡資料不必重填
    if (draft.storeId !== id) {
      draft.date = ''
      draft.slotId = null
      draft.slotTime = ''
      draft.cart = [] // 菜單是各門市自己的，換門市後原本選的餐點不適用
    }
    draft.storeId = id
    draft.storeName = name
    draft.depositPerPerson = depositPerPerson
  }

  /** 日期或人數改變後，原本選的時段可能已經不適用。 */
  function setDateAndParty(date: string, partySize: number): void {
    if (draft.date !== date || draft.partySize !== partySize) {
      draft.slotId = null
      draft.slotTime = ''
    }
    draft.date = date
    draft.partySize = partySize
  }

  function selectSlot(id: number, time: string): void {
    draft.slotId = id
    draft.slotTime = time
  }

  /** 設定某個品項的數量；0 代表從購物車移除。 */
  function setQuantity(item: { id: number; name: string; price: number }, quantity: number): void {
    const existing = draft.cart.find((line) => line.id === item.id)

    if (quantity <= 0) {
      draft.cart = draft.cart.filter((line) => line.id !== item.id)
    } else if (existing) {
      existing.quantity = quantity
    } else {
      draft.cart.push({ id: item.id, name: item.name, price: item.price, quantity })
    }
  }

  function quantityOf(id: number): number {
    return draft.cart.find((line) => line.id === id)?.quantity ?? 0
  }

  function setResult(result: Reservation): void {
    draft.result = result
  }

  /** 重新開始一筆新訂位；保留姓名與手機，下次不必再填。 */
  function startOver(): void {
    // 驗證憑證也保留：同一支手機再訂一筆不必重新收簡訊
    const { customerName, phone, email, phoneToken, phoneTokenExpiresAt, verifiedPhone } = draft
    Object.assign(draft, emptyDraft(), { customerName, phone, email, phoneToken, phoneTokenExpiresAt, verifiedPhone })
  }

  return {
    ...toRefs(draft),
    hasStore,
    hasDateAndParty,
    hasSlot,
    hasContact,
    depositTotal,
    hasValidToken,
    phoneVerified,
    setVerification,
    clearVerification,
    selectStore,
    setDateAndParty,
    selectSlot,
    setQuantity,
    quantityOf,
    setResult,
    startOver,
  }
})
