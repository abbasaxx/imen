// app/src/lib/crypto.js

const RSA_ALGO = {
  name: 'RSA-OAEP',
  modulusLength: 2048,
  publicExponent: new Uint8Array([1, 0, 1]),
  hash: 'SHA-256',
}

// ── RSA key pair ──────────────────────────────────────────

export async function generateKeyPair() {
  return crypto.subtle.generateKey(RSA_ALGO, true, ['encrypt', 'decrypt'])
}

export async function exportPublicKeyPEM(key) {
  const buf = await crypto.subtle.exportKey('spki', key)
  return toPEM(buf, 'PUBLIC KEY')
}

export async function exportPrivateKeyPEM(key) {
  const buf = await crypto.subtle.exportKey('pkcs8', key)
  return toPEM(buf, 'PRIVATE KEY')
}

export async function importPublicKeyFromPEM(pem) {
  const buf = fromPEM(pem, 'PUBLIC KEY')
  return crypto.subtle.importKey('spki', buf, RSA_ALGO, true, ['encrypt'])
}

export async function importPrivateKeyFromPEM(pem) {
  const buf = fromPEM(pem, 'PRIVATE KEY')
  return crypto.subtle.importKey('pkcs8', buf, RSA_ALGO, true, ['decrypt'])
}

/** Encrypt a plaintext string using a PEM-encoded RSA public key. Returns base64. */
export async function encryptWithPublicKey(publicKeyPEM, plaintext) {
  const key = await importPublicKeyFromPEM(publicKeyPEM)
  const buf = await crypto.subtle.encrypt(
    { name: 'RSA-OAEP' },
    key,
    new TextEncoder().encode(plaintext),
  )
  return bufToBase64(buf)
}

/** Decrypt a base64 RSA ciphertext using a CryptoKey private key. */
export async function decryptWithPrivateKey(privateKey, base64Cipher) {
  const buf = await crypto.subtle.decrypt(
    { name: 'RSA-OAEP' },
    privateKey,
    base64ToBuf(base64Cipher),
  )
  return new TextDecoder().decode(buf)
}

// ── AES-GCM ──────────────────────────────────────────────

export async function generateAESKey() {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
}

export async function exportAESKey(key) {
  const buf = await crypto.subtle.exportKey('raw', key)
  return bufToBase64(buf)
}

export async function importAESKey(base64Key) {
  return crypto.subtle.importKey(
    'raw',
    base64ToBuf(base64Key),
    { name: 'AES-GCM' },
    true,
    ['encrypt', 'decrypt'],
  )
}

/** Returns { ciphertext: base64, iv: base64 } */
export async function encryptMessage(aesKey, plaintext) {
  const iv  = crypto.getRandomValues(new Uint8Array(12))
  const buf = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    aesKey,
    new TextEncoder().encode(plaintext),
  )
  return { ciphertext: bufToBase64(buf), iv: bufToBase64(iv) }
}

export async function decryptMessage(aesKey, base64Cipher, base64IV) {
  const buf = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: base64ToBuf(base64IV) },
    aesKey,
    base64ToBuf(base64Cipher),
  )
  return new TextDecoder().decode(buf)
}

/** Encrypt a file Blob with AES-GCM. Returns { encryptedBlob, iv: base64, keyBase64 } */
export async function encryptFile(file) {
  const aesKey = await generateAESKey()
  const iv     = crypto.getRandomValues(new Uint8Array(12))
  const arrBuf = await file.arrayBuffer()
  const enc    = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, arrBuf)
  return {
    encryptedBlob: new Blob([enc], { type: 'application/octet-stream' }),
    iv:            bufToBase64(iv),
    keyBase64:     await exportAESKey(aesKey),
  }
}

/** Decrypt an encrypted ArrayBuffer/Blob back to a Blob of the given mimeType. */
export async function decryptFile(encryptedBuf, base64Key, base64IV, mimeType = '') {
  const aesKey = await importAESKey(base64Key)
  const dec    = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: base64ToBuf(base64IV) },
    aesKey,
    encryptedBuf,
  )
  return new Blob([dec], { type: mimeType })
}

// ── Utilities ─────────────────────────────────────────────

export function bufToBase64(buf) {
  return btoa(String.fromCharCode(...new Uint8Array(buf)))
}

function toPEM(buf, label) {
  const b64   = btoa(String.fromCharCode(...new Uint8Array(buf)))
  const lines = b64.match(/.{1,64}/g).join('\n')
  return `-----BEGIN ${label}-----\n${lines}\n-----END ${label}-----`
}

function fromPEM(pem, label) {
  const b64 = pem
    .replace(`-----BEGIN ${label}-----`, '')
    .replace(`-----END ${label}-----`, '')
    .replace(/\s+/g, '')
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)).buffer
}

function base64ToBuf(b64) {
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)).buffer
}
