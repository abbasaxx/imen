<!-- app/src/components/NewGroupModal.vue -->
<!-- Modal to create a new E2E-encrypted group -->
<template>
  <div class="safe-area-top safe-area-bottom safe-area-x fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div
      class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden"
      @click.stop
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $t('createGroup') }}</h2>
        <button @click="$emit('close')" class="p-1.5 rounded-full text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div class="px-5 py-4 space-y-4">

        <!-- Group name -->
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
            {{ $t('groupNameLabel') }}
          </label>
          <input
            v-model="groupName"
            :placeholder="$t('groupNameLabel')"
            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600
                   bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white
                   focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
          />
        </div>

        <!-- Member search -->
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
            {{ $t('addMembersHint') }}
          </label>
          <input
            v-model="searchQuery"
            @keydown.enter.prevent="doSearch"
            type="email"
            dir="ltr"
            :placeholder="$t('searchEmail')"
            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600
                   bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white
                   focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
          />

          <p v-if="searchError" class="mt-1 text-xs text-red-500">{{ searchError }}</p>

          <!-- Search results -->
          <div v-if="searchResults.length" class="mt-1 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden bg-white dark:bg-gray-800 max-h-36 overflow-y-auto">
            <button
              v-for="u in filteredResults"
              :key="u.id"
              @click="addMember(u)"
              class="w-full flex items-center gap-3 px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-start"
            >
              <div class="w-7 h-7 rounded-full bg-indigo-500 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                {{ (u.name || '?')[0].toUpperCase() }}
              </div>
              <div class="min-w-0">
                <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ u.name }}</div>
                <div class="text-xs text-gray-500 truncate">{{ u.email }}</div>
              </div>
              <svg class="w-4 h-4 text-blue-500 ms-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Selected members -->
        <div v-if="selectedMembers.length">
          <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
            {{ selectedMembers.length }} {{ $t('members') }}
          </p>
          <div class="flex flex-wrap gap-2">
            <span
              v-for="m in selectedMembers"
              :key="m.id"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-blue-100 dark:bg-blue-900/40
                     text-blue-800 dark:text-blue-300 rounded-full text-xs font-medium"
            >
              {{ m.name }}
              <button @click="removeMember(m.id)" class="text-blue-500 hover:text-blue-700 dark:hover:text-blue-200 leading-none">
                &times;
              </button>
            </span>
          </div>
        </div>

        <!-- Error -->
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-gray-200 dark:border-gray-700">
        <button
          @click="$emit('close')"
          class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors"
        >
          {{ $t('cancel') }}
        </button>
        <button
          @click="onCreate"
          :disabled="!canCreate || creating"
          class="px-4 py-2 text-sm font-medium rounded-lg bg-blue-500 text-white
                 hover:bg-blue-600 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <span v-if="creating" class="inline-flex items-center gap-2">
            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
          </span>
          <span v-else>{{ $t('create') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useGroupsStore }   from '@/store/groups'
import { useSettingsStore } from '@/store/settings'
import { useAuthStore }     from '@/store/auth'
import { t }  from '@/lib/i18n'
import { api } from '@/lib/api'

const emit = defineEmits(['close', 'created'])

const groups   = useGroupsStore()
const settings = useSettingsStore()
const auth     = useAuthStore()

const $t = (key) => t(key, settings.locale)

const groupName       = ref('')
const searchQuery     = ref('')
const searchResults   = ref([])
const searchError     = ref('')
const selectedMembers = ref([])
const creating        = ref(false)
const error           = ref('')

const canCreate = computed(() => groupName.value.trim().length > 0 && auth.hasPrivateKey)

// Exclude already-selected members from search results
const filteredResults = computed(() =>
  searchResults.value.filter((u) => !selectedMembers.value.find((m) => m.id === u.id)),
)

async function doSearch() {
  const email = searchQuery.value.trim()
  searchError.value   = ''
  searchResults.value = []
  if (!email) return
  try {
    const data = await api.get(`/users/search?q=${encodeURIComponent(email)}`)
    const exact = (data.users ?? []).filter((u) => u.email.toLowerCase() === email.toLowerCase())
    searchResults.value = exact
    if (!exact.length) searchError.value = $t('userNotFound')
  } catch { /* ignore */ }
}

function addMember(user) {
  if (!selectedMembers.value.find((m) => m.id === user.id)) {
    selectedMembers.value.push(user)
  }
  searchQuery.value   = ''
  searchResults.value = []
}

function removeMember(userId) {
  selectedMembers.value = selectedMembers.value.filter((m) => m.id !== userId)
}

async function onCreate() {
  if (!canCreate.value) return
  error.value    = ''
  creating.value = true
  try {
    const groupId = await groups.createGroup(groupName.value.trim(), selectedMembers.value)
    emit('created', groupId)
    emit('close')
  } catch (err) {
    error.value = err.message ?? 'Failed to create group'
  } finally {
    creating.value = false
  }
}
</script>
