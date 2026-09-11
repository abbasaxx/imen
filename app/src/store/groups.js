// app/src/store/groups.js
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
  bufToBase64,
} from '@/lib/crypto'
import { useAuthStore } from './auth'

export const useGroupsStore = defineStore('groups', () => {
  const auth = useAuthStore()

  /** Raw group rows from server (includes encrypted_group_key for current user). */
  const groups = ref([])

  /**
   * Decrypted messages keyed by group id.
   * Shape: { [groupId]: Array<{ id, sender_id, text, created_at, mine,
   *                             mediaFileId?, mediaFileType?, mediaFileKey?, mediaFileIV? }> }
   */
  const messages = ref({})

  /** Currently open group id, or null. */
  const activeGroupId = ref(null)

  /**
   * In-memory cache of imported AES CryptoKey objects per group.
   * Map<groupId, CryptoKey>
   * Avoids re-decrypting encrypted_group_key on every message.
   */
  const groupAesKeys = new Map()

  /**
   * Read receipts keyed by group id.
   * Shape: { [groupId]: Array<{ user_id, name, last_read_message_id }> }
   */
  const groupReadReceipts = ref({})

  const pagination      = ref({})
  // { [groupId]: { hasMoreAbove, hasMoreBelow, loadingAbove, loadingBelow, pendingCount } }

  const scrollPositions = ref({})
  // { [groupId]: number }

  const lruOrder = ref([])
  // [groupId, ...] capped at 20

  // ── Computed ───────────────────────────────────────────────────────────────

  const sortedGroups = computed(() =>
    [...groups.value].sort((a, b) => {
      const ta = a.last_message_at ?? a.created_at ?? ''
      const tb = b.last_message_at ?? b.created_at ?? ''
      return tb > ta ? 1 : -1
    }),
  )

  const activeGroup = computed(() =>
    groups.value.find((g) => g.id === activeGroupId.value) ?? null,
  )

  const activeMessages = computed(() =>
    activeGroupId.value ? (messages.value[activeGroupId.value] ?? []) : [],
  )

  // ── Crypto helpers ─────────────────────────────────────────────────────────

  /**
   * Get (or derive and cache) the AES CryptoKey for a group.
   * Returns null if private key is not loaded or derivation fails.
   */
  async function _getGroupAesKey(groupId) {
    groupId = Number(groupId)
    if (groupAesKeys.has(groupId)) return groupAesKeys.get(groupId)

    const privateKey = auth.privateKey
    if (!privateKey) return null

    const group = groups.value.find((g) => g.id === groupId)
    if (!group?.encrypted_group_key) return null

    try {
      const aesBase64 = await decryptWithPrivateKey(privateKey, group.encrypted_group_key)
      const aesKey    = await importAESKey(aesBase64)
      groupAesKeys.set(groupId, aesKey)
      return aesKey
    } catch {
      return null
    }
  }

  /** Store a freshly generated AES key for a newly created group. */
  function _cacheGroupAesKey(groupId, aesKey) {
    groupAesKeys.set(Number(groupId), aesKey)
  }

  /**
   * Decrypt one group message row.
   * For 'text' messages: decrypted payload is plain text.
   * For 'media'/'voice'/'text_media': payload is JSON { text?, file_id, file_type, file_iv }.
   * Returns row enriched with { text, mine, mediaFileId?, mediaFileType?, mediaFileKey?, mediaFileIV? }.
   */
  async function _decryptMsg(msg) {
    const aesKey = await _getGroupAesKey(msg.group_id)
    if (!aesKey) return { ...msg, id: Number(msg.id), is_edited: !!Number(msg.is_edited), text: null, mine: Number(msg.sender_id) === auth.user?.id }

    try {
      const isMine  = Number(msg.sender_id) === auth.user?.id
      const payload = JSON.parse(msg.encrypted_content)
      const raw     = await decryptMessage(aesKey, payload.ciphertext, payload.iv)

      let text = null
      let mediaFileId = null, mediaFileType = null, mediaFileKey = null, mediaFileIV = null, mediaFileName = null

      if (msg.message_type === 'text') {
        text = raw
      } else {
        // media / voice / text_media — payload is JSON
        try {
          const parsed  = JSON.parse(raw)
          text          = parsed.text      ?? null
          mediaFileId   = parsed.file_id   ?? null
          mediaFileType = parsed.file_type ?? null
          mediaFileIV   = parsed.file_iv   ?? null
          mediaFileName = parsed.file_name ?? null
          if (mediaFileId) {
            // Export the group AES key so MediaPlayer can decrypt the file
            mediaFileKey = await exportAESKey(aesKey)
          }
        } catch {
          // Fallback: treat as plain text if JSON.parse fails
          text = raw
        }
      }

      // Decrypt reply-to snippet (server joins reply row when reply_to_id is set)
      let replyText = null, replyFileType = null, replySenderId = null
      if (msg.reply_to_id && msg.reply_encrypted_content) {
        replySenderId = msg.reply_sender_id
        try {
          const replyPld = JSON.parse(msg.reply_encrypted_content)
          const replyRaw = await decryptMessage(aesKey, replyPld.ciphertext, replyPld.iv)
          if (msg.reply_message_type === 'text') {
            replyText = replyRaw || null
          } else {
            try {
              const parsed = JSON.parse(replyRaw)
              replyText     = parsed.text      ?? null
              replyFileType = parsed.file_type ?? msg.reply_message_type ?? null
            } catch {
              replyText = replyRaw || null
            }
          }
        } catch { /* leave null */ }
      }

      return { ...msg, id: Number(msg.id), is_edited: !!Number(msg.is_edited), text, mine: isMine, mediaFileId, mediaFileType, mediaFileKey, mediaFileIV, mediaFileName, replyText, replyFileType, replySenderId }
    } catch {
      return { ...msg, id: Number(msg.id), is_edited: !!Number(msg.is_edited), text: '[decryption failed]', mine: Number(msg.sender_id) === auth.user?.id }
    }
  }

  /** Decrypt the last-message preview for a group row in-place. */
  async function _decryptPreview(group) {
    if (!group.last_encrypted_content) { group.lastMessageText = null; return }
    try {
      const aesKey = await _getGroupAesKey(group.id)
      if (!aesKey) { group.lastMessageText = null; return }
      const payload = JSON.parse(group.last_encrypted_content)
      const raw     = await decryptMessage(aesKey, payload.ciphertext, payload.iv)
      // For media messages the plaintext is JSON; extract text field if present
      if (group.last_sender_id && raw) {
        try {
          const parsed = JSON.parse(raw)
          group.lastMessageText = parsed.text ?? null
        } catch {
          group.lastMessageText = raw
        }
      } else {
        group.lastMessageText = raw || null
      }
    } catch {
      group.lastMessageText = null
    }
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  function _touchLru(groupId) {
    const idx = lruOrder.value.indexOf(groupId)
    if (idx >= 0) lruOrder.value.splice(idx, 1)
    lruOrder.value.push(groupId)
    if (lruOrder.value.length > 20) {
      const evict = lruOrder.value.shift()
      delete messages.value[evict]
      delete pagination.value[evict]
      delete scrollPositions.value[evict]
    }
  }

  function _cachedWindowNeedsRefresh(groupId) {
    const list = messages.value[groupId]
    const pag  = pagination.value[groupId]
    if (!list?.length || !pag) return true
    if ((pag.pendingCount ?? 0) > 0) return true

    const group = groups.value.find((g) => g.id === groupId)
    const latestLoadedId = Number(list.at(-1)?.id ?? 0)
    const latestServerId = Number(group?.last_message_id ?? 0)
    return latestServerId > latestLoadedId
  }

  /** Load the first window for a group (around first unread, or latest 10). */
  async function loadInitial(groupId) {
    const group       = groups.value.find((g) => g.id === groupId)
    const firstUnread = group?.unread_count > 0 && group?.last_read_message_id
      ? group.last_read_message_id + 1
      : null

    const url  = firstUnread
      ? `/groups/${groupId}/messages?around_id=${firstUnread}`
      : `/groups/${groupId}/messages`
    const data = await api.get(url)

    if (data.encrypted_group_key) {
      const g = groups.value.find((g) => g.id === groupId)
      if (g && !g.encrypted_group_key) g.encrypted_group_key = data.encrypted_group_key
    }

    const decrypted = await Promise.all(data.messages.map(_decryptMsg))
    messages.value[groupId]   = decrypted
    pagination.value[groupId] = {
      hasMoreAbove: data.has_more_above,
      hasMoreBelow: data.has_more_below,
      loadingAbove: false,
      loadingBelow: false,
      pendingCount: 0,
    }
    _touchLru(groupId)
    return decrypted
  }

  /** Prepend 10 older group messages (triggered when user scrolls to the top). */
  async function loadOlder(groupId) {
    const pag  = pagination.value[groupId]
    const list = messages.value[groupId] ?? []
    if (!pag || !pag.hasMoreAbove || pag.loadingAbove || !list.length) return

    pag.loadingAbove = true
    try {
      const data      = await api.get(`/groups/${groupId}/messages?before_id=${list[0].id}`)
      const decrypted = await Promise.all(data.messages.map(_decryptMsg))
      messages.value[groupId] = [...decrypted, ...list]
      pag.hasMoreAbove        = data.has_more_above
    } finally {
      pag.loadingAbove = false
    }
  }

  /** Append 10 newer group messages (triggered when user scrolls to the bottom). */
  async function loadNewer(groupId) {
    const pag  = pagination.value[groupId]
    const list = messages.value[groupId] ?? []
    if (!pag || !pag.hasMoreBelow || pag.loadingBelow || !list.length) return

    pag.loadingBelow = true
    try {
      const data      = await api.get(`/groups/${groupId}/messages?after_id=${list.at(-1).id}`)
      const decrypted = await Promise.all(data.messages.map(_decryptMsg))
      messages.value[groupId] = [...list, ...decrypted]
      pag.hasMoreBelow        = data.has_more_below
      if (!data.has_more_below) pag.pendingCount = 0
    } finally {
      pag.loadingBelow = false
    }
  }

  /** Load a window centered on a specific group message (deep link). */
  async function loadAroundMessage(groupId, msgId) {
    const data = await api.get(`/groups/${groupId}/messages?around_id=${msgId}`)

    if (data.encrypted_group_key) {
      const g = groups.value.find((g) => g.id === groupId)
      if (g && !g.encrypted_group_key) g.encrypted_group_key = data.encrypted_group_key
    }

    const decrypted = await Promise.all(data.messages.map(_decryptMsg))
    messages.value[groupId]   = decrypted
    pagination.value[groupId] = {
      hasMoreAbove: data.has_more_above,
      hasMoreBelow: data.has_more_below,
      loadingAbove: false,
      loadingBelow: false,
      pendingCount: 0,
    }
    _touchLru(groupId)
    return decrypted
  }

  async function fetchGroups() {
    const data = await api.get('/groups')
    groups.value = data.groups.map((g) => ({ ...g, id: Number(g.id), created_by: Number(g.created_by) }))
    groups.value.forEach((g) => _decryptPreview(g))
  }

  /** Load + decrypt all messages for a group. */
  async function loadMessages(groupId) {
    const data      = await api.get(`/groups/${groupId}/messages?since=0`)
    // If the server returned an encrypted_group_key and we don't have it cached yet, store it
    if (data.encrypted_group_key) {
      const group = groups.value.find((g) => g.id === groupId)
      if (group && !group.encrypted_group_key) {
        group.encrypted_group_key = data.encrypted_group_key
      }
    }
    const decrypted = await Promise.all(data.messages.map(_decryptMsg))
    messages.value[groupId] = decrypted
    return decrypted
  }

  /** Open a group: set it active, load window only on first visit, mark read. */
  async function openGroup(groupId) {
    activeGroupId.value = groupId

    if (_cachedWindowNeedsRefresh(groupId)) {
      await loadInitial(groupId)
    } else {
      _touchLru(groupId)
    }

    const loaded = messages.value[groupId] ?? []
    if (loaded.length > 0) {
      await markRead(groupId, loaded[loaded.length - 1].id)
    }
  }

  /** Mark a group as read up to a given message id. */
  async function markRead(groupId, lastMessageId) {
    await api.patch(`/groups/${groupId}/read`, { last_message_id: lastMessageId })
    const group = groups.value.find((g) => g.id === groupId)
    if (group) group.unread_count = 0
  }

  /**
   * Create a new group.
   * @param {string} name
   * @param {Array<{id, name, email, public_key}>} memberUsers — peers to add (not including self)
   */
  async function createGroup(name, memberUsers) {
    // Generate the group AES key
    const aesKey    = await generateAESKey()
    const aesBase64 = await exportAESKey(aesKey)

    // Encrypt AES key with self's RSA public key
    let selfPublicKey = auth.user?.public_key
    if (!selfPublicKey) {
      // Stale session — fetch own profile to get public_key and cache it
      const profile = await api.get(`/users/search?q=${encodeURIComponent(auth.user?.email ?? '')}`)
      const me = profile.users?.find((u) => u.id === auth.user?.id)
      if (me?.public_key) {
        auth.setUser({ ...auth.user, public_key: me.public_key })
        selfPublicKey = me.public_key
      }
    }
    if (!selfPublicKey) throw new Error('Your public key is not loaded. Please log out and log back in.')

    const selfEncKey = await encryptWithPublicKey(selfPublicKey, aesBase64)

    // Encrypt AES key for each additional member
    const memberRows = await Promise.all(
      memberUsers.map(async (u) => ({
        user_id:              u.id,
        encrypted_group_key:  await encryptWithPublicKey(u.public_key, aesBase64),
      })),
    )

    const data = await api.post('/groups', {
      name,
      encrypted_group_key_self: selfEncKey,
      members: memberRows,
    })

    const groupId = data.group_id

    // Cache AES key immediately so the creator can send messages without round-trip
    _cacheGroupAesKey(groupId, aesKey)

    // Refresh group list so the new group appears in sidebar
    await fetchGroups()

    await openGroup(groupId)
    return groupId
  }

  /**
   * Add a new member to an existing group.
   * The caller decrypts the group key from their own row, re-encrypts for the new member.
   */
  async function addMember(groupId, peer) {
    const aesKey    = await _getGroupAesKey(groupId)
    if (!aesKey) throw new Error('Cannot load group key')
    const aesBase64 = await exportAESKey(aesKey)

    const encKey = await encryptWithPublicKey(peer.public_key, aesBase64)
    await api.post(`/groups/${groupId}/members`, {
      user_id:             peer.id,
      encrypted_group_key: encKey,
    })
  }

  /** Rename a group. */
  async function renameGroup(groupId, name) {
    const trimmed = String(name ?? '').trim()
    if (!trimmed) throw new Error('Group name is required')

    await api.patch(`/groups/${groupId}`, { name: trimmed })

    const g = groups.value.find((x) => x.id === groupId)
    if (g) g.name = trimmed
  }

  /** Remove a member from a group by user id. */
  async function removeMemberFromGroup(groupId, memberUserId) {
    await api.delete(`/groups/${groupId}/members/${memberUserId}`)
  }

  /**
   * Send an encrypted message to the active group.
   * For media/voice: encrypts file with group AES key, uploads, embeds {file_id, file_iv} in payload.
   * @param {string} plaintext
   * @param {{ blob: Blob, fileType: string } | null} attachment
   */
  async function sendMessage(plaintext, attachment = null, replyToId = null, onProgress = null) {
    const groupId = activeGroupId.value
    if (!groupId) throw new Error('No active group')

    const aesKey = await _getGroupAesKey(groupId)
    if (!aesKey) throw new Error('Group key not available')

    let messageType = 'text'
    let contentObj  = {}
    let mediaFileId = null, mediaFileType = null, mediaFileKey = null, mediaFileIV = null

    if (attachment) {
      // Encrypt file with group AES key directly (no per-file RSA wrapping for groups)
      const fileIv  = crypto.getRandomValues(new Uint8Array(12))
      const arrBuf  = await attachment.blob.arrayBuffer()
      const enc     = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: fileIv }, aesKey, arrBuf)
      const fileIVb64 = bufToBase64(fileIv)
      const encBlob = new Blob([enc], { type: 'application/octet-stream' })

      // Upload encrypted blob
      const uploaded = await api.uploadFile(encBlob, attachment.fileType, onProgress)
      mediaFileId   = uploaded.file_id
      mediaFileType = attachment.fileType
      mediaFileIV   = fileIVb64
      mediaFileKey  = await exportAESKey(aesKey)

      contentObj.file_id   = mediaFileId
      contentObj.file_type = mediaFileType
      contentObj.file_iv   = fileIVb64
      if (attachment.name) contentObj.file_name = attachment.name
      messageType = plaintext.trim()
        ? 'text_media'
        : (attachment.fileType === 'voice' ? 'voice' : 'media')
    }

    if (plaintext.trim()) contentObj.text = plaintext

    // Encrypt the content object (or plain text for text-only messages)
    const plain = messageType === 'text'
      ? plaintext
      : JSON.stringify(contentObj)

    const { ciphertext, iv }  = await encryptMessage(aesKey, plain)
    const encryptedContent     = JSON.stringify({ ciphertext, iv })

    const data = await api.post(`/groups/${groupId}/messages`, {
      encrypted_content: encryptedContent,
      message_type:      messageType,
      ...(replyToId ? { reply_to_id: replyToId } : {}),
    })

    // Optimistic append
    const optimistic = {
      id:                data.message_id,
      group_id:          groupId,
      sender_id:         auth.user?.id,
      reply_to_id:       replyToId ?? null,
      message_type:      messageType,
      text:              plaintext || null,
      mine:              true,
      encrypted_content: encryptedContent,
      created_at:        new Date().toISOString().replace('T', ' ').substring(0, 19),
      mediaFileId,
      mediaFileType,
      mediaFileKey,
      mediaFileIV,
      mediaFileName: attachment?.name ?? null,
    }

    if (!messages.value[groupId]) messages.value[groupId] = []
    messages.value[groupId].push(optimistic)

    const idx = groups.value.findIndex((g) => g.id === groupId)
    if (idx >= 0) {
      groups.value[idx].last_message_at        = optimistic.created_at
      groups.value[idx].last_encrypted_content = encryptedContent
      groups.value[idx].last_sender_id         = auth.user?.id
      groups.value[idx].lastMessageText        = plaintext || null
    }

    return data.message_id
  }

  /**
   * Apply group updates from GET /updates polling.
   * markRead is NOT called here — it is called on open and on close only.
   */
  async function applyGroupUpdates({ group_messages: newMsgs, groups: newGroups, group_read_receipts: newReceipts }) {
    if (newGroups) {
      // Merge to preserve lastMessageText and avoid flicker on every poll.
      for (const raw of newGroups) {
        const fresh = { ...raw, id: Number(raw.id), created_by: Number(raw.created_by) }
        const existing = groups.value.find((g) => g.id === fresh.id)
        if (existing) {
          const lastChanged = fresh.last_message_id !== existing.last_message_id
          Object.assign(existing, fresh)
          // Don't let the server overwrite unread_count while the user is reading this group
          if (existing.id === activeGroupId.value) existing.unread_count = 0
          if (lastChanged) _decryptPreview(existing)
        } else {
          groups.value.push(fresh)
          _decryptPreview(fresh)
        }
      }
      const freshIds = new Set(newGroups.map((g) => Number(g.id)))
      groups.value = groups.value.filter((g) => freshIds.has(g.id))
    }
    if (newReceipts) {
      for (const [groupId, receipts] of Object.entries(newReceipts)) {
        groupReadReceipts.value[groupId] = receipts
      }
    }

    if (!newMsgs?.length) return

    const autoRead = {} // groupId → highest new message id to mark as read

    for (const msg of newMsgs) {
      const groupId = Number(msg.group_id)
      // Only update groups that are already open/loaded — prevents polling
      // from pre-filling history and causing openGroup to skip loadMessages.
      if (!messages.value[groupId]) continue

      const existing = messages.value[groupId].find((m) => m.id === Number(msg.id))
      if (existing) {
        // Edit arrived — update text and is_edited in-place
        const dec = await _decryptMsg(msg)
        existing.text              = dec.text
        existing.is_edited         = dec.is_edited
        existing.encrypted_content = dec.encrypted_content
      } else {
        const pag = pagination.value[groupId]
        if (pag?.hasMoreBelow) {
          if (groupId === activeGroupId.value) {
            await loadInitial(groupId)
            const loaded = messages.value[groupId] ?? []
            const latest = loaded.at(-1)
            if (latest && Number(msg.sender_id) !== auth.user?.id) {
              autoRead[groupId] = Math.max(autoRead[groupId] ?? 0, latest.id)
            }
          } else {
            pag.pendingCount = (pag.pendingCount ?? 0) + 1
          }
        } else {
          const dec = await _decryptMsg(msg)
          messages.value[groupId].push(dec)
          if (groupId === activeGroupId.value && Number(msg.sender_id) !== auth.user?.id) {
            autoRead[groupId] = Math.max(autoRead[groupId] ?? 0, msg.id)
          }
        }
      }
    }

    // Mark active group as read for any newly arrived messages
    for (const [groupId, lastId] of Object.entries(autoRead)) {
      markRead(Number(groupId), lastId)
    }
  }

  /**
   * Edit own group message. Re-encrypts new plaintext with group AES key.
   * Updates local store immediately.
   */
  async function editMessage(messageId, newPlaintext) {
    // Find which group this message belongs to
    let groupId = null
    for (const gid of Object.keys(messages.value)) {
      if (messages.value[gid].find((m) => m.id === messageId)) {
        groupId = Number(gid)
        break
      }
    }
    if (!groupId) throw new Error('Group not found for this message')

    const aesKey = await _getGroupAesKey(groupId)
    if (!aesKey) throw new Error('Group key not available')

    const { ciphertext, iv } = await encryptMessage(aesKey, newPlaintext)
    const encryptedContent   = JSON.stringify({ ciphertext, iv })

    await api.patch(`/groups/${groupId}/messages/${messageId}`, { encrypted_content: encryptedContent })

    // Update local store
    const msg = messages.value[groupId]?.find((m) => m.id === messageId)
    if (msg) {
      msg.text              = newPlaintext
      msg.is_edited         = 1
      msg.encrypted_content = encryptedContent
    }
  }

  /** Delete own group message. Removes from local store immediately. */
  async function deleteMessage(messageId) {
    await api.delete(`/messages/${messageId}`)
    for (const groupId of Object.keys(messages.value)) {
      const idx = messages.value[groupId].findIndex((m) => m.id === messageId)
      if (idx >= 0) {
        messages.value[groupId].splice(idx, 1)
        break
      }
    }
  }

  /** Remove deleted messages from all cached groups. */
  function applyDeletions(deletedIds) {
    if (!deletedIds?.length) return
    const idSet = new Set(deletedIds.map(Number))
    for (const groupId of Object.keys(messages.value)) {
      messages.value[groupId] = messages.value[groupId].filter((m) => !idSet.has(m.id))
    }
  }

  return {
    groups,
    messages,
    pagination,
    scrollPositions,
    groupReadReceipts,
    activeGroupId,
    activeGroup,
    activeMessages,
    sortedGroups,
    fetchGroups,
    loadMessages,
    loadInitial,
    loadOlder,
    loadNewer,
    loadAroundMessage,
    openGroup,
    markRead,
    createGroup,
    addMember,
    renameGroup,
    removeMemberFromGroup,
    sendMessage,
    editMessage,
    deleteMessage,
    applyGroupUpdates,
    applyDeletions,
  }
})
