<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">机房与座位绑定</h1>
        <p class="text-sm text-gray-500 mt-1">为每场考试导入机房、座位号、电脑编号与考生，学生签到后只能在指定机器开考。</p>
      </div>
      <button @click="openRoomModal()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">+ 新建机房</button>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 flex space-x-6">
      <button
        v-for="t in tabs" :key="t.key"
        @click="activeTab = t.key"
        class="pb-3 text-sm font-medium border-b-2 transition-colors"
        :class="activeTab === t.key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
      >{{ t.label }}</button>
    </div>

    <div v-if="loading" class="text-center py-10">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <!-- 机房管理 -->
    <template v-else-if="activeTab === 'rooms'">
      <div v-if="rooms.length === 0" class="text-center py-10 text-gray-500 bg-white rounded-lg shadow">暂无机房</div>
      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="room in rooms" :key="room.id" class="bg-white rounded-lg shadow p-5">
          <div class="flex justify-between items-start">
            <div>
              <h3 class="font-semibold text-gray-900">{{ room.name }}</h3>
              <p class="text-sm text-gray-500 mt-1">{{ room.building || '未填写位置' }}</p>
            </div>
            <span :class="room.status ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'" class="text-xs px-2 py-0.5 rounded-full">
              {{ room.status ? '启用' : '禁用' }}
            </span>
          </div>
          <div class="mt-3 text-sm text-gray-500 space-y-1">
            <p>网段：{{ room.ip_range || '不限' }}</p>
            <p>容量：{{ room.seat_count }} 座 · 已排场：{{ room.sessions_count }} 场</p>
          </div>
          <div class="mt-4 flex space-x-3 text-sm">
            <button @click="openRoomModal(room)" class="text-indigo-600 hover:underline">编辑</button>
            <button @click="removeRoom(room)" class="text-red-600 hover:underline">删除</button>
          </div>
        </div>
      </div>
    </template>

    <!-- 场次管理 -->
    <template v-else-if="activeTab === 'sessions'">
      <div class="flex justify-end">
        <button @click="openSessionModal()" :disabled="rooms.length === 0 || papers.length === 0"
          class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 disabled:opacity-50">+ 安排场次</button>
      </div>
      <div v-if="rooms.length === 0 || papers.length === 0" class="text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-lg p-3">
        安排场次前，请先创建机房，并确保系统中已有试卷。
      </div>
      <div v-if="sessions.length === 0" class="text-center py-10 text-gray-500 bg-white rounded-lg shadow">暂无考试场次</div>
      <div v-else class="bg-white shadow overflow-x-auto rounded-lg">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">场次</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">试卷</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">机房</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">时间</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">座位</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">状态</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 whitespace-nowrap">操作</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="s in sessions" :key="s.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ s.name }}</td>
              <td class="px-4 py-3 text-sm text-gray-600">{{ s.exam_paper?.title }}</td>
              <td class="px-4 py-3 text-sm text-gray-600">{{ s.room?.name }}</td>
              <td class="px-4 py-3 text-xs text-gray-500">{{ formatDateTime(s.start_time) }}<br/>至 {{ formatDateTime(s.end_time) }}</td>
              <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                <span class="text-gray-400">{{ s.seats_total || 0 }} 座</span>
                / 已排 {{ s.seats_assigned || 0 }}
                / 已签 {{ s.seats_checked || 0 }}
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded-full whitespace-nowrap" :class="statusClass(s.status)">{{ statusLabel(s.status) }}</span>
              </td>
              <td class="px-4 py-3 text-sm space-x-2 whitespace-nowrap">
                <button class="text-indigo-600 hover:underline" @click="openSeats(s)">座位导入/座位表</button>
                <button class="text-gray-600 hover:underline" @click="openSessionModal(s)">编辑</button>
                <button class="text-red-600 hover:underline" @click="removeSession(s)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- 机房弹窗 -->
    <Teleport to="body">
      <div v-if="roomModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
          <div class="px-6 py-4 border-b"><h3 class="text-lg font-semibold">{{ roomModal.id ? '编辑机房' : '新建机房' }}</h3></div>
          <div class="px-6 py-4 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">机房名称 *</label>
              <input v-model="roomModal.form.name" class="w-full border rounded px-3 py-2" placeholder="如：第一机房" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">位置</label>
              <input v-model="roomModal.form.building" class="w-full border rounded px-3 py-2" placeholder="如：实验楼A栋301" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">机房网段（CIDR，可选）</label>
              <input v-model="roomModal.form.ip_range" class="w-full border rounded px-3 py-2" placeholder="如：192.168.10.0/24" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">座位容量</label>
              <input v-model.number="roomModal.form.seat_count" type="number" min="0" class="w-full border rounded px-3 py-2" />
            </div>
            <label class="flex items-center text-sm text-gray-700">
              <input type="checkbox" v-model="roomModal.form.status" class="mr-2" /> 启用
            </label>
          </div>
          <div class="px-6 py-4 border-t flex justify-end space-x-3">
            <button @click="roomModal.show = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
            <button @click="saveRoom" :disabled="roomModal.saving" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
              {{ roomModal.saving ? '保存中...' : '保存' }}
            </button>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500/75 -z-10" @click="roomModal.show = false"></div>
      </div>
    </Teleport>

    <!-- 场次弹窗 -->
    <Teleport to="body">
      <div v-if="sessionModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
          <div class="px-6 py-4 border-b"><h3 class="text-lg font-semibold">{{ sessionModal.id ? '编辑场次' : '安排场次' }}</h3></div>
          <div class="px-6 py-4 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">场次名称 *</label>
              <input v-model="sessionModal.form.name" class="w-full border rounded px-3 py-2" placeholder="如：计算机基础-第一场" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">试卷 *</label>
              <select v-model="sessionModal.form.exam_paper_id" class="w-full border rounded px-3 py-2">
                <option :value="null" disabled>请选择试卷</option>
                <option v-for="p in papers" :key="p.id" :value="p.id">{{ p.title }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">机房 *</label>
              <select v-model="sessionModal.form.exam_room_id" class="w-full border rounded px-3 py-2">
                <option :value="null" disabled>请选择机房</option>
                <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}（{{ r.building || '未填位置' }}）</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">开考时间 *</label>
                <input v-model="sessionModal.form.start_time" type="datetime-local" class="w-full border rounded px-3 py-2" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">结束时间 *</label>
                <input v-model="sessionModal.form.end_time" type="datetime-local" class="w-full border rounded px-3 py-2" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <label class="flex items-center text-sm text-gray-700">
                <input type="checkbox" v-model="sessionModal.form.check_ip" class="mr-2" /> 校验机房网段
              </label>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">状态</label>
                <select v-model="sessionModal.form.status" class="w-full border rounded px-3 py-2">
                  <option value="scheduled">未开始</option>
                  <option value="ongoing">进行中</option>
                  <option value="finished">已结束</option>
                  <option value="cancelled">已取消</option>
                </select>
              </div>
            </div>
          </div>
          <div class="px-6 py-4 border-t flex justify-end space-x-3">
            <button @click="sessionModal.show = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
            <button @click="saveSession" :disabled="sessionModal.saving" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
              {{ sessionModal.saving ? '保存中...' : '保存' }}
            </button>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500/75 -z-10" @click="sessionModal.show = false"></div>
      </div>
    </Teleport>

    <!-- 座位导入/座位表 抽屉 -->
    <Teleport to="body">
      <div v-if="seatsModal.show" class="fixed inset-0 z-50 flex justify-end">
        <div class="relative bg-white w-full max-w-4xl h-full shadow-2xl flex flex-col">
          <div class="px-6 py-4 border-b flex justify-between items-center">
            <div>
              <h3 class="text-lg font-semibold">座位绑定 — {{ seatsModal.session?.name }}</h3>
              <p class="text-xs text-gray-500 mt-1">{{ seatsModal.session?.room?.name }} · 共 {{ seats.length }} 个座位 · 已排考生 {{ assignedCount }} · 已签到 {{ checkedCount }}</p>
            </div>
            <button @click="seatsModal.show = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
          </div>

          <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- 导入区 -->
            <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
              <h4 class="font-semibold text-gray-800 mb-2">批量导入座位</h4>
              <p class="text-xs text-gray-500 mb-3">
                每行一个座位，CSV/TSV 格式，表头建议：<code class="bg-white px-1 rounded">座位号,电脑编号,考生(学号/邮箱，可空)</code>
              </p>
              <textarea
                v-model="seatsModal.importContent"
                rows="5"
                class="w-full border rounded px-3 py-2 font-mono text-sm"
                placeholder="座位号,电脑编号,考生&#10;A01,PC-A01,student1@example.com&#10;A02,PC-A02,"
              ></textarea>
              <div v-if="seatsModal.importErrors.length" class="mt-2 bg-red-50 border border-red-200 rounded p-2 text-xs text-red-700 whitespace-pre-line">
                {{ seatsModal.importErrors.join('\n') }}
              </div>
              <div class="mt-3 flex items-center space-x-3">
                <label class="text-sm text-gray-700 flex items-center">
                  <input type="radio" value="merge" v-model="seatsModal.mode" class="mr-1" /> 按座位号合并更新
                </label>
                <label class="text-sm text-gray-700 flex items-center">
                  <input type="radio" value="replace" v-model="seatsModal.mode" class="mr-1" /> 清空后全量替换
                </label>
                <div class="flex-1"></div>
                <button @click="downloadTemplate" class="text-sm text-indigo-600 hover:underline">下载模板</button>
                <button @click="importSeats" :disabled="seatsModal.importing"
                  class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50 text-sm">
                  {{ seatsModal.importing ? '导入中...' : '导入' }}
                </button>
              </div>
            </div>

            <!-- 座位表 -->
            <div>
              <h4 class="font-semibold text-gray-800 mb-3">座位表（点击可查看/下载座位二维码）</h4>
              <div v-if="seats.length === 0" class="text-center py-8 text-gray-500 border border-dashed rounded-lg">尚未导入座位</div>
              <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                <button
                  v-for="seat in seats" :key="seat.id"
                  @click="openQr(seat)"
                  class="text-left border rounded-lg p-3 hover:border-indigo-400 hover:shadow transition"
                  :class="seat.checkin_time ? 'bg-green-50 border-green-200' : (seat.user_id ? 'bg-indigo-50 border-indigo-100' : 'bg-white')"
                >
                  <div class="flex justify-between items-center">
                    <span class="font-bold text-gray-900">{{ seat.seat_no }}</span>
                    <span v-if="seat.checkin_time" title="已签到" class="w-2 h-2 rounded-full bg-green-500"></span>
                  </div>
                  <p class="text-xs text-gray-500 mt-1">电脑 {{ seat.current_computer_no }}</p>
                  <p class="text-sm text-gray-700 mt-1 truncate">
                    {{ seat.user ? (seat.user.real_name || seat.user.username) : '（空座）' }}
                  </p>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500/50 -z-10" @click="seatsModal.show = false"></div>
      </div>
    </Teleport>

    <!-- 二维码弹窗 -->
    <Teleport to="body">
      <div v-if="qrModal.show" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl p-6">
          <h3 class="text-lg font-semibold text-center mb-1">座位 {{ qrModal.seat?.seat_no }}</h3>
          <p class="text-xs text-gray-500 text-center mb-4">电脑 {{ qrModal.seat?.current_computer_no }} · 贴于机位桌面，巡考扫码使用</p>
          <SeatQrCode :value="qrPayload" :seat-no="qrModal.seat?.seat_no" />
          <button @click="qrModal.show = false" class="mt-4 w-full px-4 py-2 border rounded hover:bg-gray-50">关闭</button>
        </div>
        <div class="fixed inset-0 bg-gray-500/75 -z-10" @click="qrModal.show = false"></div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'
import SeatQrCode from '../../components/SeatQrCode.vue'

const { confirm } = useModal()
const toast = useToast()

const tabs = [
  { key: 'sessions', label: '考试场次' },
  { key: 'rooms', label: '机房管理' }
]
const activeTab = ref('sessions')
const loading = ref(true)
const rooms = ref([])
const sessions = ref([])
const papers = ref([])

const load = async () => {
  loading.value = true
  try {
    const [r, s, p] = await Promise.all([
      api.get('/exam-rooms', { params: { per_page: 100 } }),
      api.get('/exam-sessions', { params: { per_page: 100 } }),
      api.get('/exam-papers', { params: { per_page: 100 } })
    ])
    rooms.value = r.data.exam_rooms.data
    sessions.value = s.data.exam_sessions.data
    papers.value = p.data.exam_papers.data
  } finally {
    loading.value = false
  }
}

onMounted(load)

const statusLabel = (s) => ({ scheduled: '未开始', ongoing: '进行中', finished: '已结束', cancelled: '已取消' }[s] || s)
const statusClass = (s) => ({
  scheduled: 'bg-gray-100 text-gray-600',
  ongoing: 'bg-green-100 text-green-700',
  finished: 'bg-blue-100 text-blue-700',
  cancelled: 'bg-red-100 text-red-700'
}[s] || 'bg-gray-100 text-gray-600')

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'

/* ---- 机房弹窗 ---- */
const roomModal = reactive({ show: false, id: null, saving: false, form: {} })

const openRoomModal = (room = null) => {
  roomModal.show = true
  roomModal.id = room?.id || null
  roomModal.form = room
    ? { ...room }
    : { name: '', building: '', ip_range: '', seat_count: 0, status: true }
}

const saveRoom = async () => {
  roomModal.saving = true
  try {
    const payload = { ...roomModal.form, status: roomModal.form.status ? 1 : 0 }
    if (roomModal.id) {
      await api.put(`/exam-rooms/${roomModal.id}`, payload)
      toast.success('机房已更新')
    } else {
      await api.post('/exam-rooms', payload)
      toast.success('机房已创建')
    }
    roomModal.show = false
    await load()
  } finally {
    roomModal.saving = false
  }
}

const removeRoom = async (room) => {
  if (!(await confirm(`确认删除机房「${room.name}」？已有场次的机房不能删除。`, '删除机房'))) return
  await api.delete(`/exam-rooms/${room.id}`)
  toast.success('已删除')
  await load()
}

/* ---- 场次弹窗 ---- */
const toLocalInput = (d) => {
  if (!d) return ''
  const dt = new Date(d)
  const pad = (n) => String(n).padStart(2, '0')
  return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`
}

const sessionModal = reactive({ show: false, id: null, saving: false, form: {} })

const openSessionModal = (s = null) => {
  sessionModal.show = true
  sessionModal.id = s?.id || null
  sessionModal.form = s
    ? { ...s, start_time: toLocalInput(s.start_time), end_time: toLocalInput(s.end_time) }
    : {
        name: '',
        exam_paper_id: papers.value[0]?.id ?? null,
        exam_room_id: rooms.value[0]?.id ?? null,
        start_time: toLocalInput(new Date(Date.now() + 3600000)),
        end_time: toLocalInput(new Date(Date.now() + 3 * 3600000)),
        check_ip: false,
        status: 'scheduled'
      }
}

const saveSession = async () => {
  sessionModal.saving = true
  try {
    const payload = { ...sessionModal.form, check_ip: sessionModal.form.check_ip ? 1 : 0 }
    if (sessionModal.id) {
      await api.put(`/exam-sessions/${sessionModal.id}`, payload)
      toast.success('场次已更新')
    } else {
      await api.post('/exam-sessions', payload)
      toast.success('场次已创建')
    }
    sessionModal.show = false
    await load()
  } finally {
    sessionModal.saving = false
  }
}

const removeSession = async (s) => {
  if (!(await confirm(`确认删除场次「${s.name}」？该场次的座位绑定、异常与监考日志会一并删除。`, '删除场次', 'error'))) return
  await api.delete(`/exam-sessions/${s.id}`)
  toast.success('已删除')
  await load()
}

/* ---- 座位导入 ---- */
const seatsModal = reactive({
  show: false,
  session: null,
  importContent: '',
  mode: 'merge',
  importing: false,
  importErrors: []
})
const seats = ref([])

const assignedCount = computed(() => seats.value.filter((s) => s.user_id).length)
const checkedCount = computed(() => seats.value.filter((s) => s.checkin_time).length)

const openSeats = async (session) => {
  seatsModal.show = true
  seatsModal.session = session
  seatsModal.importContent = ''
  seatsModal.importErrors = []
  await refreshSeats()
}

const refreshSeats = async () => {
  const { data } = await api.get(`/exam-sessions/${seatsModal.session.id}/seats`)
  seats.value = data.seats
}

const importSeats = async () => {
  if (!seatsModal.importContent.trim()) {
    toast.error('请先粘贴或填写座位数据')
    return
  }
  seatsModal.importing = true
  seatsModal.importErrors = []
  try {
    const { data } = await api.post(`/exam-sessions/${seatsModal.session.id}/seats/import`, {
      content: seatsModal.importContent,
      mode: seatsModal.mode
    })
    toast.success(data.message)
    seatsModal.importContent = ''
    await refreshSeats()
    await load()
  } catch (e) {
    seatsModal.importErrors = e.response?.data?.errors || [e.response?.data?.message || '导入失败']
  } finally {
    seatsModal.importing = false
  }
}

const downloadTemplate = () => {
  const csv = '座位号,电脑编号,考生\nA01,PC-A01,student1@example.com\nA02,PC-A02,\n'
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'seat-import-template.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

/* ---- 二维码 ---- */
const qrModal = reactive({ show: false, seat: null })
const qrPayload = computed(() => qrModal.seat?.seat_token || '')

const openQr = (seat) => {
  qrModal.seat = seat
  qrModal.show = true
}
</script>
