// app/src/router/index.js
import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../store/auth.js'

const routes = [
  {
    path: '/auth',
    component: () => import('../views/Auth.vue'),
  },
  {
    path: '/',
    component: () => import('../views/Chat.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/chat/private/:id',
    component: () => import('../views/Chat.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/chat/group/:id',
    component: () => import('../views/Chat.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/chat/private/:id/m/:messageId',
    component: () => import('../views/Chat.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/chat/group/:id/m/:messageId',
    component: () => import('../views/Chat.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.isLoggedIn) return '/auth'
  if (to.path === '/auth' && auth.isLoggedIn)   return '/'
})

export default router
