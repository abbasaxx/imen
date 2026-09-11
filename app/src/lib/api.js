// app/src/lib/api.js

const BASE = 'https://imen.chat/api'

let _onUnauthorized = null
export function setUnauthorizedHandler(fn) { _onUnauthorized = fn }

async function request(method, path, body = null) {
  const token = localStorage.getItem('token')
  const headers = { 'Content-Type': 'application/json' }
  if (token) headers['Authorization'] = `Bearer ${token}`

  const opts = { method, headers }
  if (body !== null) opts.body = JSON.stringify(body)

  const res  = await fetch(BASE + path, opts)
  const data = await res.json()
  if (!res.ok) {
    if (res.status === 401) { _onUnauthorized?.(); return }
    throw new Error(data.error ?? 'Request failed')
  }
  return data
}

/** Upload an encrypted Blob as multipart form data. Returns { file_id, file_path }.
 *  onProgress(pct: 0-100) is called with upload percentage during transfer. */
function uploadFile(encryptedBlob, fileType, onProgress) {
  return new Promise((resolve, reject) => {
    const token = localStorage.getItem('token')
    const form  = new FormData()
    form.append('file', encryptedBlob, 'blob')
    form.append('file_type', fileType)

    const xhr = new XMLHttpRequest()
    xhr.open('POST', BASE + '/media/upload')
    if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`)

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && onProgress) {
        onProgress(Math.round((e.loaded / e.total) * 100))
      }
    }

    xhr.onload = () => {
      try {
        const data = JSON.parse(xhr.responseText)
        if (xhr.status >= 200 && xhr.status < 300) resolve(data)
        else reject(new Error(data.error ?? 'Upload failed'))
      } catch { reject(new Error('Upload failed')) }
    }

    xhr.onerror  = () => reject(new Error('Network error during upload'))
    xhr.onabort  = () => reject(new Error('Upload aborted'))
    xhr.send(form)
  })
}

/** Fetch an encrypted file blob as ArrayBuffer. */
async function fetchFileBuf(fileId) {
  const token = localStorage.getItem('token')
  const res   = await fetch(BASE + `/media/${fileId}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!res.ok) throw new Error('File not found')
  return res.arrayBuffer()
}

export const api = {
  get:         (path)                  => request('GET',    path),
  post:        (path, body)            => request('POST',   path, body),
  patch:       (path, body)            => request('PATCH',  path, body),
  put:         (path, body)            => request('PUT',    path, body),
  delete:      (path)                  => request('DELETE', path),
  uploadFile:  (blob, fileType, onProgress) => uploadFile(blob, fileType, onProgress),
  fetchFileBuf:(fileId)               => fetchFileBuf(fileId),
}
