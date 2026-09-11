// app/src/main.js
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router/index.js'
import App from './App.vue'
import './style.css'

const isIOS =
  /iP(hone|ad|od)/.test(navigator.userAgent) ||
  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

const KEYBOARD_GAP_PX = 120
const STALE_GAP_PX = 80
let stableViewportHeight = Math.round(window.innerHeight)
let recoveryRafId = 0
let recoveryUntil = 0

function isEditableTarget(target) {
  return (
    target instanceof HTMLInputElement ||
    target instanceof HTMLTextAreaElement ||
    target?.isContentEditable === true
  )
}

function hasEditableFocus() {
  return isEditableTarget(document.activeElement)
}

function nowMs() {
  return typeof performance !== 'undefined' ? performance.now() : Date.now()
}

function startRecoverySync(durationMs = 1800) {
  recoveryUntil = nowMs() + durationMs
  if (recoveryRafId) return

  const tick = () => {
    syncViewportHeight()
    if (nowMs() < recoveryUntil) {
      recoveryRafId = window.requestAnimationFrame(tick)
    } else {
      window.cancelAnimationFrame(recoveryRafId)
      recoveryRafId = 0
    }
  }
  recoveryRafId = window.requestAnimationFrame(tick)
}

function syncViewportHeight() {
  const inner = Math.round(window.innerHeight)
  const vv    = Math.round(window.visualViewport?.height ?? inner)
  const focus = hasEditableFocus()
  const keyboardOpen = isIOS && focus && (inner - vv > KEYBOARD_GAP_PX)

  let height = vv

  if (isIOS) {
    if (focus) {
      // Track the largest non-broken viewport seen in this session.
      stableViewportHeight = Math.max(stableViewportHeight, inner, vv)
      height = vv
    } else {
      if (Math.abs(inner - vv) <= 40) {
        stableViewportHeight = Math.max(inner, vv)
      }

      // If Safari reports stale reduced viewport after keyboard closes,
      // recover to the last stable full height.
      if (inner - vv > STALE_GAP_PX) {
        height = Math.max(stableViewportHeight, inner, vv)
      } else {
        height = Math.max(inner, vv)
      }
    }
  }

  document.documentElement.style.setProperty('--app-vh', `${height}px`)
  document.documentElement.classList.toggle('keyboard-open', keyboardOpen)
}

function initViewportSync() {
  syncViewportHeight()
  const opts = { passive: true }
  window.addEventListener('resize', syncViewportHeight, opts)
  window.addEventListener('orientationchange', () => {
    stableViewportHeight = Math.round(window.innerHeight)
    for (const delay of [0, 120, 260, 500]) {
      window.setTimeout(syncViewportHeight, delay)
    }
  }, opts)
  window.addEventListener('pageshow', syncViewportHeight, opts)

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) syncViewportHeight()
  })

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncViewportHeight, opts)
    // When visualViewport scrolls (iOS keyboard open), snap page back to origin
    window.visualViewport.addEventListener('scroll', () => {
      syncViewportHeight()
      if (hasEditableFocus()) window.scrollTo(0, 0)
    }, opts)
  }

  // iOS can delay keyboard close metrics; resync a few times.
  document.addEventListener('focusin', (event) => {
    if (isIOS && isEditableTarget(event.target)) {
      // Counteract scroll during the entire keyboard-open animation (~400 ms)
      for (const delay of [0, 50, 100, 200, 350, 500]) {
        window.setTimeout(() => window.scrollTo(0, 0), delay)
      }
    }
    window.setTimeout(syncViewportHeight, 0)
    if (isIOS) startRecoverySync(900)
  }, true)
  document.addEventListener('focusout', () => {
    for (const delay of [0, 120, 260, 500, 800]) {
      window.setTimeout(syncViewportHeight, delay)
    }
    if (isIOS) startRecoverySync(2200)
  }, true)

  if (isIOS) {
    window.addEventListener('touchend', () => {
      if (!hasEditableFocus()) startRecoverySync(900)
    }, opts)
  }
}

initViewportSync()

const pinia = createPinia()
const app   = createApp(App)
app.use(pinia)
app.use(router)
app.mount('#app')

import { useSettingsStore } from './store/settings.js'
import { useAuthStore }     from './store/auth.js'
import { evictExpired }     from './lib/mediaCache.js'
import { setUnauthorizedHandler } from './lib/api.js'
import { stopPolling } from './lib/poll.js'

const settings = useSettingsStore()
settings.init()

const auth = useAuthStore()
auth.restorePrivateKey()

setUnauthorizedHandler(() => {
  stopPolling()
  auth.logout()
  router.replace('/auth')
})

// Evict expired media cache on startup
evictExpired(settings.mediaCacheDays)
