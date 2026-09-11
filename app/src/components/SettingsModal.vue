<!-- app/src/components/SettingsModal.vue -->
<template>
  <div
    class="safe-area-top safe-area-bottom safe-area-x fixed inset-0 z-40 bg-black/50 flex items-center justify-center p-4"
    @click.self="$emit('close')"
  >
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-sm p-6 max-h-[90dvh] overflow-y-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $t('settings') }}</h2>
        <button
          @click="$emit('close')"
          class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- ── Profile ──────────────────────────────────────────────── -->
      <div class="mb-5">
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-2">{{ $t('profile') }}</label>

        <!-- Email (read-only) -->
        <p class="text-xs text-gray-400 mb-1">{{ $t('emailAddress') }}</p>
        <div class="w-full bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-xl px-4 py-2.5 text-sm mb-3 select-all" dir="ltr">
          {{ auth.user?.email ?? '—' }}
        </div>

        <!-- Name (editable) -->
        <p class="text-xs text-gray-400 mb-1">{{ $t('displayName') }}</p>
        <input
          v-model="profileName"
          type="text"
          :placeholder="auth.user?.name ?? ''"
          class="w-full bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-xl px-4 py-2.5 text-sm mb-2 outline-none focus:ring-2 focus:ring-blue-500"
        />
        <button
          @click="saveName"
          :disabled="savingName || !profileName.trim()"
          class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-xl py-2.5 text-sm font-semibold transition-colors"
        >
          {{ savingName ? '...' : $t('updateName') }}
        </button>
        <p v-if="nameMsg" :class="['text-center text-xs mt-1.5', nameMsgOk ? 'text-green-500' : 'text-red-500']">
          {{ nameMsg }}
        </p>
      </div>

      <!-- ── Language ───────────────────────────────────────────── -->
      <div class="mb-5">
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-2">{{ $t('language') }}</label>
        <div class="flex gap-2">
          <button
            v-for="loc in LOCALES"
            :key="loc"
            @click="settings.setLocale(loc)"
            class="px-4 py-2 rounded-xl border text-sm font-medium transition-colors"
            :class="settings.locale === loc
              ? 'bg-blue-600 text-white border-blue-600'
              : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:border-blue-400'"
          >
            {{ loc === 'fa' ? 'فارسی' : 'English' }}
          </button>
        </div>
      </div>

      <!-- ── Theme ─────────────────────────────────────────────── -->
      <div class="mb-5">
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-2">{{ $t('theme') }}</label>
        <div class="flex gap-2">
          <button
            @click="settings.setTheme('light')"
            class="flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-xl border text-sm font-medium transition-colors"
            :class="settings.theme === 'light'
              ? 'bg-blue-600 text-white border-blue-600'
              : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:border-blue-400'"
          >
            ☀️ {{ $t('light') }}
          </button>
          <button
            @click="settings.setTheme('dark')"
            class="flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-xl border text-sm font-medium transition-colors"
            :class="settings.theme === 'dark'
              ? 'bg-blue-600 text-white border-blue-600'
              : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:border-blue-400'"
          >
            🌙 {{ $t('dark') }}
          </button>
        </div>
      </div>

      <!-- ── Chat text size ─────────────────────────────────────── -->
      <div class="mb-5">
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-2">{{ $t('chatTextSize') }}</label>
        <div class="flex items-center gap-3">
          <input
            v-model.number="textScale"
            type="range"
            :min="MIN_TEXT_SCALE"
            :max="MAX_TEXT_SCALE"
            step="0.05"
            class="flex-1 accent-blue-500"
          />
          <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 w-14 text-center tabular-nums">
            {{ textScalePct }}%
          </span>
        </div>
        <p class="text-xs text-gray-400 mt-1">{{ $t('chatTextSizeHint') }}</p>
      </div>

      <!-- ── Media cache ────────────────────────────────────────── -->
      <div class="mb-5">
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-2">{{ $t('mediaCacheDays') }}</label>
        <div class="flex items-center gap-3">
          <input
            v-model.number="days"
            type="range"
            min="1"
            max="90"
            class="flex-1 accent-blue-500"
          />
          <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 w-10 text-center tabular-nums">
            {{ days }}
          </span>
        </div>
        <p class="text-xs text-gray-400 mt-1">{{ $t('mediaCacheDaysHint') }}</p>
      </div>

      <!-- Save settings -->
      <button
        @click="save"
        :disabled="saving"
        class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white rounded-xl py-2.5 text-sm font-semibold transition-colors mb-1"
      >
        {{ saving ? '...' : $t('save') }}
      </button>
      <p v-if="saved"      class="text-center text-xs text-green-500 mb-4">{{ $t('saved') }}</p>
      <p v-if="saveError"  class="text-center text-xs text-red-500 mb-4">{{ saveError }}</p>

      <div v-if="!saved && !saveError" class="mb-4" />

      <!-- ── Logout ────────────────────────────────────────────── -->
      <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mb-5">
        <button
          @click="emit('logout')"
          class="w-full flex items-center justify-center gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-600 dark:text-red-400 rounded-xl py-2.5 text-sm font-medium hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
          </svg>
          {{ $t('logout') }}
        </button>
      </div>

      <!-- ── Private key ────────────────────────────────────────── -->
      <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-3">{{ $t('privateKey') }}</label>

        <!-- Export -->
        <button
          @click="exportKey"
          :disabled="!auth.hasPrivateKey"
          class="w-full mb-2 flex items-center justify-center gap-2 border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 rounded-xl py-2.5 text-sm hover:border-blue-400 disabled:opacity-40 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
          {{ $t('exportKey') }}
        </button>

        <!-- iOS PEM fallback textarea -->
        <div v-if="exportedPEM" class="mb-2">
          <textarea
            readonly
            :value="exportedPEM"
            rows="4"
            class="w-full font-mono text-[10px] bg-gray-100 dark:bg-gray-700 rounded-xl px-3 py-2 text-gray-700 dark:text-gray-200 resize-none outline-none mb-2"
            @click="$event.target.select()"
          />
          <button
            @click="copyExportedPEM"
            class="w-full border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 rounded-xl py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
          >
            📋 {{ $t('copyKey') }}
          </button>
        </div>

        <!-- Import -->
        <button
          @click="fileInputEl.click()"
          class="w-full flex items-center justify-center gap-2 border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 rounded-xl py-2.5 text-sm hover:border-blue-400 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4-4m0 0l4 4m-4-4v12"/>
          </svg>
          {{ $t('importKey') }}
        </button>
        <input ref="fileInputEl" type="file" accept=".pem" class="hidden" @change="importKey" />

        <p v-if="keyMsg" :class="['text-center text-xs mt-2', keyMsgOk ? 'text-green-500' : 'text-red-500']">
          {{ keyMsg }}
        </p>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useSettingsStore } from '@/store/settings'
import { useAuthStore }     from '@/store/auth'
import { t }               from '@/lib/i18n'
import { LOCALES }         from '@/lib/i18n'
import { api }             from '@/lib/api'
import { evictExpired }    from '@/lib/mediaCache'

const emit = defineEmits(['close', 'logout'])

const settings = useSettingsStore()
const auth     = useAuthStore()
const $t = (key) => t(key, settings.locale)

// ── Profile ───────────────────────────────────────────────────────────────
const profileName = ref('')
const savingName  = ref(false)
const nameMsg     = ref('')
const nameMsgOk   = ref(true)

async function saveName() {
  const name = profileName.value.trim()
  if (!name) return
  savingName.value = true
  nameMsg.value    = ''
  try {
    const data = await api.patch('/profile', { name })
    auth.setUser({ ...auth.user, name: data.user.name })
    profileName.value = ''
    nameMsg.value     = $t('nameUpdated')
    nameMsgOk.value   = true
    setTimeout(() => { nameMsg.value = '' }, 2500)
  } catch (e) {
    nameMsg.value   = e.message ?? 'Failed'
    nameMsgOk.value = false
  } finally {
    savingName.value = false
  }
}

// ── Media cache ───────────────────────────────────────────────────────────
const days      = ref(settings.mediaCacheDays)
const MIN_TEXT_SCALE = 1.2
const MAX_TEXT_SCALE = 1.6
const textScale = ref(settings.chatTextScale)
const textScalePct = computed(() => Math.round(textScale.value * 100))
const saving    = ref(false)
const saved     = ref(false)
const saveError = ref('')

onMounted(async () => {
  try {
    const data = await api.get('/settings')
    days.value = data.media_cache_days
  } catch { /* use localStorage value */ }
})

async function save() {
  saving.value    = true
  saved.value     = false
  saveError.value = ''
  try {
    settings.setChatTextScale(textScale.value)
    await api.put('/settings', { media_cache_days: days.value })
    settings.setMediaCacheDays(days.value)
    await evictExpired(days.value)
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch (e) {
    saveError.value = e.message ?? 'Failed to save'
  } finally {
    saving.value = false
  }
}

// ── Private key ───────────────────────────────────────────────────────────
const fileInputEl = ref(null)
const exportedPEM = ref('')
const keyMsg      = ref('')
const keyMsgOk    = ref(true)

function exportKey() {
  exportedPEM.value = ''
  keyMsg.value      = ''
  const pem = localStorage.getItem('privatePEM')
  if (!pem) { keyMsg.value = $t('noPrivateKey'); keyMsgOk.value = false; return }

  // Try standard download; fall back to in-page textarea on iOS
  try {
    const blob = new Blob([pem], { type: 'application/x-pem-file' })
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href     = url
    a.download = 'securechat-private-key.pem'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    const isIOS = /iP(hone|ad|od)/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    if (isIOS) exportedPEM.value = pem
  } catch {
    exportedPEM.value = pem
  }
}

async function copyExportedPEM() {
  try { await navigator.clipboard.writeText(exportedPEM.value) } catch { /* manual select */ }
}

async function importKey(e) {
  const file = e.target.files[0]
  if (!file) return
  const pem = await file.text()
  try {
    await auth.setPrivateKeyFromPEM(pem)
    keyMsg.value   = $t('keyImported')
    keyMsgOk.value = true
  } catch {
    keyMsg.value   = $t('invalidKey')
    keyMsgOk.value = false
  }
  e.target.value = ''
  setTimeout(() => { keyMsg.value = '' }, 3000)
}
</script>
