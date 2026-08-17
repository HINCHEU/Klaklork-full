import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import api from '@/lib/api'

window.Pusher = Pusher

let echo = null

export function getEcho() {
  if (!echo) {
    echo = new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
      wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
      wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
      enabledTransports: ['ws', 'wss'],
      // No namespace — we use the dot-prefix convention in .listen() calls
      namespace: '',
      // Room feeds are private channels, so every subscription is signed by the
      // API. Going through the shared axios client means the handshake carries
      // whichever session token is current — including after a re-entry — so
      // nothing here can cache a stale one.
      authorizer: (channel) => ({
        authorize: (socketId, callback) => {
          api.post('/broadcasting/auth', {
            socket_id: socketId,
            channel_name: channel.name,
          })
            .then(({ data }) => callback(null, data))
            .catch((error) => callback(error, null))
        },
      }),
    })
  }
  return echo
}

export function disconnectEcho() {
  if (echo) {
    echo.disconnect()
    echo = null
  }
}
