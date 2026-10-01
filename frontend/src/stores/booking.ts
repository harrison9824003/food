import { defineStore } from 'pinia'
import { computed, reactive, toRefs, watch } from 'vue'
import type { Reservation } from '@/types/api'

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

  function selectStore(id: number, name: string, depositPerPerson: number): void {
    // 換門市：日期、時段都要重選；聯絡資料不必重填
    if (draft.storeId !== id) {
      draft.date = ''
      draft.slotId = null
      draft.slotTime = ''
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

  function setResult(result: Reservation): void {
    draft.result = result
  }

  /** 重新開始一筆新訂位；保留姓名與手機，下次不必再填。 */
  function startOver(): void {
    const { customerName, phone, email } = draft
    Object.assign(draft, emptyDraft(), { customerName, phone, email })
  }

  return {
    ...toRefs(draft),
    hasStore,
    hasDateAndParty,
    hasSlot,
    hasContact,
    depositTotal,
    selectStore,
    setDateAndParty,
    selectSlot,
    setResult,
    startOver,
  }
})
