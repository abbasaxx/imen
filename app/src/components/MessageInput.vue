<!-- app/src/components/MessageInput.vue -->
<!-- Text input bar — routes sends to private chats store or groups store. -->
<!-- Supports file attachment (image/video) and voice recording. -->
<template>
  <div class="safe-area-bottom border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">

    <!-- Reply bar -->
    <div v-if="replyTo" class="flex items-center gap-2 px-3 pt-2 pb-0">
      <div class="flex-1 flex items-center gap-2 bg-blue-50 dark:bg-blue-900/20 border-s-2 border-blue-500 rounded-lg px-3 py-1.5 min-w-0">
        <div class="flex-1 min-w-0">
          <p class="text-[11px] font-semibold text-blue-600 dark:text-blue-400 leading-none mb-0.5">
            {{ $t('replyTo') }} {{ replyTo.mine ? $t('you') : (replyTo.senderName ?? '') }}
          </p>
          <p class="text-xs text-gray-600 dark:text-gray-400 reply-preview-text">
            {{ replyTo.text || `[${replyTo.mediaFileType ?? 'media'}]` }}
          </p>
        </div>
      </div>
      <button
        @click="$emit('cancel-reply')"
        class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
        :title="$t('cancelReply')"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Edit bar -->
    <div v-if="editMsg" class="flex items-center gap-2 px-3 pt-2 pb-0">
      <div class="flex-1 flex items-center gap-2 bg-amber-50 dark:bg-amber-900/20 border-s-2 border-amber-500 rounded-lg px-3 py-1.5 min-w-0">
        <p class="text-[11px] font-semibold text-amber-600 dark:text-amber-400">{{ $t('editMessage') }}</p>
        <p class="text-xs text-gray-600 dark:text-gray-400 truncate flex-1">{{ editMsg.text }}</p>
      </div>
      <button
        @click="$emit('cancel-edit')"
        class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
        :title="$t('cancelEdit')"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Attachment preview -->
    <div v-if="pendingFile" class="flex items-center gap-2 px-3 pt-2 pb-1">
      <div class="flex items-center gap-2 bg-gray-100 dark:bg-gray-800 rounded-xl px-3 py-1.5 text-sm flex-1 min-w-0">
        <!-- Icon by type -->
        <svg v-if="pendingFile.fileType === 'image'" class="w-4 h-4 flex-shrink-0 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <rect x="3" y="3" width="18" height="18" rx="2" stroke-width="2"/>
          <circle cx="8.5" cy="8.5" r="1.5" stroke-width="2"/>
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15l-5-5L5 21"/>
        </svg>
        <svg v-else-if="pendingFile.fileType === 'video'" class="w-4 h-4 flex-shrink-0 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.069A1 1 0 0121 8.882v6.236a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/>
        </svg>
        <svg v-else-if="pendingFile.fileType === 'voice'" class="w-4 h-4 flex-shrink-0 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <rect x="9" y="2" width="6" height="12" rx="3"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 10a7 7 0 0014 0M12 19v3M9 22h6"/>
        </svg>
        <svg v-else class="w-4 h-4 flex-shrink-0 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <span class="truncate text-gray-700 dark:text-gray-300 text-xs">{{ pendingFile.name }}</span>
      </div>
      <button
        @click="clearAttachment"
        class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Upload progress bar -->
    <div v-if="isUploading" class="px-3 pt-2 pb-0">
      <div class="flex items-center gap-2">
        <div class="flex-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
          <div
            class="h-full rounded-full bg-blue-500 transition-all duration-200"
            :style="{ width: uploadProgress + '%' }"
          />
        </div>
        <span class="text-[11px] text-gray-400 tabular-nums w-8 text-end">{{ uploadProgress }}%</span>
      </div>
    </div>

    <!-- Input row -->
    <div class="flex items-end gap-2 px-3 py-2">

      <!-- Attach file -->
      <button
        @click="triggerFileInput"
        :title="$t('attachFile')"
        class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center transition-colors
               bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400
               hover:bg-gray-200 dark:hover:bg-gray-700"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
        </svg>
      </button>
      <input
        ref="fileInputEl"
        type="file"
        accept="*/*"
        class="hidden"
        @change="onFileSelected"
      />

      <!-- Text area -->
      <textarea
        ref="inputEl"
        v-model="text"
        @input="autoResize"
        @compositionend="onCompositionEnd"
        :placeholder="$t('typeMessage')"
        :dir="inputDir"
        :style="inputDir === 'rtl' ? 'text-align:right;unicode-bidi:plaintext' : 'text-align:left;unicode-bidi:plaintext'"
        rows="1"
        class="flex-1 resize-none rounded-2xl border border-gray-300 dark:border-gray-600
               bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm
               px-4 py-2.5 leading-relaxed
               focus:outline-none focus:ring-2 focus:ring-blue-500 transition
               overflow-y-hidden"
      />

      <!-- Voice recorder (shown when no pending file and no text) -->
      <VoiceRecorder
        v-if="!pendingFile && !text.trim()"
        @recorded="onVoiceRecorded"
        @error="voiceError = $event"
      />

      <!-- Send button -->
      <button
        v-if="hasContent"
        type="button"
        @mousedown.prevent
        @touchend.prevent="onSend"
        @click="onSend"
        :disabled="sending"
        class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center transition-colors
               bg-blue-500 hover:bg-blue-600 text-white disabled:opacity-40 disabled:cursor-not-allowed"
      >
        <svg class="w-5 h-5" :class="isRTL ? 'scale-x-[-1]' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
        </svg>
      </button>

    </div>

    <!-- Voice/upload error -->
    <p v-if="voiceError" class="text-red-500 text-xs px-4 pb-2">{{ voiceError }}</p>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useChatsStore }    from '@/store/chats'
import { useGroupsStore }   from '@/store/groups'
import { useAuthStore }     from '@/store/auth'
import { useSettingsStore } from '@/store/settings'
import { t, getDir }        from '@/lib/i18n'
import VoiceRecorder        from './VoiceRecorder.vue'

const props = defineProps({
  /** Message being replied to: { id, text, mine, senderName, mediaFileType } | null */
  replyTo: { type: Object, default: null },
  /** Message being edited: { id, text } | null */
  editMsg: { type: Object, default: null },
})

const emit = defineEmits(['cancel-reply', 'cancel-edit'])

const chats       = useChatsStore()
const groupsStore = useGroupsStore()
const auth        = useAuthStore()
const settings    = useSettingsStore()

const $t    = (key) => t(key, settings.locale)
const isRTL = computed(() => getDir(settings.locale) === 'rtl')

const text           = ref('')
const inputEl        = ref(null)
const fileInputEl    = ref(null)
const sending        = ref(false)
const voiceError     = ref('')
const uploadProgress = ref(0)
const isUploading    = ref(false)

/** { blob, fileType, name } | null */
const pendingFile = ref(null)

const isGroupActive = computed(() => groupsStore.activeGroupId !== null)
const RTL_RE = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
const inputDir = computed(() => RTL_RE.test(text.value) ? 'rtl' : 'ltr')

// Pre-fill textarea when entering edit mode
watch(() => props.editMsg, async (msg) => {
  if (msg) {
    text.value = msg.text ?? ''
    await nextTick()
    autoResize()
    inputEl.value?.focus()
  } else {
    await nextTick()
    resetInputHeight()
  }
})

// Auto-focus textarea when active conversation changes (desktop only — mobile would open keyboard)
const isTouchDevice = window.matchMedia?.('(pointer: coarse)').matches ?? false
watch(
  () => [chats.activeConvId, groupsStore.activeGroupId],
  async () => {
    if (isTouchDevice) return
    await nextTick()
    inputEl.value?.focus()
  },
)

// Show the button whenever there is content — regardless of key state.
// hasPrivateKey is checked inside onSend so the button is always visible.
const hasContent = computed(() =>
  (text.value.trim().length > 0 || pendingFile.value !== null) && !sending.value
)

const canSend = computed(() => hasContent.value && auth.hasPrivateKey)

// Android IME: force v-model sync after composition ends
function onCompositionEnd() {
  if (inputEl.value) {
    text.value = inputEl.value.value
    autoResize()
  }
}

function triggerFileInput() {
  fileInputEl.value?.click()
}

function onFileSelected(e) {
  const file = e.target.files[0]
  if (!file) return
  e.target.value = ''

  let fileType = 'image'
  if (file.type.startsWith('video/')) fileType = 'video'
  else if (!file.type.startsWith('image/')) fileType = 'file'

  pendingFile.value = { blob: file, fileType, name: file.name }
  voiceError.value  = ''
}

function onVoiceRecorded({ blob, fileType, name, mimeType }) {
  pendingFile.value = { blob, fileType, name: name ?? 'voice-message.webm', mimeType }
  voiceError.value  = ''
  // Auto-send voice messages immediately
  onSend()
}

function clearAttachment() {
  pendingFile.value = null
}

async function onSend() {
  if (!hasContent.value) return
  if (!auth.hasPrivateKey) {
    voiceError.value = $t('noPrivateKey')
    return
  }

  const msg        = text.value.trim()
  const attachment = pendingFile.value
  const isEdit     = !!props.editMsg
  const replyToId  = props.replyTo?.id ?? null

  text.value        = ''
  pendingFile.value = null
  resetInputHeight()

  sending.value        = true
  voiceError.value     = ''
  uploadProgress.value = 0
  isUploading.value    = !!attachment

  const onProgress = attachment ? (pct) => { uploadProgress.value = pct } : null

  try {
    if (isEdit) {
      // Edit mode: PATCH the existing message
      if (isGroupActive.value) {
        await groupsStore.editMessage(props.editMsg.id, msg)
      } else {
        await chats.editMessage(props.editMsg.id, msg)
      }
      emit('cancel-edit')
      resetInputHeight()
    } else {
      // Normal send (with optional reply)
      if (isGroupActive.value) {
        await groupsStore.sendMessage(msg, attachment, replyToId, onProgress)
      } else {
        await chats.sendMessage(msg, attachment, replyToId, onProgress)
      }
      if (replyToId) emit('cancel-reply')
    }
  } catch (err) {
    // Restore on failure
    text.value        = msg
    pendingFile.value = attachment
    voiceError.value  = err.message ?? 'Send failed'
    console.error('Send failed:', err)
  } finally {
    sending.value        = false
    isUploading.value    = false
    uploadProgress.value = 0
    await nextTick()
    if (isTouchDevice) {
      inputEl.value?.blur()
    } else {
      inputEl.value?.focus()
    }
  }
}

function autoResize() {
  const el = inputEl.value
  if (!el) return
  const maxHeight = props.editMsg ? 224 : 144
  el.style.height = 'auto'
  el.style.height = Math.min(el.scrollHeight, maxHeight) + 'px'
  el.style.overflowY = el.scrollHeight > maxHeight ? 'auto' : 'hidden'
}

function resetInputHeight() {
  const el = inputEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.overflowY = 'hidden'
}
</script>

<style scoped>
.reply-preview-text {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  white-space: normal;
}
</style>
