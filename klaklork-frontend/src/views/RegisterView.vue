<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <div class="card w-full max-w-sm">
      <div class="text-center mb-6">
        <div class="game-title mb-1">ខ្លាឃ្លោក</div>
        <p class="text-sm" style="color: var(--text-muted)">Create your account</p>
      </div>

      <form @submit.prevent="submit" class="flex flex-col gap-4">
        <div>
          <label class="label">Name</label>
          <input id="reg-name" v-model="form.name" type="text" class="input"
            placeholder="Your name" required />
        </div>
        <div>
          <label class="label">Email</label>
          <input id="reg-email" v-model="form.email" type="email" class="input"
            placeholder="you@example.com" required />
        </div>
        <div>
          <label class="label">Password</label>
          <input id="reg-password" v-model="form.password" type="password" class="input"
            placeholder="Min 6 characters" required />
        </div>
        <div>
          <label class="label">Confirm Password</label>
          <input id="reg-password-confirm" v-model="form.password_confirmation" type="password" class="input"
            placeholder="Repeat password" required />
        </div>

        <div v-if="error" class="text-sm text-red-400 text-center">{{ error }}</div>

        <button id="reg-btn" class="btn btn-gold w-full mt-2" :disabled="loading">
          <span v-if="loading">Creating…</span>
          <span v-else>🎲 Create Account (Start with 10,000 ៛)</span>
        </button>
      </form>

      <p class="text-center text-sm mt-4" style="color: var(--text-muted)">
        Already have an account?
        <router-link to="/login" class="font-semibold" style="color: var(--gold)">Login</router-link>
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
const form   = ref({ name: '', email: '', password: '', password_confirmation: '' })
const error  = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''; loading.value = true
  try {
    await auth.register(form.value.name, form.value.email, form.value.password, form.value.password_confirmation)
    router.push({ name: 'lobby' })
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs)[0][0] : (e.response?.data?.message || 'Registration failed')
  } finally { loading.value = false }
}
</script>
