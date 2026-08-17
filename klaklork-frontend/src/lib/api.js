import axios from 'axios'
import { getToken, clearSession } from '@/lib/session'
import { t } from '@/lib/i18n'

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

    // Throttled. Every caller already shows `data.message`, so translating it
    // here is the one place that reaches all of them — otherwise a Khmer player
    // gets Laravel's English "Too Many Attempts." in the middle of a round.
    if (err.response?.status === 429) {
      const retryAfter = Number(err.response.headers?.['retry-after'])
      err.response.data = {
        ...err.response.data,
        message: retryAfter > 0
          ? t('toast.tooFastIn', { seconds: retryAfter })
          : t('toast.tooFast'),
      }
    }

    // A locked room reports why in a machine-readable field, so the prompt can
    // be shown in the player's language instead of the API's. Every caller
    // already renders `data.message`, so translating it here reaches them all.
    if (err.response?.data?.password_required) {
      err.response.data = {
        ...err.response.data,
        message: err.response.data.reason === 'invalid'
          ? t('lobby.errWrongPassword')
          : t('lobby.errPasswordRequired'),
      }
    }

    return Promise.reject(err)
  }
)

export default api
