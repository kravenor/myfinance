<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const error = ref<string | null>(null)

async function onSubmit() {
  error.value = null
  try {
    await auth.register(form.value)
    router.push('/')
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    error.value = err.response?.data?.message ?? 'Registrazione fallita.'
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <form class="card w-full max-w-md p-6 space-y-4" @submit.prevent="onSubmit">
      <h1 class="text-xl font-semibold">Registrati</h1>
      <div>
        <label class="label" for="name">Nome</label>
        <input id="name" v-model="form.name" required class="input" />
      </div>
      <div>
        <label class="label" for="email">Email</label>
        <input id="email" v-model="form.email" type="email" required class="input" />
      </div>
      <div>
        <label class="label" for="password">Password</label>
        <input id="password" v-model="form.password" type="password" required class="input" />
      </div>
      <div>
        <label class="label" for="password_confirmation">Conferma password</label>
        <input
          id="password_confirmation"
          v-model="form.password_confirmation"
          type="password"
          required
          class="input"
        />
      </div>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
        {{ auth.loading ? 'Registrazione…' : 'Crea account' }}
      </button>
      <p class="text-sm text-slate-600 text-center">
        Hai già un account?
        <RouterLink to="/login" class="text-indigo-600 hover:underline">Accedi</RouterLink>
      </p>
    </form>
  </div>
</template>
