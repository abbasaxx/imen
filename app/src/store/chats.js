// app/src/store/chats.js
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import {
  decryptMessage,
  importAESKey,
  decryptWithPrivateKey,
  encryptMessage,
  generateAESKey,
  exportAESKey,
  encryptWithPublicKey,
  encryptFile,
} from '@/lib/crypto'
import { useAuthStore } from './auth'

export const useChatsStore = defineStore('chats', () => {
  const auth = useAuthStore()

  /** Raw conversation rows from the server (includes unread_count, peer_*, etc.) */
  const conversations = ref([])

  /**
   * Decrypted messages keyed by conversation id.
   * Shape: { [convId]: Array<{ id, sender_id, text, created_at, mine,
   *                            mediaFileId?, mediaFileType?, mediaFileKey?, mediaFileIV? }> }
   */
  const messages = ref({})

  /** The currently open conversation id, or null. */
  const activeConvId = ref(null)

  const pagination     = ref({})
  // { [convId]: { hasMoreAbove, hasMoreBelow, loadingAbove, loadingBelow, pendingCount } }

  const scrollPositions = ref({})
  // { [convId]: number } — saved scrollTop when leaving a conversation

  const lruOrder = ref([])
  // [convId, ...] — most-recently-used order, capped at 20

  // ── Computed ───────────────────────────────────────────────────────────────

  const sortedConversations = computed(() =>
    [...conversations.value].sort((a, b) => {
      const ta = a.last_message_at ?? a.created_at ?? ''
      const tb = b.last_message_at ?? b.created_at ?? ''
      return tb > ta ? 1 : -1
    }),
  )

  const activeConversation = computed(() =>
    conversations.value.find((c) => c.id === activeConvId.value) ?? null,
  )

  const activeMessages = computed(() =>
    activeConvId.value ? (messages.value[activeConvId.value] ?? []) : [],
  )

  // ── Crypto helpers ─────────────────────────────────────────────────────────

  function _sentPlaintextCacheKey() {
    return `sentPlaintext:${auth.user?.id ?? 'anon'}`
  }

  function _readSentPlaintextCache() {
    try {
      return JSON.parse(localStorage.getItem(_sentPlaintextCacheKey()) ?? '{}')
    } catch {
      return {}
    }
  }

  function _cacheSentPlaintext(messageId, plaintext) {
    if (!messageId || !plaintext) return
    const cache = _readSentPlaintextCache()
    cache[messageId] = plaintext

    const ids = Object.keys(cache).map(Number).sort((a, b) => b - a)
    for (const id of ids.slice(300)) delete cache[id]

    localStorage.setItem(_sentPlaintextCacheKey(), JSON.stringify(cache))
  }

  function _cachedSentPlaintext(messageId) {
    return _readSentPlaintextCache()[messageId] ?? null
  }

  async function _decryptPrivatePayload(msg, isMine) {
    const privateKey = auth.privateKey
    const keys = isMine
      ? [msg.encrypted_key_sender, msg.encrypted_key_recipient]
      : [msg.encrypted_key_recipient, msg.encrypted_key_sender]

    let lastError = null
    for (const encKey of keys) {
      if (!encKey) continue
      try {
        const aesBase64 = await decryptWithPrivateKey(privateKey, encKey)
        const aesKey    = await importAESKey(aesBase64)
        const payload   = JSON.parse(msg.encrypted_content)
        const raw       = await decryptMessage(aesKey, payload.ciphertext, payload.iv)
        return { text: raw || null, aesKey }
      } catch (e) {
        lastError = e
      }
    }
    throw lastError ?? new Error('No decryptable message key')
  }

  async function _decryptPrivateFileKey(msg, isMine) {
    const privateKey = auth.privateKey
    const keys = isMine
      ? [msg.encrypted_file_key_sender, msg.encrypted_file_key_recipient]
      : [msg.encrypted_file_key_recipient, msg.encrypted_file_key_sender]

    let lastError = null
    for (const encKey of keys) {
      if (!encKey) continue
      try {
        const fileKeyJson = await decryptWithPrivateKey(privateKey, encKey)
        return JSON.parse(fileKeyJson)
      } catch (e) {
        lastError = e
      }
    }
    if (lastError) throw lastError
    return null
  }

  /**
   * Decrypt one message row. Returns the row enriched with { text, mine, media* }.
   * Falls back gracefully if the private key is missing or decryption fails.
   */
  async function _decryptMsg(msg) {
    const privateKey = auth.privateKey
    if (!privateKey) return { ...msg, id: Number(msg.id), is_edited: !!Number(msg.is_edited), text: null, mine: Number(msg.sender_id) === auth.user?.id }

    try {
      const isMine = Number(msg.sender_id) === auth.user?.id

      // Decrypt text payload (encrypted_key_* may be null for pure-media messages)
      const { text } = await _decryptPrivatePayload(msg, isMine)

      // Decrypt file key if a media file is attached
      let mediaFileId = null, mediaFileType = null, mediaFileKey = null, mediaFileIV = null, mediaFileName = null
      if (msg.file_path) {
        const fileKey = await _decryptPrivateFileKey(msg, isMine)
        if (fileKey) {
          const { key, iv } = fileKey
          mediaFileKey  = key
          mediaFileIV   = iv
        }
        mediaFileId   = msg.file_path
        mediaFileType = msg.file_type
        mediaFileName = msg.file_name ?? null
      }

      // Decrypt reply-to snippet (server joins reply row when reply_to_id is set)
      let replyText = null, replyFileType = null, replySenderId = null
      if (msg.reply_to_id && msg.reply_encrypted_content) {
        replySenderId = msg.reply_sender_id
        try {
          const replyMine = String(msg.reply_sender_id) === String(auth.user?.id)
          const replyMsg  = {
            encrypted_content:       msg.reply_encrypted_content,
            encrypted_key_sender:    msg.reply_encrypted_key_sender,
            encrypted_key_recipient: msg.reply_encrypted_key_recipient,
          }
          const { text: rt } = await _decryptPrivatePayload(replyMsg, replyMine)
          replyText     = rt
          replyFileType = msg.reply_file_type ?? null
        } catch { /* leave null — quotedText falls back to threadMessages search */ }
      }

      return { ...msg, id: Number(msg.id), is_edited: !!Number(msg.is_edited), text, mine: isMine, mediaFileId, mediaFileType, mediaFileKey, mediaFileIV, mediaFileName, replyText, replyFileType, replySenderId }
    } catch {
      const isMine = Number(msg.sender_id) === auth.user?.id
      const cachedText = isMine ? _cachedSentPlaintext(Number(msg.id)) : null
      return {
        ...msg,
        id: Number(msg.id),
        is_edited: !!Number(msg.is_edited),
        text: cachedText ?? '[decryption failed]',
        mine: isMine,
      }
    }
  }

  /**
   * Encrypt a plaintext string for a private conversation.
   * Returns { encrypted_content, encrypted_key_recipient, encrypted_key_sender }.
   */
  async function _encryptForConversation(plaintext, peerPublicKeyPEM) {
    if (!auth.privateKey) throw new Error('Your private key is not loaded. Please import the correct private key for this account.')
    if (!await auth.verifyPrivateKeyMatchesUser()) {
      throw new Error('Your private key does not match this account. Please import the correct private key.')
    }

    const aesKey         = await generateAESKey()
    const aesBase64      = await exportAESKey(aesKey)
    const { ciphertext, iv } = await encryptMessage(aesKey, plaintext)

    const encryptedContent        = JSON.stringify({ ciphertext, iv })
    const encryptedKeyRecipient   = await encryptWithPublicKey(peerPublicKeyPEM, aesBase64)
    const encryptedKeySender      = await encryptWithPublicKey(auth.user.public_key, aesBase64)

    return { encrypted_content: encryptedContent, encrypted_key_recipient: encryptedKeyRecipient, encrypted_key_sender: encryptedKeySender }
  }

  /**
   * Decrypt the last-message preview for a single conversation row in-place.
   * Mutates conv.lastMessageText.
   */
  async function _decryptPreview(conv) {
    if (!conv.last_encrypted_content) { conv.lastMessageText = null; return }
    const privateKey = auth.privateKey
    if (!privateKey) { conv.lastMessageText = null; return }
    try {
      const isMine  = Number(conv.last_sender_id) === auth.user?.id
      const msg = {
        encrypted_content: conv.last_encrypted_content,
        encrypted_key_sender: conv.last_encrypted_key_sender,
        encrypted_key_recipient: conv.last_encrypted_key_recipient,
      }
      const { text: raw } = await _decryptPrivatePayload(msg, isMine)
      // For media-only messages the payload text may be empty
      conv.lastMessageText = raw || null
    } catch {
      conv.lastMessageText = null
    }
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  function _touchLru(convId) {
    const idx = lruOrder.value.indexOf(convId)
    if (idx >= 0) lruOrder.value.splice(idx, 1)
    lruOrder.value.push(convId)
    if (lruOrder.value.length > 20) {
      const evict = lruOrder.value.shift()
      delete messages.value[evict]
      delete pagination.value[evict]
      delete scrollPositions.value[evict]
    }
  }

  function _cachedWindowNeedsRefresh(convId) {
    const list = messages.value[convId]
    const pag  = pagination.value[convId]
    if (!list?.length || !pag) return true
    if ((pag.pendingCount ?? 0) > 0) return true

    const conv = conversations.value.find((c) => c.id === convId)
    const latestLoadedId = Number(list.at(-1)?.id ?? 0)
    const latestServerId = Number(conv?.last_message_id ?? 0)
    return latestServerId > latestLoadedId
  }

  /** Load the first window for a conversation (around first unread, or latest 10). */
  async function loadInitial(convId) {
    const conv        = conversations.value.find((c) => c.id === convId)
    const firstUnread = conv?.unread_count > 0 && conv?.last_read_message_id
      ? conv.last_read_message_id + 1
      : null

    const url = firstUnread
      ? `/messages?conversation_id=${convId}&around_id=${firstUnread}`
      : `/messages?conversation_id=${convId}`

    const data      = await api.get(url)
    const decrypted = await Promise.all(data.messages.map(_decryptMsg))
    messages.value[convId]    = decrypted
    pagination.value[convId]  = {
      hasMoreAbove: data.has_more_above,
      hasMoreBelow: data.has_more_below,
      loadingAbove: false,
      loadingBelow: false,
      pendingCount: 0,
    }
    _touchLru(convId)
    return decrypted
  }

  /** Prepend 10 older messages (triggered when user scrolls to the top). */
  async function loadOlder(convId) {
    const pag  = pagination.value[convId]
    const list = messages.value[convId] ?? []
    if (!pag || !pag.hasMoreAbove || pag.loadingAbove || !list.length) return

    pag.loadingAbove = true
    try {
      const data      = await api.get(`/messages?conversation_id=${convId}&before_id=${list[0].id}`)
      const decrypted = await Promise.all(data.messages.map(_decryptMsg))
      messages.value[convId] = [...decrypted, ...list]
      pag.hasMoreAbove       = data.has_more_above
    } finally {
      pag.loadingAbove = false
    }
  }

  /** Append 10 newer messages (triggered when user scrolls to the bottom). */
  async function loadNewer(convId) {
    const pag  = pagination.value[convId]
    const list = messages.value[convId] ?? []
    if (!pag || !pag.hasMoreBelow || pag.loadingBelow || !list.length) return

    pag.loadingBelow = true
    try {
      const data      = await api.get(`/messages?conversation_id=${convId}&after_id=${list.at(-1).id}`)
      const decrypted = await Promise.all(data.messages.map(_decryptMsg))
      messages.value[convId] = [...list, ...decrypted]
      pag.hasMoreBelow       = data.has_more_below
      if (!data.has_more_below) pag.pendingCount = 0
    } finally {
      pag.loadingBelow = false
    }
  }

  /** Load a window centered on a specific message (deep link). */
  async function loadAroundMessage(convId, msgId) {
    const data      = await api.get(`/messages?conversation_id=${convId}&around_id=${msgId}`)
    const decrypted = await Promise.all(data.messages.map(_decryptMsg))
    messages.value[convId]   = decrypted
    pagination.value[convId] = {
      hasMoreAbove: data.has_more_above,
      hasMoreBelow: data.has_more_below,
      loadingAbove: false,
      loadingBelow: false,
      pendingCount: 0,
    }
    _touchLru(convId)
    return decrypted
  }

  async function fetchConversations() {
    const data = await api.get('/conversations')
    conversations.value = data.conversations.map((c) => ({ ...c, id: Number(c.id) }))
    conversations.value.forEach((c) => _decryptPreview(c))
  }

  /** Load + decrypt all messages for a conversation (from message id 0). */
  async function loadMessages(convId) {
    const data = await api.get(`/messages?conversation_id=${convId}&since=0`)
    const decrypted = await Promise.all(data.messages.map(_decryptMsg))
    messages.value[convId] = decrypted
    return decrypted
  }

  /** Open a conversation: set it active, load window only on first visit, mark read. */
  async function openConversation(convId) {
    activeConvId.value = convId

    if (_cachedWindowNeedsRefresh(convId)) {
      await loadInitial(convId)
    } else {
      _touchLru(convId)
    }

    const loaded = messages.value[convId] ?? []
    if (loaded.length > 0) {
      await markRead(convId, loaded[loaded.length - 1].id)
    }
  }

  /** Find or create a private conversation with a peer, then open it. */
  async function startConversation(peerId) {
    const data = await api.post('/conversations', { peer_id: peerId })
    const raw  = data.conversation
    const peer = data.peer
    // Merge peer fields the server returns in listForUser but not in findById
    const conv = {
      ...raw,
      id:              Number(raw.id),
      peer_id:         peer.id,
      peer_name:       peer.name,
      peer_email:      peer.email,
      peer_public_key: peer.public_key,
      unread_count:    0,
    }
    const idx = conversations.value.findIndex((c) => c.id === conv.id)
    if (idx >= 0) conversations.value[idx] = conv
    else conversations.value.unshift(conv)

    await openConversation(conv.id)
    return conv
  }

  /** Mark a conversation as read up to a given message id. */
  async function markRead(convId, lastMessageId) {
    await api.patch(`/conversations/${convId}/read`, { last_message_id: lastMessageId })
    const conv = conversations.value.find((c) => c.id === convId)
    if (conv) conv.unread_count = 0
  }

  /**
   * Send an encrypted message to the active conversation.
   * @param {string} plaintext
   * @param {{ blob: Blob, fileType: string, name: string } | null} attachment
   */
  async function sendMessage(plaintext, attachment = null, replyToId = null, onProgress = null) {
    const conv = activeConversation.value
    if (!conv) throw new Error('No active conversation')

    let fileId = null, fileType = null
    let encryptedFileKeyRecipient = null, encryptedFileKeySender = null
    let mediaFileKey = null, mediaFileIV = null
    let messageType = 'text'

    if (attachment) {
      // Encrypt file with a fresh AES key
      const { encryptedBlob, iv: fiv, keyBase64: fkey } = await encryptFile(attachment.blob)
      mediaFileKey = fkey
      mediaFileIV  = fiv
      fileType     = attachment.fileType

      // Upload encrypted blob
      const uploaded = await api.uploadFile(encryptedBlob, fileType, onProgress)
      fileId = uploaded.file_id

      // RSA-encrypt { key, iv } for recipient and sender
      const fileKeyJson = JSON.stringify({ key: fkey, iv: fiv })
      encryptedFileKeyRecipient = await encryptWithPublicKey(conv.peer_public_key, fileKeyJson)
      if (auth.user?.public_key) {
        encryptedFileKeySender = await encryptWithPublicKey(auth.user.public_key, fileKeyJson)
      }

      messageType = plaintext.trim()
        ? 'text_media'
        : (fileType === 'voice' ? 'voice' : 'media')
    }

    // Always encrypt the text payload (may be empty string for media-only)
    const payload = await _encryptForConversation(plaintext || '', conv.peer_public_key)
    const body = {
      conversation_id:        conv.id,
      message_type:           messageType,
      ...payload,
      ...(replyToId ? { reply_to_id: replyToId } : {}),
      ...(fileId ? {
        file_id:                       fileId,
        file_type:                     fileType,
        file_name:                     attachment?.name ?? null,
        encrypted_file_key_recipient:  encryptedFileKeyRecipient,
        encrypted_file_key_sender:     encryptedFileKeySender,
      } : {}),
    }

    const data = await api.post('/messages', body)
    _cacheSentPlaintext(data.message_id, plaintext || null)

    // Optimistically append to local list
    const optimistic = {
      id:                       data.message_id,
      conversation_id:          conv.id,
      sender_id:                auth.user?.id,
      reply_to_id:              replyToId ?? null,
      message_type:             messageType,
      text:                     plaintext || null,
      mine:                     true,
      encrypted_content:        payload.encrypted_content,
      encrypted_key_recipient:  payload.encrypted_key_recipient,
      encrypted_key_sender:     payload.encrypted_key_sender,
      created_at:               new Date().toISOString().replace('T', ' ').substring(0, 19),
      // Media
      file_path:                fileId,
      file_type:                fileType,
      file_name:                attachment?.name ?? null,
      mediaFileId:              fileId,
      mediaFileType:            fileType,
      mediaFileKey,
      mediaFileIV,
      mediaFileName:            attachment?.name ?? null,
    }

    if (!messages.value[conv.id]) messages.value[conv.id] = []
    messages.value[conv.id].push(optimistic)

    // Update last_message in sidebar
    const idx = conversations.value.findIndex((c) => c.id === conv.id)
    if (idx >= 0) {
      conversations.value[idx].last_message_at             = optimistic.created_at
      conversations.value[idx].last_encrypted_content      = payload.encrypted_content
      conversations.value[idx].last_encrypted_key_sender   = payload.encrypted_key_sender
      conversations.value[idx].last_encrypted_key_recipient= payload.encrypted_key_recipient
      conversations.value[idx].last_sender_id              = auth.user?.id
      conversations.value[idx].lastMessageText             = plaintext || null
    }

    return data.message_id
  }

  /**
   * Apply the response from GET /updates.
   * Merges new messages and refreshes the conversation list.
   * markRead is NOT called here — it is called on open and on close only.
   */
  async function applyUpdates({ messages: newMsgs, conversations: newConvs }) {
    if (newConvs) {
      // Merge into existing array to preserve lastMessageText and avoid flicker.
      // Only re-decrypt when the last message actually changed.
      for (const raw of newConvs) {
        const fresh = { ...raw, id: Number(raw.id) }
        const existing = conversations.value.find((c) => c.id === fresh.id)
        if (existing) {
          const lastChanged = fresh.last_message_id !== existing.last_message_id
          Object.assign(existing, fresh)
          // Don't let the server overwrite unread_count while the user is reading this chat
          if (existing.id === activeConvId.value) existing.unread_count = 0
          if (lastChanged) _decryptPreview(existing)
        } else {
          conversations.value.push(fresh)
          _decryptPreview(fresh)
        }
      }
      // Remove conversations that no longer exist on the server
      const freshIds = new Set(newConvs.map((c) => Number(c.id)))
      conversations.value = conversations.value.filter((c) => freshIds.has(c.id))
    }

    if (!newMsgs?.length) return

    const autoRead = {} // convId → highest new message id to mark as read

    for (const msg of newMsgs) {
      const convId = Number(msg.conversation_id)

      // Only update conversations already open/loaded
      if (!messages.value[convId]) continue

      const existing = messages.value[convId].find((m) => m.id === Number(msg.id))
      if (existing) {
        // Edit arrived — update in-place without re-decrypting media keys
        const dec = await _decryptMsg(msg)
        existing.text              = dec.text
        existing.is_edited         = dec.is_edited
        existing.encrypted_content = dec.encrypted_content
        existing.encrypted_key_recipient = dec.encrypted_key_recipient
        existing.encrypted_key_sender    = dec.encrypted_key_sender
      } else {
        const pag = pagination.value[convId]
        if (pag?.hasMoreBelow) {
          if (convId === activeConvId.value) {
            await loadInitial(convId)
            const loaded = messages.value[convId] ?? []
            const latest = loaded.at(-1)
            if (latest && Number(msg.sender_id) !== auth.user?.id) {
              autoRead[convId] = Math.max(autoRead[convId] ?? 0, latest.id)
            }
          } else {
            pag.pendingCount = (pag.pendingCount ?? 0) + 1
          }
        } else {
          const dec = await _decryptMsg(msg)
          messages.value[convId].push(dec)
          if (convId === activeConvId.value && Number(msg.sender_id) !== auth.user?.id) {
            autoRead[convId] = Math.max(autoRead[convId] ?? 0, msg.id)
          }
        }
      }
    }

    // Mark active conversation as read for any newly arrived peer messages
    for (const [convId, lastId] of Object.entries(autoRead)) {
      markRead(Number(convId), lastId)
    }
  }

  /**
   * Edit own private message. Re-encrypts the new plaintext for both parties.
   * Updates local store immediately.
   */
  async function editMessage(messageId, newPlaintext) {
    // Find the message to know which conversation (and peer public key) it belongs to
    let conv = null
    for (const convId of Object.keys(messages.value)) {
      if (messages.value[convId].find((m) => m.id === messageId)) {
        conv = conversations.value.find((c) => c.id === Number(convId))
        break
      }
    }
    if (!conv) throw new Error('Conversation not found for this message')

    const payload = await _encryptForConversation(newPlaintext, conv.peer_public_key)
    await api.patch(`/messages/${messageId}`, payload)

    // Update local store
    for (const convId of Object.keys(messages.value)) {
      const msg = messages.value[convId].find((m) => m.id === messageId)
      if (msg) {
        msg.text       = newPlaintext
        msg.is_edited  = 1
        msg.encrypted_content         = payload.encrypted_content
        msg.encrypted_key_recipient   = payload.encrypted_key_recipient
        msg.encrypted_key_sender      = payload.encrypted_key_sender
        break
      }
    }
  }

  /** Delete own message. Removes from local store immediately. */
  async function deleteMessage(messageId) {
    await api.delete(`/messages/${messageId}`)
    for (const convId of Object.keys(messages.value)) {
      const idx = messages.value[convId].findIndex((m) => m.id === messageId)
      if (idx >= 0) {
        messages.value[convId].splice(idx, 1)
        break
      }
    }
  }

  /** Remove deleted messages from all cached private conversations. */
  function applyDeletions(deletedIds) {
    if (!deletedIds?.length) return
    const idSet = new Set(deletedIds.map(Number))
    for (const convId of Object.keys(messages.value)) {
      const before = messages.value[convId].length
      messages.value[convId] = messages.value[convId].filter((m) => !idSet.has(m.id))
      if (messages.value[convId].length !== before) {
        // Keep the idSet loop going — a message belongs to exactly one conv
      }
    }
  }

  return {
    conversations,
    messages,
    pagination,
    scrollPositions,
    activeConvId,
    activeConversation,
    activeMessages,
    sortedConversations,
    fetchConversations,
    loadMessages,
    loadInitial,
    loadOlder,
    loadNewer,
    loadAroundMessage,
    openConversation,
    startConversation,
    markRead,
    sendMessage,
    editMessage,
    deleteMessage,
    applyUpdates,
    applyDeletions,
  }
})
