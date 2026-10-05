<script setup lang="ts">
defineProps<{ active: 0 | 1 | 2 | 3 | 4 | 5 }>()
</script>

<template>
  <el-steps :active="active" finish-status="success" align-center class="steps">
    <el-step title="門市" />
    <el-step title="日期人數" />
    <el-step title="時段" />
    <el-step title="選餐" />
    <el-step title="資料" />
    <el-step title="確認" />
  </el-steps>
</template>

<style scoped>
.steps {
  margin-bottom: 24px;
}
</style>
