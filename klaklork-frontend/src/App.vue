<template>
  <router-view v-slot="{ Component }">
    <transition name="fade" mode="out-in">
      <component :is="Component" />
    </transition>
  </router-view>

  <!-- Global Toasts -->
  <TransitionGroup name="fade">
    <div
      v-for="t in game.toasts" :key="t.id"
      class="toast"
      :class="`toast-${t.type}`"
      style="bottom: calc(1.5rem + var(--i, 0) * 60px)"
    >{{ t.msg }}</div>
  </TransitionGroup>
</template>

<script setup>
import { useGameStore } from '@/stores/game'
const game = useGameStore()
</script>
