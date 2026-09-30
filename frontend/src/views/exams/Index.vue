<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">在线考试</h1>
    </div>

    <!-- 线下机房考试安排 -->
    <div v-if="mySeats.length" class="space-y-4">
      <h2 class="text-lg font-bold text-gray-800 flex items-center">
        <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>
        我的线下机房考试
      </h2>
      <div
        v-for="seat in mySeats"
        :key="seat.assignment_id"
        class="bg-white rounded-xl shadow border-l-4 p-5"
        :class="seat.checked_in ? 'border-green-500' : 'border-indigo-500'"
      >
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div class="space-y-2">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="text-lg font-semibold text-gray-900">{{ seat.session.exam_paper?.title }}</h3>
              <span class="text-xs px-2 py-0.5 rounded-full" :class="sessionStatusClass(seat.session.status)">
                {{ seat.session.status_label }}
              </span>
              <span v-if="seat.checked_in" class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">已签到</span>
            </div>
            <p class="text-sm text-gray-600">{{ seat.session.name }}</p>
            <div class="text-sm text-gray-500 flex flex-wrap gap-x-6 gap-y-1">
              <span>机房：{{ seat.room?.name }}（{{ seat.room?.building || '-' }}）</span>
              <span>座位号：<b class="text-gray-800">{{ seat.seat_no }}</b></span>
              <span>电脑编号：<b class="text-gray-800">{{ seat.current_computer_no }}</b></span>
              <span>时间：{{ formatDateTime(seat.session.start_time) }} - {{ formatDateTime(seat.session.end_time) }}</span>
            </div>
            <p v-if="seat.checked_in && seat.checkin_time" class="text-xs text-green-600">
              签到时间：{{ formatDateTime(seat.checkin_time) }}
            </p>
            <p class="text-xs text-amber-600">
              签到后只能在本台电脑（{{ seat.current_computer_no }}）开考与交卷，换机器需联系监考老师登记换座。
            </p>
          </div>
          <div class="flex flex-col items-stretch lg:items-end gap-2 shrink-0">
            <div class="flex items-center gap-2">
              <input
                v-model="computerInputs[seat.assignment_id]"
                class="border rounded-lg px-3 py-2 text-sm w-40"
                placeholder="本机电脑编号"
                :disabled="seat.checked_in"
              />
              <button
                @click="checkin(seat)"
                :disabled="seat.checked_in || checking[seat.assignment_id]"
                class="whitespace-nowrap px-4 py-2 rounded-lg text-white text-sm transition-colors"
                :class="seat.checked_in ? 'bg-green-500 cursor-default' : 'bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50'"
              >
                {{ seat.checked_in ? '✓ 已签到' : (checking[seat.assignment_id] ? '签到中...' : '本机签到') }}
              </button>
            </div>
            <button
              v-if="seat.checked_in && seat.session.exam_paper"
              @click="startExam(seat.session.exam_paper, seat)"
              class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm"
            >
              进入考试
            </button>
            <button
              v-else-if="seat.session.exam_paper"
              @click="startExam(seat.session.exam_paper, seat)"
              class="px-4 py-2 border border-indigo-300 text-indigo-600 rounded-lg hover:bg-indigo-50 text-sm"
            >
              直接开考（需先签到）
            </button>
          </div>
        </div>
      </div>
    </div>

    <h2 class="text-lg font-bold text-gray-800 flex items-center pt-2">
      <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>
      可参加的试卷
    </h2>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="examPapers.length === 0" class="text-center py-8 text-gray-500">
      暂无可用试卷
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="paper in examPapers" :key="paper.id" class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ paper.title }}</h3>
        <p class="text-gray-600 text-sm mb-4">{{ paper.description || '暂无描述' }}</p>
        <div class="space-y-2 text-sm text-gray-500">
          <div class="flex justify-between">
            <span>题目数量</span>
            <span>{{ paper.question_count }} 题</span>
          </div>
          <div class="flex justify-between">
            <span>总分</span>
            <span>{{ paper.total_score }} 分</span>
          </div>
          <div class="flex justify-between">
            <span>考试时长</span>
            <span>{{ paper.total_time }} 分钟</span>
          </div>
        </div>
        <button @click="startExam(paper, null)" class="mt-4 w-full bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700 transition-colors">
          开始考试
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const router = useRouter()
const { alert } = useModal()
const toast = useToast()
const examPapers = ref([])
const mySeats = ref([])
const loading = ref(true)
const computerInputs = reactive({})
const checking = reactive({})

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'
const sessionStatusClass = (s) => ({
  scheduled: 'bg-gray-100 text-gray-600',
  ongoing: 'bg-green-100 text-green-700',
  finished: 'bg-blue-100 text-blue-700',
  cancelled: 'bg-red-100 text-red-700'
}[s] || 'bg-gray-100 text-gray-600')

onMounted(async () => {
  try {
    const [examRes, seatsRes] = await Promise.all([
      api.get('/exams'),
      api.get('/exams/seats/my').catch(() => ({ data: { my_seats: [] } }))
    ])
    examPapers.value = examRes.data.exam_papers.data
    mySeats.value = seatsRes.data.my_seats
    mySeats.value.forEach((s) => {
      computerInputs[s.assignment_id] = s.current_computer_no || ''
    })
  } catch (e) {
    console.error('Failed to fetch exam data:', e)
  } finally {
    loading.value = false
  }
})

const checkin = async (seat) => {
  const computerNo = (computerInputs[seat.assignment_id] || '').trim()
  if (!computerNo) {
    toast.error('请输入本机电脑编号')
    return
  }
  checking[seat.assignment_id] = true
  try {
    const { data } = await api.post('/exams/seats/checkin', {
      exam_session_id: seat.session.id,
      computer_no: computerNo
    })
    localStorage.setItem('exam_computer_no', data.seat.current_computer_no)
    toast.success(data.message)
    await refreshSeats()
  } catch (e) {
    alert(e.response?.data?.message || '签到失败', '签到失败', 'error')
  } finally {
    checking[seat.assignment_id] = false
  }
}

const refreshSeats = async () => {
  const seatsRes = await api.get('/exams/seats/my')
  mySeats.value = seatsRes.data.my_seats
  mySeats.value.forEach((s) => {
    if (computerInputs[s.assignment_id] === undefined) {
      computerInputs[s.assignment_id] = s.current_computer_no || ''
    }
  })
}

const startExam = async (paper, seat) => {
  // 线下场次但尚未签到：提示先签到
  if (seat && !seat.checked_in) {
    alert('请先在本机完成签到，再进入考试', '需要签到', 'warning')
    return
  }
  if (seat) {
    localStorage.setItem('exam_computer_no', seat.current_computer_no)
  }
  try {
    await api.post(`/exams/${paper.id}/start`)
    router.push(`/exams/${paper.id}`)
  } catch (e) {
    const data = e.response?.data
    alert(data?.message || '开始考试失败', '开始考试', 'error')
  }
}
</script>
