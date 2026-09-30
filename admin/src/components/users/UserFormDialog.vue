<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage } from 'element-plus'
import { computed, reactive, ref, watch } from 'vue'
import { errorMessage, http } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { ROLE_LABELS } from '@/types/api'
import type { Store, User, UserRole } from '@/types/api'

/** user 為 null 代表新增帳號。 */
const props = defineProps<{ modelValue: boolean; user: User | null; stores: Store[] }>()
const emit = defineEmits<{ 'update:modelValue': [visible: boolean]; saved: [] }>()

const auth = useAuthStore()
const formRef = ref<FormInstance>()
const saving = ref(false)

const form = reactive({
  name: '',
  email: '',
  password: '',
  role: 'staff' as UserRole,
  store_id: null as number | null,
})

const isEdit = computed(() => props.user !== null)
const isSelf = computed(() => props.user !== null && props.user.id === auth.user?.id)

// 店長只能建立店員；角色選項只是體驗，後端 UserPolicy 才是真正的把關
const roleOptions = computed<UserRole[]>(() => (auth.isHq ? ['hq_admin', 'store_manager', 'staff'] : ['staff']))
const showStore = computed(() => form.role !== 'hq_admin')
// 店長的門市固定為自己的門市
const storeLocked = computed(() => !auth.isHq)

watch(
  () => [props.modelValue, props.user] as const,
  ([visible]) => {
    if (!visible) {
      return
    }
    formRef.value?.clearValidate()
    if (props.user) {
      Object.assign(form, {
        name: props.user.name,
        email: props.user.email,
        password: '',
        role: props.user.role,
        store_id: props.user.store_id,
      })
    } else {
      Object.assign(form, {
        name: '',
        email: '',
        password: '',
        role: 'staff',
        store_id: auth.isHq ? null : (auth.user?.store_id ?? null),
      })
    }
  },
  { immediate: true },
)

// 切成總部管理員時清掉門市（總部不隸屬門市）
watch(
  () => form.role,
  (role) => {
    if (role === 'hq_admin') {
      form.store_id = null
    }
  },
)

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: '請輸入姓名', trigger: 'blur' }],
  email: [
    { required: true, message: '請輸入 Email', trigger: 'blur' },
    { type: 'email', message: 'Email 格式不正確', trigger: 'blur' },
  ],
  password: isEdit.value
    ? [{ min: 8, message: '密碼至少 8 個字元', trigger: 'blur' }]
    : [
        { required: true, message: '請輸入密碼', trigger: 'blur' },
        { min: 8, message: '密碼至少 8 個字元', trigger: 'blur' },
      ],
  store_id: [
    {
      validator: (_rule, value, callback) =>
        showStore.value && !value ? callback(new Error('請選擇門市')) : callback(),
      trigger: 'change',
    },
  ],
}))

async function save(): Promise<void> {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }

  saving.value = true
  try {
    const payload: Record<string, unknown> = { name: form.name, email: form.email }
    if (form.password) {
      payload.password = form.password
    }
    // 編輯自己時不送角色／門市：改自己的角色會被後端拒絕（防止把自己鎖死）
    if (!isSelf.value) {
      payload.role = form.role
      payload.store_id = form.role === 'hq_admin' ? null : form.store_id
    }

    if (props.user) {
      await http.patch(`/admin/users/${props.user.id}`, payload)
    } else {
      await http.post('/admin/users', payload)
    }
    ElMessage.success('已儲存')
    emit('update:modelValue', false)
    emit('saved')
  } catch (error) {
    ElMessage.error(errorMessage(error, '儲存失敗'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="isEdit ? '編輯帳號' : '新增帳號'"
    width="460px"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="80px" @submit.prevent="save">
      <el-form-item label="姓名" prop="name">
        <el-input v-model="form.name" maxlength="50" />
      </el-form-item>
      <el-form-item label="Email" prop="email">
        <el-input v-model="form.email" autocomplete="off" />
      </el-form-item>
      <el-form-item label="密碼" prop="password">
        <el-input
          v-model="form.password"
          type="password"
          show-password
          autocomplete="new-password"
          :placeholder="isEdit ? '不修改請留空' : '至少 8 個字元'"
        />
      </el-form-item>
      <el-form-item label="角色">
        <el-select v-model="form.role" :disabled="isSelf || roleOptions.length === 1" style="width: 100%">
          <el-option v-for="r in roleOptions" :key="r" :value="r" :label="ROLE_LABELS[r]" />
        </el-select>
      </el-form-item>
      <el-form-item v-if="showStore" label="門市" prop="store_id">
        <el-select v-model="form.store_id" :disabled="isSelf || storeLocked" placeholder="請選擇門市" style="width: 100%">
          <el-option v-for="s in stores" :key="s.id" :value="s.id" :label="s.name" />
        </el-select>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="emit('update:modelValue', false)">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">儲存</el-button>
    </template>
  </el-dialog>
</template>
