import axios from 'axios'
import { getToken, clearSession } from '@/lib/session'

const api = axios.create({
  baseURL: '/api',
  headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
})

api.interceptors.request.use(config => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  r => r,
  err => {
    // Session token no longer valid — drop it and send the player back to the
    // name-entry screen (unless they're already there).
    if (err.response?.status === 401) {
      clearSession()
      if (window.location.pathname !== '/enter') window.location.href = '/enter'
    }
    return Promise.reject(err)
  }
)

export default api
