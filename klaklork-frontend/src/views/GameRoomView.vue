<template>
  <div class="min-h-screen px-3 py-4 md:px-6 md:py-6">
    <!-- Loading -->
    <div v-if="!game.room" class="flex items-center justify-center h-screen">
      <div class="text-center">
        <div class="text-4xl mb-4 animate-spin" style="display:inline-block">🎰</div>
        <p style="color: var(--text-muted)">Loading room…</p>
      </div>
    </div>

    <template v-else>
      <!-- Top bar -->
      <div class="max-w-4xl mx-auto flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
          <button class="btn btn-ghost text-sm" @click="leaveRoom">← Back</button>
          <div class="game-title text-xl">ខ្លាឃ្លោក</div>
          <span class="status-badge" :class="`status-${game.status}`">
            <span class="pulse-dot" :style="{ background: statusColor }"></span>
            {{ game.status }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <div class="balance-badge">💰 {{ auth.user?.balance?.toLocaleString() }} ៛</div>
          <!-- Copy invite link -->
          <button id="invite-btn" class="btn btn-ghost text-sm" @click="copyInvite">
            {{ copied ? '✅ Copied!' : '🔗 ' + game.room.code }}
          </button>
        </div>
      </div>

      <div class="max-w-4xl mx-auto grid gap-4 lg:grid-cols-[1fr_280px]">
        <!-- Left: Game Board -->
        <div class="flex flex-col gap-4">
          <!-- Result display -->
          <div class="card">
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-bold text-sm" style="color: var(--text-muted)">🎲 RESULT SLOTS</h3>
              <span v-if="game.status === 'spinning'" class="text-xs animate-pulse" style="color: var(--gold)">Rolling…</span>
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div v-for="i in 3" :key="i"
                class="result-slot aspect-square"
                :class="{ spinning: game.status === 'spinning', revealed: game.status === 'finished' }"
              >
                <img :src="resultImage(i)" :alt="`Slot ${i}`" class="w-full h-full object-cover rounded-lg" />
              </div>
            </div>
          </div>

          <!-- Betting Grid -->
          <div class="card">
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-bold text-sm" style="color: var(--text-muted)">🐾 PLACE YOUR BETS</h3>
              <span class="text-xs" style="color: var(--text-muted)">{{ game.room.bet_amount.toLocaleString() }} ៛ per click</span>
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div
                v-for="slot in 6" :key="slot"
                class="animal-tile"
                :class="tileClass(slot)"
                :id="`tile-${slot}`"
                @click="placeBet(slot)"
              >
                <img :src="`/images/${slot}.jpg`" :alt="`Animal ${slot}`" />
                <div v-if="game.myBets[slot]" class="bet-label">
                  ចាក់ {{ game.myBets[slot].toLocaleString() }} ៛
                </div>
                <!-- Win badge -->
                <div v-if="game.status === 'finished' && winBadge(slot)" class="bet-label" style="color: #55efc4">
                  {{ winBadge(slot) }}
                </div>
              </div>
            </div>

            <!-- Action buttons -->
            <div class="flex gap-3 mt-4 justify-center flex-wrap">
              <!-- Host controls -->
              <template v-if="game.isHost(auth.user?.id)">
                <button id="open-betting-btn" v-if="game.status === 'waiting'"
                  class="btn btn-gold" @click="openBetting" :disabled="actionLoading">
                  🟢 Open Betting
                </button>
                <button id="spin-btn" v-if="game.status === 'betting'"
                  class="btn btn-red" @click="spin" :disabled="actionLoading">
                  🎰 SPIN!
                </button>
                <button id="stop-btn" v-if="game.status === 'spinning'"
                  class="btn btn-gold" @click="stopSpin" :disabled="actionLoading">
                  🛑 STOP!
                </button>
              </template>

              <!-- Clear bets (for player during betting) -->
              <button id="clear-btn"
                v-if="game.status === 'betting' && game.totalBet > 0"
                class="btn btn-ghost text-sm" @click="clearLocalBets">
                🔄 Clear Bets
              </button>

              <!-- Total bet display -->
              <div v-if="game.totalBet > 0" class="balance-badge">
                Total: {{ game.totalBet.toLocaleString() }} ៛
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Sidebar -->
        <div class="flex flex-col gap-4">
          <!-- Players -->
          <div class="card">
            <h3 class="font-bold text-sm mb-3" style="color: var(--text-muted)">
              👥 PLAYERS ({{ game.players.length }}/{{ game.room.max_players }})
            </h3>
            <div class="flex flex-col gap-2">
              <div v-for="p in game.players" :key="p.id" class="player-pill">
                <div class="player-avatar">{{ p.name[0].toUpperCase() }}</div>
                <span class="text-sm">{{ p.name }}</span>
                <span v-if="p.id === game.room.host_user_id" class="text-xs ml-1" style="color: var(--gold)">👑</span>
                <span v-if="p.id === auth.user?.id" class="text-xs ml-auto" style="color: var(--text-muted)">(you)</span>
              </div>
            </div>
          </div>

          <!-- Live bets feed -->
          <div class="card" v-if="game.allBets.length">
            <h3 class="font-bold text-sm mb-3" style="color: var(--text-muted)">📊 BETS PLACED</h3>
            <div class="flex flex-col gap-2 max-h-48 overflow-y-auto">
              <div v-for="(b, i) in game.allBets" :key="i"
                class="flex items-center justify-between text-xs py-1"
                style="border-bottom: 1px solid var(--border)"
              >
                <span style="color: var(--text-muted)">{{ b.user?.name || b.name }}</span>
                <span>Animal {{ b.animal_slot }} — {{ b.amount?.toLocaleString() }} ៛</span>
                <span v-if="b.won_amount > 0" style="color: #55efc4">+{{ b.won_amount?.toLocaleString() }}</span>
                <span v-else-if="b.won_amount < 0" style="color: #ff7675">{{ b.won_amount?.toLocaleString() }}</span>
              </div>
            </div>
          </div>

          <!-- Status instructions -->
          <div class="card text-center" style="background: rgba(245,200,66,0.05)">
            <template v-if="game.status === 'waiting'">
              <div class="text-2xl mb-2">⏳</div>
              <p class="text-sm" style="color: var(--text-muted)">Waiting for host to open betting…</p>
            </template>
            <template v-else-if="game.status === 'betting'">
              <div class="text-2xl mb-2">🎯</div>
              <p class="text-sm font-semibold" style="color: var(--gold)">Click the animals to bet!</p>
              <p class="text-xs mt-1" style="color: var(--text-muted)">{{ game.room.bet_amount.toLocaleString() }} ៛ per click</p>
            </template>
            <template v-else-if="game.status === 'spinning'">
              <div class="text-2xl mb-2 animate-spin" style="display:inline-block">🎰</div>
              <p class="text-sm" style="color: var(--text-muted)">Rolling…</p>
            </template>
            <template v-else-if="game.status === 'finished'">
              <div class="text-2xl mb-2">🏆</div>
              <p class="text-sm font-semibold" style="color: #55efc4">Results are in!</p>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useGameStore } from '@/stores/game'
import api from '@/lib/api'
import { getEcho, disconnectEcho } from '@/lib/echo'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const game   = useGameStore()

const code         = route.params.code
const copied       = ref(false)
const actionLoading = ref(false)
const countdown    = ref('')
const spinImages   = ref([1, 1, 1])  // displayed result slot images

// Animal image: use from original project assets (served via public/)
function resultImage(i) {
  if (game.status === 'finished' && game.result) {
    return `/images/${game.result[i - 1]}.jpg`
  }
  if (game.status === 'spinning') {
    return `/images/${spinImages.value[i - 1]}.jpg`
  }
  return `/images/back.jpg`
}

const statusColor = computed(() => ({
  waiting:  '#8888ff',
  betting:  'var(--gold)',
  spinning: '#ff6b6b',
  finished: '#55efc4',
}[game.status] || '#888'))

function tileClass(slot) {
  const classes = []
  if (game.myBets[slot]) classes.push('has-bet')
  if (game.status === 'spinning') classes.push('spinning')
  if (game.status === 'finished' && game.result) {
    const count = game.result.filter(r => r === slot).length
    if (count === 1) classes.push('win-1x')
    else if (count === 2) classes.push('win-2x')
    else if (count === 3) classes.push('win-3x')
  }
  return classes
}

function winBadge(slot) {
  if (game.status !== 'finished' || !game.result || !game.myBets[slot]) return null
  const count = game.result.filter(r => r === slot).length
  if (count === 3) return '3x WIN! 🔥'
  if (count === 2) return '2x WIN! ✨'
  if (count === 1) return 'WIN! 🎉'
  return '❌ Loss'
}

// Spinning animation interval
let spinInterval = null
function startSpinAnimation() {
  spinInterval = setInterval(() => {
    spinImages.value = [
      Math.ceil(Math.random() * 6),
      Math.ceil(Math.random() * 6),
      Math.ceil(Math.random() * 6),
    ]
  }, 100)
}
function stopSpinAnimation() {
  if (spinInterval) clearInterval(spinInterval)
  spinInterval = null
}

// WebSocket subscriptions
let channel = null
function subscribeChannel() {
  const echo = getEcho()
  channel = echo.channel(`game.${code}`)
  channel
    .listen('PlayerJoined', (e) => {
      game.addPlayerJoined(e.user)
      game.addToast(`${e.user.name} joined the room`, 'info')
    })
    .listen('BettingOpened', () => {
      game.openBetting()
      game.addToast('Betting is now open! 🎯', 'info')
    })
    .listen('BetPlaced', (e) => {
      game.addBet({ user: e.user, animal_slot: e.animal_slot, amount: e.amount })
    })
    .listen('SpinStarted', () => {
      game.setSpinning()
      startSpinAnimation()
    })
    .listen('SpinResult', (e) => {
      stopSpinAnimation()
      game.setResult(e.result, e.bets)
      auth.refreshUser()
      // Toast my result
      const myResults = e.bets.filter(b => b.user?.id === auth.user?.id)
      myResults.forEach(b => {
        if (b.won_amount > 0) game.addToast(`You won ${b.won_amount.toLocaleString()} ៛! 🎉`, 'win')
        else game.addToast(`Lost ${Math.abs(b.won_amount).toLocaleString()} ៛ 😢`, 'loss')
      })
    })
}

// Actions
async function placeBet(slot) {
  if (game.status !== 'betting') return
  if (auth.user?.balance < game.room.bet_amount) {
    game.addToast('Insufficient balance!', 'loss'); return
  }
  try {
    const { data } = await api.post(`/games/${code}/bets`, { animal_slot: slot })
    game.myBets[slot] = (game.myBets[slot] || 0) + game.room.bet_amount
    auth.user.balance = data.balance
    game.addBet({ user: { id: auth.user.id, name: auth.user.name }, animal_slot: slot, amount: game.room.bet_amount })
  } catch (e) {
    game.addToast(e.response?.data?.message || 'Bet failed', 'loss')
  }
}

function clearLocalBets() {
  game.myBets = {}
}

async function openBetting() {
  actionLoading.value = true
  try { await api.post(`/games/${code}/open-betting`) } catch (e) {
    game.addToast(e.response?.data?.message || 'Error', 'loss')
  } finally { actionLoading.value = false }
}

async function spin() {
  actionLoading.value = true
  try {
    game.setSpinning()
    startSpinAnimation()
    await api.post(`/games/${code}/spin`)
  } catch (e) {
    stopSpinAnimation()
    game.addToast(e.response?.data?.message || 'Spin failed', 'loss')
  } finally { actionLoading.value = false }
}

async function stopSpin() {
  actionLoading.value = true
  try {
    await api.post(`/games/${code}/stop`)
  } catch (e) {
    game.addToast(e.response?.data?.message || 'Stop failed', 'loss')
  } finally { actionLoading.value = false }
}

function copyInvite() {
  const url = `${window.location.origin}/game/${code}`
  navigator.clipboard.writeText(url)
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

function leaveRoom() {
  disconnectEcho()
  game.reset()
  router.push({ name: 'lobby' })
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/games/${code}`)
    game.setRoom(data)
    game.setMyBets(data.bets || [], auth.user?.id)
    subscribeChannel()
  } catch (err) {
    console.error('Failed to load room:', err)
    router.push({ name: 'lobby' })
  }
})

onUnmounted(() => {
  stopSpinAnimation()
  if (channel) channel.stopListening('PlayerJoined').stopListening('BettingOpened')
    .stopListening('BetPlaced').stopListening('SpinStarted').stopListening('SpinResult')
  disconnectEcho()
})
</script>
