import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/enter',      name: 'enter', component: () => import('@/views/EnterView.vue'),     meta: { guest: true } },
  { path: '/',           name: 'lobby', component: () => import('@/views/LobbyView.vue'),     meta: { auth: true } },
  // The param is constrained to the shape of a real room code (mirrors
  // GameRoom::CODE_PATTERN on the API). A malformed invite link therefore never
  // mounts the room view at all — it falls through to the catch-all below, so
  // the code that reaches request paths and channel names is always well formed.
  {
    path: '/game/:code([A-HJ-NP-Za-hj-np-z2-9]{6})',
    name: 'game',
    component: () => import('@/views/GameRoomView.vue'),
    meta: { auth: true },
  },
  // Anything else (including the old /login and /register URLs) → lobby
  { path: '/:pathMatch(.*)*', redirect: { name: 'lobby' } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Resume the stored browser session once per page load
  if (!auth.user && auth.token) await auth.fetchUser()

  if (to.meta.auth && !auth.isLoggedIn) {
    // Remember where they were headed (e.g. an invite link) so we can send them
    // back there right after they type a name.
    return to.fullPath === '/'
      ? { name: 'enter' }
      : { name: 'enter', query: { redirect: to.fullPath } }
  }

  if (to.meta.guest && auth.isLoggedIn) return { name: 'lobby' }
})

export default router
