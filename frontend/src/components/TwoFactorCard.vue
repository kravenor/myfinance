<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { confirmAction } from '@/composables/useConfirm'

const auth = useAuthStore()
const toast = useToastStore()

const enabled = computed(() => auth.user?.two_factor_enabled === true)
const password = ref('')
const code = ref('')
const busy = ref(false)
const error = ref('')
const setup = ref<{ secret: string; qr_svg: string } | null>(null)
const recoveryCodes = ref<string[]>([])
const codeInput = ref<HTMLInputElement | null>(null)

// SVG generato dal server: come <img> non esegue script, a differenza di v-html.
const qrSrc = computed(() => (setup.value ? `data:image/svg+xml;base64,${btoa(setup.value.qr_svg)}` : ''))

function messageOf(e: unknown, fallback: string): string {
  const err = e as { response?: { status?: number; data?: { message?: string } } }
  if (err.response?.status === 429) return 'Troppi tentativi: attendi un minuto.'
  return err.response?.data?.message ?? fallback
}

async function run(action: () => Promise<void>, fallback: string) {
  busy.value = true
  error.value = ''
  try {
    await action()
  } catch (e: unknown) {
    error.value = messageOf(e, fallback)
  } finally {
    busy.value = false
  }
}

function start() {
  return run(async () => {
    const { data } = await api.post<{ secret: string; qr_svg: string }>('/auth/two-factor', {
      current_password: password.value,
    })
    setup.value = data
    password.value = ''
    code.value = ''
    await nextTick()
    codeInput.value?.focus()
  }, 'Attivazione non riuscita.')
}

function confirm() {
  return run(async () => {
    const { data } = await api.post<{ recovery_codes: string[] }>('/auth/two-factor/confirm', {
      code: code.value.replace(/\s/g, ''),
    })
    recoveryCodes.value = data.recovery_codes
    setup.value = null
    await auth.fetchMe()
  }, 'Codice non valido.')
}

function regenerate() {
  return run(async () => {
    const { data } = await api.post<{ recovery_codes: string[] }>('/auth/two-factor/recovery-codes', {
      current_password: password.value,
    })
    recoveryCodes.value = data.recovery_codes
    password.value = ''
  }, 'Rigenerazione non riuscita.')
}

async function disable() {
  const ok = await confirmAction('Disattivare la verifica in due passaggi?', {
    title: 'Verifica in due passaggi',
    detail: 'Per accedere basterà di nuovo la password. I codici di recupero non varranno più.',
    confirmLabel: 'Disattiva',
    danger: true,
  })
  if (!ok) return
  await run(async () => {
    await api.delete('/auth/two-factor', { data: { current_password: password.value } })
    password.value = ''
    recoveryCodes.value = []
    await auth.fetchMe()
    toast.success('Verifica in due passaggi disattivata.')
  }, 'Disattivazione non riuscita.')
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Copiato.')
  } catch {
    toast.error('Copia non riuscita: seleziona il testo a mano.')
  }
}

function download() {
  const url = URL.createObjectURL(new Blob([recoveryCodes.value.join('\n') + '\n'], { type: 'text/plain' }))
  const a = Object.assign(document.createElement('a'), { href: url, download: 'codici-recupero-finance.txt' })
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="p-4 card sm:p-6 space-y-5">
    <div>
      <h2 class="font-medium">
        Verifica in due passaggi
        <span v-if="enabled" class="ml-2 text-xs font-normal text-income-700">Attiva</span>
      </h2>
      <p class="text-sm text-slate-500 mt-1">
        Oltre alla password, all'accesso ti chiede un codice dall'app di autenticazione del telefono (Google
        Authenticator, Microsoft Authenticator, 1Password…). Sui dispositivi con «Ricordami» il codice si
        chiede solo al primo accesso.
      </p>
    </div>

    <div v-if="recoveryCodes.length" class="space-y-3" role="status">
      <p class="text-sm text-slate-800">
        <strong>Salva questi codici di recupero</strong> in un posto sicuro: servono se perdi il telefono.
        Ognuno vale una volta sola e non verranno più mostrati.
      </p>
      <ul class="grid grid-cols-2 gap-2 rounded-md bg-slate-100 p-3 font-mono text-sm">
        <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="copy(recoveryCodes.join('\n'))">Copia</button>
        <button type="button" class="btn-secondary" @click="download">Scarica .txt</button>
        <button type="button" class="btn-primary" @click="recoveryCodes = []">Li ho salvati</button>
      </div>
    </div>

    <form v-else-if="setup" class="space-y-4" @submit.prevent="confirm">
      <p class="text-sm text-slate-800">
        Inquadra il QR con l'app di autenticazione, poi inserisci il codice a 6 cifre che mostra.
      </p>
      <img
        :src="qrSrc"
        alt="QR code da inquadrare con l'app di autenticazione"
        class="h-48 w-48 rounded bg-white p-1"
      />
      <div>
        <p class="text-sm text-slate-500">Non riesci a inquadrarlo? Inserisci a mano questa chiave:</p>
        <div class="mt-1 flex items-center gap-2">
          <code class="break-all font-mono text-sm">{{ setup.secret }}</code>
          <button type="button" class="btn-secondary shrink-0" @click="copy(setup.secret)">Copia</button>
        </div>
      </div>
      <div>
        <label class="label" for="two-factor-code">Codice</label>
        <input
          id="two-factor-code"
          ref="codeInput"
          v-model="code"
          type="text"
          class="input num w-40 tracking-widest"
          inputmode="numeric"
          autocomplete="one-time-code"
          pattern="[0-9 ]{6,7}"
          maxlength="7"
          required
          :aria-invalid="!!error"
        />
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary" :disabled="busy">
          {{ busy ? 'Verifica…' : 'Conferma' }}
        </button>
        <button type="button" class="btn-secondary" :disabled="busy" @click="setup = null">Annulla</button>
        <span v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</span>
      </div>
    </form>

    <form v-else class="space-y-4" @submit.prevent="enabled ? regenerate() : start()">
      <div>
        <label class="label" for="two-factor-password">Password attuale</label>
        <input
          id="two-factor-password"
          v-model="password"
          type="password"
          class="input"
          required
          autocomplete="current-password"
        />
        <p class="field-hint">
          {{
            enabled
              ? 'Serve per rigenerare i codici di recupero o disattivare la verifica.'
              : "Dopo l'attivazione, gli altri dispositivi con «Ricordami» ti chiederanno di accedere di nuovo, con il codice."
          }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <template v-if="enabled">
          <button type="submit" class="btn-secondary" :disabled="busy">Rigenera codici di recupero</button>
          <button type="button" class="btn-danger" :disabled="busy || !password" @click="disable">
            Disattiva
          </button>
        </template>
        <button v-else type="submit" class="btn-primary" :disabled="busy">
          {{ busy ? 'Attendi…' : 'Attiva' }}
        </button>
        <span v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</span>
      </div>
    </form>
  </div>
</template>
