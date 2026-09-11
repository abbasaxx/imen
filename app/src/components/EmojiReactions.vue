<!-- app/src/components/EmojiReactions.vue -->
<!-- Reaction pills shown below a message bubble. Picker is now in MessagePopup. -->
<template>
  <div v-if="currentReactions.length" class="flex flex-wrap gap-1 mt-1">
    <button
      v-for="r in currentReactions"
      :key="r.emoji"
      @click.stop="toggle(r.emoji)"
      :class="[
        'flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs transition-colors border',
        r.reacted_by_me
          ? 'bg-blue-100 dark:bg-blue-900/40 border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300'
          : 'bg-gray-100 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600',
      ]"
    >
      <span>{{ r.emoji }}</span>
      <span class="tabular-nums">{{ r.count }}</span>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useReactionsStore } from '@/store/reactions'

const props = defineProps({
  messageId: { type: Number, required: true },
})

const store = useReactionsStore()

const currentReactions = computed(() => store.forMessage(props.messageId))

async function toggle(emoji) {
  await store.toggle(props.messageId, emoji)
}
</script>
