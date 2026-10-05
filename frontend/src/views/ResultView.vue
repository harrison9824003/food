<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { http } from '@/api/http'
import PayPanel from '@/components/PayPanel.vue'
import ReservationCard from '@/components/ReservationCard.vue'
import { useBookingStore } from '@/stores/booking'
import type { Reservation } from '@/types/api'

const booking = useBookingStore()
const router = useRouter()

/** 向伺服器取回最新狀態（重新整理、付款完成、保留時間到期後都會用到）。 */
async function refresh(): Promise<void> {
  if (booking.result === null) {
    return
  }
  try {
    const { data } = await http.post<Reservation>('/reservations/lookup', { code: booking.result.code, phone: booking.phone })
    booking.setResult(data)
  } catch {
    /* 查不到就維持畫面上現有的資料 */
  }
}

onMounted(() => {
  // 沒有結果（直接輸入網址、或已按過「再訂一筆」）就沒什麼可顯示，導去查詢頁
  if (booking.result === null) {
    void router.replace({ name: 'lookup' })
    return
  }
  void refresh()
})

function again(): void {
  booking.startOver()
  void router.push({ name: 'store' })
}
</script>

<template>
  <template v-if="booking.result">
    <el-result
      v-if="booking.result.status === 'pending_payment'"
      icon="warning"
      title="已為您保留座位"
      sub-title="請完成付款，訂位才會成立"
    />
    <el-result v-else-if="booking.result.status === 'confirmed'" icon="success" title="訂位成功" sub-title="期待您的光臨" />
    <el-result v-else icon="info" :title="`訂位${booking.result.status_label}`" />

    <ReservationCard :reservation="booking.result" />

    <!-- 需要訂金：顯示倒數與付款按鈕。key 帶上 code 與到期時間，狀態更新後面板會重新初始化計時 -->
    <PayPanel
      v-if="booking.result.can_pay"
      :key="`${booking.result.code}-${booking.result.hold_expires_at}`"
      :reservation="booking.result"
      :phone="booking.phone"
      @refresh="refresh"
      @expired="refresh"
    />

    <el-alert
      type="info"
      show-icon
      :closable="false"
      class="tip"
      title="請記下訂位代號與手機號碼，日後可到「我的訂位」查詢或取消。"
    />

    <div class="actions">
      <el-button @click="router.push({ name: 'lookup' })">我的訂位</el-button>
      <el-button type="primary" @click="again">再訂一筆</el-button>
    </div>
  </template>
</template>

<style scoped>
.tip {
  margin-top: 16px;
}
.actions {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 24px;
}
</style>
