<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ensureCsrf } from '@/lib/api'
import type { Account, Paginated } from '@/types/api'

interface PreviewResult {
  headers: string[]
  sample: Record<string, string>[]
  suggested: {
    date: string | null
    amount: string | null
    description: string | null
    type: string | null
    category: string | null
  }
}

interface ImportResult {
  imported: number
  skipped: number
  auto_categorized: number
  errors: { row: number; message: string }[]
}

interface Prediction {
  category_id: number | null
  category_name: string | null
  rule_id: number | null
}

const accounts = ref<Account[]>([])

const exportFilters = ref({ account_id: '', type: '', from: '', to: '' })
const exporting = ref(false)

async function downloadExport() {
  exporting.value = true
  try {
    const params = new URLSearchParams()
    if (exportFilters.value.account_id) params.set('account_id', exportFilters.value.account_id)
    if (exportFilters.value.type) params.set('type', exportFilters.value.type)
    if (exportFilters.value.from) params.set('from', exportFilters.value.from)
    if (exportFilters.value.to) params.set('to', exportFilters.value.to)

    const response = await api.get(`/transactions/export?${params.toString()}`, { responseType: 'blob' })
    const blob = new Blob([response.data], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `transactions-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
    URL.revokeObjectURL(url)
  } finally {
    exporting.value = false
  }
}

const importFile = ref<File | null>(null)
const importAccount = ref<number>(0)
const dateFormat = ref('Y-m-d')
const preview = ref<PreviewResult | null>(null)
const mapping = ref<Record<'date' | 'amount' | 'description' | 'type' | 'category', string>>({
  date: '',
  amount: '',
  description: '',
  type: '',
  category: '',
})
const importResult = ref<ImportResult | null>(null)
const importing = ref(false)
const previewLoading = ref(false)
const importError = ref<string | null>(null)
const predictions = ref<Prediction[]>([])
const predictionsLoading = ref(false)

function onFileChange(e: Event) {
  const target = e.target as HTMLInputElement
  importFile.value = target.files?.[0] ?? null
  preview.value = null
  importResult.value = null
  importError.value = null
  predictions.value = []
}

async function loadPredictions() {
  if (!importFile.value || !mapping.value.date || !mapping.value.amount) return
  predictionsLoading.value = true
  try {
    await ensureCsrf()
    const form = new FormData()
    form.append('file', importFile.value)
    form.append('mapping[date]', mapping.value.date)
    form.append('mapping[amount]', mapping.value.amount)
    if (mapping.value.description) form.append('mapping[description]', mapping.value.description)
    if (mapping.value.type) form.append('mapping[type]', mapping.value.type)
    if (mapping.value.category) form.append('mapping[category]', mapping.value.category)
    const { data } = await api.post<{ data: Prediction[] }>(
      '/transactions/import/preview-predictions',
      form,
    )
    predictions.value = data.data
  } catch {
    predictions.value = []
  } finally {
    predictionsLoading.value = false
  }
}

async function runPreview() {
  if (!importFile.value) return
  previewLoading.value = true
  importError.value = null
  try {
    await ensureCsrf()
    const form = new FormData()
    form.append('file', importFile.value)
    const { data } = await api.post<{ data: PreviewResult }>('/transactions/import/preview', form)
    preview.value = data.data
    mapping.value = {
      date: data.data.suggested.date ?? '',
      amount: data.data.suggested.amount ?? '',
      description: data.data.suggested.description ?? '',
      type: data.data.suggested.type ?? '',
      category: data.data.suggested.category ?? '',
    }
    await loadPredictions()
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    importError.value = err.response?.data?.message ?? 'Errore nella lettura del file.'
  } finally {
    previewLoading.value = false
  }
}

async function runImport() {
  if (!importFile.value || !importAccount.value || !mapping.value.date || !mapping.value.amount) return
  importing.value = true
  importError.value = null
  try {
    await ensureCsrf()
    const form = new FormData()
    form.append('file', importFile.value)
    form.append('account_id', String(importAccount.value))
    form.append('mapping[date]', mapping.value.date)
    form.append('mapping[amount]', mapping.value.amount)
    if (mapping.value.description) form.append('mapping[description]', mapping.value.description)
    if (mapping.value.type) form.append('mapping[type]', mapping.value.type)
    if (mapping.value.category) form.append('mapping[category]', mapping.value.category)
    form.append('date_format', dateFormat.value)

    const { data } = await api.post<{ data: ImportResult }>('/transactions/import', form)
    importResult.value = data.data
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    importError.value = err.response?.data?.message ?? 'Errore nell\'import.'
  } finally {
    importing.value = false
  }
}

const canImport = computed(
  () => !!importFile.value && !!importAccount.value && !!mapping.value.date && !!mapping.value.amount,
)

watch(
  () => [
    mapping.value.description,
    mapping.value.type,
    mapping.value.category,
    mapping.value.date,
    mapping.value.amount,
  ],
  () => {
    if (preview.value) loadPredictions()
  },
)

onMounted(async () => {
  const { data } = await api.get<Paginated<Account>>('/accounts', { params: { per_page: 100 } })
  accounts.value = data.data
  importAccount.value = accounts.value.find((a) => a.is_primary)?.id ?? accounts.value[0]?.id ?? 0
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl sm:text-2xl font-semibold">Import / Export</h1>

    <section class="card p-4 space-y-4">
      <h2 class="font-medium">Export CSV</h2>
      <form class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3" @submit.prevent="downloadExport">
        <div>
          <label class="label">Conto</label>
          <select v-model="exportFilters.account_id" class="input">
            <option value="">Tutti</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.is_primary ? ' ★' : '' }}</option>
          </select>
        </div>
        <div>
          <label class="label">Tipo</label>
          <select v-model="exportFilters.type" class="input">
            <option value="">Tutti</option>
            <option value="income">income</option>
            <option value="expense">expense</option>
            <option value="transfer">transfer</option>
          </select>
        </div>
        <div>
          <label class="label">Da</label>
          <input v-model="exportFilters.from" type="date" class="input" />
        </div>
        <div>
          <label class="label">A</label>
          <input v-model="exportFilters.to" type="date" class="input" />
        </div>
        <div class="flex items-end sm:col-span-2 md:col-span-1">
          <button class="btn-primary w-full" :disabled="exporting" type="submit">
            {{ exporting ? 'Download…' : 'Scarica CSV' }}
          </button>
        </div>
      </form>
    </section>

    <section class="card p-4 space-y-4">
      <h2 class="font-medium">Import CSV</h2>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="md:col-span-2">
          <label class="label">File CSV</label>
          <input type="file" accept=".csv,text/csv" class="input" @change="onFileChange" />
        </div>
        <div>
          <label class="label">Conto destinazione</label>
          <select v-model.number="importAccount" class="input">
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.is_primary ? ' ★' : '' }}</option>
          </select>
        </div>
      </div>

      <div>
        <button class="btn-secondary" :disabled="!importFile || previewLoading" @click="runPreview">
          {{ previewLoading ? 'Analisi…' : 'Analizza file' }}
        </button>
      </div>

      <div v-if="preview" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
          <div v-for="field in ['date','amount','description','type','category'] as const" :key="field">
            <label class="label capitalize">{{ field }}{{ ['date','amount'].includes(field) ? ' *' : '' }}</label>
            <select v-model="mapping[field]" class="input">
              <option value="">— Nessuna —</option>
              <option v-for="h in preview.headers" :key="h" :value="h">{{ h }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="label">Formato data (PHP date format)</label>
          <input v-model="dateFormat" class="input md:w-48" placeholder="Y-m-d" />
        </div>

        <div class="flex items-center justify-between gap-3 text-sm">
          <div>
            <span class="text-slate-500">
              Categoria suggerita dalle
              <RouterLink :to="{ name: 'categorization-rules' }" class="underline">
                regole di categorizzazione
              </RouterLink>.
            </span>
            <span v-if="predictionsLoading" class="ml-2 text-slate-400">Calcolo…</span>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="table">
            <thead class="bg-slate-100">
              <tr>
                <th v-for="h in preview.headers" :key="h">{{ h }}</th>
                <th>Categoria suggerita</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(row, idx) in preview.sample" :key="idx">
                <td v-for="h in preview.headers" :key="h">{{ row[h] }}</td>
                <td class="text-sm">
                  <span v-if="predictions[idx]?.category_name" class="text-slate-700">
                    {{ predictions[idx].category_name }}
                  </span>
                  <span v-else class="text-slate-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div>
          <button class="btn-primary" :disabled="!canImport || importing" @click="runImport">
            {{ importing ? 'Import…' : 'Esegui import' }}
          </button>
        </div>
      </div>

      <p v-if="importError" class="text-sm text-red-600">{{ importError }}</p>

      <div v-if="importResult" class="card bg-slate-50 p-4 space-y-2">
        <p class="text-sm">
          <span class="font-medium text-green-700">{{ importResult.imported }}</span> importate ·
          <span class="font-medium text-sky-700">{{ importResult.auto_categorized }}</span> auto-categorizzate ·
          <span class="font-medium text-amber-700">{{ importResult.skipped }}</span> saltate.
        </p>
        <p class="text-xs text-slate-500">
          <RouterLink :to="{ name: 'categorization-rules' }" class="underline">
            Gestisci regole →
          </RouterLink>
        </p>
        <details v-if="importResult.errors.length" class="text-sm">
          <summary class="cursor-pointer">{{ importResult.errors.length }} errori</summary>
          <ul class="mt-2 space-y-1 pl-4 list-disc">
            <li v-for="(err, i) in importResult.errors" :key="i">
              riga {{ err.row }}: {{ err.message }}
            </li>
          </ul>
        </details>
      </div>
    </section>
  </div>
</template>
