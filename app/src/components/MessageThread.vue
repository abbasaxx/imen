<!-- app/src/components/MessageThread.vue -->
<!-- Scrollable decrypted message list — works for both private and group conversations -->
<template>
  <div class="flex flex-col h-full overflow-hidden">

    <!-- ── Header ───────────────────────────────────────────── -->
    <div class="flex items-center gap-3 px-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
      <!-- Back (mobile) -->
      <button
        @click="$emit('back')"
        class="md:hidden p-1.5 -ms-1 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            :d="isRTL ? 'M9 5l7 7-7 7' : 'M15 19l-7-7 7-7'"/>
        </svg>
      </button>

      <!-- Avatar -->
      <button
        v-if="isGroup"
        @click="$emit('edit-group')"
        class="w-9 h-9 rounded-full flex items-center justify-center text-white font-semibold text-sm flex-shrink-0 bg-emerald-500 hover:bg-emerald-600 transition-colors"
        :title="canEditGroup ? $t('editGroup') : $t('groupMembers')"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
      </button>
      <div
        v-else
        class="w-9 h-9 rounded-full flex items-center justify-center text-white font-semibold text-sm flex-shrink-0 bg-indigo-500"
      >
        <span>{{ initial(threadTitle) }}</span>
      </div>

      <div class="flex-1 min-w-0">
        <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ threadTitle }}</div>
        <div class="text-xs text-green-500">{{ $t(isGroup ? 'groupChat' : 'encrypted') }}</div>
      </div>
    </div>

    <!-- ── No-private-key warning ────────────────────────────── -->
    <div v-if="!auth.hasPrivateKey"
         class="mx-4 mt-3 flex items-center gap-2 px-3 py-2 bg-yellow-50 dark:bg-yellow-900/20
                border border-yellow-200 dark:border-yellow-700 rounded-lg text-xs text-yellow-700 dark:text-yellow-400 flex-shrink-0">
      <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
      </svg>
      <span>{{ $t('noKeyWarning') }}</span>
    </div>

    <!-- ── Message list ──────────────────────────────────────── -->
    <div class="relative flex-1 min-h-0">
    <div ref="scrollEl" class="h-full overflow-y-auto px-4 py-3 space-y-3" @scroll="onScroll">

      <!-- Load-older spinner -->
      <div v-if="currentPagination?.loadingAbove" class="flex justify-center py-3">
        <svg class="animate-spin w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
        </svg>
      </div>

      <div v-if="!threadMessages.length" class="flex items-center justify-center h-full text-sm text-gray-400 dark:text-gray-500">
        {{ $t('noMessages') }}
      </div>

      <template v-for="(msg, idx) in threadMessages" :key="msg.id">

        <!-- Date divider -->
        <div v-if="showDateDivider(idx)" class="flex items-center justify-center my-3">
          <span class="text-[11px] bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-full px-3 py-0.5">
            {{ msgDate(msg.created_at) }}
          </span>
        </div>

        <!-- Unread divider -->
        <div v-if="isFirstUnread(msg)" class="flex items-center gap-2 my-1">
          <div class="flex-1 h-px bg-blue-400/40"></div>
          <span class="text-[11px] text-blue-500 dark:text-blue-400 font-medium px-1">{{ $t('newMessages') }}</span>
          <div class="flex-1 h-px bg-blue-400/40"></div>
        </div>

        <!-- Message bubble wrapper -->
        <div :id="`msg-${msg.id}`" :data-msg-id="msg.id" :class="['flex flex-col', msg.mine ? 'items-end' : 'items-start', highlightedMsgId === msg.id ? 'reply-highlight' : '']">
          <div
            :class="[
              'rounded-2xl px-3.5 py-2 text-sm leading-relaxed shadow-sm select-none',
              isVisualMedia(msg) ? 'w-[90%] max-w-[90%]' : 'max-w-[90%]',
              msg.mine
                ? 'bg-blue-500 text-white rounded-br-sm'
                : 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white rounded-bl-sm',
              longPressTarget === msg.id ? 'opacity-75 scale-95' : '',
              'transition-all duration-100',
            ]"
            :dir="textDir(msg.text)"
            @click.stop="popupMsg = msg"
            @pointerdown="startLongPress(msg)"
            @pointerup="cancelLongPress"
            @pointermove="cancelLongPress"
            @pointercancel="cancelLongPress"
            @contextmenu.prevent
          >
            <!-- Group: show sender name above their messages -->
            <p v-if="isGroup && !msg.mine" class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 mb-0.5">
              {{ senderName(msg.sender_id) }}
            </p>

            <!-- Reply quote strip — click scrolls to original, stops bubble popup -->
            <div
              v-if="msg.reply_to_id"
              :class="[
                'mb-1.5 px-2 py-1 rounded-lg text-[11px] border-s-2 opacity-80 cursor-pointer',
                msg.mine
                  ? 'bg-blue-400/40 border-white/60 text-blue-100'
                  : 'bg-gray-100 dark:bg-gray-700 border-blue-400 text-gray-600 dark:text-gray-300',
              ]"
              @click.stop="scrollToReply(msg.reply_to_id)"
              @pointerdown.stop
              @pointerup.stop
              @pointercancel.stop
            >
              <span class="font-semibold block">{{ quotedSenderName(msg) }}</span>
              <span class="reply-quote-text break-words">{{ quotedText(msg) }}</span>
            </div>

            <!-- Media content (shown above text for media_text messages) -->
            <MediaPlayer
              v-if="msg.mediaFileId && msg.mediaFileKey"
              :fileId="msg.mediaFileId"
              :fileType="msg.mediaFileType"
              :fileKey="msg.mediaFileKey"
              :fileIV="msg.mediaFileIV"
              :fileName="msg.mediaFileName"
              :mine="msg.mine"
              :bleed="isVisualMedia(msg)"
              :textBelow="!!msg.text"
            />

            <!-- Text content -->
            <p
              v-if="msg.text"
              :class="['whitespace-pre-wrap break-words', msg.mediaFileId ? 'pt-1' : '']"
              :dir="textDir(msg.text)"
              :style="textDir(msg.text) === 'rtl' ? 'text-align:right;unicode-bidi:plaintext' : 'text-align:left;unicode-bidi:plaintext'"
            >{{ msg.text }}</p>
            <p v-else-if="!msg.mediaFileId" class="italic opacity-60 text-xs">{{ $t('encryptedMessage') }}</p>

            <div :class="['text-[10px] mt-1 text-end flex items-center justify-end gap-0.5', msg.mine ? 'text-blue-200' : 'text-gray-400 dark:text-gray-500']">
              <span v-if="msg.is_edited" class="italic me-1">{{ $t('edited') }}</span>
              {{ msgTime(msg.created_at) }}
              <!-- Tick(s) for own messages -->
              <template v-if="msg.mine">
                <template v-if="!isGroup">
                  <svg v-if="isPeerRead(msg)" class="w-4 h-3 inline flex-shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M1 12l5 5L17 5M7 12l5 5L23 5"/>
                  </svg>
                  <svg v-else class="w-3 h-3 inline flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                  </svg>
                </template>
                <template v-else>
                  <svg v-if="groupReadCount(msg) > 0" class="w-4 h-3 inline flex-shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M1 12l5 5L17 5M7 12l5 5L23 5"/>
                  </svg>
                  <svg v-else class="w-3 h-3 inline flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                  </svg>
                </template>
              </template>
            </div>
          </div>

          <!-- Reaction pills (always visible) -->
          <EmojiReactions :messageId="msg.id" class="max-w-[90%]" />
        </div>
      </template>

      <!-- Load-newer spinner -->
      <div v-if="currentPagination?.loadingBelow" class="flex justify-center py-3">
        <svg class="animate-spin w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
        </svg>
      </div>
    </div>

    <!-- Scroll-to-bottom / jump-to-latest FAB -->
    <button
      v-if="!atBottom || currentPagination?.hasMoreBelow"
      @click="currentPagination?.hasMoreBelow ? jumpToLatest() : scrollToBottom()"
      class="absolute bottom-3 end-3 w-9 h-9 rounded-full bg-white dark:bg-gray-700 shadow-lg border border-gray-200 dark:border-gray-600 flex items-center justify-center text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors z-10"
    >
      <span
        v-if="currentPagination?.pendingCount > 0"
        class="absolute -top-1 -end-1 min-w-[16px] h-4 bg-red-500 rounded-full text-[9px] text-white flex items-center justify-center px-0.5"
      >{{ currentPagination.pendingCount > 9 ? '9+' : currentPagination.pendingCount }}</span>
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
      </svg>
    </button>
    </div>

    <!-- Message popup (long-press) -->
    <MessagePopup
      v-if="popupMsg"
      :msg="popupMsg"
      :isGroup="isGroup"
      :convId="chats.activeConvId"
      :groupId="groups.activeGroupId"
      :groupReaders="groups.groupReadReceipts[groups.activeGroupId] ?? []"
      :peerName="chats.activeConversation?.peer_name ?? ''"
      :peerRead="isPeerRead(popupMsg)"
      :peerReadAt="chats.activeConversation?.peer_read_at ?? null"
      @close="popupMsg = null"
      @reply="onReply(popupMsg)"
      @edit="onEdit(popupMsg)"
      @delete="onDelete(popupMsg.id)"
    />
  </div>
</template>

<script setup>
import { ref, watch, nextTick, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useChatsStore }     from '@/store/chats'
import { useGroupsStore }    from '@/store/groups'
import { useAuthStore }      from '@/store/auth'
import { useSettingsStore }  from '@/store/settings'
import { useReactionsStore } from '@/store/reactions'
import { t, getDir }        from '@/lib/i18n'
import { api }              from '@/lib/api'
import MediaPlayer          from './MediaPlayer.vue'
import EmojiReactions       from './EmojiReactions.vue'
import MessagePopup         from './MessagePopup.vue'

const emit = defineEmits(['back', 'reply', 'edit', 'edit-group'])

const chats          = useChatsStore()
const groups         = useGroupsStore()
const auth           = useAuthStore()
const settings       = useSettingsStore()
const reactionsStore = useReactionsStore()

const scrollEl        = ref(null)
const atBottom        = ref(true)
const popupMsg        = ref(null)
const longPressTarget = ref(null)
let   longPressTimer  = null
const highlightedMsgId = ref(null)

const route = useRoute()

const currentPagination = computed(() => {
  if (isGroup.value) return groups.pagination[groups.activeGroupId] ?? null
  return chats.pagination[chats.activeConvId] ?? null
})

const $t    = (key) => t(key, settings.locale)
const isRTL = computed(() => getDir(settings.locale) === 'rtl')

const isGroup     = computed(() => groups.activeGroupId !== null)
const canEditGroup = computed(() => groups.activeGroup?.created_by === auth.user?.id)
const threadTitle = computed(() => {
  if (isGroup.value) return groups.activeGroup?.name ?? ''
  return chats.activeConversation?.peer_name ?? ''
})
const threadMessages = computed(() =>
  isGroup.value ? groups.activeMessages : chats.activeMessages,
)

// ── Long press ──────────────────────────────────────────────────────────────

function startLongPress(msg) {
  cancelLongPress()
  longPressTarget.value = msg.id
  longPressTimer = setTimeout(() => {
    popupMsg.value       = msg
    longPressTarget.value = null
  }, 500)
}

function cancelLongPress() {
  clearTimeout(longPressTimer)
  longPressTarget.value = null
}

// ── Scroll ──────────────────────────────────────────────────────────────────

function scrollToBottom() {
  if (scrollEl.value) {
    scrollEl.value.scrollTop = scrollEl.value.scrollHeight
    atBottom.value = true
  }
}

async function jumpToLatest() {
  if (isGroup.value) await groups.loadInitial(groups.activeGroupId)
  else               await chats.loadInitial(chats.activeConvId)
  await nextTick()
  scrollToBottom()
}

let _loadingOlder = false
let _loadingNewer = false

async function _loadOlder() {
  if (_loadingOlder) return
  _loadingOlder = true
  try {
    const oldHeight = scrollEl.value?.scrollHeight ?? 0
    if (isGroup.value) await groups.loadOlder(groups.activeGroupId)
    else               await chats.loadOlder(chats.activeConvId)
    await nextTick()
    if (scrollEl.value) scrollEl.value.scrollTop += scrollEl.value.scrollHeight - oldHeight
  } finally {
    _loadingOlder = false
  }
}

async function _loadNewer() {
  if (_loadingNewer) return
  _loadingNewer = true
  try {
    if (isGroup.value) await groups.loadNewer(groups.activeGroupId)
    else               await chats.loadNewer(chats.activeConvId)
  } finally {
    _loadingNewer = false
  }
}

/** Called after scroll setup — fills viewport if initial window doesn't overflow. */
async function _fillViewportIfNeeded() {
  await nextTick()
  const el = scrollEl.value
  if (el && currentPagination.value?.hasMoreAbove && el.scrollHeight <= el.clientHeight + 10) {
    await _loadOlder()
    await _fillViewportIfNeeded()
  }
}

function onScroll() {
  const el = scrollEl.value
  if (!el) return
  atBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 60

  if (el.scrollTop <= 150) _loadOlder()
  if (el.scrollHeight - el.scrollTop - el.clientHeight <= 150) _loadNewer()
}

// ── Unread tracking ─────────────────────────────────────────────────────────

const firstUnreadId = computed(() => {
  if (!isGroup.value) return null
  const lastReadId = groups.activeGroup?.last_read_message_id ?? 0
  if (lastReadId === 0) return null
  const first = threadMessages.value.find((m) => m.id > lastReadId && !m.mine)
  return first?.id ?? null
})

function isFirstUnread(msg) {
  return firstUnreadId.value !== null && msg.id === firstUnreadId.value
}

function offsetFromContainer(el, container) {
  // Walk up offsetParent chain to get el's top relative to the scroll container
  let offset = 0
  let cur = el
  while (cur && cur !== container) {
    offset += cur.offsetTop
    cur = cur.offsetParent
  }
  return offset
}

function scrollToFirstUnread() {
  const doScroll = () => {
    if (!scrollEl.value) { scrollToBottom(); return }
    if (firstUnreadId.value) {
      const el = scrollEl.value.querySelector(`[data-msg-id="${firstUnreadId.value}"]`)
      if (el) {
        scrollEl.value.scrollTop = offsetFromContainer(el, scrollEl.value) - 24
        atBottom.value = false
        return
      }
    }
    scrollToBottom()
  }
  // First attempt immediately after nextTick
  doScroll()
  // Second attempt after iOS finishes layout (fonts, images, safe-area)
  setTimeout(doScroll, 200)
}

async function scrollToReply(replyToId) {
  const inWindow = threadMessages.value.some((m) => m.id === replyToId)

  if (!inWindow) {
    if (isGroup.value) await groups.loadAroundMessage(groups.activeGroupId, replyToId)
    else               await chats.loadAroundMessage(chats.activeConvId, replyToId)
    await nextTick()
  }

  const el = scrollEl.value?.querySelector(`#msg-${replyToId}`)
  if (!el) return

  el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  highlightedMsgId.value = replyToId
  setTimeout(() => { highlightedMsgId.value = null }, 1200)
}

// ── Reactions / messages watch ──────────────────────────────────────────────

let justOpened = false

// Fires when the active chat changes (switching conversations / groups).
watch(
  () => [chats.activeConvId, groups.activeGroupId],
  async ([newConvId, newGroupId], old) => {
    const [oldConvId, oldGroupId] = old ?? []
    // Save scroll position for the departing conversation
    if (oldConvId && scrollEl.value) chats.scrollPositions[oldConvId] = scrollEl.value.scrollTop
    if (oldGroupId && scrollEl.value) groups.scrollPositions[oldGroupId] = scrollEl.value.scrollTop

    popupMsg.value = null
    atBottom.value = false

    if (threadMessages.value.length > 0) {
      justOpened = false
      await nextTick()
      const ids = threadMessages.value.map((m) => m.id)
      if (ids.length) reactionsStore.loadForMessages(ids)

      // Restore saved scroll position if available
      const savedPos = isGroup.value
        ? groups.scrollPositions[groups.activeGroupId]
        : chats.scrollPositions[chats.activeConvId]

      if (savedPos != null && scrollEl.value) {
        scrollEl.value.scrollTop = savedPos
      } else {
        scrollToFirstUnread()
      }
      _fillViewportIfNeeded()
    } else {
      justOpened = true
    }
  },
  { immediate: true },
)

// Fires on every length change: initial load AND push from polling.
watch(
  () => threadMessages.value.length,
  async (newLen, oldLen) => {
    await nextTick()

    if (justOpened && newLen > 0) {
      justOpened = false
      const ids = threadMessages.value.map((m) => m.id)
      if (ids.length) reactionsStore.loadForMessages(ids)

      const deepMsgId = route.params.messageId
      if (deepMsgId && scrollEl.value) {
        const el = scrollEl.value.querySelector(`#msg-${deepMsgId}`)
        if (el) {
          el.scrollIntoView({ block: 'center' })
          el.closest('[id^="msg-"]')?.classList.add('highlight-message')
          setTimeout(() => el.closest('[id^="msg-"]')?.classList.remove('highlight-message'), 1000)
          return
        }
      }
      scrollToFirstUnread()
      _fillViewportIfNeeded()
      return
    }

    if (newLen > oldLen) {
      if (atBottom.value && scrollEl.value) {
        scrollEl.value.scrollTop = scrollEl.value.scrollHeight
        setTimeout(() => {
          if (atBottom.value && scrollEl.value)
            scrollEl.value.scrollTop = scrollEl.value.scrollHeight
        }, 350)
      }
      const newItems = threadMessages.value.slice(oldLen)
      reactionsStore.loadForMessages(newItems.map((m) => m.id))
    }
  },
  { flush: 'post' },
)

// ── Group member names ───────────────────────────────────────────────────────

const groupMembersCache = ref([])
watch(
  () => groups.activeGroupId,
  async (groupId) => {
    if (!groupId) { groupMembersCache.value = []; return }
    try {
      const data = await api.get(`/groups/${groupId}/members`)
      groupMembersCache.value = data.members
    } catch { groupMembersCache.value = [] }
  },
  { immediate: true },
)
function senderName(senderId) {
  const m = groupMembersCache.value.find((u) => u.id === senderId)
  return m?.name ?? `#${senderId}`
}

// ── Actions ──────────────────────────────────────────────────────────────────

function onReply(msg) {
  popupMsg.value = null
  emit('reply', msg)
}

function onEdit(msg) {
  popupMsg.value = null
  emit('edit', msg)
}

async function onDelete(messageId) {
  popupMsg.value = null
  try {
    if (isGroup.value) await groups.deleteMessage(messageId)
    else               await chats.deleteMessage(messageId)
  } catch (e) { console.error('Delete failed:', e) }
}

// ── Read receipts ────────────────────────────────────────────────────────────

function isPeerRead(msg) {
  if (!msg) return false
  const conv = chats.activeConversation
  if (!conv) return false
  return msg.id <= (conv.peer_last_read_message_id ?? 0)
}

function groupReaders(msg) {
  const receipts = groups.groupReadReceipts[groups.activeGroupId] ?? []
  return receipts.filter(
    (r) => Number(r.user_id) !== auth.user?.id && (r.last_read_message_id ?? 0) >= msg.id,
  )
}
function groupReadCount(msg) { return groupReaders(msg).length }

// ── Quoted message helpers ───────────────────────────────────────────────────

function quotedText(msg) {
  // Use pre-decrypted snippet attached to the message by _decryptMsg
  if (msg.replySenderId !== null && msg.replySenderId !== undefined) {
    if (msg.replyText) return msg.replyText
    if (msg.replyFileType) return `[${msg.replyFileType}]`
    return '...'
  }
  // Fallback: search current loaded window (optimistic messages, old data)
  const ref = threadMessages.value.find((m) => m.id === msg.reply_to_id)
  if (!ref) return '...'
  if (ref.text) return ref.text
  if (ref.mediaFileId) return `[${ref.mediaFileType ?? 'media'}]`
  return '...'
}

function quotedSenderName(msg) {
  const senderId = msg.replySenderId
    ?? threadMessages.value.find((m) => m.id === msg.reply_to_id)?.sender_id
  if (!senderId) return ''
  if (String(senderId) === String(auth.user?.id)) return $t('you')
  if (isGroup.value) return senderName(senderId)
  return chats.activeConversation?.peer_name ?? ''
}

// ── Formatting ───────────────────────────────────────────────────────────────

function initial(name) { return (name || '?')[0].toUpperCase() }
function isVisualMedia(msg) {
  return msg?.mediaFileId && (msg.mediaFileType === 'image' || msg.mediaFileType === 'video')
}

// Detect Farsi/Arabic characters → RTL, otherwise LTR
const RTL_RE = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
function textDir(text) { return RTL_RE.test(text) ? 'rtl' : 'ltr' }
function parseDate(dt) { return new Date(String(dt ?? '').replace(' ', 'T') + 'Z') }

function msgTime(dt) {
  return parseDate(dt).toLocaleTimeString(
    settings.locale === 'fa' ? 'fa-IR' : 'en-GB',
    { hour: '2-digit', minute: '2-digit' },
  )
}

function msgDate(dt) {
  const d   = parseDate(dt)
  const now = new Date()
  if (d.toDateString() === now.toDateString()) return $t('today')
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (d.toDateString() === yesterday.toDateString()) return $t('yesterday')
  return d.toLocaleDateString(settings.locale === 'fa' ? 'fa-IR' : 'en-GB', {
    day: 'numeric', month: 'long', year: 'numeric',
  })
}

function showDateDivider(idx) {
  if (idx === 0) return true
  const msgs = threadMessages.value
  return parseDate(msgs[idx - 1].created_at).toDateString() !== parseDate(msgs[idx].created_at).toDateString()
}
</script>

<style scoped>
.reply-quote-text {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  white-space: normal;
}

.reply-highlight {
  animation: reply-flash 1.2s ease-out;
}

@keyframes reply-flash {
  0%   { background-color: transparent; }
  20%  { background-color: rgba(99, 179, 237, 0.35); }
  100% { background-color: transparent; }
}
</style>
