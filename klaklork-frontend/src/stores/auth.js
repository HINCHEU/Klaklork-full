import { defineStore } from 'pinia'
import api from '@/lib/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.user,
  },
  actions: {
    async register(name, email, password, password_confirmation) {
      const { data } = await api.post('/register', { name, email, password, password_confirmation })
      this.token = data.token
      this.user = data.user
      localStorage.setItem('token', data.token)
    },
    async login(email, password) {
      const { data } = await api.post('/login', { email, password })
      this.token = data.token
      this.user = data.user
      localStorage.setItem('token', data.token)
    },
    async fetchUser() {
      try {
        const { data } = await api.get('/user')
        this.user = data
      } catch {
        this.logout()
      }
    },
    async refreshUser() {
      const { data } = await api.get('/user')
      this.user = data
    },
    async logout() {
      try { await api.post('/logout') } catch {}
      this.user = null
      this.token = null
      localStorage.removeItem('token')
    },
  },
})
