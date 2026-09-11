// app/src/store/reactions.js
import { defineStore } from 'pinia'
import { ref }         from 'vue'
import { api }         from '@/lib/api'

export const useReactionsStore = defineStore('reactions', () => {
  /**
   * reactions map: { [messageId]: [{ emoji, count, reacted_by_me }] }
   */
  const reactions = ref({})
  const pendingToggles = new Map()

  function enqueueToggle(messageId, task) {
    const prev = pendingToggles.get(messageId) ?? Promise.resolve()
    const next = prev
      .catch(() => {})
      .then(task)
      .finally(() => {
        if (pendingToggles.get(messageId) === next) {
          pendingToggles.delete(messageId)
        }
      })
    pendingToggles.set(messageId, next)
    return next
  }

  /** Toggle a reaction on a message. */
  async function toggle(messageId, emoji) {
    return enqueueToggle(messageId, async () => {
      const current = reactions.value[messageId] ?? []
      const existing = current.find((r) => r.emoji === emoji)

      if (existing?.reacted_by_me) {
        const data = await api.delete(`/messages/${messageId}/reactions/${encodeURIComponent(emoji)}`)
        reactions.value[messageId] = data.reactions
      } else {
        const data = await api.post(`/messages/${messageId}/reactions`, { emoji })
        reactions.value[messageId] = data.reactions
      }
    })
  }

  /** Load reactions for a list of message ids — one batch request instead of N. */
  async function loadForMessages(messageIds) {
    if (!messageIds?.length) return
    try {
      const ids  = messageIds.join(',')
      const data = await api.get(`/reactions/batch?ids=${ids}`)
      // Merge into store — only messages with reactions are returned
      for (const [msgId, reactionList] of Object.entries(data.reactions ?? {})) {
        reactions.value[parseInt(msgId)] = reactionList
      }
    } catch { /* silently skip */ }
  }

  /**
   * Apply reaction updates from GET /updates polling.
   * @param {{ [messageId]: Array }} newReactions — keyed by message id (numbers as strings)
   */
  function applyUpdates(newReactions) {
    if (!newReactions) return
    for (const [msgId, reactionList] of Object.entries(newReactions)) {
      reactions.value[parseInt(msgId)] = reactionList
    }
  }

  /** Get reactions for a single message. */
  function forMessage(messageId) {
    return reactions.value[messageId] ?? []
  }

  return { reactions, toggle, loadForMessages, applyUpdates, forMessage }
})
