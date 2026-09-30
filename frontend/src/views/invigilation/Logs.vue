<template>
  <div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">监考日志</h1>
        <p class="text-sm text-gray-500 mt-1">签到、扫码巡看、异常上报/处理、换座登记在此全程留痕。</p>
      </div>
      <div class="flex gap-3">
        <select v-model="sessionId" @change="reload" class="border rounded-lg px-3 py-2 text-sm">
          <option :value="null" disabled>选择场次</option>
          <option v-for="s in sessions" :key="s.id" :value="s.id">{{ s.name }}（{{ s.room?.name }}）</option>
        </select>
        <button @click="activeTab = 'logs'" :class="tabBtn('logs')">监考日志</button>
        <button @click="activeTab = 'anomalies'" :class="tabBtn('anomalies')">异常记录</button>
      </div>
    </div>

    <div v-if="!sessionId" class="bg-white rounded-lg shadow p-10 text-center text-gray-400">请先选择考试场次</div>

    <template v-else>
      <div v-if="loading" class="text-center py-10">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      </div>

      <div v-else-if="activeTab === 'logs'">
        <div v-if="logs.length === 0" class="bg-white rounded-lg shadow p-10 text-center text-gray-400">暂无监考日志</div>
        <div v-else class="bg-white shadow rounded-lg overflow-hidden">
          <ul class="divide-y divide-gray-100">
            <li v-for="log in logs" :key="log.id" class="px-5 py-4 flex items-start gap-4">
              <span class="mt-0.5 w-9 h-9 rounded-full flex items-center justify-center text-white shrink-0" :class="actionColor(log.action)">
                <span class="text-sm">{{ actionIcon(log.action) }}</span>
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900">
                  <span class="font-medium">{{ log.operator?.real_name || log.operator?.username }}</span>
                  <span class="text-gray-400 mx-1">·</span>{{ actionLabel(log.action) }}
                  <span v-if="log.seat_no" class="ml-1 text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">座位 {{ log.seat_no }}</span>
                </p>
                <p class="text-sm text-gray-500 mt-1 break-words">{{ log.detail }}</p>
              </div>
              <span class="text-xs text-gray-400 whitespace-nowrap">{{ formatDateTime(log.created_at) }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div v-else>
        <div v-if="anomalies.length === 0" class="bg-white rounded-lg shadow p-10 text-center text-gray-400">暂无异常记录</div>
        <div v-else class="bg-white shadow rounded-lg overflow-hidden">
          <ul class="divide-y divide-gray-100">
            <li v-for="a in anomalies" :key="a.id" class="px-5 py-4">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs px-2 py-0.5 rounded" :class="severityBadge(a.severity)">{{ severityLabel(a.severity) }}</span>
                <span class="text-sm font-medium text-gray-900">{{ typeLabel(a.type) }}</span>
                <span v-if="a.seat_assignment" class="text-xs text-gray-500">座位 {{ a.seat_assignment.seat_no }}（{{ a.seat_assignment.current_computer_no }}）</span>
                <span v-if="a.user" class="text-xs text-gray-500">{{ a.user.real_name || a.user.username }}</span>
                <span :class="a.resolved ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'" class="ml-auto text-xs px-2 py-0.5 rounded-full">
                  {{ a.resolved ? '已处理' : '待处理' }}
                </span>
              </div>
              <p class="text-sm text-gray-600 mt-2">{{ a.detail }}</p>
              <p class="text-xs text-gray-400 mt-1">{{ formatDateTime(a.created_at) }}<span v-if="a.resolved"> · 处理人 {{ a.resolver?.real_name || a.resolver?.username }}：{{ a.resolved_note }}</span></p>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'

const route = useRoute()
const sessions = ref([])
const sessionId = ref(route.query.session_id ? Number(route.query.session_id) : null)
const activeTab = ref('logs')
const loading = ref(false)
const logs = ref([])
const anomalies = ref([])

const tabBtn = (t) => [
  'px-4 py-2 rounded-lg text-sm font-medium',
  activeTab.value === t ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-50'
].join(' ')

const loadSessions = async () => {
  const { data } = await api.get('/invigilation/sessions')
  sessions.value = data.exam_sessions
}

const reload = async () => {
  if (!sessionId.value) return
  loading.value = true
  logs.value = []
  anomalies.value = []
  try {
    const perPage = { params: { per_page: 200 } }
    if (activeTab.value === 'logs') {
      const { data } = await api.get(`/invigilation/sessions/${sessionId.value}/logs`, perPage)
      logs.value = data.invigilation_logs.data
    } else {
      const { data } = await api.get(`/invigilation/sessions/${sessionId.value}/anomalies`, perPage)
      anomalies.value = data.exam_anomalies.data
    }
  } finally {
    loading.value = false
  }
}

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'
const actionLabel = (a) => ({
  checkin: '学生签到',
  seat_change: '换座登记',
  anomaly_report: '上报异常',
  anomaly_resolve: '处理异常',
  seat_scan: '扫码巡看',
  mark_absent: '标记缺考'
}[a] || a)
const actionIcon = (a) => ({ checkin: '✓', seat_change: '⇄', anomaly_report: '!', anomaly_resolve: '✓', seat_scan: '◎', mark_absent: '×' }[a] || '•')
const actionColor = (a) => ({
  checkin: 'bg-green-500',
  seat_change: 'bg-indigo-500',
  anomaly_report: 'bg-red-500',
  anomaly_resolve: 'bg-emerald-500',
  seat_scan: 'bg-gray-400',
  mark_absent: 'bg-yellow-500'
}[a] || 'bg-gray-400')
const severityLabel = (s) => ({ info: '提示', warning: '警告', critical: '严重' }[s] || s)
const severityBadge = (s) => ({
  info: 'bg-gray-100 text-gray-600',
  warning: 'bg-yellow-100 text-yellow-700',
  critical: 'bg-red-100 text-red-700'
}[s] || 'bg-gray-100 text-gray-600')
const typeLabel = (t) => ({
  computer_mismatch: '电脑不匹配',
  ip_mismatch: 'IP不在机房网段',
  no_checkin: '未签到开考',
  wrong_student: '替考嫌疑',
  manual_report: '巡考上报',
  idle: '长时间无作答'
}[t] || t)

onMounted(async () => {
  await loadSessions()
  if (sessionId.value) await reload()
})
</script>
