<template>
  <!-- Ambient backgrounds -->
  <div class="bg-animated"></div>
  <div class="bg-grid"></div>

  <div class="min-h-screen flex z-content" style="position:relative">

    <!-- LEFT — Hero Panel.
         Sticky at full viewport height so it stays on the first screen no matter
         how far the entry column scrolls. -->
    <div class="hero-panel hidden lg:flex flex-col justify-between flex-1 px-16 py-12"
      style="background: linear-gradient(135deg, rgba(232,57,74,0.08) 0%, transparent 60%), rgba(8,8,18,0.4)">

      <!-- Logo -->
      <div>
        <div class="logo-badge hero-logo">
          <div class="logo-dot">🎲</div>
          <span style="font-size:0.8rem; font-weight:700; color: var(--gold); letter-spacing:0.08em;">KLA KLOUK</span>
        </div>

        <div style="max-width: 520px">
          <h1 class="game-title hero-title">
            ខ្លា<br/>ឃ្លោក
          </h1>
          <p style="font-size:1.2rem; color: var(--text-muted); line-height: 1.7; max-width: 400px">
            {{ t('enter.tagline') }}
          </p>
          <div style="display:inline-flex; align-items:center; gap:0.5rem; margin-top:1.25rem; padding:0.45rem 0.9rem; border-radius:999px; background:rgba(232,57,74,0.08); border:1px solid rgba(232,57,74,0.2); font-size:0.78rem; color:var(--text-muted)">
            {{ t('enter.playMoneyPill') }}
          </div>
        </div>
      </div>

      <!-- All six animal tiles — wraps instead of overflowing on narrow desktops -->
      <div class="preview-row" style="perspective: 800px">
        <div v-for="(animal, i) in previewAnimals" :key="animal"
          class="preview-tile"
          :style="{
            animationDelay: `${i * 0.35}s`,
            transform: `rotateY(${(i - (previewAnimals.length - 1) / 2) * 7}deg)`
          }"
        >
          <img :src="`/images/${animal}.jpg`" :alt="`Animal ${animal}`" class="preview-img" />
        </div>
      </div>

      <!-- Stats -->
      <div class="flex gap-8">
        <div v-for="stat in stats" :key="stat.label">
          <div style="font-size:1.8rem; font-weight:900; color:var(--gold)">{{ stat.value }}</div>
          <div style="font-size:0.75rem; color: var(--text-muted); letter-spacing:0.06em; text-transform:uppercase">{{ stat.label }}</div>
        </div>
      </div>
    </div>

    <!-- RIGHT — Name Entry Panel -->
    <div class="flex items-center justify-center px-6 py-12 w-full lg:w-[480px] lg:flex-shrink-0">
      <div style="width:100%; max-width:400px">

        <!-- Language switch -->
        <div class="flex justify-end mb-4">
          <LanguageToggle />
        </div>

        <!-- Mobile logo -->
        <div class="lg:hidden text-center mb-8">
          <div class="game-title" style="font-size:3rem">ខ្លាឃ្លោក</div>
          <p style="color:var(--text-muted); font-size:0.9rem; margin-top:0.4rem">{{ t('enter.subtitle') }}</p>
        </div>

        <!-- Entry card -->
        <div class="card card-premium" style="padding: 2.5rem">
          <div class="text-center mb-8">
            <h2 style="font-size:1.5rem; font-weight:800; margin-bottom:0.3rem">{{ t('enter.title') }}</h2>
            <p style="color:var(--text-muted); font-size:0.9rem">{{ t('enter.cardSubtitle') }}</p>
          </div>

          <form @submit.prevent="submit" class="flex flex-col gap-5">
            <div>
              <label class="label">{{ t('enter.nameLabel') }}</label>
              <input id="player-name" ref="nameInput" v-model="name" type="text" class="input"
                :placeholder="t('enter.namePlaceholder')" maxlength="20" required autocomplete="nickname"
                style="font-weight:700; letter-spacing:0.02em" />
              <p style="font-size:0.72rem; color:var(--text-faint); margin-top:0.45rem">
                {{ t('enter.nameHint') }}
              </p>
            </div>

            <div v-if="error" class="flex items-center gap-2 text-sm"
              style="color:#fca5a5; background:rgba(232,57,74,0.1); border:1px solid rgba(232,57,74,0.2); border-radius:10px; padding:0.65rem 0.9rem">
              <span>⚠️</span> {{ error }}
            </div>

            <button id="enter-btn" class="btn btn-gold w-full" style="padding: 0.9rem; font-size:1rem" :disabled="loading">
              <span v-if="loading" class="flex items-center gap-2">
                <span style="animation: spin 0.8s linear infinite; display:inline-block">⟳</span> {{ t('enter.submitting') }}
              </span>
              <span v-else>{{ t('enter.submit') }}</span>
            </button>
          </form>

          <div class="divider" style="margin: 1.75rem 0">{{ t('enter.howItWorks') }}</div>

          <p class="text-center text-sm" style="color: var(--text-muted); line-height:1.6">
            {{ t('enter.startingNote', { amount: (10000).toLocaleString() }) }}
          </p>
        </div>

        <!-- Features list -->
        <div class="flex justify-center gap-6 mt-6" style="font-size:0.75rem; color: var(--text-muted)">
          <span>{{ t('enter.featureMultiplayer') }}</span>
          <span>{{ t('enter.featureRealtime') }}</span>
          <span>{{ t('enter.featureNoSignup') }}</span>
        </div>

        <!-- Play-money notice + gambling harm information -->
        <PlayResponsibly class="mt-6" />

        <p class="text-center mt-4" style="font-size:0.7rem; color: var(--text-faint)">
          {{ t('enter.footer') }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import PlayResponsibly from '@/components/PlayResponsibly.vue'
import LanguageToggle from '@/components/LanguageToggle.vue'
import { useI18n } from '@/lib/i18n'

const auth    = useAuthStore()
const route   = useRoute()
const router  = useRouter()
const name    = ref(auth.lastName || '')
const error   = ref('')
const loading = ref(false)
const nameInput = ref(null)

const { t } = useI18n()
const previewAnimals = [1, 2, 3, 4, 5, 6]
const stats = computed(() => [
  { value: '6', label: t('enter.statSymbols') },
  { value: '∞', label: t('enter.statPlayers') },
  { value: '3×', label: t('enter.statMultiplier') },
])

onMounted(() => nameInput.value?.focus())

async function submit() {
  const trimmed = name.value.trim()
  if (trimmed.length < 2) { error.value = t('enter.errTooShort'); return }

  error.value = ''
  loading.value = true
  try {
    await auth.enter(trimmed)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    // Only follow same-origin in-app paths
    if (redirect && redirect.startsWith('/') && !redirect.startsWith('//')) router.replace(redirect)
    else router.replace({ name: 'lobby' })
  } catch (e) {
    error.value = e.response?.data?.errors?.name?.[0]
      || e.response?.data?.message
      || t('enter.errFailed')
  } finally { loading.value = false }
}
</script>

<style scoped>
/* Hero stays put on the first screen while the entry column scrolls.
   align-self stops the flex parent from stretching it, which would kill sticky. */
.hero-panel {
  position: sticky;
  top: 0;
  align-self: flex-start;
  height: 100vh;
  overflow: hidden;
}
/* Sized against viewport height so the whole hero fits above the fold on short screens */
.hero-logo  { margin-bottom: clamp(1.5rem, 7vh, 4rem); }
.hero-title { font-size: clamp(3rem, 8.5vh, 5.5rem); line-height: 1.05; margin-bottom: clamp(0.75rem, 2vh, 1.5rem); }

/* Sized so all six fit one row from 1024px up; wrap is only a safety net */
.preview-row {
  display: flex; flex-wrap: wrap;
  gap: clamp(0.4rem, 0.9vw, 1rem);
  margin-bottom: clamp(1rem, 4vh, 3rem);
}
.preview-img {
  width: clamp(40px, 4.2vw, 70px); height: clamp(40px, 4.2vw, 70px);
  border-radius: 12px; object-fit: cover; display: block;
}
.preview-tile {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(245,200,66,0.15);
  border-radius: clamp(10px, 1vw, 16px);
  padding: clamp(4px, 0.5vw, 8px);
  animation: tileFloat 3s ease-in-out infinite;
  transition: transform 0.3s ease;
}
.preview-tile:hover { transform: translateY(-8px) !important; }
@keyframes tileFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-10px); }
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
