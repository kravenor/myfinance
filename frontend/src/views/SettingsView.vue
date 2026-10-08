<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { DATE_FORMATS, DEFAULT_DATE_FORMAT, formatDateWith, financialMonthRange } from '@/lib/date'
import { useAuthStore } from '@/stores/auth'
import { ALWAYS_VISIBLE, NAV_ITEMS, useMenuStore } from '@/stores/menu'
import { useThemeStore, type ThemePreference } from '@/stores/theme'
import AppIcon from '@/components/ui/AppIcon.vue'
import TwoFactorCard from '@/components/TwoFactorCard.vue'
import { disablePush, enablePush, pushState, sendTestPush, type PushState } from '@/lib/push'
import { useToastStore } from '@/stores/toast'
import type { NotificationPreferences, User } from '@/types/api'

const auth = useAuthStore()
const menu = useMenuStore()
const theme = useThemeStore()
const THEME_OPTIONS: { value: ThemePreference; label: string; hint: string }[] = [
  { value: 'system', label: 'Automatico', hint: 'Segue il sistema operativo' },
  { value: 'light', label: 'Chiaro', hint: 'Sempre chiaro' },
  { value: 'dark', label: 'Scuro', hint: 'Sempre scuro' },
]

const menuItems = NAV_ITEMS.map((item) => ({
  ...item,
  locked: ALWAYS_VISIBLE.includes(item.name),
}))

const form = ref<NotificationPreferences>({
  email: true,
  email_address: '',
  budget: true,
  savings_goals: true,
  budget_threshold: 80,
  pac: true,
  stale_prices: true,
  monthly_summary: true,
  large_expense: false,
  large_expense_threshold: 500,
})

const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const error = ref('')
// Cambiare l'indirizzo delle notifiche richiede la password attuale (al vecchio arriva un avviso).
const savedAddress = ref('')
const addressPassword = ref('')
const addressChanged = computed(
  () => (form.value.email_address ?? '').trim().toLowerCase() !== savedAddress.value.toLowerCase(),
)

const passwordForm = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordSaving = ref(false)
const passwordSaved = ref(false)
const passwordError = ref('')

async function onPasswordSubmit() {
  passwordSaving.value = true
  passwordSaved.value = false
  passwordError.value = ''
  try {
    await api.put('/auth/password', passwordForm.value)
    passwordSaved.value = true
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' }
  } catch (e: unknown) {
    passwordError.value =
      (e as { response?: { status?: number } }).response?.status === 429
        ? 'Troppi tentativi: attendi un minuto.'
        : 'Aggiornamento non riuscito. Controlla la password attuale e i requisiti della nuova.'
    throw e
  } finally {
    passwordSaving.value = false
  }
}

// Esci dagli altri dispositivi: l'unico modo per revocare sessioni e «Ricordami» aperti altrove.
const othersPassword = ref('')
const othersBusy = ref(false)
const othersError = ref('')
async function onLogoutOthers() {
  othersBusy.value = true
  othersError.value = ''
  try {
    await api.post('/auth/logout-other-devices', { current_password: othersPassword.value })
    othersPassword.value = ''
    toast.success('Gli altri dispositivi sono stati scollegati.')
  } catch (e: unknown) {
    const status = (e as { response?: { status?: number } }).response?.status
    othersError.value = status === 429 ? 'Troppi tentativi: attendi un minuto.' : 'Password non corretta.'
  } finally {
    othersBusy.value = false
  }
}

const dateFormat = ref(DEFAULT_DATE_FORMAT)
const monthStartDay = ref(1)
const monthStartDays = Array.from({ length: 28 }, (_, i) => i + 1)
const dateSaving = ref(false)
const dateSaved = ref(false)
const dateError = ref('')
const dateSample = computed(() => formatDateWith(new Date(), dateFormat.value))
const cycleSample = computed(() => {
  const { from, to } = financialMonthRange()
  return `${formatDateWith(from, dateFormat.value)} → ${formatDateWith(to, dateFormat.value)}`
})

async function onDateSubmit() {
  dateSaving.value = true
  dateSaved.value = false
  dateError.value = ''
  try {
    await api.put<{ data: User }>('/auth/preferences', {
      date_format: dateFormat.value,
      month_start_day: monthStartDay.value,
    })
    await auth.fetchMe()
    dateSaved.value = true
  } catch (e: unknown) {
    dateError.value = 'Salvataggio non riuscito.'
    throw e
  } finally {
    dateSaving.value = false
  }
}

function hydrate(prefs: NotificationPreferences) {
  savedAddress.value = prefs.email_address ?? ''
  addressPassword.value = ''
  form.value = {
    email: prefs.email,
    email_address: prefs.email_address ?? '',
    budget: prefs.budget,
    savings_goals: prefs.savings_goals,
    budget_threshold: prefs.budget_threshold,
    pac: prefs.pac,
    stale_prices: prefs.stale_prices,
    monthly_summary: prefs.monthly_summary,
    large_expense: prefs.large_expense,
    large_expense_threshold: prefs.large_expense_threshold,
  }
}

async function onSubmit() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    const payload = {
      ...form.value,
      email_address: form.value.email_address?.trim() || null,
      ...(addressChanged.value ? { current_password: addressPassword.value } : {}),
    }
    const { data } = await api.put<{ data: NotificationPreferences }>('/notification-preferences', payload)
    hydrate(data.data)
    saved.value = true
    await auth.fetchMe()
  } catch (e: unknown) {
    const errors = (e as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors
    error.value = errors?.current_password
      ? "Per cambiare l'indirizzo serve la password attuale corretta."
      : 'Salvataggio non riuscito. Controlla i campi.'
    throw e
  } finally {
    saving.value = false
  }
}

// Email di prova: dice subito se l'invio funziona (o se il server le scrive solo nel log).
const testingEmail = ref(false)
const testEmailResult = ref<{ ok: boolean; text: string } | null>(null)
async function sendTestEmail() {
  testingEmail.value = true
  testEmailResult.value = null
  try {
    const { data } = await api.post<{ message: string }>('/notification-preferences/test-email')
    testEmailResult.value = { ok: true, text: data.message }
  } catch (e: unknown) {
    const message = (e as { response?: { data?: { message?: string } } }).response?.data?.message
    testEmailResult.value = { ok: false, text: message ?? 'Invio non riuscito.' }
  } finally {
    testingEmail.value = false
  }
}

// Notifiche push sul dispositivo corrente (una sottoscrizione per browser).
const toast = useToastStore()
const push = ref<PushState | null>(null)
const pushBusy = ref(false)
const PUSH_HINT: Record<Exclude<PushState, 'on' | 'off'>, string> = {
  unsupported: 'Questo browser non supporta le notifiche push.',
  'ios-not-installed':
    'Su iPhone e iPad aggiungi prima Finance alla schermata Home (Condividi → Aggiungi alla schermata Home) e aprila da lì. Serve iOS 16.4 o successivo.',
  'no-worker': 'Disponibili nell\'app pubblicata: in sviluppo il service worker non è attivo.',
  'no-server-key': 'Il server non ha ancora le chiavi per le notifiche push (VAPID).',
  denied: 'Hai bloccato le notifiche per questo sito: riattivale dalle impostazioni del browser.',
}

async function runPush(action: () => Promise<PushState | void>, done?: string) {
  pushBusy.value = true
  try {
    const next = await action()
    if (next) push.value = next
    if (done) toast.success(done)
  } catch {
    toast.error('Operazione non riuscita. Riprova.')
  } finally {
    pushBusy.value = false
  }
}

onMounted(async () => {
  pushState().then((st) => (push.value = st)).catch(() => (push.value = 'unsupported'))
  dateFormat.value = auth.user?.date_format ?? DEFAULT_DATE_FORMAT
  monthStartDay.value = auth.user?.month_start_day ?? 1
  try {
    const { data } = await api.get<{ data: NotificationPreferences }>('/notification-preferences')
    hydrate(data.data)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <h1 class="text-xl sm:text-2xl font-semibold">Impostazioni</h1>
  <p class="page-desc">Avvisi, password, formato delle date, inizio del mese e aspetto dell'app.</p>
  <div class="space-y-6 w-full grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="card p-4 sm:p-6 mt-6">
      <form class="space-y-5" @submit.prevent="onSubmit">
        <div>
          <h2 class="font-medium">Notifiche</h2>
          <p class="text-sm text-slate-500 mt-1">
            Le notifiche in-app sono sempre attive. Qui configuri email e tipi di avviso.
          </p>
        </div>

        <div v-if="loading" class="space-y-3" aria-busy="true" aria-label="Caricamento">
          <div v-for="i in 3" :key="i" class="h-10 animate-pulse rounded bg-slate-100" />
        </div>

        <template v-else>
          <!-- Email -->
          <label class="flex items-start gap-3">
            <input v-model="form.email" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Ricevi notifiche via email</span>
              <span class="block text-xs text-slate-500">Oltre a quelle in-app.</span>
            </span>
          </label>

          <div :class="{ 'opacity-50 pointer-events-none': !form.email }">
            <label class="label">Email di destinazione</label>
            <input
              v-model="form.email_address"
              type="email"
              class="input"
              :placeholder="auth.user?.email ?? 'email dell\'account'"
              aria-describedby="hint-email-address"
            />
            <p id="hint-email-address" class="field-hint">Lascia vuoto per usare l'email dell'account.</p>
            <div v-if="addressChanged" class="mt-3">
              <label class="label" for="address-password">Password attuale</label>
              <input
                id="address-password"
                v-model="addressPassword"
                type="password"
                class="input"
                autocomplete="current-password"
                required
                aria-describedby="hint-address-password"
              />
              <p id="hint-address-password" class="field-hint">
                Serve per cambiare l'indirizzo. Al vecchio indirizzo arriva un'email che segnala il cambio.
              </p>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-3">
              <button type="button" class="btn-secondary" :disabled="testingEmail" @click="sendTestEmail">
                {{ testingEmail ? 'Invio…' : 'Invia email di prova' }}
              </button>
              <span v-if="testEmailResult" :role="testEmailResult.ok ? 'status' : 'alert'" class="text-sm" :class="testEmailResult.ok ? 'text-income-700' : 'text-danger-600'">
                {{ testEmailResult.text }}
              </span>
            </div>
            <p class="field-hint">Salva prima un indirizzo diverso: la prova va a quello salvato.</p>
          </div>

          <hr class="border-slate-100" />

          <!-- Tipi -->
          <p class="text-sm font-medium">Avvisi attivi</p>
          <label class="flex items-start gap-3">
            <input v-model="form.budget" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Budget sforati / in allerta</span>
              <span class="block text-xs text-slate-500">Quando la spesa supera la soglia impostata.</span>
            </span>
          </label>
          <label class="flex items-start gap-3">
            <input v-model="form.savings_goals" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Obiettivi di risparmio a rischio</span>
              <span class="block text-xs text-slate-500">Obiettivi in ritardo o scaduti.</span>
            </span>
          </label>
          <label class="flex items-start gap-3">
            <input v-model="form.pac" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Rate PAC</span>
              <span class="block text-xs text-slate-500">Dopo ogni rata registrata: quote comprate, o avviso se il prezzo è stimato o mancano le quote.</span>
            </span>
          </label>
          <label class="flex items-start gap-3">
            <input v-model="form.stale_prices" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Quotazioni ferme</span>
              <span class="block text-xs text-slate-500">Uno strumento in portafoglio non riceve quotazioni da più di 7 giorni.</span>
            </span>
          </label>
          <label class="flex items-start gap-3">
            <input v-model="form.monthly_summary" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Riepilogo mensile</span>
              <span class="block text-xs text-slate-500">A inizio mese: entrate, uscite e risparmio del mese appena chiuso.</span>
            </span>
          </label>
          <label class="flex items-start gap-3">
            <input v-model="form.large_expense" type="checkbox" class="w-4 h-4 mt-0.5" />
            <span>
              <span class="font-medium text-sm">Spese importanti</span>
              <span class="block text-xs text-slate-500">Quando registri o importi un'uscita oltre la soglia qui sotto.</span>
            </span>
          </label>
          <div :class="{ 'opacity-50 pointer-events-none': !form.large_expense }">
            <label class="label" for="large-expense-threshold">Soglia spesa importante ({{ auth.user?.currency ?? 'EUR' }})</label>
            <input
              id="large-expense-threshold"
              v-model.number="form.large_expense_threshold"
              type="number"
              min="1"
              step="1"
              class="input w-40"
              aria-describedby="hint-large-expense"
            />
            <p id="hint-large-expense" class="field-hint">
              Nella tua valuta principale: le uscite in altre valute vengono convertite al cambio del giorno.
            </p>
          </div>

          <!-- Soglia -->
          <div :class="{ 'opacity-50 pointer-events-none': !form.budget }">
            <label class="label">Soglia di allerta budget (%)</label>
            <input
              v-model.number="form.budget_threshold"
              type="number"
              min="1"
              max="100"
              step="1"
              class="input w-32"
              aria-describedby="hint-budget-threshold"
            />
            <p id="hint-budget-threshold" class="field-hint">
              Un budget va «in allerta» quando la spesa raggiunge questa percentuale ed è «sforato» dal 100%. Vale per notifiche, Dashboard e pagina Budget.
            </p>
          </div>

          <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? 'Salvataggio…' : 'Salva' }}
            </button>
            <span v-if="saved" role="status" class="text-sm text-income-700">Preferenze salvate.</span>
            <span v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</span>
          </div>
        </template>
      </form>
    </div>
    <div class="p-4 card sm:p-6">
      <form class="space-y-5" @submit.prevent="onPasswordSubmit">
        <div>
          <h2 class="font-medium">Password</h2>
          <p class="text-sm text-slate-500 mt-1">Cambia la password di accesso al tuo account. Questo dispositivo resta collegato; gli altri ti chiederanno di accedere di nuovo.</p>
        </div>

        <div>
          <label class="label">Password attuale</label>
          <input
            v-model="passwordForm.current_password"
            type="password"
            class="input"
            required
            autocomplete="current-password"
          />
        </div>
        <div>
          <label class="label">Nuova password</label>
          <input
            v-model="passwordForm.password"
            type="password"
            class="input"
            required
            autocomplete="new-password"
          />
        </div>
        <div>
          <label class="label">Conferma nuova password</label>
          <input
            v-model="passwordForm.password_confirmation"
            type="password"
            class="input"
            required
            autocomplete="new-password"
          />
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="submit" class="btn-primary" :disabled="passwordSaving">
            {{ passwordSaving ? 'Salvataggio…' : 'Cambia password' }}
          </button>
          <span v-if="passwordSaved" role="status" class="text-sm text-income-700">Password aggiornata.</span>
          <span v-if="passwordError" role="alert" class="text-sm text-danger-600">{{ passwordError }}</span>
        </div>
      </form>
    </div>
    <div class="p-4 card sm:p-6">
      <form class="space-y-5" @submit.prevent="onLogoutOthers">
        <div>
          <h2 class="font-medium">Dispositivi collegati</h2>
          <p class="text-sm text-slate-500 mt-1">
            «Esci» chiude solo questo dispositivo. Se hai usato Finance su un computer non tuo o temi che qualcuno abbia
            accesso, scollega tutti gli altri: anche quelli con «Ricordami» ti chiederanno di accedere di nuovo.
          </p>
        </div>
        <div>
          <label class="label" for="others-password">Password attuale</label>
          <input
            id="others-password"
            v-model="othersPassword"
            type="password"
            class="input"
            required
            autocomplete="current-password"
          />
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button type="submit" class="btn-secondary" :disabled="othersBusy">
            {{ othersBusy ? 'Attendi…' : 'Esci dagli altri dispositivi' }}
          </button>
          <span v-if="othersError" role="alert" class="text-sm text-danger-600">{{ othersError }}</span>
        </div>
      </form>
    </div>
    <TwoFactorCard />
    <div class="card p-4 sm:p-6">
      <form class="space-y-5" @submit.prevent="onDateSubmit">
        <div>
          <h2 class="font-medium">Periodo e data</h2>
          <p class="text-sm text-slate-500 mt-1">
            Come vengono mostrate le date e da che giorno parte il mese nei calcoli (budget,
            report, previsioni). I campi di inserimento restano nel formato del tuo dispositivo.
          </p>
        </div>

        <div>
          <label class="label">Formato</label>
          <select v-model="dateFormat" class="input w-48" aria-describedby="hint-date-format">
            <option v-for="f in DATE_FORMATS" :key="f" :value="f">{{ f }}</option>
          </select>
          <p id="hint-date-format" class="field-hint">
            Anteprima: {{ dateSample }}. Cambia solo come vedi le date; l'import da file ha un suo formato data.
          </p>
        </div>

        <div>
          <label class="label">Giorno di inizio del mese</label>
          <select v-model.number="monthStartDay" class="input w-48" aria-describedby="hint-month-start">
            <option v-for="d in monthStartDays" :key="d" :value="d">{{ d }}</option>
          </select>
          <p id="hint-month-start" class="field-hint">
            Utile se il tuo mese parte dallo stipendio: con 27, il mese di giugno va dal 27/06 al 26/07. Periodo corrente: {{ cycleSample }}.
            Cambiarlo non rinumera i budget già inseriti, ma ricalcola quanto risulta speso.
          </p>
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="submit" class="btn-primary" :disabled="dateSaving">
            {{ dateSaving ? 'Salvataggio…' : 'Salva' }}
          </button>
          <span v-if="dateSaved" role="status" class="text-sm text-income-700">Formato salvato.</span>
          <span v-if="dateError" role="alert" class="text-sm text-danger-600">{{ dateError }}</span>
        </div>
      </form>
    </div>

    <section class="card p-4 sm:p-6 space-y-4">
      <div>
        <h2 class="font-medium">Notifiche su questo dispositivo</h2>
        <p class="text-sm text-slate-500 mt-1">
          Ricevi gli avvisi anche ad app chiusa, come le notifiche delle altre app. Si attivano dispositivo per dispositivo.
        </p>
      </div>
      <p v-if="push === null" class="text-sm text-slate-500">Verifica in corso…</p>
      <p v-else-if="push !== 'on' && push !== 'off'" class="text-sm text-slate-600">{{ PUSH_HINT[push] }}</p>
      <div v-else class="flex flex-wrap items-center gap-3">
        <span class="inline-flex items-center gap-2 text-sm" :class="push === 'on' ? 'text-income-700' : 'text-slate-600'">
          <span class="h-2 w-2 rounded-full" :class="push === 'on' ? 'bg-income-500' : 'bg-slate-400'" aria-hidden="true" />
          {{ push === 'on' ? 'Attive su questo dispositivo' : 'Non attive su questo dispositivo' }}
        </span>
        <button v-if="push === 'off'" type="button" class="btn-primary" :disabled="pushBusy" @click="runPush(enablePush, 'Notifiche attivate.')">
          Attiva
        </button>
        <template v-else>
          <button type="button" class="btn-secondary" :disabled="pushBusy" @click="runPush(sendTestPush, 'Notifica di prova inviata.')">
            Invia una prova
          </button>
          <button type="button" class="btn-secondary" :disabled="pushBusy" @click="runPush(disablePush, 'Notifiche disattivate su questo dispositivo.')">
            Disattiva
          </button>
        </template>
      </div>
    </section>

    <section class="card p-4 sm:p-6 space-y-4">
      <div>
        <h2 class="font-medium">Aspetto</h2>
        <p class="text-sm text-slate-500 mt-1">Tema dell'interfaccia, salvato su questo dispositivo.</p>
      </div>
      <fieldset class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <legend class="sr-only">Tema</legend>
        <label
          v-for="opt in THEME_OPTIONS"
          :key="opt.value"
          class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
          :class="theme.preference === opt.value ? 'border-primary-500 bg-primary-50' : 'border-slate-200 hover:border-slate-300'"
        >
          <input v-model="theme.preference" type="radio" name="theme" :value="opt.value" class="mt-0.5 h-4 w-4" />
          <span>
            <span class="block text-sm font-medium text-slate-900">{{ opt.label }}</span>
            <span class="block text-xs text-slate-500">{{ opt.hint }}</span>
          </span>
        </label>
      </fieldset>
    </section>

    <section class="card p-4 sm:p-6 space-y-5">
      <div>
        <h2 class="font-medium">Sezioni del menu</h2>
        <p class="text-sm text-slate-500 mt-1">
          Disattiva le voci che non usi per snellire il menu laterale. La scelta è salvata su questo
          dispositivo; Dashboard e Impostazioni restano sempre visibili.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
        <label
          v-for="item in menuItems"
          :key="item.name"
          class="flex items-center gap-3 py-1.5"
          :class="{ 'opacity-50': item.locked }"
        >
          <input
            type="checkbox"
            class="w-4 h-4"
            :checked="menu.isVisible(item.name)"
            :disabled="item.locked"
            @change="menu.setVisible(item.name, ($event.target as HTMLInputElement).checked)"
          />
          <AppIcon :name="item.icon" class="h-4 w-4 text-slate-500" />
          <span class="text-sm">{{ item.label }}</span>
        </label>
      </div>
    </section>
  </div>
</template>
