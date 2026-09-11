// app/src/lib/mediaCache.js
// IndexedDB cache for decrypted media blobs. Evicts entries older than mediaCacheDays.

const DB_NAME    = 'securechat-media'
const STORE_NAME = 'blobs'
const DB_VERSION = 1

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION)
    req.onupgradeneeded = (e) => {
      e.target.result.createObjectStore(STORE_NAME, { keyPath: 'fileId' })
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror   = () => reject(req.error)
  })
}

/** Store a decrypted Blob in IndexedDB. */
export async function cacheMedia(fileId, blob) {
  const db = await openDB()
  return new Promise((resolve, reject) => {
    const tx  = db.transaction(STORE_NAME, 'readwrite')
    const req = tx.objectStore(STORE_NAME).put({ fileId, blob, cachedAt: Date.now() })
    req.onsuccess = () => resolve()
    req.onerror   = () => reject(req.error)
  })
}

/** Retrieve a cached Blob, or null if not found. */
export async function getCachedMedia(fileId) {
  const db = await openDB()
  return new Promise((resolve, reject) => {
    const tx  = db.transaction(STORE_NAME, 'readonly')
    const req = tx.objectStore(STORE_NAME).get(fileId)
    req.onsuccess = () => resolve(req.result?.blob ?? null)
    req.onerror   = () => reject(req.error)
  })
}

/** Evict all entries older than cacheDays days. Call on app startup. */
export async function evictExpired(cacheDays = 30) {
  const db        = await openDB()
  const cutoff    = Date.now() - cacheDays * 86400 * 1000
  return new Promise((resolve, reject) => {
    const tx    = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    const req   = store.openCursor()
    req.onsuccess = (e) => {
      const cursor = e.target.result
      if (!cursor) { resolve(); return }
      if (cursor.value.cachedAt < cutoff) cursor.delete()
      cursor.continue()
    }
    req.onerror = () => reject(req.error)
  })
}
