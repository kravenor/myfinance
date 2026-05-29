<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('demo@finance.local')
const password = ref('password')
const error = ref<string | null>(null)
const resetDone = ref(route.query.reset === '1')

async function onSubmit() {
  error.value = null
  try {
    await auth.login(email.value, password.value)
    const redirect = (route.query.redirect as string) || '/'
    router.push(redirect)
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    error.value = err.response?.data?.message ?? 'Credenziali non valide.'
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <form class="card w-full max-w-md p-6 space-y-4" @submit.prevent="onSubmit">
      <h1 class="text-xl font-semibold">Accedi</h1>
      <p v-if="resetDone" class="text-sm text-green-600">
        Password reimpostata con successo. Ora puoi accedere.
      </p>
      <div>
        <label class="label" for="email">Email</label>
        <input id="email" v-model="email" type="email" required class="input" />
      </div>
      <div>
        <label class="label" for="password">Password</label>
        <input id="password" v-model="password" type="password" required class="input" />
      </div>
      <div class="text-right -mt-2">
        <RouterLink to="/forgot-password" class="text-sm text-indigo-600 hover:underline">
          Password dimenticata?
        </RouterLink>
      </div>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
        {{ auth.loading ? 'Accesso…' : 'Accedi' }}
      </button>
      <p class="text-sm text-slate-600 text-center">
        Non hai un account?
        <RouterLink to="/register" class="text-indigo-600 hover:underline">Registrati</RouterLink>
      </p>
    </form>
  </div>
</template>
