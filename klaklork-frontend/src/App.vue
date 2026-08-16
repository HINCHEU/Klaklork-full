<template>
  <!-- Views have multi-root templates, so the page needs a single wrapper element
       for <transition mode="out-in"> to detect the leave and mount the next view. -->
  <router-view v-slot="{ Component, route }">
    <transition name="fade" mode="out-in">
      <div :key="route.path">
        <component :is="Component" />
      </div>
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
