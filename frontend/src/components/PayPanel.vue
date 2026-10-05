<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import type { PayResponse, Reservation } from '@/types/api'

const props = defineProps<{ reservation: Reservation; phone: string }>()
const emit = defineEmits<{ refresh: []; expired: [] }>()

/** 開發環境才有的付款模擬器（綠界打不到 localhost，本機沒辦法收到真正的背景通知）。 */
const simulatorEnabled = import.meta.env.VITE_PAYMENT_SIMULATOR === 'true'

const busy = ref(false)

// ── 倒數計時 ────────────────────────────────────────────────────────────
// 用「伺服器的時間」算：顧客手機的時鐘可能快慢好幾分鐘，會讓倒數和實際保留時間對不上。
// 作法是記下「手機時間與伺服器時間的差」，之後每秒用差值校正。
const clockOffset = Date.now() - Date.parse(props.reservation.server_time)
const now = ref(Date.now() - clockOffset)
let timer: ReturnType<typeof setInterval> | undefined
let notifiedExpiry = false

const remainingSeconds = computed(() => {
  if (!props.reservation.hold_expires_at) {
    return 0
  }
  return Math.max(0, Math.floor((Date.parse(props.reservation.hold_expires_at) - now.value) / 1000))
})

const countdown = computed(() => {
  const m = String(Math.floor(remainingSeconds.value / 60)).padStart(2, '0')
  const s = String(remainingSeconds.value % 60).padStart(2, '0')
  return `${m}:${s}`
})

const expired = computed(() => remainingSeconds.value === 0)

onMounted(() => {
  timer = setInterval(() => {
    now.value = Date.now() - clockOffset
    if (expired.value && !notifiedExpiry) {
      notifiedExpiry = true
      emit('expired')
    }
  }, 1000)
})

onBeforeUnmount(() => clearInterval(timer))

// ── 付款 ────────────────────────────────────────────────────────────────

async function createPayment(): Promise<PayResponse> {
  const { data } = await http.post<PayResponse>('/reservations/pay', {
    code: props.reservation.code,
    phone: props.phone,
  })
  return data
}

/** 綠界要求以表單 POST 的方式送出，所以動態建一個隱藏表單送出，瀏覽器會被導向綠界的付款頁。 */
function submitToGateway(pay: PayResponse): void {
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = pay.action
  for (const [name, value] of Object.entries(pay.fields)) {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = value
    form.appendChild(input)
  }
  document.body.appendChild(form)
  form.submit()
}

async function payNow(): Promise<void> {
  if (busy.value) {
    return
  }
  busy.value = true
  try {
    submitToGateway(await createPayment())
  } catch (error) {
    ElMessage.error(errorMessage(error, '無法建立付款'))
    emit('refresh') // 可能是保留時間剛好到了，重新載入以顯示最新狀態
    busy.value = false
  }
}

/** 開發用：不經過綠界，由伺服器組一份簽好章的通知打到我們自己的 notify 網址。 */
async function simulate(outcome: 'paid' | 'failed', times = 1): Promise<void> {
  if (busy.value) {
    return
  }
  busy.value = true
  try {
    const pay = await createPayment()
    const { data } = await http.post<{ results: Array<{ response: string }> }>('/dev/ecpay/simulate', {
      merchant_trade_no: pay.merchant_trade_no,
      outcome,
      times,
    })
    const replies = data.results.map((r) => r.response).join('、')
    ElMessage.success(`已送出 ${times} 次模擬通知，綠界回覆：${replies}`)
    emit('refresh')
  } catch (error) {
    ElMessage.error(errorMessage(error, '模擬失敗'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <el-card shadow="never" class="panel">
    <template v-if="!expired">
      <div class="head">
        <span>請於 <strong class="count">{{ countdown }}</strong> 內完成付款，逾時座位將釋出</span>
      </div>
      <div class="amount">訂金 <strong>${{ reservation.deposit_amount }}</strong></div>
      <el-button type="primary" size="large" :loading="busy" class="pay" @click="payNow">前往付款（信用卡）</el-button>

      <div v-if="simulatorEnabled" class="sim">
        <div class="sim-title">開發用付款模擬器（不經過綠界）</div>
        <el-button size="small" :disabled="busy" @click="simulate('paid')">模擬付款成功</el-button>
        <el-button size="small" :disabled="busy" @click="simulate('paid', 3)">連發 3 次成功通知（測冪等）</el-button>
        <el-button size="small" :disabled="busy" @click="simulate('failed')">模擬付款失敗</el-button>
      </div>
    </template>

    <el-alert v-else type="warning" show-icon :closable="false" title="保留時間已過，座位已釋出，請重新訂位" />
  </el-card>
</template>

<style scoped>
.panel {
  margin-top: 16px;
}
.head {
  margin-bottom: 8px;
}
.count {
  font-size: 20px;
  color: #e6a23c;
  font-variant-numeric: tabular-nums;
}
.amount {
  margin-bottom: 12px;
}
.pay {
  width: 100%;
}
.sim {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px dashed #dcdfe6;
}
.sim-title {
  color: #909399;
  font-size: 12px;
  margin-bottom: 8px;
}
</style>
