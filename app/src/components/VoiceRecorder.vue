<!-- app/src/components/VoiceRecorder.vue -->
<!-- Hold-to-record voice message. Emits 'recorded' with { blob, fileType: 'voice' }. -->
<template>
  <button
    @mousedown="startRecording"
    @mouseup="stopRecording"
    @touchstart.prevent="startRecording"
    @touchend.prevent="stopRecording"
    @mouseleave="cancelIfRecording"
    :title="$t('recordVoice')"
    class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center transition-colors select-none"
    :class="recording
      ? 'bg-red-500 text-white scale-110'
      : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
  >
    <!-- Mic icon -->
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <rect x="9" y="2" width="6" height="12" rx="3"/>
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 10a7 7 0 0014 0M12 19v3M9 22h6"/>
    </svg>
  </button>
</template>

<script setup>
import { ref } from 'vue'
import { useSettingsStore } from '@/store/settings'
import { t } from '@/lib/i18n'

const emit     = defineEmits(['recorded', 'error'])
const settings = useSettingsStore()
const $t       = (key) => t(key, settings.locale)

const recording   = ref(false)
let stream         = null
let audioCtx       = null
let sourceNode     = null
let processorNode  = null
let chunks         = []
let sampleRate     = 44100

async function startRecording() {
  if (recording.value) return
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    chunks  = []
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext
    audioCtx = new AudioContextCtor()
    await audioCtx.resume()
    sampleRate = audioCtx.sampleRate

    sourceNode = audioCtx.createMediaStreamSource(stream)
    processorNode = audioCtx.createScriptProcessor(4096, 1, 1)
    processorNode.onaudioprocess = (event) => {
      if (!recording.value) return
      chunks.push(new Float32Array(event.inputBuffer.getChannelData(0)))
    }

    sourceNode.connect(processorNode)
    processorNode.connect(audioCtx.destination)
    recording.value = true
  } catch (e) {
    cleanup()
    emit('error', e.message ?? 'Microphone access denied')
  }
}

function stopRecording() {
  if (!recording.value) return
  recording.value = false
  const blob = encodeWav(chunks, sampleRate)
  cleanup()
  emit('recorded', { blob, fileType: 'voice', name: 'voice-message.wav', mimeType: 'audio/wav' })
}

function cancelIfRecording() {
  // If mouse leaves button while holding, stop and send anyway
  stopRecording()
}

function cleanup() {
  try { processorNode?.disconnect() } catch {}
  try { sourceNode?.disconnect() } catch {}
  try { audioCtx?.close() } catch {}
  stream?.getTracks().forEach((track) => track.stop())
  processorNode = null
  sourceNode = null
  audioCtx = null
  stream = null
}

function encodeWav(buffers, rate) {
  const length = buffers.reduce((sum, buffer) => sum + buffer.length, 0)
  const samples = new Float32Array(length)
  let offset = 0
  for (const buffer of buffers) {
    samples.set(buffer, offset)
    offset += buffer.length
  }

  const wav = new ArrayBuffer(44 + samples.length * 2)
  const view = new DataView(wav)
  writeAscii(view, 0, 'RIFF')
  view.setUint32(4, 36 + samples.length * 2, true)
  writeAscii(view, 8, 'WAVE')
  writeAscii(view, 12, 'fmt ')
  view.setUint32(16, 16, true)
  view.setUint16(20, 1, true)
  view.setUint16(22, 1, true)
  view.setUint32(24, rate, true)
  view.setUint32(28, rate * 2, true)
  view.setUint16(32, 2, true)
  view.setUint16(34, 16, true)
  writeAscii(view, 36, 'data')
  view.setUint32(40, samples.length * 2, true)

  let pos = 44
  for (const sample of samples) {
    const clamped = Math.max(-1, Math.min(1, sample))
    view.setInt16(pos, clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff, true)
    pos += 2
  }

  return new Blob([wav], { type: 'audio/wav' })
}

function writeAscii(view, offset, value) {
  for (let i = 0; i < value.length; i++) {
    view.setUint8(offset + i, value.charCodeAt(i))
  }
}
</script>
