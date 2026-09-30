import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/auth/Login.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('../views/auth/Register.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/',
    name: 'Home',
    component: () => import('../views/Home.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/exams',
    name: 'Exams',
    component: () => import('../views/exams/Index.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/exams/:id',
    name: 'TakeExam',
    component: () => import('../views/exams/Take.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/records',
    name: 'Records',
    component: () => import('../views/exams/Records.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/questions',
    name: 'Questions',
    component: () => import('../views/questions/Index.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/exam-papers',
    name: 'ExamPapers',
    component: () => import('../views/exam-papers/Index.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/statistics',
    name: 'Statistics',
    component: () => import('../views/statistics/Index.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/invigilation/seats',
    name: 'AdminSeats',
    component: () => import('../views/invigilation/AdminSeats.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/invigilation/patrol',
    name: 'Patrol',
    component: () => import('../views/invigilation/Patrol.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/invigilation/logs',
    name: 'InvigilationLogs',
    component: () => import('../views/invigilation/Logs.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/invigilation/scan/:token',
    redirect: (to) => ({ name: 'Patrol', query: { token: to.params.token } })
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isLoggedIn) {
    next('/login')
    return
  }

  if (to.meta.roles && !to.meta.roles.includes(authStore.user?.role)) {
    next('/')
    return
  }

  if ((to.name === 'Login' || to.name === 'Register') && authStore.isLoggedIn) {
    next('/')
    return
  }

  next()
})

export default router
