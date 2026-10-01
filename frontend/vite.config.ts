import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Windows 的 bind mount 不會傳遞檔案變更事件（inotify），Vite 收不到通知就會一直用舊的快取，
    // 所以改成輪詢。代價是多一點 CPU，僅開發環境使用。
    watch: { usePolling: true, interval: 300 },
    hmr: {
      // nginx proxies the HMR WebSocket through /__vite_hmr
      clientPort: 80,
      path: '/__vite_hmr',
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
})
