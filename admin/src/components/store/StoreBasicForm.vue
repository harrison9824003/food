<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage } from 'element-plus'
import { reactive, ref, watch } from 'vue'
import { errorMessage, http } from '@/api/http'
import type { Store } from '@/types/api'

/**
 * 門市基本資料與開放規則。store 為 null 代表新增門市。
 * canEdit：能否修改地址、電話、開放規則（總部或該店店長）
 * canEditCore：能否修改名稱、訂金、啟用狀態（僅總部）
 */
const props = defineProps<{ store: Store | null; canEdit: boolean; canEditCore: boolean }>()
const emit = defineEmits<{ saved: [store: Store] }>()

const formRef = ref<FormInstance>()
const saving = ref(false)

function defaults() {
  return {
    name: '',
    address: '',
    phone: '',
    is_active: true,
    advance_days: 30,
    cutoff_minutes: 60,
    slot_capacity_default: 20,
    deposit_per_person: 0,
  }
}

const form = reactive(defaults())

watch(
  () => props.store,
  (store) => {
    Object.assign(form, store ? { ...store, address: store.address ?? '', phone: store.phone ?? '' } : defaults())
  },
  { immediate: true },
)

const rules: FormRules = {
  name: [{ required: true, message: '請輸入門市名稱', trigger: 'blur' }],
}

async function save(): Promise<void> {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }

  saving.value = true
  try {
    const rulesPart = {
      address: form.address || null,
      phone: form.phone || null,
      advance_days: form.advance_days,
      cutoff_minutes: form.cutoff_minutes,
      slot_capacity_default: form.slot_capacity_default,
    }
    // 非總部不送名稱／訂金／啟用狀態，否則後端會明確回 403
    const corePart = props.canEditCore
      ? { name: form.name, deposit_per_person: form.deposit_per_person, is_active: form.is_active }
      : {}

    const { data } = props.store
      ? await http.patch<Store>(`/admin/stores/${props.store.id}`, { ...rulesPart, ...corePart })
      : await http.post<Store>('/admin/stores', { ...rulesPart, ...corePart })

    ElMessage.success(props.store ? '已儲存' : '已新增門市')
    emit('saved', data)
  } catch (error) {
    ElMessage.error(errorMessage(error, '儲存失敗'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-form ref="formRef" :model="form" :rules="rules" label-width="150px" :disabled="!canEdit" @submit.prevent="save">
    <el-form-item label="門市名稱" prop="name">
      <el-input v-model="form.name" maxlength="100" :disabled="!canEditCore" />
    </el-form-item>
    <el-form-item label="地址">
      <el-input v-model="form.address" maxlength="255" />
    </el-form-item>
    <el-form-item label="電話">
      <el-input v-model="form.phone" maxlength="20" />
    </el-form-item>
    <el-form-item label="每人訂金（元）">
      <el-input-number v-model="form.deposit_per_person" :min="0" :max="100000" :disabled="!canEditCore" />
      <span class="hint">0 代表免訂金</span>
    </el-form-item>
    <el-form-item label="提前開放天數">
      <el-input-number v-model="form.advance_days" :min="1" :max="365" />
      <span class="hint">今天起最多可訂幾天後</span>
    </el-form-item>
    <el-form-item label="訂位截止（分鐘）">
      <el-input-number v-model="form.cutoff_minutes" :min="0" :max="1440" />
      <span class="hint">距用餐時間前幾分鐘停止受理</span>
    </el-form-item>
    <el-form-item label="每時段預設名額">
      <el-input-number v-model="form.slot_capacity_default" :min="1" :max="1000" />
      <span class="hint">只影響之後新產生的時段</span>
    </el-form-item>
    <el-form-item v-if="store" label="啟用中">
      <el-switch v-model="form.is_active" :disabled="!canEditCore" />
      <span class="hint">停用後不再產生時段</span>
    </el-form-item>
    <el-form-item v-if="canEdit">
      <el-button type="primary" native-type="submit" :loading="saving">{{ store ? '儲存' : '新增' }}</el-button>
    </el-form-item>
  </el-form>
</template>

<style scoped>
.hint {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
