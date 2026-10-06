<script setup lang="ts">
import LegalLinks from '@/components/LegalLinks.vue'
import { nextTick, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('demo@finance.local')
const password = ref('password')
const remember = ref(true)
const error = ref<string | null>(null)
const resetDone = ref(route.query.reset === '1')

// Secondo passaggio: la password è giusta, serve il codice dell'app o uno di recupero.
const twoFactor = ref(false)
const useRecovery = ref(false)
const code = ref('')
const codeInput = ref<HTMLInputElement | null>(null)

function messageOf(e: unknown, fallback: string): string {
  const err = e as { response?: { data?: { message?: string } } }
  return err.response?.data?.message ?? fallback
}

function goToApp() {
  router.push((route.query.redirect as string) || '/')
}

async function focusCode() {
  code.value = ''
  await nextTick()
  codeInput.value?.focus()
}

async function onSubmit() {
  error.value = null
  try {
    if ((await auth.login(email.value, password.value, remember.value)) === 'two_factor') {
      twoFactor.value = true
      useRecovery.value = false
      await focusCode()
      return
    }
    goToApp()
  } catch (e: unknown) {
    error.value = messageOf(e, 'Credenziali non valide.')
  }
}

async function onCodeSubmit() {
  error.value = null
  try {
    await auth.twoFactorChallenge(
      useRecovery.value ? { recovery_code: code.value.trim() } : { code: code.value.replace(/\s/g, '') },
    )
    goToApp()
  } catch (e: unknown) {
    const res = (e as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })
      .response
    error.value =
      res?.status === 429 ? 'Troppi tentativi: attendi un minuto.' : messageOf(e, 'Codice non valido.')
    // Errore su «email»: il login in attesa è scaduto, si riparte dalla password.
    if (res?.data?.errors?.email) twoFactor.value = false
  }
}

async function toggleRecovery() {
  useRecovery.value = !useRecovery.value
  error.value = null
  await focusCode()
}

function backToPassword() {
  twoFactor.value = false
  error.value = null
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <form v-if="twoFactor" class="card w-full max-w-md p-6 space-y-4" @submit.prevent="onCodeSubmit">
      <h1 class="text-xl font-semibold">Verifica in due passaggi</h1>
      <p class="text-sm text-slate-600">
        {{
          useRecovery
            ? 'Inserisci uno dei codici di recupero che hai salvato. Ognuno vale una volta sola.'
            : "Inserisci il codice a 6 cifre mostrato dall'app di autenticazione."
        }}
      </p>
      <div>
        <label class="label" for="code">{{ useRecovery ? 'Codice di recupero' : 'Codice' }}</label>
        <input
          v-if="useRecovery"
          id="code"
          ref="codeInput"
          v-model="code"
          type="text"
          required
          class="input"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          :aria-invalid="!!error"
        />
        <input
          v-else
          id="code"
          ref="codeInput"
          v-model="code"
          type="text"
          required
          class="input num tracking-widest"
          inputmode="numeric"
          autocomplete="one-time-code"
          pattern="[0-9 ]{6,7}"
          maxlength="7"
          :aria-invalid="!!error"
        />
      </div>
      <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
        {{ auth.loading ? 'Verifica…' : 'Verifica' }}
      </button>
      <div class="flex items-center justify-between gap-3 text-sm">
        <button type="button" class="text-primary-600 hover:underline py-2" @click="toggleRecovery">
          {{ useRecovery ? "Usa il codice dell'app" : 'Usa un codice di recupero' }}
        </button>
        <button type="button" class="text-slate-600 hover:underline py-2" @click="backToPassword">
          Indietro
        </button>
      </div>
    </form>
    <form v-else class="card w-full max-w-md p-6 space-y-4" @submit.prevent="onSubmit">
      <h1 class="text-xl font-semibold">Accedi</h1>
      <p v-if="resetDone" role="status" class="text-sm text-income-700">
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
      <div class="flex items-center justify-between gap-3 -mt-2">
        <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer py-2">
          <input v-model="remember" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
          Ricordami
        </label>
        <RouterLink to="/forgot-password" class="text-sm text-primary-600 hover:underline py-2">
          Password dimenticata?
        </RouterLink>
      </div>
      <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
        {{ auth.loading ? 'Accesso…' : 'Accedi' }}
      </button>
      <p class="text-sm text-slate-600 text-center">
        Non hai un account?
        <RouterLink to="/register" class="text-primary-600 hover:underline">Registrati</RouterLink>
      </p>
      <LegalLinks class="border-t border-slate-200 pt-4" />
    </form>
  </div>
</template>
