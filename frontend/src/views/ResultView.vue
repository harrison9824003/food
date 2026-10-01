<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import ReservationCard from '@/components/ReservationCard.vue'
import { useBookingStore } from '@/stores/booking'

const booking = useBookingStore()
const router = useRouter()

onMounted(() => {
  // 沒有結果（直接輸入網址、或已按過「再訂一筆」）就沒什麼可顯示，導去查詢頁
  if (booking.result === null) {
    void router.replace({ name: 'lookup' })
  }
})

function again(): void {
  booking.startOver()
  void router.push({ name: 'store' })
}
</script>

<template>
  <template v-if="booking.result">
    <el-result
      :icon="booking.result.status === 'pending_payment' ? 'warning' : 'success'"
      :title="booking.result.status === 'pending_payment' ? '已為您保留座位' : '訂位成功'"
      :sub-title="booking.result.status === 'pending_payment' ? '此門市需要訂金，線上付款功能即將開放' : '期待您的光臨'"
    />

    <ReservationCard :reservation="booking.result" />

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
