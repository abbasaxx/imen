<!-- app/src/components/MediaPlayer.vue -->
<!-- Fetches an encrypted media file, decrypts it, caches in IndexedDB, and renders inline. -->
<template>
  <div
    :class="[
      'mt-1.5',
      isVisualMedia
        ? (bleed ? 'w-[calc(100%+1.75rem)] -mx-3.5' : 'w-full')
        : (props.fileType === 'voice' ? 'w-full' : 'max-w-xs'),
      textBelow ? 'mb-1.5' : '',
    ]"
    @click.stop
    @pointerdown.stop
    @pointerup.stop
  >
    <!-- Loading -->
    <div
      v-if="loading && isVisualMedia"
      class="w-full"
    >
      <div class="aspect-[10/9] w-full bg-gray-200/70 dark:bg-gray-700/70 flex items-center justify-center overflow-hidden">
        <div class="flex items-center gap-2 text-xs opacity-60">
          <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
          </svg>
          <span>{{ $t('loadingMedia') }}</span>
        </div>
      </div>
      <div v-if="fileType === 'video'" class="mt-1 h-4" />
    </div>

    <div v-else-if="loading" class="flex items-center gap-2 text-xs opacity-60 py-2">
      <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
      </svg>
      <span>{{ $t('loadingMedia') }}</span>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-xs opacity-60 italic py-1">
      {{ $t('mediaError') }}
    </div>

    <!-- Image -->
    <div
      v-else-if="fileType === 'image' && blobUrl"
      class="aspect-[10/9] w-full bg-black/5 dark:bg-white/10 overflow-hidden cursor-pointer"
      @click="lightbox = true"
    >
      <img
        :src="blobUrl"
        class="w-full h-full object-cover"
        loading="lazy"
      />
    </div>

    <!-- Video (auto-plays muted when visible; click for audio in lightbox) -->
    <div v-else-if="fileType === 'video' && blobUrl">
      <div
        class="relative aspect-[10/9] w-full bg-black overflow-hidden cursor-pointer"
        @click="openVideoLightbox"
      >
        <video
          ref="videoEl"
          :src="blobUrl"
          muted
          loop
          playsinline
          preload="auto"
          class="w-full h-full object-cover pointer-events-none"
          @play="videoPlaying = true"
          @pause="videoPlaying = false"
          @ended="videoPlaying = false"
        />
        <!-- Muted badge -->
        <div class="absolute top-2 end-2 pointer-events-none text-white/70">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
            <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
          </svg>
        </div>
        <!-- Tap-for-sound hint overlay when paused -->
        <div
          v-if="!videoPlaying"
          class="absolute inset-0 flex items-center justify-center text-white bg-black/20 pointer-events-none"
        >
          <span class="w-12 h-12 rounded-full bg-black/50 flex items-center justify-center shadow-lg">
            <svg class="w-6 h-6 ms-0.5" fill="currentColor" viewBox="0 0 24 24">
              <path d="M8 5v14l11-7z"/>
            </svg>
          </span>
        </div>
      </div>
      <button @click="download('video')" class="mt-1 text-[11px] opacity-60 hover:opacity-100 flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        {{ $t('download') }}
      </button>
    </div>

    <!-- Generic file (PDF, DOC, ZIP, etc.) -->
    <div v-else-if="fileType === 'file' && blobUrl" class="flex items-center gap-2 py-1.5 px-1">
      <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
           :class="mine ? 'bg-blue-400/40' : 'bg-gray-200 dark:bg-gray-600'">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
      </div>
      <span class="text-xs truncate max-w-[140px] flex-1">{{ fileName ?? 'File' }}</span>
      <button
        @click="downloadGeneric"
        class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center transition-colors"
        :class="mine ? 'bg-blue-400/40 hover:bg-blue-300/50' : 'bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500'"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
      </button>
    </div>

    <!-- Voice -->
    <div v-else-if="fileType === 'voice' && blobUrl" class="w-[85%] flex items-center gap-2 py-1">
      <button
        @click="togglePlay"
        class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
        :class="mine ? 'bg-blue-400 hover:bg-blue-300' : 'bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500'"
      >
        <svg v-if="!playing" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M8 5v14l11-7z"/>
        </svg>
        <svg v-else class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
        </svg>
      </button>
      <div
        ref="waveformEl"
        class="flex-1 flex items-end gap-[2px] h-7 relative cursor-pointer"
        @pointerdown="onSeekStart"
        @pointermove="onSeekMove"
        @pointerup="onSeekEnd"
        @pointercancel="onSeekEnd"
      >
        <div
          v-for="(peak, i) in peakBars"
          :key="i"
          class="min-w-[2px] flex-1 rounded-sm transition-none"
          :style="{
            height: Math.max(3, peak * 28) + 'px',
            backgroundColor: mine
              ? `rgba(255,255,255,${i < playedCount ? 0.95 : 0.35})`
              : `rgba(59,130,246,${i < playedCount ? 0.85 : 0.3})`,
          }"
        />
      </div>
      <span class="text-[10px] opacity-60 tabular-nums w-10 text-end">{{ timeLabel }}</span>
      <audio ref="audioEl" :src="blobUrl" @timeupdate="onTimeUpdate" @ended="onEnded" class="hidden" />
    </div>
  </div>

  <!-- Lightbox (outside v-if chain) -->
  <Teleport to="body">
    <div
      v-if="lightbox"
      class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center overflow-hidden"
      @click="onLightboxBgClick"
      @wheel.prevent="onWheel"
    >
      <img
        ref="lightboxImg"
        :src="blobUrl"
        class="select-none touch-none origin-center transition-none"
        :style="imgStyle"
        @pointerdown.stop="onPointerDown"
        @pointermove.stop="onPointerMove"
        @pointerup.stop="onPointerUp"
        @pointercancel.stop="onPointerUp"
        draggable="false"
      />
      <!-- Close -->
      <button
        class="absolute top-4 end-4 text-white/70 hover:text-white z-10"
        @click.stop="closeLightbox"
      >
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
      <!-- Download -->
      <button
        class="absolute bottom-4 end-4 text-white/70 hover:text-white flex items-center gap-1.5 text-sm bg-black/40 rounded-xl px-3 py-1.5 z-10"
        @click.stop="download('image')"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        {{ $t('download') }}
      </button>
    </div>
  </Teleport>

  <!-- Video lightbox (outside v-if chain) -->
  <Teleport to="body">
    <div
      v-if="videoLightbox"
      class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center overflow-hidden p-4"
      @click="closeVideoLightbox"
    >
      <video
        :src="blobUrl"
        controls
        autoplay
        playsinline
        class="max-w-full max-h-full rounded-xl bg-black"
        @click.stop
      />
      <button
        class="absolute top-4 end-4 text-white/70 hover:text-white z-10"
        @click.stop="closeVideoLightbox"
      >
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useSettingsStore } from '@/store/settings'
import { t }               from '@/lib/i18n'
import { decryptFile }     from '@/lib/crypto'
import { getCachedMedia, cacheMedia } from '@/lib/mediaCache'
import { api }             from '@/lib/api'

const props = defineProps({
  fileId:   { type: String, required: true },
  fileType: { type: String, required: true }, // 'image' | 'video' | 'voice' | 'file'
  fileKey:  { type: String, required: true }, // base64 AES key
  fileIV:   { type: String, required: true }, // base64 IV
  fileName: { type: String, default: null },  // original filename (for generic files)
  mine:     { type: Boolean, default: false },
  bleed:    { type: Boolean, default: false },
  textBelow:{ type: Boolean, default: false },
})

const settings = useSettingsStore()
const $t = (key) => t(key, settings.locale)

const blobUrl  = ref(null)
const loading  = ref(true)
const error    = ref(false)
const lightbox = ref(false)
const videoLightbox = ref(false)
const isVisualMedia = computed(() => props.fileType === 'image' || props.fileType === 'video')

// ── Lightbox pan + zoom ────────────────────────────────────────────────────
const lightboxImg = ref(null)
const scale       = ref(1)
const tx          = ref(0)  // translate X
const ty          = ref(0)  // translate Y

const imgStyle = computed(() => ({
  transform: `translate(${tx.value}px, ${ty.value}px) scale(${scale.value})`,
  cursor: scale.value > 1 ? 'grab' : 'default',
  maxWidth: '100vw',
  maxHeight: '100vh',
}))

function closeLightbox() {
  lightbox.value = false
  scale.value    = 1
  tx.value       = 0
  ty.value       = 0
}

function onLightboxBgClick() {
  if (scale.value === 1) closeLightbox()
}

// Mouse wheel zoom
function onWheel(e) {
  const delta = e.deltaY > 0 ? 0.85 : 1.15
  scale.value = Math.min(8, Math.max(1, scale.value * delta))
  if (scale.value === 1) { tx.value = 0; ty.value = 0 }
}

// Pointer tracking (mouse drag + touch pinch)
const activePointers = new Map()
let lastPinchDist = null
let dragStart = null

function onPointerDown(e) {
  lightboxImg.value?.setPointerCapture(e.pointerId)
  activePointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (activePointers.size === 1) {
    dragStart = { x: e.clientX, y: e.clientY, tx: tx.value, ty: ty.value }
  }
  lastPinchDist = null
}

function onPointerMove(e) {
  activePointers.set(e.pointerId, { x: e.clientX, y: e.clientY })

  if (activePointers.size === 2) {
    // Pinch zoom
    const pts   = [...activePointers.values()]
    const dist  = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y)
    if (lastPinchDist !== null) {
      const ratio = dist / lastPinchDist
      scale.value = Math.min(8, Math.max(1, scale.value * ratio))
      if (scale.value === 1) { tx.value = 0; ty.value = 0 }
    }
    lastPinchDist = dist
  } else if (activePointers.size === 1 && dragStart && scale.value > 1) {
    // Pan
    tx.value = dragStart.tx + (e.clientX - dragStart.x)
    ty.value = dragStart.ty + (e.clientY - dragStart.y)
  }
}

function onPointerUp(e) {
  activePointers.delete(e.pointerId)
  lastPinchDist = null
  if (activePointers.size < 2) lastPinchDist = null
  if (activePointers.size === 0) dragStart = null
}

// Voice playback state
const audioEl       = ref(null)
const playing       = ref(false)
const progressPct   = ref(0)
const timeLabel     = ref('0:00')
const BARS        = 50
const peaks       = ref([])
const peaksReady  = ref(false)
const duration    = ref(0)
const waveformEl  = ref(null)
let   seeking     = false

const peakBars = computed(() =>
  peaksReady.value ? peaks.value : Array(BARS).fill(0.4)
)

const playedCount = computed(() =>
  Math.floor((progressPct.value / 100) * BARS)
)

async function decodeWaveform(url) {
  try {
    const response  = await fetch(url)
    const buffer    = await response.arrayBuffer()
    const ctx       = new AudioContext()
    const audioBuf  = await ctx.decodeAudioData(buffer)
    await ctx.close()

    const data      = audioBuf.getChannelData(0)
    const chunkSize = Math.floor(data.length / BARS)
    const raw       = Array.from({ length: BARS }, (_, i) => {
      const start = i * chunkSize
      const end   = start + chunkSize
      let   max   = 0
      for (let j = start; j < end; j++) {
        const abs = Math.abs(data[j])
        if (abs > max) max = abs
      }
      return max
    })

    const globalMax  = Math.max(...raw, 0.001)
    peaks.value      = raw.map((v) => v / globalMax)
    peaksReady.value = true
  } catch {
    peaks.value      = Array(BARS).fill(0.4)
    peaksReady.value = true
  }
}

const videoEl       = ref(null)
const videoPlaying  = ref(false)
const videoObserver = ref(null)
const voiceMimeHint = computed(() => {
  const name = String(props.fileName ?? '').toLowerCase()
  if (name.endsWith('.wav')) return 'audio/wav'
  if (name.endsWith('.m4a') || name.endsWith('.mp4') || name.endsWith('.aac')) return 'audio/mp4'
  return 'audio/webm'
})

// Auto-play muted when the video scrolls into view; pause when it leaves.
watch(blobUrl, async (newUrl) => {
  if (newUrl && props.fileType === 'voice') {
    decodeWaveform(newUrl)
  }
  if (!newUrl || props.fileType !== 'video') return
  await nextTick()
  if (!videoEl.value) return
  videoObserver.value?.disconnect()
  videoObserver.value = new IntersectionObserver(
    ([entry]) => {
      if (videoLightbox.value) return   // lightbox has focus — leave inline alone
      if (entry.isIntersecting) {
        videoEl.value?.play().catch(() => {})
      } else {
        videoEl.value?.pause()
      }
    },
    { threshold: 0.5 },
  )
  videoObserver.value.observe(videoEl.value)
})

watch(audioEl, (el) => {
  if (!el) return
  el.addEventListener('loadedmetadata', () => {
    duration.value = el.duration
    if (!playing.value) timeLabel.value = formatTime(el.duration)
  })
})

onMounted(async () => {
  try {
    // 1. Check IndexedDB cache
    let blob = await getCachedMedia(props.fileId)

    if (!blob) {
      // 2. Fetch encrypted blob from server
      const encBuf = await api.fetchFileBuf(props.fileId)

      // 3. Decrypt — provide MIME hints so Safari can identify the stream
      let mimeHint = ''
      if (props.fileType === 'voice') mimeHint = voiceMimeHint.value
      if (props.fileType === 'video') mimeHint = 'video/mp4'
      blob = await decryptFile(encBuf, props.fileKey, props.fileIV, mimeHint)

      // 4. Cache decrypted blob (fire-and-forget — a quota error must not block display)
      cacheMedia(props.fileId, blob).catch(() => {})
    } else if (
      props.fileType === 'video' && !blob.type ||
      props.fileType === 'voice' && blob.type !== voiceMimeHint.value
    ) {
      // Re-type cached media blobs when the browser needs a precise MIME.
      blob = new Blob([await blob.arrayBuffer()], {
        type: props.fileType === 'voice' ? voiceMimeHint.value : 'video/mp4',
      })
    }

    blobUrl.value = URL.createObjectURL(blob)
  } catch (e) {
    console.error('MediaPlayer load error:', e)
    error.value = true
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  videoObserver.value?.disconnect()
  if (blobUrl.value) URL.revokeObjectURL(blobUrl.value)
})


function download(type) {
  if (!blobUrl.value) return
  const ext  = type === 'image' ? 'jpg' : type === 'video' ? 'mp4' : 'webm'
  const a    = document.createElement('a')
  a.href     = blobUrl.value
  a.download = `securechat-${props.fileId.slice(0, 8)}.${ext}`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}

function downloadGeneric() {
  if (!blobUrl.value) return
  const a    = document.createElement('a')
  a.href     = blobUrl.value
  a.download = props.fileName ?? `file-${props.fileId.slice(0, 8)}`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}

function openVideoLightbox() {
  videoEl.value?.pause()
  videoLightbox.value = true
}

function closeVideoLightbox() {
  videoLightbox.value = false
}

function togglePlay() {
  const el = audioEl.value
  if (!el) return
  if (playing.value) {
    el.pause()
    playing.value = false
  } else {
    el.play()
    playing.value = true
  }
}

function onTimeUpdate() {
  const el = audioEl.value
  if (!el || !el.duration) return
  progressPct.value = (el.currentTime / el.duration) * 100
  const remaining   = el.duration - el.currentTime
  timeLabel.value   = '-' + formatTime(remaining)
}

function onEnded() {
  playing.value     = false
  progressPct.value = 0
  timeLabel.value   = duration.value > 0 ? formatTime(duration.value) : '0:00'
}

function onSeekStart(e) {
  seeking = true
  waveformEl.value?.setPointerCapture(e.pointerId)
  seekTo(e)
}

function onSeekMove(e) {
  if (!seeking) return
  seekTo(e)
}

function onSeekEnd() {
  seeking = false
}

function seekTo(e) {
  const el = waveformEl.value
  if (!el || !audioEl.value?.duration) return
  const rect     = el.getBoundingClientRect()
  const isRTL    = getComputedStyle(el).direction === 'rtl'
  let   fraction = (e.clientX - rect.left) / rect.width
  if (isRTL) fraction = 1 - fraction
  audioEl.value.currentTime = Math.min(1, Math.max(0, fraction)) * audioEl.value.duration
}

function formatTime(sec) {
  const m = Math.floor(sec / 60)
  const s = Math.floor(sec % 60).toString().padStart(2, '0')
  return `${m}:${s}`
}
</script>
