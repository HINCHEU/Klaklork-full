<template>
  <!-- Compact strip for the lobby sidebar -->
  <div v-if="compact" class="card" style="background: rgba(232,57,74,0.04); border-color: rgba(232,57,74,0.15)">
    <div class="section-header">{{ t('responsible.compactTitle') }}</div>
    <!-- Copy contains <strong> emphasis, and it is our own translation table -->
    <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.6" v-html="t('responsible.compactBody1')"></p>
    <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.6; margin-top:0.6rem" v-html="t('responsible.compactBody2')"></p>
  </div>

  <!-- Full notice for the entry page -->
  <div v-else class="card" style="background: rgba(232,57,74,0.04); border-color: rgba(232,57,74,0.15)">
    <div class="section-header">{{ t('responsible.fullTitle') }}</div>

    <p style="font-size:0.85rem; color:var(--text-muted); line-height:1.7" v-html="t('responsible.fullIntro')"></p>

    <div class="divider" style="margin: 1.1rem 0">{{ t('responsible.divider') }}</div>

    <ul style="list-style:none; display:flex; flex-direction:column; gap:0.7rem">
      <li v-for="risk in risks" :key="risk.icon" style="display:flex; gap:0.6rem; align-items:flex-start">
        <span style="flex-shrink:0; font-size:0.9rem; line-height:1.4">{{ risk.icon }}</span>
        <span style="font-size:0.8rem; color:var(--text-muted); line-height:1.6">
          <strong style="color:var(--text-primary)">{{ risk.title }}</strong> — {{ risk.body }}
        </span>
      </li>
    </ul>

    <p style="font-size:0.78rem; color:var(--text-muted); line-height:1.6; margin-top:1.1rem; padding-top:0.9rem; border-top:1px solid var(--border-dim)">
      {{ t('responsible.help') }}
      <a href="https://www.gamblingtherapy.org" target="_blank" rel="noopener noreferrer"
        style="color:var(--gold); font-weight:600">gamblingtherapy.org</a>
      {{ t('responsible.helpMid') }}
      <a href="https://www.gamblersanonymous.org" target="_blank" rel="noopener noreferrer"
        style="color:var(--gold); font-weight:600">gamblersanonymous.org</a>{{ t('responsible.helpEnd') }}
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from '@/lib/i18n'

defineProps({ compact: { type: Boolean, default: false } })

const { t } = useI18n()

// The "~8%" figure is this game's actual math: staking on one symbol across three
// dice returns 92.1% of what's staked on average (-17/216 per bet), so the banker
// keeps the rest over the long run.
const risks = computed(() => [
  { icon: '📉', title: t('responsible.risk1Title'), body: t('responsible.risk1Body') },
  { icon: '🌀', title: t('responsible.risk2Title'), body: t('responsible.risk2Body') },
  { icon: '🧠', title: t('responsible.risk3Title'), body: t('responsible.risk3Body') },
  { icon: '🏠', title: t('responsible.risk4Title'), body: t('responsible.risk4Body') },
  { icon: '⏸️', title: t('responsible.risk5Title'), body: t('responsible.risk5Body') },
])
</script>
