<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, ref, watch } from 'vue'
import { errorMessage, http } from '@/api/http'
import PayPanel from '@/components/PayPanel.vue'
import PhoneVerify from '@/components/PhoneVerify.vue'
import ReservationCard from '@/components/ReservationCard.vue'
import { useBookingStore } from '@/stores/booking'
import type { Reservation } from '@/types/api'

const booking = useBookingStore()

/** 這一頁可以查任何一支手機：先填號碼、收簡訊驗證，通過之後列出該手機的所有訂位（不必記得訂位代號）。 */
const phone = ref(booking.verifiedPhone || booking.phone)
const reservations = ref<Reservation[]>([])
const loading = ref(false)
const cancelling = ref('')

const loggedIn = computed(() => booking.hasValidToken && booking.verifiedPhone !== '')
const phoneLooksValid = computed(() => /^09\d{8}$/.test(phone.value))

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<Reservation[]>('/my/reservations')
    reservations.value = data
  } catch (error) {
    // 憑證過期時 http 層已清掉驗證狀態，畫面會自動回到驗證步驟
    ElMessage.error(errorMessage(error, '載入訂位失敗'))
  } finally {
    loading.value = false
  }
}

async function cancel(reservation: Reservation): Promise<void> {
  try {
    await ElMessageBox.confirm('確定要取消這筆訂位嗎？取消後座位會釋出給其他人。', '取消訂位', {
      type: 'warning',
      confirmButtonText: '確定取消',
      cancelButtonText: '返回',
    })
  } catch {
    return
  }

  cancelling.value = reservation.code
  try {
    const { data } = await http.post<Reservation>('/reservations/cancel', { code: reservation.code })
    Object.assign(reservation, data)
    // 剛訂完的那筆被取消了，結果頁也要同步
    if (booking.result?.code === data.code) {
      booking.result = data
    }
    ElMessage.success('已取消訂位')
  } catch (error) {
    // 已付訂金時後端會說明需走退款流程，直接顯示給使用者
    ElMessage.error(errorMessage(error, '取消失敗'))
  } finally {
    cancelling.value = ''
  }
}

function switchPhone(): void {
  booking.clearVerification()
  reservations.value = []
}

const cancellable = (r: Reservation): boolean => r.status === 'confirmed' || r.status === 'pending_payment'

watch(loggedIn, (value) => {
  if (value) {
    void load()
  }
})

onMounted(() => {
  if (loggedIn.value) {
    void load()
  }
})
</script>

<template>
  <h2>我的訂位</h2>

  <template v-if="!loggedIn">
    <p class="hint">輸入訂位時使用的手機號碼，收到簡訊驗證碼後，就能看到這支手機的所有訂位。</p>
    <el-form label-position="top" @submit.prevent>
      <el-form-item label="手機號碼">
        <el-input v-model="phone" maxlength="10" inputmode="numeric" placeholder="09xxxxxxxx" />
      </el-form-item>
    </el-form>
    <PhoneVerify :phone="phone" />
    <p v-if="phone && !phoneLooksValid" class="hint warn">請輸入正確的手機號碼（09 開頭共 10 碼）</p>
  </template>

  <template v-else>
    <div class="bar">
      <span>手機 {{ booking.verifiedPhone }}</span>
      <el-button link type="primary" @click="switchPhone">換一支手機</el-button>
    </div>

    <div v-loading="loading" class="list">
      <el-empty v-if="!loading && reservations.length === 0" description="這支手機目前沒有訂位" />

      <div v-for="r in reservations" :key="r.code" class="item">
        <ReservationCard :reservation="r" />
        <PayPanel
          v-if="r.can_pay"
          :key="`${r.code}-${r.hold_expires_at}`"
          :reservation="r"
          :phone="booking.verifiedPhone"
          @refresh="load"
          @expired="load"
        />
        <div v-if="cancellable(r)" class="actions">
          <el-button type="danger" plain :loading="cancelling === r.code" @click="cancel(r)">取消訂位</el-button>
        </div>
      </div>
    </div>
  </template>
</template>

<style scoped>
.hint {
  color: #606266;
  font-size: 14px;
}
.hint.warn {
  color: #e6a23c;
}
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.list {
  display: grid;
  gap: 24px;
  min-height: 60px;
}
.actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
