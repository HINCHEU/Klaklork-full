<template>
  <div class="min-h-screen px-4 py-8">
    <!-- Header -->
    <div class="max-w-2xl mx-auto mb-8 flex items-center justify-between">
      <div class="game-title text-2xl">ខ្លាឃ្លោក</div>
      <div class="flex items-center gap-3">
        <div class="balance-badge">💰 {{ auth.user?.balance?.toLocaleString() }} ៛</div>
        <button class="btn btn-ghost text-sm" @click="logout">Logout</button>
      </div>
    </div>

    <div class="max-w-2xl mx-auto grid gap-6 md:grid-cols-2">
      <!-- Create Room -->
      <div class="card">
        <h2 class="text-lg font-bold mb-4">🎮 Create Game Room</h2>
        <form @submit.prevent="createRoom" class="flex flex-col gap-4">
          <div>
            <label class="label">Bet Amount per Click (៛)</label>
            <input id="create-bet-amount" v-model.number="createForm.bet_amount"
              type="number" class="input" min="100" max="100000" step="100" required />
          </div>
          <div>
            <label class="label">Max Players</label>
            <input id="create-max-players" v-model.number="createForm.max_players"
              type="number" class="input" min="2" max="20" required />
          </div>
          <div v-if="createError" class="text-sm text-red-400">{{ createError }}</div>
          <button id="create-room-btn" class="btn btn-gold" :disabled="creating">
            {{ creating ? 'Creating…' : '✨ Create Room' }}
          </button>
        </form>
      </div>

      <!-- Join Room -->
      <div class="card">
        <h2 class="text-lg font-bold mb-4">🔗 Join a Game</h2>
        <form @submit.prevent="joinRoom" class="flex flex-col gap-4">
          <div>
            <label class="label">Room Code</label>
            <input id="join-code" v-model="joinCode" type="text" class="input"
              placeholder="e.g. ABC123" maxlength="8" style="text-transform: uppercase; letter-spacing: 0.15em; font-weight: 700;" required />
          </div>
          <div v-if="joinError" class="text-sm text-red-400">{{ joinError }}</div>
          <button id="join-room-btn" class="btn btn-gold" :disabled="joining">
            {{ joining ? 'Joining…' : '🎲 Join Room' }}
          </button>
        </form>

        <!-- Open Rooms -->
        <div v-if="openRooms.length" class="mt-5">
          <p class="label mb-3">Open Rooms</p>
          <div class="flex flex-col gap-2">
            <div v-for="room in openRooms" :key="room.id"
              class="flex items-center justify-between p-3 rounded-xl cursor-pointer"
              style="background: var(--bg-surface); border: 1px solid var(--border)"
              @click="quickJoin(room.code)"
            >
              <div>
                <span class="font-bold tracking-wider">{{ room.code }}</span>
                <span class="text-xs ml-2" style="color: var(--text-muted)">by {{ room.host?.name }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-xs" style="color: var(--text-muted)">{{ room.players_count }}/{{ room.max_players }}</span>
                <span class="status-badge" :class="`status-${room.status}`">{{ room.status }}</span>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="mt-5 text-center text-sm" style="color: var(--text-muted)">No open rooms right now.</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/lib/api'

const auth    = useAuthStore()
const router  = useRouter()
const openRooms   = ref([])
const createForm  = ref({ bet_amount: 500, max_players: 10 })
const joinCode    = ref('')
const createError = ref('')
const joinError   = ref('')
const creating    = ref(false)
const joining     = ref(false)

onMounted(fetchRooms)

async function fetchRooms() {
  try { const { data } = await api.get('/games'); openRooms.value = data } catch {}
}

async function createRoom() {
  createError.value = ''; creating.value = true
  try {
    const { data } = await api.post('/games', createForm.value)
    router.push({ name: 'game', params: { code: data.code } })
  } catch (e) {
    createError.value = e.response?.data?.message || 'Failed to create room'
  } finally { creating.value = false }
}

async function joinRoom() {
  joinError.value = ''; joining.value = true
  try {
    await api.post(`/games/${joinCode.value.toUpperCase()}/join`)
    router.push({ name: 'game', params: { code: joinCode.value.toUpperCase() } })
  } catch (e) {
    joinError.value = e.response?.data?.message || 'Failed to join room'
  } finally { joining.value = false }
}

async function quickJoin(code) {
  joinCode.value = code
  await joinRoom()
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>
