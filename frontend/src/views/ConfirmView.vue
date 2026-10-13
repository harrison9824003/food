<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorCode, errorMessage, http } from '@/api/http'
import BookingSteps from '@/components/BookingSteps.vue'
import { useBookingStore } from '@/stores/booking'
import type { Reservation } from '@/types/api'

const booking = useBookingStore()
const router = useRouter()

const submitting = ref(false)
const errorText = ref('')
/** 時段被別人搶走／客滿時，引導回到選時段重選。 */
const needsNewSlot = ref(false)

async function submit(): Promise<void> {
  // 送出中不可再按：連點兩次會送出兩筆訂位
  if (submitting.value) {
    return
  }

  submitting.value = true
  errorText.value = ''
  needsNewSlot.value = false
  try {
    const { data } = await http.post<Reservation>('/reservations', {
      slot_id: booking.slotId,
      party_size: booking.partySize,
      customer_name: booking.customerName.trim(),
      phone: booking.phone,
      email: booking.email || null,
      note: booking.note || null,
      // 只送品項 ID 與數量；價格以伺服器上的為準，不信任前端
      items: booking.cart.map((line) => ({ menu_item_id: line.id, quantity: line.quantity })),
    })
    booking.setResult(data)
    await router.replace({ name: 'result' })
  } catch (error) {
    errorText.value = errorMessage(error, '訂位失敗')
    const code = errorCode(error)

    // 驗證憑證過期（停在確認頁超過 15 分鐘）：回到聯絡資料重新驗證，其他資料都還在
    if (code === 'PHONE_NOT_VERIFIED' || code === 'PHONE_MISMATCH') {
      ElMessage.warning('手機驗證已失效，請重新驗證')
      void router.replace({ name: 'info' })
      return
    }
    needsNewSlot.value = ['SLOT_FULL', 'NO_TABLE_AVAILABLE', 'CUTOFF_PASSED', 'DATE_CLOSED', 'OUT_OF_WINDOW'].includes(code ?? '')
    if (!needsNewSlot.value) {
      ElMessage.error(errorText.value)
    }
  } finally {
    submitting.value = false
  }
}

function reselect(): void {
  booking.slotId = null
  booking.slotTime = ''
  void router.push({ name: 'slot' })
}
</script>

<template>
  <BookingSteps :active="5" />

  <h2>確認訂位資料</h2>

  <el-alert v-if="errorText && needsNewSlot" type="warning" show-icon :closable="false" class="alert">
    <template #title>{{ errorText }}</template>
    <el-button size="small" type="primary" @click="reselect">重新選擇時段</el-button>
  </el-alert>

  <el-descriptions :column="1" border>
    <el-descriptions-item label="門市">{{ booking.storeName }}</el-descriptions-item>
    <el-descriptions-item label="日期時間">{{ booking.date }}　{{ booking.slotTime }}</el-descriptions-item>
    <el-descriptions-item label="人數">{{ booking.partySize }} 人</el-descriptions-item>
    <el-descriptions-item label="姓名">{{ booking.customerName }}</el-descriptions-item>
    <el-descriptions-item label="手機">{{ booking.phone }}</el-descriptions-item>
    <el-descriptions-item v-if="booking.email" label="Email">{{ booking.email }}</el-descriptions-item>
    <el-descriptions-item v-if="booking.note" label="備註">{{ booking.note }}</el-descriptions-item>
    <el-descriptions-item v-if="booking.cart.length" label="預點餐點">
      <div v-for="line in booking.cart" :key="line.id">{{ line.name }} × {{ line.quantity }}</div>
      <div class="sub">預點不計費，用餐時結帳</div>
    </el-descriptions-item>
    <el-descriptions-item label="訂金">
      <template v-if="booking.depositTotal > 0">
        <strong>${{ booking.depositTotal }}</strong>（每人 ${{ booking.depositPerPerson }}）
      </template>
      <template v-else>免訂金</template>
    </el-descriptions-item>
  </el-descriptions>

  <el-alert
    v-if="booking.depositTotal > 0"
    type="info"
    show-icon
    :closable="false"
    class="alert"
    title="此門市需要訂金：送出後會為您保留座位 10 分鐘，請在時間內完成線上付款，逾時座位將釋出。"
  />

  <div class="actions">
    <el-button :disabled="submitting" @click="router.push({ name: 'info' })">上一步</el-button>
    <el-button type="primary" :loading="submitting" @click="submit">確認送出</el-button>
  </div>
</template>

<style scoped>
.alert {
  margin: 16px 0;
}
.sub {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}
.actions {
  display: flex;
  justify-content: space-between;
  margin-top: 24px;
}
</style>
