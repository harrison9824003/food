<template>
  <div class="forbidden">
    <el-result icon="warning" title="沒有權限" sub-title="你的角色無法使用此功能">
      <template #extra>
        <el-button type="primary" @click="$router.replace({ name: 'dashboard' })">回總覽</el-button>
      </template>
    </el-result>
  </div>
</template>

<style scoped>
.forbidden {
  padding-top: 10vh;
}
</style>
