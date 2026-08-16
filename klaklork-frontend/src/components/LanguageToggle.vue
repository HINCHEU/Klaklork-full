<template>
  <button
    id="lang-btn"
    class="btn btn-ghost"
    :title="t('lang.switchTo')"
    :aria-label="t('lang.switchTo')"
    style="padding:0.45rem 0.75rem; font-size:0.8rem; font-weight:700; gap:0.35rem"
    @click="toggleLocale"
  >
    <span>🌐</span>
    <!-- Show the language you'd switch *to* -->
    <span>{{ other.label }}</span>
  </button>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from '@/lib/i18n'

const { t, locale, toggleLocale, LOCALES } = useI18n()
const other = computed(() => LOCALES.find(l => l.code !== locale.value))
</script>
