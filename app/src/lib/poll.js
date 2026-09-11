// app/src/lib/poll.js
// 2-second polling loop for new messages across all chats.
import { api } from './api.js'

let timer         = null
let lastTimestamp = null

/** Callbacks registered via onConnectionChange() */
const connectionListeners = new Set()
let _connected = true

function setConnected(value) {
  if (_connected === value) return
  _connected = value
  connectionListeners.forEach((fn) => fn(value))
}

/** Register a callback that fires when online/offline status changes. */
export function onConnectionChange(fn) {
  connectionListeners.add(fn)
  return () => connectionListeners.delete(fn)
}

export function isConnected() { return _connected }

/**
 * Start polling /updates every 2 seconds.
 * onUpdate(data) is called with the response whenever new data arrives.
 */
export function startPolling(onUpdate) {
  stopPolling()
  lastTimestamp = new Date().toISOString()

  async function poll() {
    try {
      const data = await api.get(`/updates?since=${encodeURIComponent(lastTimestamp)}`)
      lastTimestamp = new Date().toISOString()
      setConnected(true)
      onUpdate(data)
    } catch {
      setConnected(false)
    }
    timer = setTimeout(poll, 2000)
  }

  poll()
}

export function stopPolling() {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}
