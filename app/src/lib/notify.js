// app/src/lib/notify.js
// Browser Notifications API helper — safe on iOS where Notification is undefined.

const supported = typeof Notification !== 'undefined'

/** Request notification permission if not already granted/denied. */
export async function requestPermission() {
  if (!supported) return false
  if (Notification.permission === 'default') {
    await Notification.requestPermission()
  }
  return Notification.permission === 'granted'
}

/**
 * Show a browser notification.
 * @param {string} title
 * @param {string} body
 * @param {Function} onClick  — called when the user clicks the notification
 */
export function showNotification(title, body, onClick) {
  if (!supported) return
  if (Notification.permission !== 'granted') return
  if (document.visibilityState === 'visible') return

  const n = new Notification(title, { body, icon: '/favicon.ico' })
  n.onclick = () => { window.focus(); onClick?.(); n.close() }
  setTimeout(() => n.close(), 6000)
}
