<template>
  <div class="shell">
    <header class="top">
      <RouterLink to="/" class="brand">線上訂位</RouterLink>
      <nav>
        <RouterLink to="/lookup">我的訂位</RouterLink>
      </nav>
    </header>
    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
}
.top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  height: 56px;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
}
.brand {
  font-weight: 600;
  font-size: 18px;
  color: #303133;
  text-decoration: none;
}
nav a {
  color: #409eff;
  text-decoration: none;
}
.content {
  max-width: 720px;
  margin: 0 auto;
  padding: 24px 16px 48px;
}
</style>
