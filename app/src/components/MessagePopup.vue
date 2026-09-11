<!-- app/src/components/MessagePopup.vue -->
<!-- Bottom-sheet popup shown on long-press of a message bubble. -->
<template>
  <Teleport to="body">
    <!-- Backdrop -->
    <div
      class="chat-text-scaled safe-area-top safe-area-bottom safe-area-x fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center p-4"
      @click.self="$emit('close')"
    >
      <!-- Card -->
      <div
        class="w-full max-w-sm bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden"
        @click.stop
      >
        <!-- Close row -->
        <div class="flex items-center justify-between px-4 pt-4 pb-2">
          <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ msgTime(msg.created_at) }}</span>
          <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- ── Emoji picker ─────────────────────────────── -->
        <div class="px-4 pt-2 pb-3 border-b border-gray-100 dark:border-gray-800">
          <div class="flex justify-around">
            <button
              v-for="emoji in EMOJIS"
              :key="emoji"
              @click="onReact(emoji)"
              class="text-2xl p-1.5 rounded-xl transition-transform hover:scale-125 active:scale-110"
              :class="isReacted(emoji) ? 'bg-blue-100 dark:bg-blue-900/40' : 'hover:bg-gray-100 dark:hover:bg-gray-800'"
            >{{ emoji }}</button>
          </div>
        </div>

        <!-- ── Actions ──────────────────────────────────── -->
        <div class="grid grid-cols-5 divide-x divide-gray-100 dark:divide-gray-800 border-b border-gray-100 dark:border-gray-800">
          <!-- Reply -->
          <button
            @click="$emit('reply'); $emit('close')"
            class="flex flex-col items-center gap-1 py-3 text-blue-500 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6"/>
            </svg>
            <span class="text-xs font-medium">{{ $t('reply') }}</span>
          </button>

          <!-- Copy link -->
          <button
            @click="onCopyLink"
            class="flex flex-col items-center gap-1 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            :class="linkCopied ? 'text-green-500' : 'text-gray-500 dark:text-gray-400'"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
            </svg>
            <span class="text-xs font-medium">{{ linkCopied ? $t('linkCopied') : $t('copyLink') }}</span>
          </button>

          <!-- Copy text (only if message has text) -->
          <button
            v-if="msg.text"
            @click="onCopy"
            class="flex flex-col items-center gap-1 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            :class="copied ? 'text-green-500' : 'text-gray-500 dark:text-gray-400'"
          >
            <svg v-if="!copied" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <span class="text-xs font-medium">{{ copied ? $t('copied') : $t('copyText') }}</span>
          </button>
          <!-- Spacer when no text -->
          <div v-else />

          <!-- Edit (own text messages only) -->
          <button
            v-if="msg.mine && msg.text && msg.message_type === 'text'"
            @click="$emit('edit'); $emit('close')"
            class="flex flex-col items-center gap-1 py-3 text-amber-500 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            <span class="text-xs font-medium">{{ $t('edit') }}</span>
          </button>
          <!-- Spacer when no edit -->
          <div v-else />

          <!-- Delete (own messages only) -->
          <button
            v-if="msg.mine"
            @click="$emit('delete'); $emit('close')"
            class="flex flex-col items-center gap-1 py-3 text-red-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            <span class="text-xs font-medium">{{ $t('deleteMessage') }}</span>
          </button>
          <!-- Spacer when not own -->
          <div v-else />
        </div>

        <!-- ── Reactions (who reacted) ───────────────────── -->
        <div v-if="allReactions.length" class="px-4 pt-3 pb-2 border-b border-gray-100 dark:border-gray-800">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">{{ $t('reactions') }}</p>
          <div class="space-y-1.5 max-h-40 overflow-y-auto">
            <div v-for="r in allReactions" :key="r.emoji" class="flex items-start gap-2">
              <span class="text-lg w-7 flex-shrink-0 leading-tight">{{ r.emoji }}</span>
              <div class="flex-1 min-w-0 space-y-0.5">
                <div
                  v-for="u in r.by"
                  :key="typeof u === 'string' ? u : u.name"
                  class="flex items-center justify-between gap-2"
                >
                  <span class="text-xs text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 rounded-full px-2 py-0.5 truncate">
                    {{ typeof u === 'string' ? u : u.name }}
                  </span>
                  <span v-if="u.at" class="text-[10px] text-gray-400 tabular-nums flex-shrink-0">{{ formatAt(u.at) }}</span>
                </div>
              </div>
              <span class="text-xs text-gray-400 tabular-nums flex-shrink-0 pt-0.5">{{ r.count }}</span>
            </div>
          </div>
        </div>

        <!-- ── Read by ───────────────────────────────────── -->
        <div class="px-4 pt-3 pb-4">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">{{ $t('readBy') }}</p>

          <!-- Private chat -->
          <template v-if="!isGroup">
            <div v-if="peerHasRead" class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <span class="w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0">
                  {{ peerInitial }}
                </span>
                {{ peerName }}
              </div>
              <span v-if="peerReadAt" class="text-[11px] text-gray-400 tabular-nums flex-shrink-0">{{ formatAt(peerReadAt) }}</span>
            </div>
            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">{{ $t('notReadYet') }}</p>
          </template>

          <!-- Group chat -->
          <template v-else>
            <div v-if="groupReadersList.length" class="space-y-1.5">
              <div
                v-for="r in groupReadersList"
                :key="r.user_id"
                class="flex items-center justify-between gap-2"
              >
                <div class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                  <span class="w-5 h-5 rounded-full bg-emerald-500 flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0">
                    {{ r.name[0]?.toUpperCase() }}
                  </span>
                  {{ r.name }}
                </div>
                <span v-if="r.read_at" class="text-[11px] text-gray-400 tabular-nums flex-shrink-0">{{ formatAt(r.read_at) }}</span>
              </div>
            </div>
            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">{{ $t('notReadYet') }}</p>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useReactionsStore } from '@/store/reactions'
import { useAuthStore }      from '@/store/auth'
import { useSettingsStore }  from '@/store/settings'
import { t }                 from '@/lib/i18n'
import { api }               from '@/lib/api'

const props = defineProps({
  msg:           { type: Object, required: true },
  isGroup:       { type: Boolean, default: false },
  /** group readers list: [{ user_id, name, last_read_message_id }] */
  groupReaders:  { type: Array,   default: () => [] },
  peerName:      { type: String,  default: '' },
  peerRead:      { type: Boolean, default: false },
  convId:        { type: Number,  default: null },
  groupId:       { type: Number,  default: null },
  peerReadAt:    { type: String,  default: null },
})

const emit = defineEmits(['close', 'reply', 'edit', 'delete'])

const reactionsStore = useReactionsStore()
const settings       = useSettingsStore()
const auth           = useAuthStore()

const $t = (key) => t(key, settings.locale)

const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🔥', '👏', '🎉']

const copied = ref(false)
let copiedTimer = null

async function onCopy() {
  if (!props.msg.text) return
  try {
    await navigator.clipboard.writeText(props.msg.text)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // Fallback for browsers without clipboard API
    const ta = document.createElement('textarea')
    ta.value = props.msg.text
    ta.style.position = 'fixed'
    ta.style.opacity  = '0'
    document.body.appendChild(ta)
    ta.focus()
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copied.value = false }, 2000)
  }
}

onBeforeUnmount(() => clearTimeout(copiedTimer))

const linkCopied      = ref(false)
let   linkCopiedTimer = null

async function onCopyLink() {
  const base = window.location.origin + window.location.pathname
  const hash = props.isGroup
    ? `#/chat/group/${props.groupId}/m/${props.msg.id}`
    : `#/chat/private/${props.convId}/m/${props.msg.id}`
  try {
    await navigator.clipboard.writeText(base + hash)
  } catch {
    const ta = document.createElement('textarea')
    ta.value = base + hash
    ta.style.cssText = 'position:fixed;opacity:0'
    document.body.appendChild(ta)
    ta.focus(); ta.select(); document.execCommand('copy')
    document.body.removeChild(ta)
  }
  linkCopied.value = true
  clearTimeout(linkCopiedTimer)
  linkCopiedTimer = setTimeout(() => { linkCopied.value = false }, 2000)
  emit('close')
}

onBeforeUnmount(() => { clearTimeout(linkCopiedTimer) })

// Detailed reactions with names — fetched fresh when popup opens
const detailedReactions = ref([])

onMounted(async () => {
  await refreshReactions()
})

async function refreshReactions() {
  try {
    const data = await api.get(`/messages/${props.msg.id}/reactions`)
    detailedReactions.value = data.reactions ?? []
  } catch { /* keep store data */ }
}

// Prefer freshly fetched detailed data; fall back to store (which now also has names)
const allReactions = computed(() =>
  detailedReactions.value.length
    ? detailedReactions.value
    : reactionsStore.forMessage(props.msg.id),
)

function isReacted(emoji) {
  const r = allReactions.value.find((r) => r.emoji === emoji)
  return r?.reacted_by_me ?? false
}

async function onReact(emoji) {
  try {
    await reactionsStore.toggle(props.msg.id, emoji)
  } catch (e) {
    console.error('Reaction toggle failed:', e)
  } finally {
    emit('close')
  }
}

function msgTime(dt) {
  return new Date(String(dt ?? '').replace(' ', 'T') + 'Z').toLocaleTimeString(
    settings.locale === 'fa' ? 'fa-IR' : 'en-GB',
    { hour: '2-digit', minute: '2-digit' },
  )
}

function formatAt(dt) {
  if (!dt) return ''
  const d   = new Date(String(dt).replace(' ', 'T') + 'Z')
  const loc = settings.locale === 'fa' ? 'fa-IR' : 'en-GB'
  const now = new Date()
  const sameDay = d.toDateString() === now.toDateString()
  if (sameDay) {
    return d.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' })
  }
  return d.toLocaleDateString(loc, { day: 'numeric', month: 'short' }) + ' ' +
         d.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' })
}

// ── Read receipts ───────────────────────────────────────────────────────────

const peerHasRead = computed(() => props.peerRead)
const peerInitial = computed(() => props.peerName?.[0]?.toUpperCase() ?? '?')

const groupReadersList = computed(() =>
  props.groupReaders.filter(
    (r) => String(r.user_id) !== String(auth.user?.id) && (r.last_read_message_id ?? 0) >= props.msg.id,
  ),
)
</script>
