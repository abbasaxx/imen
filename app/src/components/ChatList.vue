<!-- app/src/components/ChatList.vue -->
<!-- Sidebar: merged private + group conversation list -->
<template>
  <div class="flex flex-col h-full bg-white dark:bg-gray-900">

    <!-- ── Private chat search panel ──────────────────────────── -->
    <div v-if="showSearch" class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
      <input
        ref="searchInput"
        v-model="searchQuery"
        @keydown.enter.prevent="doSearch"
        @keydown.escape="closeSearch"
        type="email"
        dir="ltr"
        :placeholder="$t('searchEmail')"
        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600
               bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white
               focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
      />
      <div v-if="searchResults.length" class="mt-1 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden bg-white dark:bg-gray-800">
        <button
          v-for="u in searchResults"
          :key="u.id"
          @click="beginChat(u)"
          class="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-start"
        >
          <div class="avatar-circle flex-shrink-0 text-sm">{{ initial(u.name) }}</div>
          <div class="min-w-0">
            <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ u.name }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ u.email }}</div>
          </div>
        </button>
      </div>
      <p v-if="searchError" class="mt-1 text-xs text-red-500">{{ searchError }}</p>
      <button @click="$emit('close-search')" class="mt-2 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
        {{ $t('cancel') }}
      </button>
    </div>

    <!-- ── Merged chat list ────────────────────────────────────── -->
    <div class="flex-1 overflow-y-auto">

      <div v-if="!allChats.length" class="flex items-center justify-center h-full text-sm text-gray-400 dark:text-gray-500 px-6 text-center">
        {{ $t('noChats') }}
      </div>

      <button
        v-for="item in allChats"
        :key="item._type + item.id"
        @click="$emit('select', { id: item.id, type: item._type })"
        class="w-full flex items-center gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800 text-start transition-colors"
        :class="isActive(item) ? 'bg-blue-50 dark:bg-blue-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800'"
      >
        <!-- Avatar / group icon -->
        <div
          class="w-11 h-11 rounded-full flex items-center justify-center text-white font-semibold flex-shrink-0"
          :class="item._type === 'group' ? 'bg-emerald-500' : 'bg-indigo-500'"
        >
          <span v-if="item._type === 'private'">{{ initial(item.peer_name) }}</span>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <div class="flex items-baseline justify-between gap-1">
            <span class="text-sm font-medium text-gray-900 dark:text-white truncate">
              {{ item._type === 'private' ? item.peer_name : item.name }}
            </span>
            <span class="text-[11px] text-gray-400 dark:text-gray-500 flex-shrink-0">
              {{ formatTime(item.last_message_at) }}
            </span>
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
            <template v-if="item.last_encrypted_content">
              <span v-if="item.last_sender_id === auth.user?.id" class="text-gray-400">{{ $t('you') }}: </span>
              <span v-if="item.lastMessageText">{{ item.lastMessageText }}</span>
              <span v-else class="italic opacity-60">{{ $t('encryptedMessage') }}</span>
            </template>
            <template v-else>{{ $t('noMessages') }}</template>
          </div>
        </div>

        <!-- Unread badge -->
        <div v-if="item.unread_count > 0" class="flex-shrink-0">
          <span class="bg-blue-500 text-white text-[11px] font-semibold rounded-full px-1.5 py-0.5 min-w-[20px] text-center block">
            {{ item.unread_count > 99 ? '99+' : item.unread_count }}
          </span>
        </div>
      </button>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useChatsStore }   from '@/store/chats'
import { useGroupsStore }  from '@/store/groups'
import { useAuthStore }    from '@/store/auth'
import { useSettingsStore } from '@/store/settings'
import { t }               from '@/lib/i18n'
import { api }             from '@/lib/api'

const props = defineProps({
  showSearch: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'close-search'])

const chats    = useChatsStore()
const groups   = useGroupsStore()
const auth     = useAuthStore()
const settings = useSettingsStore()

const $t = (key) => t(key, settings.locale)

// ── Merged sorted list of private convs + groups ───────────────────────────
const allChats = computed(() => {
  const privates = chats.conversations.map((c) => ({ ...c, _type: 'private' }))
  const grouped  = groups.groups.map((g) => ({ ...g, _type: 'group' }))
  return [...privates, ...grouped].sort((a, b) => {
    const ta = a.last_message_at ?? a.created_at ?? ''
    const tb = b.last_message_at ?? b.created_at ?? ''
    return tb > ta ? 1 : -1
  })
})

function isActive(item) {
  if (item._type === 'private') return chats.activeConvId === item.id
  return groups.activeGroupId === item.id
}

// ── Private chat search ───────────────────────────────────────────────────
const searchQuery   = ref('')
const searchResults = ref([])
const searchError   = ref('')
const searchInput   = ref(null)

watch(() => props.showSearch, async (val) => {
  if (val) {
    searchQuery.value   = ''
    searchResults.value = []
    searchError.value   = ''
    await nextTick()
    searchInput.value?.focus()
  }
})

function closeSearch() {
  searchQuery.value   = ''
  searchResults.value = []
  searchError.value   = ''
  emit('close-search')
}

async function doSearch() {
  const email = searchQuery.value.trim()
  searchError.value   = ''
  searchResults.value = []
  if (!email) return
  try {
    const data = await api.get(`/users/search?q=${encodeURIComponent(email)}`)
    // Only show exact email match for security
    const exact = (data.users ?? []).filter((u) => u.email.toLowerCase() === email.toLowerCase())
    searchResults.value = exact
    if (!exact.length) searchError.value = $t('userNotFound')
  } catch (err) {
    searchError.value = err.message ?? 'Search failed'
  }
}

async function beginChat(user) {
  closeSearch()
  await chats.startConversation(user.id)
  emit('select', { id: chats.activeConvId, type: 'private' })
}

// ── Formatting helpers ────────────────────────────────────────────────────
function initial(name) { return (name || '?')[0].toUpperCase() }

function formatTime(dt) {
  if (!dt) return ''
  const d   = new Date(String(dt).replace(' ', 'T') + 'Z')
  const now = new Date()
  if (d.toDateString() === now.toDateString()) {
    return d.toLocaleTimeString(settings.locale === 'fa' ? 'fa-IR' : 'en-GB', { hour: '2-digit', minute: '2-digit' })
  }
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (d.toDateString() === yesterday.toDateString()) return $t('yesterday')
  return d.toLocaleDateString(settings.locale === 'fa' ? 'fa-IR' : 'en-GB', { day: 'numeric', month: 'short' })
}
</script>

<style scoped>
.avatar-circle {
  @apply w-11 h-11 rounded-full bg-indigo-500 flex items-center justify-center text-white font-semibold;
}
</style>
