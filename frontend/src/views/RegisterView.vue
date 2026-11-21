<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { formatDateWith } from '@/lib/date'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const error = ref<string | null>(null)

// Con ?invite= la registrazione dipende dall'invito, non dal flag globale.
const inviteToken = typeof route.query.invite === 'string' ? route.query.invite : ''
// 'loading' finché non si sa se il form è usabile: evita il lampo di "disattivata".
const state = ref<'loading' | 'open' | 'closed' | 'invalid-invite'>('loading')
const lockedEmail = ref<string | null>(null)
const inviteExpires = ref<string | null>(null)

onMounted(async () => {
  if (inviteToken) {
    const invite = await auth.invitation(inviteToken)
    if (!invite) {
      state.value = 'invalid-invite'
      return
    }
    lockedEmail.value = invite.email
    inviteExpires.value = invite.expires_at
    if (invite.email) form.value.email = invite.email
    state.value = 'open'
    return
  }
  state.value = (await auth.registrationEnabled()) ? 'open' : 'closed'
})

async function onSubmit() {
  error.value = null
  try {
    await auth.register(inviteToken ? { ...form.value, invitation_token: inviteToken } : form.value)
    router.push('/')
  } catch (e: unknown) {
    const err = e as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } }
    }
    const errors = err.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : (err.response?.data?.message ?? 'Registrazione non riuscita.')
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <p v-if="state === 'loading'" role="status" class="text-sm text-slate-600">Caricamento…</p>
    <div v-else-if="state === 'invalid-invite'" class="card w-full max-w-md p-6 space-y-4">
      <h1 class="text-xl font-semibold">Invito non valido</h1>
      <p class="text-sm text-slate-600">
        Il link è scaduto, è già stato usato oppure è stato revocato. Chiedi a chi ti ha invitato di mandartene uno nuovo.
      </p>
      <RouterLink to="/login" class="btn-primary w-full text-center">Vai all'accesso</RouterLink>
    </div>
    <div v-else-if="state === 'closed'" class="card w-full max-w-md p-6 space-y-4">
      <h1 class="text-xl font-semibold">Registrazione disattivata</h1>
      <p class="text-sm text-slate-600">Su questa installazione non si possono creare nuovi account senza un invito.</p>
      <RouterLink to="/login" class="btn-primary w-full text-center">Vai all'accesso</RouterLink>
    </div>
    <form v-else class="card w-full max-w-md p-6 space-y-4" @submit.prevent="onSubmit">
      <h1 class="text-xl font-semibold">Registrati</h1>
      <p v-if="inviteToken && inviteExpires" class="text-sm text-slate-600">
        Hai ricevuto un invito a creare un account, valido fino al {{ formatDateWith(inviteExpires) }}.
      </p>
      <div>
        <label class="label" for="name">Nome</label>
        <input id="name" v-model="form.name" required class="input" />
      </div>
      <div>
        <label class="label" for="email">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          required
          class="input"
          :readonly="lockedEmail !== null"
          :aria-describedby="lockedEmail !== null ? 'hint-email' : undefined"
        />
        <p v-if="lockedEmail !== null" id="hint-email" class="field-hint">L'invito vale solo per questo indirizzo.</p>
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
      <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
        {{ auth.loading ? 'Registrazione…' : 'Crea account' }}
      </button>
      <p class="text-sm text-slate-600 text-center">
        Hai già un account?
        <RouterLink to="/login" class="text-primary-600 hover:underline">Accedi</RouterLink>
      </p>
      <p class="border-t border-slate-200 pt-4 text-center text-xs text-slate-500">
        Creando l'account dichiari di aver letto l'<RouterLink
          :to="{ name: 'privacy' }"
          class="underline hover:text-slate-700"
          >informativa sulla privacy</RouterLink
        >
        e la
        <RouterLink :to="{ name: 'cookie' }" class="underline hover:text-slate-700">cookie policy</RouterLink
        >.
      </p>
    </form>
  </div>
</template>
