<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const formRef = ref<FormInstance>()
const form = reactive({ email: '', password: '' })
const rules: FormRules = {
  email: [
    { required: true, message: '請輸入 Email', trigger: 'blur' },
    { type: 'email', message: 'Email 格式不正確', trigger: 'blur' },
  ],
  password: [{ required: true, message: '請輸入密碼', trigger: 'blur' }],
}

const loading = ref(false)
const errorText = ref('')

/** 只接受站內相對路徑，避免 ?redirect=https://evil.example 造成開放式重導向。 */
function safeRedirect(): string {
  const target = route.query.redirect
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : '/'
}

async function submit(): Promise<void> {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }

  loading.value = true
  errorText.value = ''
  try {
    await auth.login(form.email, form.password)
    await router.replace(safeRedirect())
  } catch (error) {
    errorText.value = errorMessage(error, '登入失敗')
    form.password = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <el-card class="login-card" shadow="hover">
      <template #header>
        <h2 class="title">門市管理後台</h2>
      </template>

      <el-alert v-if="errorText" :title="errorText" type="error" show-icon :closable="false" class="alert" />

      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="submit">
        <el-form-item label="Email" prop="email">
          <el-input v-model="form.email" autocomplete="username" placeholder="name@example.com" />
        </el-form-item>
        <el-form-item label="密碼" prop="password">
          <el-input v-model="form.password" type="password" show-password autocomplete="current-password" />
        </el-form-item>
        <el-button type="primary" native-type="submit" :loading="loading" class="submit">登入</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  box-sizing: border-box;
}
.login-card {
  width: 100%;
  max-width: 380px;
}
.title {
  margin: 0;
  text-align: center;
}
.alert {
  margin-bottom: 16px;
}
.submit {
  width: 100%;
}
</style>
