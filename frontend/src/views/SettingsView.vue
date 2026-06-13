<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import type { NotificationPreferences } from '@/types/api'

const auth = useAuthStore()

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
  try {
    const { data } = await api.get<{ data: NotificationPreferences }>('/notification-preferences')
    hydrate(data.data)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6 max-w-2xl">
    <h1 class="text-xl sm:text-2xl font-semibold">Impostazioni</h1>

    <form class="card p-4 sm:p-6 space-y-5" @submit.prevent="onSubmit">
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
</template>
