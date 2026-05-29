<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const token = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const error = ref<string | null>(null)

onMounted(() => {
  token.value = (route.query.token as string) ?? ''
  email.value = (route.query.email as string) ?? ''
})

async function onSubmit() {
  error.value = null
  loading.value = true
  try {
    await auth.resetPassword({
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'login', query: { reset: '1' } })
  } catch (e: unknown) {
    const err = e as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } }
    }
    const errors = err.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : (err.response?.data?.message ?? 'Errore nel reset della password.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <form class="card w-full max-w-md p-6 space-y-4" @submit.prevent="onSubmit">
      <h1 class="text-xl font-semibold">Imposta nuova password</h1>

      <p v-if="!token || !email" class="text-sm text-red-600">
        Link non valido o incompleto. Richiedi un nuovo link di reset.
      </p>

      <template v-else>
        <div>
          <label class="label" for="email">Email</label>
          <input id="email" v-model="email" type="email" class="input" readonly />
        </div>
        <div>
          <label class="label" for="password">Nuova password</label>
          <input id="password" v-model="password" type="password" required class="input" />
        </div>
        <div>
          <label class="label" for="password_confirmation">Conferma password</label>
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
            required
            class="input"
          />
        </div>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="loading">
          {{ loading ? 'Salvataggio…' : 'Reimposta password' }}
        </button>
      </template>

      <p class="text-sm text-slate-600 text-center">
        <RouterLink to="/login" class="text-indigo-600 hover:underline">Torna al login</RouterLink>
      </p>
    </form>
  </div>
</template>
