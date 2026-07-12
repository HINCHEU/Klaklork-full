<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <div class="card w-full max-w-sm">
      <div class="text-center mb-6">
        <div class="game-title mb-1">ខ្លាឃ្លោក</div>
        <p class="text-sm" style="color: var(--text-muted)">Login to join the game</p>
      </div>

      <form @submit.prevent="submit" class="flex flex-col gap-4">
        <div>
          <label class="label">Email</label>
          <input id="login-email" v-model="form.email" type="email" class="input"
            placeholder="you@example.com" required autocomplete="email" />
        </div>
        <div>
          <label class="label">Password</label>
          <input id="login-password" v-model="form.password" type="password" class="input"
            placeholder="••••••••" required autocomplete="current-password" />
        </div>

        <div v-if="error" class="text-sm text-red-400 text-center">{{ error }}</div>

        <button id="login-btn" class="btn btn-gold w-full mt-2" :disabled="loading">
          <span v-if="loading">Loading…</span>
          <span v-else>🎰 Login</span>
        </button>
      </form>

      <p class="text-center text-sm mt-4" style="color: var(--text-muted)">
        No account?
        <router-link to="/register" class="font-semibold" style="color: var(--gold)">Register</router-link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth   = useAuthStore()
const router = useRouter()
const form   = ref({ email: '', password: '' })
const error  = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''; loading.value = true
  try {
    await auth.login(form.value.email, form.value.password)
    router.push({ name: 'lobby' })
  } catch (e) {
    error.value = e.response?.data?.message || 'Login failed'
  } finally { loading.value = false }
}
</script>
