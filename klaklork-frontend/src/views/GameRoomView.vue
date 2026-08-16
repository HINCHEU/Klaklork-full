<template>
  <div class="bg-animated"></div>
  <div class="bg-grid"></div>

  <div class="min-h-screen z-content" style="position:relative">
    <!-- Loading -->
    <div v-if="!game.room" class="flex items-center justify-center min-h-screen">
      <div class="text-center">
        <div style="font-size:3rem; animation: spinAnim 1s linear infinite; display:inline-block; margin-bottom:1rem">🎰</div>
        <p style="color:var(--text-muted)">{{ t('room.loading') }}</p>
      </div>
    </div>

    <template v-else>
      <!-- ── Sticky Top Bar ── -->
      <header style="border-bottom:1px solid var(--border-dim); backdrop-filter:blur(24px); background:rgba(8,8,18,0.8); position:sticky; top:0; z-index:50">
        <div class="w-full mx-auto px-4 py-3 flex items-center justify-between gap-3 flex-wrap" style="max-width:1024px">
          <div class="flex items-center gap-3">
            <button class="btn btn-ghost" style="padding:0.45rem 0.85rem; font-size:0.85rem; gap:0.4rem" @click="leaveRoom">
              {{ t('common.back') }}
            </button>
            <div class="game-title" style="font-size:1.4rem">ខ្លាឃ្លោក</div>
            <span class="status-badge" :class="`status-${game.status}`">
              <span class="pulse-dot" :style="{ background: statusColor }"></span>
              {{ t(`status.${game.status}`) }}
            </span>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <div class="balance-badge">
              <span>💰</span>
              <span>{{ auth.user?.balance?.toLocaleString() }} ៛</span>
            </div>
            <LanguageToggle />
            <button id="sound-btn" class="btn btn-ghost" :title="muted ? t('room.soundOff') : t('room.soundOn')"
              style="padding:0.45rem 0.7rem; font-size:0.9rem" @click="toggleSound">
              {{ muted ? '🔇' : '🔊' }}
            </button>
            <button id="invite-btn" class="btn btn-ghost" style="padding:0.45rem 0.85rem; font-size:0.82rem; letter-spacing:0.08em; font-weight:800" @click="copyInvite">
              {{ copied ? t('room.copied') : `🔗 ${game.room.code}` }}
            </button>
          </div>
        </div>
      </header>

      <!-- ── Main Layout ── -->
      <div class="w-full mx-auto px-4 py-5 grid gap-5 lg:grid-cols-[1fr_270px]" style="max-width:1024px">

        <!-- ══ LEFT: Game Area ══ -->
        <div class="flex flex-col gap-4">

          <!-- Result Slots -->
          <div class="card card-premium">
            <div class="flex items-center justify-between mb-4">
              <div class="section-header" style="margin-bottom:0">{{ t('room.resultSlots') }}</div>
              <div v-if="game.status === 'spinning'"
                style="display:flex; align-items:center; gap:0.4rem; font-size:0.8rem; color:var(--crimson); font-weight:700; animation: countPulse 0.8s ease infinite">
                <span>⚡</span> {{ t('room.rolling') }}
              </div>
              <div v-else-if="game.status === 'finished'"
                style="font-size:0.8rem; color:var(--jade); font-weight:700">
                {{ t('room.roundComplete') }}
              </div>
            </div>
            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:0.75rem; max-width:480px; margin: 0 auto">
              <div v-for="i in 3" :key="i"
                class="result-slot"
                :class="{ spinning: game.status === 'spinning', revealed: game.status === 'finished' }"
                style="aspect-ratio:1"
              >
                <img :src="resultImage(i)" :alt="`Slot ${i}`"
                  style="width:100%; height:100%; object-fit:cover; border-radius:12px; display:block" />
              </div>
            </div>

            <!-- Net P&L after round -->
            <div v-if="game.status === 'finished' && myRoundResult !== null"
              style="margin-top:1rem; text-align:center; padding:0.85rem; border-radius:14px; border:1px solid"
              :style="{
                background: myRoundResult >= 0 ? 'rgba(16,185,129,0.1)' : 'rgba(232,57,74,0.1)',
                borderColor: myRoundResult >= 0 ? 'rgba(16,185,129,0.3)' : 'rgba(232,57,74,0.3)',
                color: myRoundResult >= 0 ? '#6ee7b7' : '#fca5a5',
              }"
            >
              <span style="font-size:1.5rem; font-weight:900">
                {{ myRoundResult >= 0 ? `+${myRoundResult.toLocaleString()} ៛` : `${myRoundResult.toLocaleString()} ៛` }}
                <template v-if="isBanker">{{ myRoundResult >= 0 ? '🏦' : '💸' }}</template>
                <template v-else>{{ myRoundResult >= 0 ? '🎉' : '😢' }}</template>
              </span>
              <div v-if="isBanker" style="font-size:0.78rem; opacity:0.85; margin-top:0.2rem">
                {{ myRoundResult >= 0 ? t('room.collectedFrom') : t('room.paidOutTo') }}
              </div>
            </div>
          </div>

          <!-- Betting Grid -->
          <div class="card">
            <div class="flex items-center justify-between mb-4">
              <div class="section-header" style="margin-bottom:0">{{ t('room.board') }}</div>
              <div style="display:flex; align-items:center; gap:0.5rem">
                <span v-if="isBanker" style="font-size:0.78rem; color:var(--gold); font-weight:700">{{ t('room.youAreBanker') }}</span>
                <span style="font-size:0.78rem; color:var(--text-muted)">{{ t('common.perClick', { amount: game.room.bet_amount?.toLocaleString() }) }}</span>
                <button v-if="game.status === 'betting' && game.totalBet > 0"
                  class="btn btn-ghost" style="padding:0.3rem 0.7rem; font-size:0.75rem" @click="clearLocalBets">
                  {{ t('room.clear') }}
                </button>
              </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:0.75rem; max-width:480px; margin: 0 auto">
              <div
                v-for="slot in 6" :key="slot"
                class="animal-tile"
                :class="tileClass(slot)"
                :id="`tile-${slot}`"
                @click="placeBet(slot)"
              >
                <img :src="`/images/${slot}.jpg`" :alt="`Animal ${slot}`" />
                <div v-if="isBanker && slotStake(slot)" class="bet-label">
                  {{ t(game.status === 'finished' ? 'room.staked' : 'room.atRisk', { amount: slotStake(slot).toLocaleString() }) }}
                </div>
                <div v-else-if="game.myBets[slot]" class="bet-label">
                  {{ game.myBets[slot].toLocaleString() }} ៛
                </div>
                <div v-if="game.status === 'finished' && winBadge(slot)" class="bet-label"
                  :style="{ color: winBadgeColor(slot) }">
                  {{ winBadge(slot) }}
                </div>
              </div>
            </div>

            <!-- Total bet + host controls row -->
            <div class="flex flex-col items-center gap-4 mt-6">
              <div class="flex gap-2 flex-wrap justify-center w-full">
                <template v-if="game.isHost(auth.user?.id)">
                  <button id="open-betting-btn"
                    v-if="game.status === 'waiting' || game.status === 'finished'"
                    class="btn btn-jade" @click="openBetting" :disabled="actionLoading">
                    {{ t('room.openBetting') }}
                  </button>
                  <button id="spin-btn" v-if="game.status === 'betting'"
                    class="btn btn-red" @click="spin" :disabled="actionLoading">
                    {{ t('room.spin') }}
                  </button>
                  <button id="stop-btn" v-if="game.status === 'spinning'"
                    class="btn btn-gold" @click="stopSpin" :disabled="actionLoading">
                    {{ t('room.stop') }}
                  </button>
                </template>
              </div>

              <div v-if="isBanker && tableStake > 0" class="balance-badge" style="font-size:0.9rem">
                {{ t('room.onTheTable', { amount: tableStake.toLocaleString() }) }}
              </div>
              <div v-else-if="!isBanker && game.totalBet > 0" class="balance-badge" style="font-size:0.9rem">
                {{ t('room.totalBet', { amount: game.totalBet.toLocaleString() }) }}
              </div>
              <div v-else style="font-size:0.85rem; color:var(--text-muted)">
                {{ game.status !== 'betting' ? '' : isBanker ? t('room.waitingForBets') : t('room.clickToBet') }}
              </div>
            </div>
          </div>

          <!-- Round History -->
          <div v-if="game.roundHistory.length" class="card">
            <div class="flex items-center justify-between mb-3 cursor-pointer"
              @click="historyOpen = !historyOpen">
              <div class="section-header" style="margin-bottom:0; flex:1">{{ t('room.history', { count: game.roundHistory.length }) }}</div>
              <span style="font-size:0.75rem; color:var(--text-muted)">{{ historyOpen ? '▲' : '▼' }}</span>
            </div>
            <div v-if="historyOpen" class="flex flex-col gap-2">
              <div v-for="(h, idx) in game.roundHistory" :key="idx"
                style="display:flex; align-items:center; gap:0.75rem; padding:0.5rem 0; border-bottom:1px solid var(--border-dim)"
              >
                <span style="font-size:0.7rem; color:var(--text-faint); width:24px; text-align:center">#{{ game.roundHistory.length - idx }}</span>
                <div style="display:flex; gap:0.35rem">
                  <img v-for="r in h.result" :key="r" :src="`/images/${r}.jpg`"
                    style="width:28px; height:28px; border-radius:7px; object-fit:cover" />
                </div>
                <span style="margin-left:auto; font-size:0.7rem; color:var(--text-muted)">
                  {{ new Date(h.timestamp).toLocaleTimeString() }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ RIGHT: Sidebar ══ -->
        <div class="flex flex-col gap-4">

          <!-- Status card -->
          <div class="card" :style="statusCardStyle">
            <template v-if="game.status === 'waiting'">
              <div style="font-size:2rem; margin-bottom:0.5rem">⏳</div>
              <p style="font-weight:700; font-size:0.95rem; margin-bottom:0.2rem">{{ t('room.waitingTitle') }}</p>
              <p style="color:var(--text-muted); font-size:0.82rem">{{ t('room.waitingBody') }}</p>
            </template>
            <template v-else-if="game.status === 'betting'">
              <template v-if="isBanker">
                <div style="font-size:2rem; margin-bottom:0.5rem">🏦</div>
                <p style="font-weight:700; font-size:0.95rem; color:var(--gold); margin-bottom:0.2rem">{{ t('room.bankerTitle') }}</p>
                <p style="color:var(--text-muted); font-size:0.82rem">
                  {{ t('room.bankerBody') }}
                </p>
              </template>
              <template v-else>
                <div style="font-size:2rem; margin-bottom:0.5rem">🎯</div>
                <p style="font-weight:700; font-size:0.95rem; color:var(--gold); margin-bottom:0.2rem">{{ t('room.placeBets') }}</p>
                <p style="color:var(--text-muted); font-size:0.82rem">{{ t('room.perClickNote', { amount: game.room.bet_amount?.toLocaleString() }) }}</p>
              </template>
            </template>
            <template v-else-if="game.status === 'spinning'">
              <div style="font-size:2rem; margin-bottom:0.5rem; animation:spinAnim 0.8s linear infinite; display:inline-block">🎰</div>
              <p style="font-weight:700; font-size:0.95rem; margin-bottom:0.2rem">{{ t('room.rollingTitle') }}</p>
              <p style="color:var(--text-muted); font-size:0.82rem">{{ t('room.rollingBody') }}</p>
            </template>
            <template v-else-if="game.status === 'finished'">
              <div style="font-size:2rem; margin-bottom:0.5rem">🏆</div>
              <p style="font-weight:700; font-size:0.95rem; color:#6ee7b7; margin-bottom:0.2rem">{{ t('room.resultsIn') }}</p>
              <p v-if="!game.isHost(auth.user?.id)" style="color:var(--text-muted); font-size:0.82rem">
                {{ t('room.waitingNextRound') }}
              </p>
            </template>
          </div>

          <!-- Players List -->
          <div class="card">
            <div class="section-header">
              {{ t('room.playersTitle', { count: game.players.length, max: game.room.max_players }) }}
            </div>
            <div style="display:flex; flex-direction:column; gap:0.5rem">
              <div v-for="p in game.players" :key="p.id" class="player-chip">
                <div class="avatar">{{ p.name?.[0]?.toUpperCase() }}</div>
                <div style="flex:1; min-width:0">
                  <div style="font-size:0.85rem; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                    {{ p.name }}
                    <span v-if="p.id === game.room.host_user_id" style="color:var(--gold)"> 👑</span>
                    <span v-if="p.id === auth.user?.id" style="color:var(--text-muted); font-size:0.7rem"> {{ t('common.you') }}</span>
                  </div>
                  <div v-if="p.balance != null" style="font-size:0.72rem; color:var(--text-muted)">
                    {{ p.balance?.toLocaleString() }} ៛
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Round settlement — who paid whom -->
          <div v-if="game.settlement" id="settlement-card" class="card">
            <div class="section-header">{{ t('room.settlement') }}</div>
            <div style="display:flex; flex-direction:column; gap:0.35rem">
              <div v-for="p in game.settlement.players" :key="p.id"
                style="display:flex; align-items:center; justify-content:space-between; font-size:0.8rem; padding:0.35rem 0; border-bottom:1px solid var(--border-dim)">
                <span style="color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                  {{ p.name }}<span v-if="p.id === auth.user?.id" style="color:var(--text-faint)"> {{ t('common.you') }}</span>
                </span>
                <span :style="{ color: p.net >= 0 ? '#6ee7b7' : '#fca5a5', fontWeight: 700 }">
                  {{ p.net >= 0 ? `+${p.net.toLocaleString()}` : p.net.toLocaleString() }} ៛
                </span>
              </div>
              <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.82rem; padding-top:0.5rem; font-weight:800">
                <span style="color:var(--gold)">🏦 {{ game.settlement.host.name }} {{ t('room.banker') }}</span>
                <span :style="{ color: game.settlement.host.net >= 0 ? '#6ee7b7' : '#fca5a5' }">
                  {{ game.settlement.host.net >= 0 ? `+${game.settlement.host.net.toLocaleString()}` : game.settlement.host.net.toLocaleString() }} ៛
                </span>
              </div>
            </div>
          </div>

          <!-- Live Bets Feed -->
          <div v-if="game.allBets.length" class="card">
            <div class="section-header">{{ t('room.betsPlaced') }}</div>
            <div style="display:flex; flex-direction:column; gap:0.4rem; max-height:200px; overflow-y:auto">
              <div v-for="(b, i) in game.allBets" :key="i"
                style="display:flex; align-items:center; justify-content:space-between; font-size:0.78rem; padding:0.4rem 0; border-bottom:1px solid var(--border-dim)"
              >
                <div style="display:flex; align-items:center; gap:0.5rem">
                  <img :src="`/images/${b.animal_slot}.jpg`"
                    style="width:22px; height:22px; border-radius:5px; object-fit:cover" />
                  <span style="color:var(--text-muted)">{{ b.user?.name || b.name }}</span>
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem">
                  <span>{{ b.amount?.toLocaleString() }} ៛</span>
                  <span v-if="b.won_amount > 0" style="color:#6ee7b7; font-weight:700">+{{ b.won_amount?.toLocaleString() }}</span>
                  <span v-else-if="b.won_amount < 0" style="color:#fca5a5; font-weight:700">{{ b.won_amount?.toLocaleString() }}</span>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- Win / lose popup -->
      <transition name="fade">
        <div v-if="popup" id="result-popup" class="popup-backdrop" @click.self="closePopup">
          <div class="card card-premium popup-card" :class="popup.win ? 'popup-win' : 'popup-loss'">
            <img :src="popup.image" :alt="popup.win ? 'Happy' : 'Sad'" class="popup-face" />
            <div id="popup-amount" style="font-size:2rem; font-weight:900; margin-top:0.5rem"
              :style="{ color: popup.win ? '#6ee7b7' : '#fca5a5' }">
              {{ popup.title }}
            </div>
            <p style="color:var(--text-muted); font-size:0.9rem; margin-top:0.2rem">{{ popup.message }}</p>
            <button id="popup-close" class="btn btn-gold w-full" style="margin-top:1.25rem" @click="closePopup">
              {{ t('room.popupClose') }}
            </button>
          </div>
        </div>
      </transition>
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
import { setLastRoom, clearLastRoom } from '@/lib/session'
import LanguageToggle from '@/components/LanguageToggle.vue'
import { useI18n } from '@/lib/i18n'
import { playClick, playWin, startMusic, stopMusic, isMuted, toggleMute } from '@/lib/sound'

const { t }  = useI18n()
const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const game   = useGameStore()

// Room codes are canonically uppercase — a typed/shared lowercase link must still
// land on the same broadcast channel.
const code          = String(route.params.code || '').toUpperCase()
const copied        = ref(false)
const actionLoading = ref(false)
const spinImages    = ref([1, 1, 1])
const historyOpen   = ref(true)
const muted         = ref(isMuted())
const popup         = ref(null)   // { win: bool, title, message, image }
let pollInterval    = null
let popupTimer      = null

function toggleSound() { muted.value = toggleMute() }

/** Win/lose popup with the happy/sad face, like the original game. */
function showResultPopup(net, banker) {
  const win  = net >= 0
  const even = net === 0
  popup.value = {
    win,
    image: win ? '/images/happy.png' : '/images/sad.png',
    title: even
      ? t('room.popupBreakEven')
      : t('common.riel', { amount: `${win ? '+' : ''}${net.toLocaleString()}` }),
    message: even
      ? t('room.popupBreakEvenBody')
      : banker
        ? (win ? t('room.popupCollected') : t('room.popupPaidOut'))
        : (win ? t('room.popupWin') : t('room.popupLose')),
  }
  if (win && !even) playWin()
  clearTimeout(popupTimer)
  popupTimer = setTimeout(closePopup, 6000)
}

function closePopup() {
  clearTimeout(popupTimer)
  popup.value = null
}

// The host banks the game: they pay every winner and collect every losing stake.
const isBanker = computed(() => game.isHost(auth.user?.id))

const myRoundResult = computed(() => {
  if (game.status !== 'finished') return null
  // The banker's result is the whole round's transfer, not a bet of their own
  if (isBanker.value) return game.settlement?.host?.net ?? null
  const myBets = game.allBets.filter(b => b.user?.id === auth.user?.id)
  if (!myBets.length) return null
  return myBets.reduce((sum, b) => sum + (b.won_amount || 0), 0)
})

const statusCardStyle = computed(() => {
  const map = {
    waiting:  'background: rgba(99,102,241,0.05); border-color: rgba(99,102,241,0.15); text-align:center; padding:1.25rem',
    betting:  'background: rgba(245,200,66,0.05); border-color: rgba(245,200,66,0.2); text-align:center; padding:1.25rem',
    spinning: 'background: rgba(232,57,74,0.06); border-color: rgba(232,57,74,0.2); text-align:center; padding:1.25rem',
    finished: 'background: rgba(16,185,129,0.05); border-color: rgba(16,185,129,0.2); text-align:center; padding:1.25rem',
  }
  return map[game.status] || 'text-align:center; padding:1.25rem'
})

function resultImage(i) {
  if (game.status === 'finished' && game.result) return `/images/${game.result[i - 1]}.jpg`
  if (game.status === 'spinning') return `/images/${spinImages.value[i - 1]}.jpg`
  return `/images/back.jpg`
}

const statusColor = computed(() => ({
  waiting:  '#a5b4fc',
  betting:  'var(--gold)',
  spinning: '#fca5a5',
  finished: '#6ee7b7',
}[game.status] || '#888'))

/** Total riel every player has staked on one slot — the banker's exposure. */
function slotStake(slot) {
  return game.allBets
    .filter(b => b.animal_slot === slot)
    .reduce((sum, b) => sum + (b.amount || 0), 0)
}

const tableStake = computed(() => game.allBets.reduce((sum, b) => sum + (b.amount || 0), 0))

function tileClass(slot) {
  const cls = []
  if (isBanker.value) cls.push('banker-view')
  if (isBanker.value ? slotStake(slot) : game.myBets[slot]) cls.push('has-bet')
  if (game.status === 'spinning') cls.push('spinning')
  if (game.status === 'finished' && game.result) {
    const count = game.result.filter(r => r === slot).length
    if (count === 1) cls.push('win-1x')
    else if (count === 2) cls.push('win-2x')
    else if (count === 3) cls.push('win-3x')
    // "lost" is red — for the banker a slot nobody hit is money collected, not lost
    else if (!isBanker.value && game.myBets[slot]) cls.push('lost')
  }
  return cls
}

function winBadge(slot) {
  if (game.status !== 'finished' || !game.result || isBanker.value || !game.myBets[slot]) return null
  const count = game.result.filter(r => r === slot).length
  if (count === 3) return t('room.win3')
  if (count === 2) return t('room.win2')
  if (count === 1) return t('room.win1')
  return t('room.lost')
}

function winBadgeColor(slot) {
  if (game.status !== 'finished' || !game.result) return 'var(--gold)'
  return game.result.filter(r => r === slot).length > 0 ? '#6ee7b7' : '#fca5a5'
}

let spinInterval = null
function startSpinAnimation() {
  spinInterval = setInterval(() => {
    spinImages.value = [Math.ceil(Math.random() * 6), Math.ceil(Math.random() * 6), Math.ceil(Math.random() * 6)]
  }, 100)
}
function stopSpinAnimation() { if (spinInterval) clearInterval(spinInterval); spinInterval = null }

let channel = null
function subscribeChannel() {
  const echo = getEcho()
  channel = echo.channel(`game.${code}`)
  channel
    .listen('.PlayerJoined', (e) => {
      game.addPlayerJoined(e.user)
      game.addToast(t('toast.joined', { name: e.user.name }), 'info')
    })
    .listen('.PlayerLeft', (e) => {
      game.removePlayer(e.user.id)
      game.addToast(t('toast.left', { name: e.user.name }), 'info')
    })
    .listen('.BettingOpened', () => {
      game.openBetting()
      closePopup()
      game.addToast(t('toast.bettingOpen'), 'info')
    })
    .listen('.BetPlaced', (e) => {
      game.addBet({ user: e.user, animal_slot: e.animal_slot, amount: e.amount })
    })
    .listen('.SpinStarted', () => {
      game.setSpinning()
      closePopup()
      startSpinAnimation()
      startMusic()
    })
    .listen('.SpinResult', (e) => resolveRound(e.result, e.bets, e.settlement))
}

/** Round is over: stop the roll, settle the display, announce the outcome. */
function resolveRound(result, bets = [], settlement = null) {
  stopSpinAnimation()
  stopMusic()
  game.setResult(result, bets, settlement)

  // The settlement carries every account's new balance — use mine straight away
  // so the header updates with the result, not a round-trip later.
  const s    = game.settlement
  const mine = s?.host?.id === auth.user?.id ? s.host : (s?.players || []).find(p => p.id === auth.user?.id)
  if (mine?.balance != null) auth.setBalance(mine.balance)
  else auth.refreshUser().catch(() => {})

  if (isBanker.value) {
    const net = s?.host?.net ?? 0
    if (net > 0)      game.addToast(t('toast.collected', { amount: net.toLocaleString() }), 'win')
    else if (net < 0) game.addToast(t('toast.paidOut', { amount: net.toLocaleString() }), 'loss')
    else              game.addToast(t('toast.noBets'), 'info')
    if (bets.length) showResultPopup(net, true)   // nothing to show if nobody bet
    return
  }

  const myB = bets.filter(b => b.user?.id === auth.user?.id)
  if (!myB.length) return
  const pnl = myB.reduce((sum, b) => sum + (b.won_amount || 0), 0)
  if (pnl > 0) game.addToast(t('toast.playerWon', { amount: pnl.toLocaleString() }), 'win')
  else if (pnl < 0) game.addToast(t('toast.playerLost', { amount: pnl.toLocaleString() }), 'loss')
  else game.addToast(t('toast.breakEven'), 'info')
  showResultPopup(pnl, false)
}

async function placeBet(slot) {
  if (game.status !== 'betting') return
  if (isBanker.value) { game.addToast(t('toast.bankerCannotBet'), 'info'); return }
  if (auth.user?.balance < game.room.bet_amount) { game.addToast(t('toast.insufficient'), 'loss'); return }
  try {
    const { data } = await api.post(`/games/${code}/bets`, { animal_slot: slot })
    playClick()
    game.myBets[slot] = (game.myBets[slot] || 0) + game.room.bet_amount
    auth.user.balance = data.balance
    game.addBet({ user: { id: auth.user.id, name: auth.user.name }, animal_slot: slot, amount: game.room.bet_amount })
  } catch (e) { game.addToast(e.response?.data?.message || t('toast.betFailed'), 'loss') }
}

function clearLocalBets() { game.myBets = {} }

async function openBetting() {
  actionLoading.value = true
  try { await api.post(`/games/${code}/open-betting`) } catch (e) { game.addToast(e.response?.data?.message || t('toast.error'), 'loss') }
  finally { actionLoading.value = false }
}

async function spin() {
  actionLoading.value = true
  try { game.setSpinning(); closePopup(); startSpinAnimation(); startMusic(); await api.post(`/games/${code}/spin`) }
  catch (e) { stopSpinAnimation(); stopMusic(); game.addToast(e.response?.data?.message || t('toast.spinFailed'), 'loss') }
  finally { actionLoading.value = false }
}

async function stopSpin() {
  actionLoading.value = true
  try { await api.post(`/games/${code}/stop`) }
  catch (e) { game.addToast(e.response?.data?.message || t('toast.stopFailed'), 'loss') }
  finally { actionLoading.value = false }
}

function copyInvite() {
  navigator.clipboard.writeText(`${window.location.origin}/game/${code}`)
  copied.value = true; setTimeout(() => { copied.value = false }, 2000)
}

async function leaveRoom() {
  try { await api.post(`/games/${code}/leave`) } catch {}
  clearLastRoom()
  disconnectEcho(); game.reset(); router.push({ name: 'lobby' })
}

async function loadRoom() {
  const { data } = await api.get(`/games/${code}`)
  return data
}

onMounted(async () => {
  let room
  try {
    room = await loadRoom()
  } catch {
    clearLastRoom()
    game.addToast(t('toast.roomGone'), 'loss')
    router.push({ name: 'lobby' })
    return
  }

  // Not a member yet? This is either an invite link or a first visit — join now.
  // Members who come back after closing the tab are already in the list.
  if (!(room.players || []).some(p => p.id === auth.user?.id)) {
    try {
      await api.post(`/games/${code}/join`)
      room = await loadRoom()
    } catch (e) {
      game.addToast(e.response?.data?.message || t('toast.joinFailed'), 'loss')
      router.push({ name: 'lobby' })
      return
    }
  }

  // Remember the room so the lobby can offer a one-click rejoin next visit
  setLastRoom(room.code)

  game.setRoom(room)
  game.setMyBets(room.bets || [], auth.user?.id)
  if (room.status === 'spinning') { startSpinAnimation(); startMusic() }
  subscribeChannel()

  // Safety net: if a websocket event is missed (flaky connection, Reverb not
  // running), polling keeps the roster and the round phase correct.
  pollInterval = setInterval(async () => {
    let fresh
    try { fresh = await loadRoom() } catch { return }

    game.room    = fresh
    game.players = fresh.players || []

    if (fresh.status === game.status) return

    if (fresh.status === 'spinning') {
      game.setSpinning()
      closePopup()
      startSpinAnimation()
      startMusic()
    } else if (fresh.status === 'finished') {
      resolveRound(fresh.result, fresh.bets || [])
    } else if (fresh.status === 'betting' && (game.status === 'waiting' || game.status === 'finished')) {
      game.openBetting()
      closePopup()
      game.allBets = fresh.bets || []
      game.setMyBets(fresh.bets || [], auth.user?.id)
    }
  }, 5000)
})

onUnmounted(() => {
  stopSpinAnimation()
  stopMusic()
  clearTimeout(popupTimer)
  if (pollInterval) clearInterval(pollInterval)
  if (channel) {
    channel
      .stopListening('.PlayerJoined').stopListening('.PlayerLeft')
      .stopListening('.BettingOpened').stopListening('.BetPlaced')
      .stopListening('.SpinStarted').stopListening('.SpinResult')
  }
  disconnectEcho()
})
</script>

<style scoped>
.popup-backdrop {
  position: fixed; inset: 0; z-index: 120;
  display: flex; align-items: center; justify-content: center; padding: 1.5rem;
  background: rgba(4, 4, 10, 0.7); backdrop-filter: blur(6px);
}
.popup-card {
  width: 100%; max-width: 320px; text-align: center;
  animation: popIn 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.popup-win  { border-color: rgba(16,185,129,0.4);  box-shadow: 0 20px 60px rgba(16,185,129,0.18); }
.popup-loss { border-color: rgba(232,57,74,0.35);  box-shadow: 0 20px 60px rgba(232,57,74,0.18); }
/* The faces are line art on white — frame them so the square reads as intentional */
.popup-face {
  width: 120px; height: 120px; object-fit: contain; margin: 0 auto; display: block;
  background: #fff; border-radius: 22px; padding: 8px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.35);
  animation: faceBob 1.6s ease-in-out infinite;
}
@keyframes popIn { from { opacity: 0; transform: scale(0.85) translateY(12px); } to { opacity: 1; transform: none; } }
@keyframes faceBob { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-6px) } }

/* The banker doesn't bet — their board is a read-only view of the table */
.animal-tile.banker-view { cursor: default; }
.animal-tile.banker-view:hover { transform: none; }
.animal-tile.banker-view:active { transform: none; }
@keyframes spinAnim { to { transform: rotate(360deg); } }
@keyframes countPulse { 0%,100% { transform:scale(1); } 50% { transform:scale(1.06); } }
</style>
