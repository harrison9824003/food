<script setup lang="ts">
import { Loading } from '@element-plus/icons-vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { http } from '@/api/http'
import PayPanel from '@/components/PayPanel.vue'
import ReservationCard from '@/components/ReservationCard.vue'
import { useBookingStore } from '@/stores/booking'
import type { Reservation } from '@/types/api'

/**
 * 顧客在綠界付完款、被導回來的頁面。
 *
 * 這一頁「只顯示」，不改任何狀態：付款是否成功以綠界的背景通知為準。
 * 背景通知通常比瀏覽器導回慢幾秒，所以進來時可能還是待付款，這裡會輪詢我們自己的 API 等結果出來。
 */
const POLL_MS = 3000
const MAX_POLLS = 20

const route = useRoute()
const router = useRouter()
const booking = useBookingStore()

const code = computed(() => String(route.query.code ?? ''))
const reservation = ref<Reservation | null>(null)
const polls = ref(0)
const gaveUp = ref(false)
const notFound = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

const settled = computed(() => reservation.value !== null && reservation.value.status !== 'pending_payment')
const paymentFailed = computed(() => reservation.value?.payment_status === 'failed')
const canRetry = computed(() => reservation.value?.can_pay === true)

async function check(): Promise<void> {
  try {
    const { data } = await http.post<Reservation>('/reservations/lookup', { code: code.value, phone: booking.phone })
    reservation.value = data
    if (booking.result?.code === data.code) {
      booking.setResult(data)
    }
  } catch {
    notFound.value = true
    return
  }

  polls.value++
  // 已經有結果（確認、逾時…）或付款明確失敗就停止輪詢
  if (settled.value || paymentFailed.value) {
    return
  }
  if (polls.value >= MAX_POLLS) {
    gaveUp.value = true
    return
  }
  timer = setTimeout(check, POLL_MS)
}

onMounted(() => {
  // 手機號碼只存在原本訂位的那個瀏覽器分頁；換了瀏覽器就沒有，請顧客自己去查詢
  if (!code.value || !booking.phone) {
    notFound.value = true
    return
  }
  void check()
})

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div v-if="notFound" class="center">
    <el-result icon="info" title="請到「我的訂位」查看付款結果" sub-title="需要輸入訂位代號與手機號碼">
      <template #extra>
        <el-button type="primary" @click="router.push({ name: 'lookup' })">我的訂位</el-button>
      </template>
    </el-result>
  </div>

  <template v-else-if="reservation">
    <el-result v-if="reservation.status === 'confirmed'" icon="success" title="付款成功，訂位已確認" sub-title="期待您的光臨" />
    <el-result v-else-if="reservation.status === 'expired' || reservation.status === 'cancelled'" icon="warning" title="這筆訂位已失效" sub-title="如果您已完成付款，請聯絡門市，我們會為您處理退款" />
    <el-result v-else-if="paymentFailed" icon="error" title="付款未完成" sub-title="您可以在保留時間內重新付款" />
    <el-result v-else-if="gaveUp" icon="info" title="尚未收到付款結果" sub-title="結果通常幾分鐘內會更新，請稍後到「我的訂位」查看；若已扣款，不需要重複付款" />
    <el-result v-else title="付款處理中…" sub-title="正在確認付款結果，請不要關閉此頁面">
      <template #icon><el-icon class="is-loading" :size="48"><Loading /></el-icon></template>
    </el-result>

    <ReservationCard :reservation="reservation" />

    <PayPanel v-if="canRetry && (paymentFailed || gaveUp)" :reservation="reservation" :phone="booking.phone" @refresh="check" @expired="check" />

    <div class="actions">
      <el-button @click="router.push({ name: 'lookup' })">我的訂位</el-button>
      <el-button type="primary" @click="router.push({ name: 'store' })">回首頁</el-button>
    </div>
  </template>

  <div v-else class="center" v-loading="true" style="min-height: 200px" />
</template>

<style scoped>
.center {
  padding-top: 40px;
}
.actions {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 24px;
}
</style>
