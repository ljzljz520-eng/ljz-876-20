<template>
  <div class="flex flex-col items-center">
    <img v-if="dataUrl" :src="dataUrl" :alt="'座位 ' + seatNo + ' 二维码'" class="w-44 h-44" />
    <div v-else class="w-44 h-44 flex items-center justify-center text-gray-400 text-sm">二维码生成中...</div>
    <p class="mt-2 text-xs text-gray-500 break-all text-center max-w-[12rem]">{{ value }}</p>
    <button
      type="button"
      @click="download"
      class="mt-2 text-xs text-indigo-600 hover:text-indigo-800"
    >下载二维码</button>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import QRCode from 'qrcode'

const props = defineProps({
  value: { type: String, required: true },
  seatNo: { type: String, default: '' }
})

const dataUrl = ref('')

const render = async () => {
  if (!props.value) {
    dataUrl.value = ''
    return
  }
  dataUrl.value = await QRCode.toDataURL(props.value, {
    width: 320,
    margin: 1,
    color: { dark: '#1e1b4b', light: '#ffffff' }
  })
}

const download = () => {
  if (!dataUrl.value) return
  const a = document.createElement('a')
  a.href = dataUrl.value
  a.download = `seat-${props.seatNo || 'qrcode'}.png`
  a.click()
}

onMounted(render)
watch(() => props.value, render)
</script>
