<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { DATE_FORMATS, DEFAULT_DATE_FORMAT, formatDateWith } from '@/lib/date'
import { useAuthStore } from '@/stores/auth'
import { ALWAYS_VISIBLE, NAV_ITEMS, useMenuStore } from '@/stores/menu'
import type { NotificationPreferences, User } from '@/types/api'

const auth = useAuthStore()
const menu = useMenuStore()

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
})

const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const error = ref('')

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
      'Aggiornamento non riuscito. Controlla la password attuale e i requisiti della nuova.'
    throw e
  } finally {
    passwordSaving.value = false
  }
}

const dateFormat = ref(DEFAULT_DATE_FORMAT)
const dateSaving = ref(false)
const dateSaved = ref(false)
const dateError = ref('')
const dateSample = computed(() => formatDateWith(new Date(), dateFormat.value))

async function onDateSubmit() {
  dateSaving.value = true
  dateSaved.value = false
  dateError.value = ''
  try {
    await api.put<{ data: User }>('/auth/preferences', { date_format: dateFormat.value })
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
  form.value = {
    email: prefs.email,
    email_address: prefs.email_address ?? '',
    budget: prefs.budget,
    savings_goals: prefs.savings_goals,
    budget_threshold: prefs.budget_threshold,
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
    }
    const { data } = await api.put<{ data: NotificationPreferences }>('/notification-preferences', payload)
    hydrate(data.data)
    saved.value = true
    await auth.fetchMe()
  } catch (e: unknown) {
    error.value = 'Salvataggio non riuscito. Controlla i campi.'
    throw e
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  dateFormat.value = auth.user?.date_format ?? DEFAULT_DATE_FORMAT
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
  <div class="space-y-6 w-full grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="card p-4 sm:p-6 mt-6">
      <form class="space-y-5" @submit.prevent="onSubmit">
        <div>
          <h2 class="font-medium">Notifiche</h2>
          <p class="text-sm text-slate-500 mt-1">
            Le notifiche in-app sono sempre attive. Qui configuri email e tipi di avviso.
          </p>
        </div>

        <p v-if="loading" class="text-sm text-slate-500">Caricamento…</p>

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
            />
            <p class="text-xs text-slate-500 mt-1">Lascia vuoto per usare l'email dell'account.</p>
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
            />
            <p class="text-xs text-slate-500 mt-1">
              Percentuale oltre la quale un budget è «in allerta» (sotto il 100% = sforato).
            </p>
          </div>

          <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? 'Salvataggio…' : 'Salva' }}
            </button>
            <span v-if="saved" class="text-sm text-green-600">Preferenze salvate.</span>
            <span v-if="error" class="text-sm text-red-600">{{ error }}</span>
          </div>
        </template>
      </form>
    </div>
    <div class="p-4 card sm:p-6">
      <form class="space-y-5" @submit.prevent="onPasswordSubmit">
        <div>
          <h2 class="font-medium">Password</h2>
          <p class="text-sm text-slate-500 mt-1">Cambia la password di accesso al tuo account.</p>
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
          <span v-if="passwordSaved" class="text-sm text-green-600">Password aggiornata.</span>
          <span v-if="passwordError" class="text-sm text-red-600">{{ passwordError }}</span>
        </div>
      </form>
    </div>
    <div class="card p-4 sm:p-6">
      <form class="space-y-5" @submit.prevent="onDateSubmit">
        <div>
          <h2 class="font-medium">Formato data</h2>
          <p class="text-sm text-slate-500 mt-1">
            Come vengono mostrate le date in tutte le pagine. I campi di inserimento restano nel
            formato del tuo dispositivo.
          </p>
        </div>

        <div>
          <label class="label">Formato</label>
          <select v-model="dateFormat" class="input w-48">
            <option v-for="f in DATE_FORMATS" :key="f" :value="f">{{ f }}</option>
          </select>
          <p class="text-xs text-slate-500 mt-1">Anteprima: {{ dateSample }}</p>
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="submit" class="btn-primary" :disabled="dateSaving">
            {{ dateSaving ? 'Salvataggio…' : 'Salva' }}
          </button>
          <span v-if="dateSaved" class="text-sm text-green-600">Formato salvato.</span>
          <span v-if="dateError" class="text-sm text-red-600">{{ dateError }}</span>
        </div>
      </form>
    </div>

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
          <span class="text-sm">{{ item.label }}</span>
        </label>
      </div>
    </section>
  </div>
</template>
