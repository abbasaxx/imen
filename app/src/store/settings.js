// app/src/store/settings.js
import { defineStore } from 'pinia'
import { getDir } from '../lib/i18n.js'

const CHAT_TEXT_SCALE_MIN = 1.2
const CHAT_TEXT_SCALE_MAX = 1.6

function clampTextScale(value) {
  const n = Number.parseFloat(String(value))
  if (!Number.isFinite(n)) return CHAT_TEXT_SCALE_MIN
  return Math.min(CHAT_TEXT_SCALE_MAX, Math.max(CHAT_TEXT_SCALE_MIN, Number(n.toFixed(2))))
}

export const useSettingsStore = defineStore('settings', {
  state: () => ({
    locale:         localStorage.getItem('locale')         ?? 'fa',
    theme:          localStorage.getItem('theme')          ?? 'light',
    mediaCacheDays: parseInt(localStorage.getItem('mediaCacheDays') ?? '30', 10),
    chatTextScale:  clampTextScale(localStorage.getItem('chatTextScale') ?? CHAT_TEXT_SCALE_MIN),
  }),

  actions: {
    setLocale(locale) {
      this.locale = locale
      localStorage.setItem('locale', locale)
      document.documentElement.dir  = getDir(locale)
      document.documentElement.lang = locale
    },

    setTheme(theme) {
      this.theme = theme
      localStorage.setItem('theme', theme)
      document.documentElement.classList.toggle('dark', theme === 'dark')
    },

    setMediaCacheDays(days) {
      this.mediaCacheDays = days
      localStorage.setItem('mediaCacheDays', String(days))
    },

    setChatTextScale(scale) {
      const value = clampTextScale(scale)
      this.chatTextScale = value
      localStorage.setItem('chatTextScale', String(value))
      document.documentElement.style.setProperty('--chat-text-scale', String(value))
    },

    /** Call once on app startup to apply saved preferences. */
    init() {
      this.setLocale(this.locale)
      this.setTheme(this.theme)
      this.setChatTextScale(this.chatTextScale)
    },
  },
})
