// app/src/store/auth.js
import { defineStore } from 'pinia'
import { api } from '../lib/api.js'
import { decryptWithPrivateKey, encryptWithPublicKey, importPrivateKeyFromPEM } from '../lib/crypto.js'

function storedUser() {
  const raw = JSON.parse(localStorage.getItem('user') ?? 'null')
  return raw ? { ...raw, id: Number(raw.id) } : null
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token:      localStorage.getItem('token') ?? null,
    user:       storedUser(),
    privateKey: null, // CryptoKey — loaded from localStorage PEM on startup
  }),

  getters: {
    isLoggedIn:    (s) => !!s.token,
    hasPrivateKey: (s) => !!s.privateKey,
  },

  actions: {
    setToken(token) {
      this.token = token
      localStorage.setItem('token', token)
    },

    setUser(user) {
      this.user = { ...user, id: Number(user.id) }
      localStorage.setItem('user', JSON.stringify(this.user))
    },

    /** Call after registration: saves PEM to localStorage and loads CryptoKey. */
    async setPrivateKeyFromPEM(pem) {
      const privateKey = await importPrivateKeyFromPEM(pem)
      if (this.user?.public_key) {
        const probe = `key-check-${crypto.randomUUID()}`
        const encrypted = await encryptWithPublicKey(this.user.public_key, probe)
        const decrypted = await decryptWithPrivateKey(privateKey, encrypted)
        if (decrypted !== probe) throw new Error('Private key does not match this account')
      }

      this.privateKey = privateKey
      localStorage.setItem('privatePEM', pem)
      if (this.user?.id) localStorage.setItem('privatePEMUserId', String(this.user.id))
    },

    /** Called on app startup to restore private key from localStorage. */
    async restorePrivateKey() {
      const pem = localStorage.getItem('privatePEM')
      if (!pem) return

      try {
        const privateKey = await importPrivateKeyFromPEM(pem)
        const storedUserId = localStorage.getItem('privatePEMUserId')

        if (storedUserId && storedUserId !== String(this.user?.id ?? '')) {
          this.privateKey = null
          return
        }

        if (!storedUserId && this.user?.public_key) {
          const probe = `key-check-${crypto.randomUUID()}`
          const encrypted = await encryptWithPublicKey(this.user.public_key, probe)
          const decrypted = await decryptWithPrivateKey(privateKey, encrypted)
          if (decrypted !== probe) {
            this.privateKey = null
            return
          }
          localStorage.setItem('privatePEMUserId', String(this.user.id))
        }

        this.privateKey = privateKey
      } catch {
        this.privateKey = null
        localStorage.removeItem('privatePEM')
        localStorage.removeItem('privatePEMUserId')
      }
    },

    async verifyPrivateKeyMatchesUser() {
      if (!this.privateKey || !this.user?.public_key) return false
      try {
        const probe = `key-check-${crypto.randomUUID()}`
        const encrypted = await encryptWithPublicKey(this.user.public_key, probe)
        const decrypted = await decryptWithPrivateKey(this.privateKey, encrypted)
        return decrypted === probe
      } catch {
        return false
      }
    },

    async logout() {
      // Clear state synchronously before the first await so the router guard
      // sees isLoggedIn = false immediately when called from the 401 handler.
      this.token      = null
      this.user       = null
      this.privateKey = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      // Intentionally keep 'privatePEM' — user may log back in on the same device.
      // It is replaced on new registration via setPrivateKeyFromPEM().
      try { await api.post('/auth/logout') } catch { /* token may already be invalid */ }
    },
  },
})
