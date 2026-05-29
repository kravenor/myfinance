<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const email = ref('')
const loading = ref(false)
const done = ref(false)
const error = ref<string | null>(null)

async function onSubmit() {
  error.value = null
  loading.value = true
  try {
    await auth.forgotPassword(email.value)
    done.value = true
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    error.value = err.response?.data?.message ?? 'Errore nell\'invio della richiesta.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <div class="card w-full max-w-md p-6 space-y-4">
      <h1 class="text-xl font-semibold">Recupera password</h1>

      <div v-if="done" class="space-y-4">
        <p class="text-sm text-slate-600">
          Se l'indirizzo è associato a un account, riceverai a breve un'email con il link per
          reimpostare la password.
        </p>
        <RouterLink to="/login" class="btn-primary w-full inline-block text-center">
          Torna al login
        </RouterLink>
      </div>

      <form v-else class="space-y-4" @submit.prevent="onSubmit">
        <p class="text-sm text-slate-600">
          Inserisci la tua email: ti invieremo un link per reimpostare la password.
        </p>
        <div>
          <label class="label" for="email">Email</label>
          <input id="email" v-model="email" type="email" required class="input" />
        </div>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="loading">
          {{ loading ? 'Invio…' : 'Invia link di reset' }}
        </button>
        <p class="text-sm text-slate-600 text-center">
          <RouterLink to="/login" class="text-indigo-600 hover:underline">Torna al login</RouterLink>
        </p>
      </form>
    </div>
  </div>
</template>
