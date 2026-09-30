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
  <TransitionGroup name="fade" tag="div" class="toast-stack">
    <div
      v-for="t in game.toasts" :key="t.id"
      class="toast"
      :class="`toast-${t.type}`"
    >{{ t.msg }}</div>
  </TransitionGroup>
</template>

<script setup>
import { useGameStore } from '@/stores/game'
const game = useGameStore()
</script>
