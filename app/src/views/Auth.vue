<!-- app/src/views/Auth.vue -->
<template>
  <div
    class="app-viewport safe-area-top safe-area-bottom safe-area-x bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4"
    :dir="dir"
  >
    <!-- Language / Theme controls -->
    <div class="fixed end-4" style="top: calc(env(safe-area-inset-top, 0px) + 1rem)">
      <LangThemeToggle />
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg w-full max-w-sm p-8">
      <!-- Header -->
      <div class="text-center mb-6">
        <div class="text-4xl mb-2">🔒</div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">
          {{ t('appName', locale) }}
        </h1>
      </div>

      <form @submit.prevent="handleSubmit" novalidate>
        <!-- Email -->
        <label class="block text-xs text-gray-500 uppercase tracking-wide mb-1">
          {{ t('email', locale) }}
        </label>
        <input
          v-model="email"
          type="email"
          required
          autocomplete="email"
          dir="ltr"
          :disabled="step !== 'email'"
          class="w-full bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-xl px-4 py-2.5 text-sm mb-3 outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60"
        />

        <!-- Name (register only) -->
        <template v-if="step === 'register'">
          <label class="block text-xs text-gray-500 uppercase tracking-wide mb-1">
            {{ t('name', locale) }}
          </label>
          <input
            v-model="name"
            type="text"
            required
            autocomplete="name"
            class="w-full bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-xl px-4 py-2.5 text-sm mb-3 outline-none focus:ring-2 focus:ring-blue-500"
          />
        </template>

        <!-- Password (login + register) -->
        <template v-if="step !== 'email'">
          <label class="block text-xs text-gray-500 uppercase tracking-wide mb-1">
            {{ t('password', locale) }}
          </label>
          <input
            v-model="password"
            type="password"
            required
            minlength="6"
            autocomplete="current-password"
            class="w-full bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-xl px-4 py-2.5 text-sm mb-3 outline-none focus:ring-2 focus:ring-blue-500"
          />
        </template>

        <!-- Error -->
        <p v-if="error" class="text-red-500 text-xs mb-3">{{ error }}</p>

        <!-- Primary action -->
        <button
          type="submit"
          :disabled="loading"
          class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white rounded-xl py-2.5 text-sm font-semibold mb-3 transition-colors"
        >
          {{ loading ? '...' : buttonLabel }}
        </button>

        <!-- Import key (login step only) -->
        <button
          v-if="step === 'login'"
          type="button"
          @click="triggerImport"
          class="w-full border border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400 rounded-xl py-2.5 text-sm hover:border-blue-400 transition-colors"
        >
          📥 {{ t('importKey', locale) }}
        </button>
        <input
          ref="fileInput"
          type="file"
          accept=".pem"
          class="hidden"
          @change="handleImport"
        />
      </form>

    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore }     from '../store/auth.js'
import { useSettingsStore } from '../store/settings.js'
import { t, getDir }        from '../lib/i18n.js'
import { api }              from '../lib/api.js'
import {
  generateKeyPair,
  exportPublicKeyPEM,
  exportPrivateKeyPEM,
} from '../lib/crypto.js'
import LangThemeToggle from '../components/LangThemeToggle.vue'

const auth     = useAuthStore()
const settings = useSettingsStore()
const router   = useRouter()

const locale = computed(() => settings.locale)
const dir    = computed(() => getDir(settings.locale))

const email       = ref('')
const name        = ref('')
const password    = ref('')
const step        = ref('email') // 'email' | 'login' | 'register'
const loading     = ref(false)
const error       = ref('')
const fileInput   = ref(null)

const buttonLabel = computed(() => {
  if (step.value === 'email')    return t('continue',  locale.value)
  if (step.value === 'login')    return t('login',     locale.value)
  return                                t('register',  locale.value)
})

async function handleSubmit() {
  error.value   = ''
  loading.value = true
  try {
    if (step.value === 'email') {
      const { exists } = await api.post('/auth/check', { email: email.value })
      step.value = exists ? 'login' : 'register'
      return
    }

    if (step.value === 'login') {
      const data = await api.post('/auth/login', {
        email:    email.value,
        password: password.value,
      })
      auth.setToken(data.token)
      auth.setUser(data.user)
      await auth.restorePrivateKey()
      router.push('/')
      return
    }

    // Register: generate key pair, register on server, navigate to app.
    const pair          = await generateKeyPair()
    const publicKeyPEM  = await exportPublicKeyPEM(pair.publicKey)
    const privateKeyPEM = await exportPrivateKeyPEM(pair.privateKey)

    const data = await api.post('/auth/register', {
      email:      email.value,
      name:       name.value,
      password:   password.value,
      public_key: publicKeyPEM,
    })
    auth.setToken(data.token)
    auth.setUser(data.user)
    await auth.setPrivateKeyFromPEM(privateKeyPEM)

    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function triggerImport() {
  fileInput.value.click()
}

async function handleImport(e) {
  const file = e.target.files[0]
  if (!file) return
  const pem = await file.text()
  try {
    await auth.setPrivateKeyFromPEM(pem)
    error.value = ''
  } catch {
    error.value = 'Invalid key file'
  }
  e.target.value = ''
}
</script>
