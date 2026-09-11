<!-- app/src/views/Chat.vue -->
<!-- Full Telegram-style layout — handles both private and group conversations -->
<template>
  <div class="chat-text-scaled app-viewport safe-area-top safe-area-x flex flex-col bg-gray-100 dark:bg-gray-950 overflow-hidden" :dir="dir">


    <!-- Offline banner -->
    <div
      v-if="!connected"
      class="flex-shrink-0 flex items-center justify-center gap-2 bg-yellow-400 dark:bg-yellow-600 text-yellow-900 dark:text-white text-xs py-1.5 px-4"
    >
      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
      </svg>
      {{ $t('offline') }}
    </div>

    <div class="flex flex-1 min-h-0 overflow-hidden">

    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <aside
      :class="[
        'relative flex-shrink-0 border-e border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900',
        'transition-all duration-200',
        hasActive ? 'hidden md:flex md:w-72 lg:w-80' : 'flex w-full md:w-72 lg:w-80',
        'flex-col',
      ]"
    >
      <!-- App bar -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
        <img
          :src="settings.locale === 'fa' ? logoFa : logoEn"
          alt="Imen"
          class="h-10 w-auto"
        />
        <div class="flex items-center gap-1">
          <!-- Settings -->
          <button
            @click="settingsOpen = true"
            class="p-2 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            :title="$t('settings')"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
          </button>
        </div>
      </div>

      <ChatList
        class="flex-1 min-h-0"
        :show-search="newChatOpen"
        @select="onSelect"
        @close-search="newChatOpen = false"
      />

      <!-- ── Floating Action Button ───────────────────────────── -->
      <div class="absolute bottom-6 end-6 z-20 flex flex-col items-end gap-3" :class="hasActive ? 'hidden md:flex' : 'flex'">
        <!-- FAB menu items -->
        <transition
          enter-active-class="transition-all duration-200 ease-out"
          enter-from-class="opacity-0 translate-y-2 scale-95"
          enter-to-class="opacity-100 translate-y-0 scale-100"
          leave-active-class="transition-all duration-150 ease-in"
          leave-from-class="opacity-100 translate-y-0 scale-100"
          leave-to-class="opacity-0 translate-y-2 scale-95"
        >
          <div v-if="fabOpen" class="flex flex-col items-end gap-2">
            <!-- New Group -->
            <button
              @click="fabOpen = false; newGroupOpen = true"
              class="flex items-center gap-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 shadow-lg rounded-full pl-4 pr-3 py-2 text-sm font-medium border border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
              {{ $t('newGroup') }}
              <span class="w-8 h-8 rounded-full bg-emerald-500 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
              </span>
            </button>
            <!-- New Chat -->
            <button
              @click="fabOpen = false; newChatOpen = true"
              class="flex items-center gap-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 shadow-lg rounded-full pl-4 pr-3 py-2 text-sm font-medium border border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
              {{ $t('newChat') }}
              <span class="w-8 h-8 rounded-full bg-indigo-500 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3v-3z"/>
                </svg>
              </span>
            </button>
          </div>
        </transition>

        <!-- Main FAB -->
        <button
          @click="fabOpen = !fabOpen"
          class="w-16 h-16 rounded-full bg-blue-600 hover:bg-blue-700 text-white shadow-xl flex items-center justify-center transition-all duration-200"
          :class="fabOpen ? 'rotate-45' : ''"
        >
          <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
          </svg>
        </button>
      </div>

      <!-- FAB backdrop -->
      <div
        v-if="fabOpen"
        class="absolute inset-0 z-10"
        @click="fabOpen = false"
      />
    </aside>

    <!-- ── Main panel ──────────────────────────────────────── -->
    <main
      :class="[
        'flex-1 flex flex-col min-w-0 bg-gray-50 dark:bg-gray-950',
        hasActive ? 'flex' : 'hidden md:flex',
      ]"
    >
      <!-- Empty state (desktop) -->
      <div v-if="!hasActive" class="flex-1 flex flex-col items-center justify-center text-gray-400 dark:text-gray-600 gap-2 select-none">
        <svg class="w-16 h-16 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        <p class="text-sm">{{ $t('selectChat') }}</p>
        <p class="text-xs opacity-60">{{ $t('encrypted') }}</p>
      </div>

      <!-- Active thread -->
      <template v-else>
        <MessageThread
          class="flex-1 min-h-0"
          @back="closeActive"
          @reply="onReply"
          @edit="onEditMsg"
          @edit-group="onEditGroup"
        />
        <MessageInput
          :reply-to="replyTo"
          :edit-msg="editMsg"
          @cancel-reply="replyTo = null"
          @cancel-edit="editMsg = null"
        />
      </template>
    </main>

    </div><!-- end flex row -->

    <!-- Settings modal -->
    <SettingsModal v-if="settingsOpen" @close="settingsOpen = false" @logout="doLogout" />

    <!-- New group modal -->
    <NewGroupModal
      v-if="newGroupOpen"
      @close="newGroupOpen = false"
      @created="(id) => { newGroupOpen = false; onSelect({ id, type: 'group' }) }"
    />

    <GroupEditModal
      v-if="groupEditOpen && groupsStore.activeGroupId"
      :group-id="groupsStore.activeGroupId"
      @close="groupEditOpen = false"
      @saved="groupEditOpen = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useChatsStore }     from '@/store/chats'
import { useGroupsStore }    from '@/store/groups'
import { useAuthStore }      from '@/store/auth'
import { useSettingsStore }  from '@/store/settings'
import { useReactionsStore } from '@/store/reactions'
import { t, getDir }        from '@/lib/i18n'
import { requestPermission, showNotification } from '@/lib/notify'
import logoEn from '@/assets/imen-logo-en.svg?url'
import logoFa from '@/assets/imen-logo-fa.svg?url'
import ChatList        from '@/components/ChatList.vue'
import MessageThread   from '@/components/MessageThread.vue'
import MessageInput    from '@/components/MessageInput.vue'
import SettingsModal   from '@/components/SettingsModal.vue'
import NewGroupModal   from '@/components/NewGroupModal.vue'
import GroupEditModal  from '@/components/GroupEditModal.vue'
import { startPolling, stopPolling, onConnectionChange } from '@/lib/poll'

const router      = useRouter()
const route       = useRoute()
const chats       = useChatsStore()
const groupsStore = useGroupsStore()
const auth        = useAuthStore()
const settings    = useSettingsStore()

const reactionsStore = useReactionsStore()
const settingsOpen   = ref(false)
const newChatOpen    = ref(false)
const newGroupOpen   = ref(false)
const groupEditOpen  = ref(false)
const fabOpen        = ref(false)

/** Message being replied to. Passed as prop to MessageInput. */
const replyTo = ref(null)
/** Message being edited. Passed as prop to MessageInput. */
const editMsg = ref(null)

const $t  = (key) => t(key, settings.locale)
const dir = computed(() => getDir(settings.locale))

const hasActive  = computed(() => chats.activeConvId !== null || groupsStore.activeGroupId !== null)
const connected  = ref(true)

// ── Lifecycle ──────────────────────────────────────────────────────────────

// ── Tab title unread count ─────────────────────────────────────────────────

function updateTabTitle() {
  const total =
    chats.conversations.reduce((s, c) => s + Number(c.unread_count ?? 0), 0) +
    groupsStore.groups.reduce((s, g) => s + Number(g.unread_count ?? 0), 0)
  document.title = total > 0 ? `(${total}) Imen` : 'Imen'
}

// ── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(async () => {
  await requestPermission()

  await Promise.all([
    chats.fetchConversations(),
    groupsStore.fetchGroups(),
  ])

  updateTabTitle()

  // Open conversation from URL if user landed on a deep link
  await openFromRoute()

  onConnectionChange((v) => { connected.value = v })

  startPolling(async (data) => {
    await chats.applyUpdates(data)
    await groupsStore.applyGroupUpdates({
      group_messages:      data.group_messages,
      groups:              data.groups,
      group_read_receipts: data.group_read_receipts,
    })
    reactionsStore.applyUpdates(data.reactions)
    if (data.deleted_message_ids?.length) {
      chats.applyDeletions(data.deleted_message_ids)
      groupsStore.applyDeletions(data.deleted_message_ids)
    }

    updateTabTitle()

    // Notifications for new messages in non-active chats
    for (const msg of data.messages ?? []) {
      if (msg.sender_id === auth.user?.id) continue
      if (chats.activeConvId === msg.conversation_id) continue
      const conv = chats.conversations.find((c) => c.id === msg.conversation_id)
      const name = conv?.peer_name ?? 'Imen'
      showNotification(name, '📨 New message', () => {
        onSelect({ id: msg.conversation_id, type: 'private' })
      })
    }

    for (const msg of data.group_messages ?? []) {
      if (msg.sender_id === auth.user?.id) continue
      if (groupsStore.activeGroupId === msg.group_id) continue
      const group = groupsStore.groups.find((g) => g.id === msg.group_id)
      const name  = group?.name ?? 'Imen'
      showNotification(name, '📨 New message', () => {
        onSelect({ id: msg.group_id, type: 'group' })
      })
    }
  })
})

onUnmounted(() => stopPolling())


// ── Navigation ─────────────────────────────────────────────────────────────

/** Mark the currently open conversation/group as read up to the latest loaded message. */
function markCurrentRead() {
  if (chats.activeConvId) {
    const msgs = chats.messages[chats.activeConvId]
    if (msgs?.length) chats.markRead(chats.activeConvId, msgs.at(-1).id)
  }
  if (groupsStore.activeGroupId) {
    const msgs = groupsStore.messages[groupsStore.activeGroupId]
    if (msgs?.length) groupsStore.markRead(groupsStore.activeGroupId, msgs.at(-1).id)
  }
}

async function onSelect({ id, type }) {
  markCurrentRead()
  replyTo.value = null
  editMsg.value = null
  // Navigate — the route watcher will open the conversation
  router.push(type === 'group' ? `/chat/group/${id}` : `/chat/private/${id}`)
}

function closeActive() {
  markCurrentRead()
  chats.activeConvId        = null
  groupsStore.activeGroupId = null
  router.replace('/')
}

// Open the correct conversation whenever the route changes
async function openFromRoute() {
  const { params } = route
  if (route.path.startsWith('/chat/private/')) {
    const id        = Number(params.id)
    const messageId = params.messageId ? Number(params.messageId) : null
    groupsStore.activeGroupId = null
    if (messageId) {
      await chats.loadAroundMessage(id, messageId)
      chats.activeConvId = id
    } else {
      await chats.openConversation(id)
    }
  } else if (route.path.startsWith('/chat/group/')) {
    const id        = Number(params.id)
    const messageId = params.messageId ? Number(params.messageId) : null
    chats.activeConvId = null
    if (messageId) {
      await groupsStore.loadAroundMessage(id, messageId)
      groupsStore.activeGroupId = id
    } else {
      await groupsStore.openGroup(id)
    }
  } else {
    chats.activeConvId        = null
    groupsStore.activeGroupId = null
  }
}

watch(() => route.path, openFromRoute)

function onReply(msg) {
  editMsg.value  = null
  replyTo.value  = msg
}

function onEditMsg(msg) {
  replyTo.value = null
  editMsg.value  = msg
}

function onEditGroup() {
  if (!groupsStore.activeGroupId) return
  groupEditOpen.value = true
}

async function doLogout() {
  stopPolling()
  await auth.logout()
  router.replace('/auth')
}
</script>
