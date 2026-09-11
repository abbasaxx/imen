<!-- app/src/components/LangThemeToggle.vue -->
<template>
  <div class="flex items-center gap-2">
    <button
      v-for="loc in LOCALES"
      :key="loc"
      @click="settings.setLocale(loc)"
      class="text-xs px-2 py-1 rounded-xl border transition-colors"
      :class="settings.locale === loc
        ? 'bg-blue-600 text-white border-blue-600'
        : 'text-gray-500 dark:text-gray-400 border-gray-200 dark:border-gray-600 hover:border-blue-400'"
    >
      {{ loc.toUpperCase() }}
    </button>

    <button
      @click="toggleTheme"
      class="w-8 h-8 rounded-full border border-gray-200 dark:border-gray-600 flex items-center justify-center text-base hover:border-blue-400 transition-colors"
    >
      {{ settings.theme === 'dark' ? '☀️' : '🌙' }}
    </button>
  </div>
</template>

<script setup>
import { useSettingsStore } from '../store/settings.js'
import { LOCALES } from '../lib/i18n.js'

const settings = useSettingsStore()

function toggleTheme() {
  settings.setTheme(settings.theme === 'dark' ? 'light' : 'dark')
}
</script>
