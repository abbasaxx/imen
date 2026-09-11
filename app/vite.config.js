import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  base: './', 
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
  },
  server: {
    proxy: {
      '/api': {
        target: 'https://imen.chat',
        changeOrigin: true,
        secure: true,
      },
    },
  },
  build: {
    outDir: 'dist',
  },
})
