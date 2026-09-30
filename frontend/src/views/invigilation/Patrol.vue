<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">巡考台</h1>
      <p class="text-sm text-gray-500 mt-1">扫描机位二维码或手动输入座位码，查看学生身份、考试进度与异常记录，并可登记换座。</p>
    </div>

    <!-- 扫码输入 -->
    <div class="bg-white rounded-xl shadow p-6">
      <div class="flex flex-col md:flex-row md:items-end gap-4">
        <div class="flex-1">
          <label class="block text-sm font-medium text-gray-700 mb-1">座位二维码内容 / 座位码</label>
          <input
            v-model="scanCode"
            @keyup.enter="lookup"
            type="text"
            class="w-full border rounded-lg px-3 py-2.5 font-mono text-sm"
            placeholder="如：SEAT-1-A1B2C3D4E5F60718"
            :disabled="lookupLoading"
          />
        </div>
        <button
          @click="lookup"
          :disabled="lookupLoading || !scanCode.trim()"
          class="bg-indigo-600 text-white px-6 py-2.5 rounded-lg hover:bg-indigo-700 disabled:opacity-50"
        >
          {{ lookupLoading ? '查询中...' : '扫码查询' }}
        </button>
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
        <label class="text-gray-600">或选择场次后按座位号查询：</label>
        <select v-model="manualSessionId" class="border rounded px-3 py-1.5">
          <option :value="null" disabled>选择场次</option>
          <option v-for="s in sessions" :key="s.id" :value="s.id">{{ s.name }}（{{ s.room?.name }}）</option>
        </select>
        <input v-model="manualSeatNo" @keyup.enter="lookupManual" class="border rounded px-3 py-1.5 w-32 uppercase" placeholder="座位号 A01" />
        <button @click="lookupManual" :disabled="!manualSessionId || !manualSeatNo.trim()" class="text-indigo-600 hover:underline disabled:opacity-40">查询</button>
      </div>
    </div>

    <!-- 座位详情 -->
    <div v-if="seat" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- 左：身份 + 进度 -->
      <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl shadow p-6">
          <div class="flex items-start justify-between">
            <div>
              <div class="flex items-center gap-3">
                <h2 class="text-xl font-bold text-gray-900">座位 {{ seat.seat_no }}</h2>
                <span class="text-sm px-2 py-0.5 rounded-full" :class="seat.checked_in ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'">
                  {{ seat.checked_in ? '已签到' : '未签到' }}
                </span>
              </div>
              <p class="text-sm text-gray-500 mt-1">
                {{ seat.room.name }}（{{ seat.room.building || '-' }}） · 当前电脑 {{ seat.current_computer_no }}
              </p>
            </div>
            <div class="text-right text-xs text-gray-400">
              <p>{{ seat.session.name }}</p>
              <p>{{ formatDateTime(seat.session.start_time) }} - {{ formatDateTime(seat.session.end_time) }}</p>
            </div>
          </div>

          <div class="mt-5 border-t pt-5">
            <h3 class="text-sm font-semibold text-gray-500 mb-3">学生身份</h3>
            <div v-if="seat.student" class="flex items-center gap-4">
              <div class="w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 font-bold text-xl flex items-center justify-center">
                {{ (seat.student.real_name || seat.student.username).charAt(0) }}
              </div>
              <div>
                <p class="text-lg font-semibold text-gray-900">{{ seat.student.real_name || seat.student.username }}</p>
                <p class="text-sm text-gray-500">学号/账号：{{ seat.student.username }} · {{ seat.student.email }}</p>
                <p v-if="seat.checkin_time" class="text-xs text-green-600 mt-1">
                  签到时间 {{ formatDateTime(seat.checkin_time) }} · 签到 IP {{ seat.checkin_ip || '-' }}
                </p>
              </div>
            </div>
            <div v-else class="text-sm text-gray-400">该座位尚未安排考生（空座）</div>
          </div>

          <div class="mt-5 border-t pt-5">
            <h3 class="text-sm font-semibold text-gray-500 mb-3">考试进度</h3>
            <div v-if="seat.progress" class="space-y-3">
              <div class="flex justify-between text-sm">
                <span class="text-gray-600">{{ seat.session.exam_paper.title }}</span>
                <span class="font-medium" :class="seat.progress.seconds_remaining < 300 ? 'text-red-600' : 'text-gray-800'">
                  剩余 {{ formatDuration(seat.progress.seconds_remaining) }}
                </span>
              </div>
              <div>
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                  <span>已作答 {{ seat.progress.answered_count }} / {{ seat.progress.question_count }} 题</span>
                  <span>{{ progressPercent }}%</span>
                </div>
                <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                  <div class="h-full bg-indigo-500 transition-all" :style="{ width: progressPercent + '%' }"></div>
                </div>
              </div>
              <p class="text-xs text-gray-400">
                状态：{{ seat.progress.status_label }}
                <template v-if="seat.last_progress_at"> · 最近作答心跳 {{ formatDateTime(seat.last_progress_at) }}</template>
              </p>
            </div>
            <div v-else class="text-sm text-gray-400">
              {{ seat.student ? '考生尚未开考' : '空座位' }}
            </div>
          </div>
        </div>

        <!-- 异常记录 -->
        <div class="bg-white rounded-xl shadow p-6">
          <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold text-gray-900">异常记录（{{ seat.anomalies.length }}）</h3>
            <button @click="openAnomalyModal" :disabled="!seat.student" class="text-sm bg-red-50 text-red-600 px-3 py-1.5 rounded-lg hover:bg-red-100 disabled:opacity-50">+ 上报异常</button>
          </div>
          <div v-if="seat.anomalies.length === 0" class="text-sm text-gray-400 py-4 text-center">暂无异常记录</div>
          <ul v-else class="space-y-3">
            <li v-for="a in seat.anomalies" :key="a.id" class="border rounded-lg p-3" :class="a.resolved ? 'bg-gray-50 border-gray-200' : severityBg(a.severity)">
              <div class="flex justify-between items-start gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-900">
                    {{ a.type_label }}
                    <span class="ml-2 text-xs px-1.5 py-0.5 rounded" :class="severityBadge(a.severity)">{{ severityLabel(a.severity) }}</span>
                    <span v-if="a.resolved" class="ml-1 text-xs text-green-600">已处理</span>
                  </p>
                  <p class="text-sm text-gray-600 mt-1 break-words">{{ a.detail }}</p>
                  <p class="text-xs text-gray-400 mt-1">{{ formatDateTime(a.created_at) }}</p>
                  <p v-if="a.resolved && a.resolved_note" class="text-xs text-green-700 mt-1">处理备注：{{ a.resolved_note }}</p>
                </div>
                <button v-if="!a.resolved" @click="resolveAnomaly(a)" class="text-xs text-indigo-600 hover:underline whitespace-nowrap">标记处理</button>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <!-- 右：操作区 -->
      <div class="space-y-6">
        <div class="bg-white rounded-xl shadow p-6">
          <h3 class="font-semibold text-gray-900 mb-4">监考操作</h3>
          <div class="space-y-3">
            <button
              @click="openChangeModal"
              :disabled="!seat.student"
              class="w-full text-left border rounded-lg p-3 hover:border-indigo-400 hover:bg-indigo-50 disabled:opacity-50"
            >
              <p class="text-sm font-medium text-gray-900">登记换座</p>
              <p class="text-xs text-gray-500 mt-0.5">调整到同场次其他座位，必须填写原因，并同步监考日志</p>
            </button>
            <router-link
              :to="`/invigilation/logs?session_id=${seat.session.id}`"
              class="block text-left border rounded-lg p-3 hover:border-indigo-400 hover:bg-indigo-50"
            >
              <p class="text-sm font-medium text-gray-900">查看本场监考日志</p>
              <p class="text-xs text-gray-500 mt-0.5">签到、换座、异常处理全程留痕</p>
            </router-link>
          </div>
        </div>
      </div>
    </div>

    <div v-else-if="!lookupLoading" class="bg-white rounded-xl shadow p-10 text-center text-gray-400">
      扫描或输入座位码后，将在此显示学生身份、考试进度和异常记录
    </div>

    <!-- 上报异常弹窗 -->
    <Teleport to="body">
      <div v-if="anomalyModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
          <div class="px-6 py-4 border-b"><h3 class="text-lg font-semibold">上报异常 — 座位 {{ seat?.seat_no }}</h3></div>
          <div class="px-6 py-4 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">异常类型</label>
              <select v-model="anomalyModal.form.type" class="w-full border rounded px-3 py-2">
                <option value="manual_report">巡考上报</option>
                <option value="idle">长时间无作答</option>
                <option value="wrong_student">人证不一致/替考嫌疑</option>
                <option value="computer_mismatch">电脑/机位异常</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">严重程度</label>
              <select v-model="anomalyModal.form.severity" class="w-full border rounded px-3 py-2">
                <option value="info">提示</option>
                <option value="warning">警告</option>
                <option value="critical">严重</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">情况说明 *</label>
              <textarea v-model="anomalyModal.form.detail" rows="3" class="w-full border rounded px-3 py-2" placeholder="请描述现场情况"></textarea>
            </div>
          </div>
          <div class="px-6 py-4 border-t flex justify-end space-x-3">
            <button @click="anomalyModal.show = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
            <button @click="submitAnomaly" :disabled="anomalyModal.saving" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 disabled:opacity-50">
              {{ anomalyModal.saving ? '提交中...' : '提交上报' }}
            </button>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500/75 -z-10" @click="anomalyModal.show = false"></div>
      </div>
    </Teleport>

    <!-- 换座弹窗 -->
    <Teleport to="body">
      <div v-if="changeModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
          <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold">登记换座 — {{ seat?.student?.real_name || seat?.student?.username }}</h3>
            <p class="text-xs text-gray-500 mt-1">当前座位 {{ seat?.seat_no }}（电脑 {{ seat?.current_computer_no }}）</p>
          </div>
          <div class="px-6 py-4 space-y-4">
            <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-700">
              换座必须填写原因，操作会写入换座登记表与监考日志；若目标座位有人，将执行双方对调。考生需在新机器重新签到。
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">目标座位号 *（同场次）</label>
              <input v-model="changeModal.form.target_seat_no" class="w-full border rounded px-3 py-2 uppercase" placeholder="如：B05" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">换座原因 *</label>
              <textarea v-model="changeModal.form.reason" rows="3" class="w-full border rounded px-3 py-2" placeholder="如：A01 电脑蓝屏无法恢复"></textarea>
            </div>
          </div>
          <div class="px-6 py-4 border-t flex justify-end space-x-3">
            <button @click="changeModal.show = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
            <button @click="submitChange" :disabled="changeModal.saving" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
              {{ changeModal.saving ? '提交中...' : '确认换座' }}
            </button>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500/75 -z-10" @click="changeModal.show = false"></div>
      </div>
    </Teleport>

    <!-- 处理异常弹窗 -->
    <Teleport to="body">
      <div v-if="resolveModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md">
          <div class="px-6 py-4 border-b"><h3 class="text-lg font-semibold">处理异常</h3></div>
          <div class="px-6 py-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">处理备注 *</label>
            <textarea v-model="resolveModal.note" rows="3" class="w-full border rounded px-3 py-2" placeholder="说明现场核实与处置情况"></textarea>
          </div>
          <div class="px-6 py-4 border-t flex justify-end space-x-3">
            <button @click="resolveModal.show = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
            <button @click="submitResolve" class="px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700">确认处理</button>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500/75 -z-10" @click="resolveModal.show = false"></div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'
import { useModal } from '../../composables/useModal'

const route = useRoute()
const toast = useToast()
const { alert } = useModal()

const sessions = ref([])
const scanCode = ref(route.query.token || '')
const lookupLoading = ref(false)
const seat = ref(null)

const manualSessionId = ref(null)
const manualSeatNo = ref('')

const loadSessions = async () => {
  const { data } = await api.get('/invigilation/sessions')
  sessions.value = data.exam_sessions
}

onMounted(async () => {
  await loadSessions()
  if (scanCode.value) {
    await lookup()
  }
})

const applySeat = (data) => {
  seat.value = data.seat
}

const lookup = async () => {
  if (!scanCode.value.trim()) return
  lookupLoading.value = true
  try {
    const { data } = await api.post('/invigilation/seats/lookup', { code: scanCode.value.trim() })
    applySeat(data)
  } catch (e) {
    seat.value = null
    alert(e.response?.data?.message || '未查询到座位信息', '扫码失败', 'error')
  } finally {
    lookupLoading.value = false
  }
}

const lookupManual = async () => {
  if (!manualSessionId.value || !manualSeatNo.value.trim()) return
  lookupLoading.value = true
  try {
    const { data } = await api.get(`/invigilation/sessions/${manualSessionId.value}/seats/${manualSeatNo.value.trim().toUpperCase()}`)
    applySeat(data)
    scanCode.value = data.seat.seat_token
  } catch (e) {
    seat.value = null
    alert(e.response?.data?.message || '未查询到座位信息', '查询失败', 'error')
  } finally {
    lookupLoading.value = false
  }
}

const progressPercent = computed(() => {
  if (!seat.value?.progress) return 0
  const total = seat.value.progress.question_count || 0
  if (!total) return 0
  return Math.min(100, Math.round((seat.value.progress.answered_count / total) * 100))
})

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'
const formatDuration = (sec) => {
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  const s = sec % 60
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

const severityLabel = (s) => ({ info: '提示', warning: '警告', critical: '严重' }[s] || s)
const severityBadge = (s) => ({
  info: 'bg-gray-100 text-gray-600',
  warning: 'bg-yellow-100 text-yellow-700',
  critical: 'bg-red-100 text-red-700'
}[s] || 'bg-gray-100 text-gray-600')
const severityBg = (s) => ({
  info: 'bg-gray-50 border-gray-200',
  warning: 'bg-yellow-50 border-yellow-200',
  critical: 'bg-red-50 border-red-200'
}[s] || 'bg-gray-50 border-gray-200')

/* 上报异常 */
const anomalyModal = reactive({ show: false, saving: false, form: { type: 'manual_report', severity: 'warning', detail: '' } })

const openAnomalyModal = () => {
  anomalyModal.form = { type: 'manual_report', severity: 'warning', detail: '' }
  anomalyModal.show = true
}

const submitAnomaly = async () => {
  if (!anomalyModal.form.detail.trim()) {
    toast.error('请填写情况说明')
    return
  }
  anomalyModal.saving = true
  try {
    await api.post(`/invigilation/seats/${seat.value.id}/anomalies`, anomalyModal.form)
    toast.success('异常已记录')
    anomalyModal.show = false
    await refreshSeat()
  } finally {
    anomalyModal.saving = false
  }
}

/* 处理异常 */
const resolveModal = reactive({ show: false, anomalyId: null, note: '' })

const resolveAnomaly = (a) => {
  resolveModal.anomalyId = a.id
  resolveModal.note = ''
  resolveModal.show = true
}

const submitResolve = async () => {
  if (!resolveModal.note.trim()) {
    toast.error('请填写处理备注')
    return
  }
  await api.post(`/invigilation/anomalies/${resolveModal.anomalyId}/resolve`, {
    resolved_note: resolveModal.note.trim()
  })
  resolveModal.show = false
  toast.success('已标记处理')
  await refreshSeat()
}

/* 换座 */
const changeModal = reactive({ show: false, saving: false, form: { target_seat_no: '', reason: '' } })

const openChangeModal = () => {
  changeModal.form = { target_seat_no: '', reason: '' }
  changeModal.show = true
}

const submitChange = async () => {
  if (!changeModal.form.target_seat_no.trim() || !changeModal.form.reason.trim()) {
    toast.error('目标座位号和换座原因均为必填')
    return
  }
  changeModal.saving = true
  try {
    const { data } = await api.post(`/invigilation/seats/${seat.value.id}/change`, {
      target_seat_no: changeModal.form.target_seat_no.trim().toUpperCase(),
      reason: changeModal.form.reason.trim()
    })
    changeModal.show = false
    await alert(data.message, '换座成功', 'success')
    seat.value = null
    scanCode.value = ''
    await loadSessions()
  } catch (e) {
    alert(e.response?.data?.message || '换座失败', '换座失败', 'error')
  } finally {
    changeModal.saving = false
  }
}

const refreshSeat = async () => {
  const { data } = await api.post('/invigilation/seats/lookup', { code: scanCode.value.trim() })
  applySeat(data)
}
</script>
