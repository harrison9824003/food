<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { computed, onBeforeUnmount, ref } from 'vue'
import { errorCode, errorMessage, http, retryAfterSeconds } from '@/api/http'
import { useBookingStore } from '@/stores/booking'

/**
 * 簡訊驗證：寄驗證碼 → 輸入 → 換到憑證（存進 booking store，之後的 API 請求自動帶上）。
 * 手機號碼由外面傳進來；號碼格式不對時不能寄。
 */
const props = defineProps<{ phone: string }>()
const emit = defineEmits<{ verified: [] }>()

const booking = useBookingStore()

/** 開發環境才有的簡訊收件匣（本機不發真的簡訊）。 */
const inboxEnabled = import.meta.env.VITE_SMS_INBOX === 'true'

const phoneValid = computed(() => /^09\d{8}$/.test(props.phone))
const code = ref('')
const sent = ref(false)
const sending = ref(false)
const verifying = ref(false)
const errorText = ref('')
const inbox = ref<string[]>([])

// 重送冷卻倒數
const cooldown = ref(0)
let timer: ReturnType<typeof setInterval> | undefined

function startCooldown(seconds: number): void {
  cooldown.value = seconds
  clearInterval(timer)
  timer = setInterval(() => {
    cooldown.value = Math.max(0, cooldown.value - 1)
    if (cooldown.value === 0) {
      clearInterval(timer)
    }
  }, 1000)
}

onBeforeUnmount(() => clearInterval(timer))

async function sendCode(): Promise<void> {
  if (sending.value || cooldown.value > 0 || !phoneValid.value) {
    return
  }

  sending.value = true
  errorText.value = ''
  try {
    const { data } = await http.post<{ resend_after: number }>('/phone/send-code', { phone: props.phone })
    sent.value = true
    code.value = ''
    startCooldown(data.resend_after)
    ElMessage.success('驗證碼已寄出')
  } catch (error) {
    // 剛寄過還在冷卻：後端會說還要等幾秒，直接接上倒數
    const wait = retryAfterSeconds(error)
    if (wait !== null) {
      sent.value = true
      startCooldown(wait)
    }
    errorText.value = errorMessage(error, '寄送失敗，請稍後再試')
  } finally {
    sending.value = false
  }
}

async function verify(): Promise<void> {
  if (verifying.value || !/^\d{6}$/.test(code.value)) {
    return
  }

  verifying.value = true
  errorText.value = ''
  try {
    const { data } = await http.post<{ token: string; expires_at: string }>('/phone/verify', {
      phone: props.phone,
      code: code.value,
    })
    booking.setVerification(props.phone, data.token, data.expires_at)
    emit('verified')
  } catch (error) {
    errorText.value = errorMessage(error, '驗證失敗')
    code.value = ''
    // 錯誤次數用完，驗證碼已作廢：回到「重新寄送」
    if (errorCode(error) === 'CODE_ATTEMPTS_EXCEEDED') {
      sent.value = false
      cooldown.value = 0
    }
  } finally {
    verifying.value = false
  }
}

async function loadInbox(): Promise<void> {
  try {
    const { data } = await http.get<Array<{ body: string }>>('/dev/sms', { params: { phone: props.phone } })
    inbox.value = data.map((m) => m.body)
    if (inbox.value.length === 0) {
      ElMessage.info('收件匣是空的')
    }
  } catch (error) {
    ElMessage.error(errorMessage(error, '無法讀取模擬簡訊'))
  }
}
</script>

<template>
  <el-card shadow="never" class="panel">
    <div class="title">手機驗證</div>
    <p class="hint">為了確認這支手機是您的，我們會傳送驗證碼簡訊到 <strong>{{ phone || '您的手機' }}</strong>。</p>

    <el-alert v-if="errorText" :title="errorText" type="error" show-icon :closable="false" class="alert" />

    <div v-if="!sent" class="row">
      <el-button type="primary" :disabled="!phoneValid" :loading="sending" @click="sendCode">傳送驗證碼</el-button>
      <span v-if="!phoneValid" class="hint">請先輸入正確的手機號碼</span>
    </div>

    <template v-else>
      <el-input
        v-model="code"
        maxlength="6"
        inputmode="numeric"
        autocomplete="one-time-code"
        placeholder="輸入 6 位數驗證碼"
        class="code"
        @keyup.enter="verify"
      />
      <div class="row">
        <el-button type="primary" :loading="verifying" :disabled="code.length !== 6" @click="verify">確認</el-button>
        <el-button :disabled="cooldown > 0 || sending" @click="sendCode">
          {{ cooldown > 0 ? `${cooldown} 秒後可重新傳送` : '重新傳送' }}
        </el-button>
      </div>
      <p class="hint">驗證碼 5 分鐘內有效；輸入錯誤 5 次後需重新取得。</p>
    </template>

    <div v-if="inboxEnabled" class="dev">
      <div class="dev-title">開發用：模擬簡訊收件匣（本機不會真的發簡訊）</div>
      <el-button size="small" :disabled="!phoneValid" @click="loadInbox">查看最近的簡訊</el-button>
      <div v-for="(body, i) in inbox" :key="i" class="msg">{{ body }}</div>
    </div>
  </el-card>
</template>

<style scoped>
.panel {
  margin-top: 8px;
}
.title {
  font-weight: 600;
  margin-bottom: 6px;
}
.hint {
  color: #909399;
  font-size: 13px;
  margin: 6px 0;
}
.alert {
  margin: 8px 0;
}
.row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}
.code {
  margin-top: 8px;
  max-width: 220px;
}
.dev {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px dashed #dcdfe6;
}
.dev-title {
  color: #909399;
  font-size: 12px;
  margin-bottom: 8px;
}
.msg {
  margin-top: 6px;
  padding: 6px 8px;
  background: #f5f7fa;
  border-radius: 4px;
  font-size: 13px;
}
</style>
