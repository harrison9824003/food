<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage, ElMessageBox } from 'element-plus'
import { reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import PayPanel from '@/components/PayPanel.vue'
import ReservationCard from '@/components/ReservationCard.vue'
import { useBookingStore } from '@/stores/booking'
import type { Reservation } from '@/types/api'

const booking = useBookingStore()

const formRef = ref<FormInstance>()
// 剛訂完位的人來查詢時，帶入剛才的代號與手機省得再打一次
const form = reactive({ code: booking.result?.code ?? '', phone: booking.phone })
const rules: FormRules = {
  code: [
    { required: true, message: '請輸入訂位代號', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9]{8}$/, message: '訂位代號為 8 碼英數字', trigger: 'blur' },
  ],
  phone: [
    { required: true, message: '請輸入手機號碼', trigger: 'blur' },
    { pattern: /^09\d{8}$/, message: '請輸入正確的手機號碼（09 開頭共 10 碼）', trigger: 'blur' },
  ],
}

const reservation = ref<Reservation | null>(null)
const searching = ref(false)
const cancelling = ref(false)
const errorText = ref('')

async function search(): Promise<void> {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }

  searching.value = true
  errorText.value = ''
  reservation.value = null
  try {
    const { data } = await http.post<Reservation>('/reservations/lookup', { code: form.code.trim(), phone: form.phone })
    reservation.value = data
  } catch (error) {
    errorText.value = errorMessage(error, '查詢失敗')
  } finally {
    searching.value = false
  }
}

async function cancel(): Promise<void> {
  try {
    await ElMessageBox.confirm('確定要取消這筆訂位嗎？取消後座位會釋出給其他人。', '取消訂位', {
      type: 'warning',
      confirmButtonText: '確定取消',
      cancelButtonText: '返回',
    })
  } catch {
    return
  }

  cancelling.value = true
  try {
    const { data } = await http.post<Reservation>('/reservations/cancel', { code: form.code.trim(), phone: form.phone })
    reservation.value = data
    // 剛訂完的那筆被取消了，結果頁就不必再顯示
    if (booking.result?.code === data.code) {
      booking.result = data
    }
    ElMessage.success('已取消訂位')
  } catch (error) {
    // 已付訂金等情況後端會說明原因（需走退款流程），直接顯示給使用者
    ElMessage.error(errorMessage(error, '取消失敗'))
  } finally {
    cancelling.value = false
  }
}

const cancellable = (r: Reservation): boolean => r.status === 'confirmed' || r.status === 'pending_payment'
</script>

<template>
  <h2>我的訂位</h2>

  <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="search">
    <el-form-item label="訂位代號" prop="code">
      <el-input v-model="form.code" maxlength="8" placeholder="訂位完成時取得的 8 碼代號" />
    </el-form-item>
    <el-form-item label="手機號碼" prop="phone">
      <el-input v-model="form.phone" maxlength="10" inputmode="numeric" placeholder="訂位時填寫的手機號碼" />
    </el-form-item>
    <el-button type="primary" native-type="submit" :loading="searching">查詢</el-button>
  </el-form>

  <el-alert v-if="errorText" :title="errorText" type="error" show-icon :closable="false" class="block" />

  <div v-if="reservation" class="block">
    <ReservationCard :reservation="reservation" />
    <PayPanel
      v-if="reservation.can_pay"
      :key="`${reservation.code}-${reservation.hold_expires_at}`"
      :reservation="reservation"
      :phone="form.phone"
      @refresh="search"
      @expired="search"
    />
    <div v-if="cancellable(reservation)" class="actions">
      <el-button type="danger" plain :loading="cancelling" @click="cancel">取消訂位</el-button>
    </div>
  </div>
</template>

<style scoped>
.block {
  margin-top: 24px;
}
.actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
