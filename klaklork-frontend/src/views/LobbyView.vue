<template>
  <div class="bg-animated"></div>
  <div class="bg-grid"></div>

  <div class="min-h-screen z-content" style="position:relative">
    <!-- Topbar -->
    <header style="border-bottom: 1px solid var(--border-dim); backdrop-filter: blur(20px); position:sticky; top:0; z-index:50; background: rgba(8,8,18,0.7)">
      <div class="w-full mx-auto px-6 py-4 flex items-center justify-between" style="max-width:1024px">
        <div class="flex items-center gap-3">
          <div class="game-title" style="font-size:1.6rem">ខ្លាឃ្លោក</div>
          <span style="font-size:0.7rem; font-weight:700; color:var(--text-muted); letter-spacing:0.1em; background: rgba(255,255,255,0.05); border:1px solid var(--border-dim); border-radius:6px; padding:2px 8px">{{ t('lobby.badge') }}</span>
        </div>
        <div class="flex items-center gap-3">
          <LanguageToggle />
          <div class="balance-badge">
            <span>💰</span>
            <span>{{ auth.user?.balance?.toLocaleString() }} ៛</span>
          </div>
          <button
            id="player-chip"
            :title="t('lobby.changeNameTip')"
            @click="openRename"
            style="display:flex; align-items:center; gap:0.5rem; background:rgba(255,255,255,0.04); border:1px solid var(--border-dim); border-radius:12px; padding:0.4rem 0.8rem; cursor:pointer; color:inherit">
            <div class="avatar" style="width:26px; height:26px; border-radius:8px; font-size:0.65rem">
              {{ auth.user?.name?.[0]?.toUpperCase() }}
            </div>
            <span style="font-size:0.85rem; font-weight:600">{{ auth.user?.name }}</span>
            <span style="font-size:0.7rem; color:var(--text-muted)">✏️</span>
          </button>
          <button class="btn btn-ghost text-sm" @click="exitSession" style="padding: 0.45rem 0.9rem">
            {{ t('common.exit') }}
          </button>
        </div>
      </div>
    </header>

    <main class="w-full mx-auto px-6 py-8" style="max-width:1024px">

      <!-- Page title -->
      <div class="mb-8">
        <h1 style="font-size:1.75rem; font-weight:900; margin-bottom:0.25rem">{{ t('lobby.title') }}</h1>
        <p style="color:var(--text-muted); font-size:0.9rem">{{ t('lobby.subtitle') }}</p>
      </div>

      <!-- Resume last room (after an accidental tab close) -->
      <div v-if="resumeRoom" id="resume-card" class="card card-premium mb-6"
        style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; background:rgba(245,200,66,0.05); border-color:rgba(245,200,66,0.25)">
        <div style="display:flex; align-items:center; gap:0.85rem">
          <div style="width:44px; height:44px; border-radius:12px; background:rgba(245,200,66,0.12); border:1px solid rgba(245,200,66,0.25); display:flex; align-items:center; justify-content:center; font-size:1.2rem">
            ↩️
          </div>
          <div>
            <div style="font-weight:800; font-size:0.98rem">{{ t('lobby.resumeTitle', { code: resumeRoom.code }) }}</div>
            <div style="font-size:0.8rem; color:var(--text-muted)">
              {{ t('lobby.resumeMeta', { count: resumeRoom.players_count, max: resumeRoom.max_players, status: t(`status.${resumeRoom.status}`) }) }}
            </div>
          </div>
        </div>
        <div style="display:flex; gap:0.5rem">
          <button class="btn btn-ghost text-sm" style="padding:0.5rem 0.9rem" @click="dismissResume">{{ t('lobby.dismiss') }}</button>
          <button id="resume-btn" class="btn btn-gold text-sm" style="padding:0.5rem 1.1rem" @click="quickJoin(resumeRoom.code)">
            {{ t('lobby.rejoin') }}
          </button>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-[1fr_380px]">

        <!-- LEFT: Open Rooms -->
        <div>
          <div class="flex items-center justify-between mb-4">
            <div class="section-header" style="margin-bottom:0; flex:1">{{ t('lobby.openRooms') }}</div>
            <button class="btn btn-ghost text-sm" @click="fetchRooms" style="padding:0.35rem 0.8rem; font-size:0.75rem; margin-left:1rem">
              {{ t('common.refresh') }}
            </button>
          </div>

          <!-- Room list -->
          <div v-if="openRooms.length" class="flex flex-col gap-3">
            <div v-for="room in openRooms" :key="room.id"
              class="room-card"
              @click="quickJoin(room.code)"
            >
              <div class="flex items-center gap-3">
                <!-- Room icon -->
                <div style="width:44px; height:44px; border-radius:12px; background: linear-gradient(135deg, rgba(245,200,66,0.15), rgba(232,57,74,0.1)); border:1px solid var(--border-dim); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0">
                  🎮
                </div>
                <div>
                  <div style="font-size:1rem; font-weight:800; letter-spacing:0.08em; color:var(--text-primary)">
                    {{ room.code }}
                  </div>
                  <div style="font-size:0.78rem; color:var(--text-muted)">
                    {{ t('lobby.hostLine') }} <span style="color:var(--text-primary)">{{ room.host?.name }}</span>
                    · {{ t('common.perClick', { amount: room.bet_amount?.toLocaleString() }) }}
                  </div>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div style="text-align:right">
                  <div style="font-size:0.85rem; font-weight:700; color:var(--text-primary)">{{ room.players_count }}/{{ room.max_players }}</div>
                  <div style="font-size:0.7rem; color:var(--text-muted)">{{ t('common.players') }}</div>
                </div>
                <span class="status-badge" :class="`status-${room.status}`">
                  <span class="pulse-dot" :style="{ background: room.status === 'betting' ? 'var(--gold)' : '#a5b4fc' }"></span>
                  {{ t(`status.${room.status}`) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Empty state -->
          <div v-else class="card text-center" style="padding:3rem; border-style:dashed; border-color:rgba(255,255,255,0.08)">
            <div style="font-size:2.5rem; margin-bottom:0.75rem">🎲</div>
            <p style="font-weight:700; margin-bottom:0.3rem">{{ t('lobby.emptyTitle') }}</p>
            <p style="color:var(--text-muted); font-size:0.85rem">{{ t('lobby.emptyBody') }}</p>
          </div>
        </div>

        <!-- RIGHT: Create + Join -->
        <div class="flex flex-col gap-4">

          <!-- Create Room -->
          <div class="card card-premium">
            <div class="section-header">{{ t('lobby.createTitle') }}</div>
            <form @submit.prevent="createRoom" class="flex flex-col gap-4">
              <div>
                <label class="label">{{ t('lobby.betPerClick') }}</label>
                <select id="create-bet-amount" v-model.number="createForm.bet_amount" class="input">
                  <option :value="100">{{ t('lobby.betCasual') }}</option>
                  <option :value="500">{{ t('lobby.betStandard') }}</option>
                  <option :value="1000">{{ t('lobby.betHigh') }}</option>
                  <option :value="5000">{{ t('lobby.betWhale') }}</option>
                  <option :value="10000">{{ t('lobby.betInsane') }}</option>
                </select>
              </div>
              <div>
                <label class="label">{{ t('lobby.maxPlayers') }}</label>
                <input id="create-max-players" v-model.number="createForm.max_players"
                  type="number" class="input" min="2" max="20" required />
              </div>
              <div v-if="createError" class="flex items-center gap-2 text-sm"
                style="color:#fca5a5; background:rgba(232,57,74,0.1); border:1px solid rgba(232,57,74,0.2); border-radius:10px; padding:0.6rem 0.85rem">
                ⚠️ {{ createError }}
              </div>
              <p style="font-size:0.72rem; color:var(--text-faint); line-height:1.5; margin-top:-0.4rem">
                {{ t('lobby.bankerNote') }}
              </p>
              <button id="create-room-btn" class="btn btn-gold w-full" :disabled="creating">
                {{ creating ? t('lobby.creating') : t('lobby.createBtn') }}
              </button>
            </form>
          </div>

          <!-- Join by Code -->
          <div class="card">
            <div class="section-header">{{ t('lobby.joinTitle') }}</div>
            <form @submit.prevent="joinRoom" class="flex flex-col gap-3">
              <input id="join-code" v-model="joinCode" type="text" class="input"
                :placeholder="t('lobby.joinPlaceholder')"
                maxlength="8"
                style="text-transform: uppercase; letter-spacing: 0.18em; font-weight:800; font-size:1rem; text-align:center"
                required />
              <div v-if="joinError" class="flex items-center gap-2 text-sm"
                style="color:#fca5a5; background:rgba(232,57,74,0.1); border:1px solid rgba(232,57,74,0.2); border-radius:10px; padding:0.6rem 0.85rem">
                ⚠️ {{ joinError }}
              </div>
              <button id="join-room-btn" class="btn btn-ghost w-full" :disabled="joining">
                {{ joining ? t('lobby.joining') : t('lobby.joinBtn') }}
              </button>
            </form>
          </div>

          <!-- How to play -->
          <div class="card" style="background: rgba(245,200,66,0.03)">
            <div class="section-header">{{ t('lobby.howTitle') }}</div>
            <ol style="list-style:none; display:flex; flex-direction:column; gap:0.6rem">
              <li v-for="(step, i) in howToPlay" :key="i" style="display:flex; gap:0.75rem; align-items:flex-start">
                <span style="width:22px; height:22px; border-radius:50%; background:rgba(245,200,66,0.15); border:1px solid rgba(245,200,66,0.2); display:flex; align-items:center; justify-content:center; font-size:0.65rem; font-weight:900; color:var(--gold); flex-shrink:0; margin-top:1px">{{ i+1 }}</span>
                <span style="font-size:0.82rem; color:var(--text-muted); line-height:1.5">{{ step }}</span>
              </li>
            </ol>
          </div>

          <!-- Play-money reminder + gambling harm note -->
          <PlayResponsibly compact />
        </div>
      </div>
    </main>

    <!-- Change name -->
    <div v-if="renaming" class="modal-backdrop" @click.self="renaming = false">
      <div class="card card-premium" style="width:100%; max-width:360px">
        <div class="section-header">{{ t('lobby.renameTitle') }}</div>
        <form @submit.prevent="saveName" class="flex flex-col gap-3">
          <input id="rename-input" v-model="renameValue" type="text" class="input"
            maxlength="20" required autofocus style="font-weight:700" />
          <div v-if="renameError" class="text-sm"
            style="color:#fca5a5; background:rgba(232,57,74,0.1); border:1px solid rgba(232,57,74,0.2); border-radius:10px; padding:0.6rem 0.85rem">
            ⚠️ {{ renameError }}
          </div>
          <div style="display:flex; gap:0.5rem">
            <button type="button" class="btn btn-ghost" style="flex:1" @click="renaming = false">{{ t('common.cancel') }}</button>
            <button class="btn btn-gold" style="flex:1" :disabled="savingName">
              {{ savingName ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/lib/api'
import { getLastRoom, clearLastRoom } from '@/lib/session'
import PlayResponsibly from '@/components/PlayResponsibly.vue'
import LanguageToggle from '@/components/LanguageToggle.vue'
import { useI18n } from '@/lib/i18n'

const { t }      = useI18n()
const auth       = useAuthStore()
const router     = useRouter()
const openRooms  = ref([])
const createForm = ref({ bet_amount: 500, max_players: 10 })
const joinCode   = ref('')
const createError = ref('')
const joinError  = ref('')
const creating   = ref(false)
const joining    = ref(false)
const resumeRoom = ref(null)

const renaming    = ref(false)
const renameValue = ref('')
const renameError = ref('')
const savingName  = ref(false)

const howToPlay = computed(() => [
  t('lobby.how1'), t('lobby.how2'), t('lobby.how3'), t('lobby.how4'),
])

let refreshInterval = null

onMounted(() => {
  fetchRooms()
  checkResumeRoom()
  auth.refreshUser().catch(() => {})
  refreshInterval = setInterval(fetchRooms, 10000)
})
onUnmounted(() => { if (refreshInterval) clearInterval(refreshInterval) })

async function fetchRooms() {
  try { const { data } = await api.get('/games'); openRooms.value = data } catch {}
}

/** If the tab was closed mid-game, offer to jump back into that room. */
async function checkResumeRoom() {
  const code = getLastRoom()
  if (!code) return
  try {
    const { data } = await api.get(`/games/${code}`)
    resumeRoom.value = data
  } catch {
    clearLastRoom()   // room no longer exists
  }
}

function dismissResume() { clearLastRoom(); resumeRoom.value = null }

async function createRoom() {
  createError.value = ''; creating.value = true
  try {
    const { data } = await api.post('/games', createForm.value)
    router.push({ name: 'game', params: { code: data.code } })
  } catch (e) {
    createError.value = e.response?.data?.message || t('lobby.errCreate')
  } finally { creating.value = false }
}

async function joinRoom() {
  joinError.value = ''; joining.value = true
  try {
    await api.post(`/games/${joinCode.value.toUpperCase()}/join`)
    router.push({ name: 'game', params: { code: joinCode.value.toUpperCase() } })
  } catch (e) {
    joinError.value = e.response?.data?.message || t('lobby.errJoin')
  } finally { joining.value = false }
}

async function quickJoin(code) { joinCode.value = code; await joinRoom() }

function openRename() {
  renameValue.value = auth.user?.name || ''
  renameError.value = ''
  renaming.value = true
}

async function saveName() {
  savingName.value = true; renameError.value = ''
  try {
    await auth.changeName(renameValue.value.trim())
    renaming.value = false
  } catch (e) {
    renameError.value = e.response?.data?.errors?.name?.[0] || e.response?.data?.message || t('lobby.renameError')
  } finally { savingName.value = false }
}

async function exitSession() {
  await auth.exit()
  router.push({ name: 'enter' })
}
</script>

<style scoped>
.modal-backdrop {
  position: fixed; inset: 0; z-index: 100;
  display: flex; align-items: center; justify-content: center; padding: 1.5rem;
  background: rgba(4, 4, 10, 0.72); backdrop-filter: blur(6px);
}
</style>
