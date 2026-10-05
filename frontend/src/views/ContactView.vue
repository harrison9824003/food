<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import BookingSteps from '@/components/BookingSteps.vue'
import { useBookingStore } from '@/stores/booking'

const booking = useBookingStore()
const router = useRouter()
const formRef = ref<FormInstance>()

// 表單直接綁在 store 上：按上一步再回來、或重新整理，填過的資料都還在
const rules: FormRules = {
  customerName: [{ required: true, message: '請輸入姓名', trigger: 'blur' }],
  phone: [
    { required: true, message: '請輸入手機號碼', trigger: 'blur' },
    { pattern: /^09\d{8}$/, message: '請輸入正確的手機號碼（09 開頭共 10 碼）', trigger: 'blur' },
  ],
  email: [{ type: 'email', message: 'Email 格式不正確', trigger: 'blur' }],
}

async function next(): Promise<void> {
  if (await formRef.value?.validate().catch(() => false)) {
    void router.push({ name: 'confirm' })
  }
}
</script>

<template>
  <BookingSteps :active="4" />

  <h2>聯絡資料</h2>

  <el-form ref="formRef" :model="booking" :rules="rules" label-position="top" @submit.prevent="next">
    <el-form-item label="姓名" prop="customerName">
      <el-input v-model="booking.customerName" maxlength="50" autocomplete="name" />
    </el-form-item>
    <el-form-item label="手機號碼" prop="phone">
      <el-input v-model="booking.phone" maxlength="10" inputmode="numeric" autocomplete="tel" placeholder="09xxxxxxxx" />
    </el-form-item>
    <el-form-item label="Email（選填）" prop="email">
      <el-input v-model="booking.email" maxlength="255" autocomplete="email" />
    </el-form-item>
    <el-form-item label="備註（選填）">
      <el-input v-model="booking.note" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="例如：需要兒童座椅、慶生" />
    </el-form-item>
  </el-form>

  <div class="actions">
    <el-button @click="router.push({ name: 'menu' })">上一步</el-button>
    <el-button type="primary" @click="next">下一步</el-button>
  </div>
</template>

<style scoped>
.actions {
  display: flex;
  justify-content: space-between;
  margin-top: 24px;
}
</style>
