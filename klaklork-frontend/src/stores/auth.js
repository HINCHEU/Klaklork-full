import { defineStore } from 'pinia'
import api from '@/lib/api'
import { getToken, setToken, setLastName, getLastName, clearSession } from '@/lib/session'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: getToken(),
    lastName: getLastName() || '',
  }),
  getters: {
    isLoggedIn: (s) => !!s.user,
  },
  actions: {
    /** Enter the game with a display name — creates the player and their session. */
    async enter(name) {
      const { data } = await api.post('/guest', { name })
      this.applySession(data.token, data.user)
    },

    applySession(token, user) {
      this.token = token
      this.user  = user
      setToken(token)
      this.rememberName(user?.name)
    },

    rememberName(name) {
      if (!name) return
      this.lastName = name
      setLastName(name)
    },

    /** Resume a stored session on app boot. Returns true if the token is still good. */
    async fetchUser() {
      if (!this.token) return false
      try {
        const { data } = await api.get('/user')
        this.user = data
        this.rememberName(data?.name)
        return true
      } catch {
        // Token is gone/expired — drop it locally (no API call, it would 401 too)
        this.user  = null
        this.token = null
        clearSession()
        return false
      }
    },

    /** Apply a balance the server already told us about (no extra round-trip). */
    setBalance(balance) {
      if (this.user && balance != null) this.user.balance = balance
    },

    async refreshUser() {
      const { data } = await api.get('/user')
      this.user = data
    },

    async changeName(name) {
      const { data } = await api.patch('/user', { name })
      this.user = data
      this.rememberName(data?.name)
    },

    /** End the session for this browser (player can enter again with a new name). */
    async exit() {
      try { await api.post('/logout') } catch { /* token may already be invalid */ }
      this.user  = null
      this.token = null
      clearSession()
    },
  },
})
