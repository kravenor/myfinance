<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
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
  errors: { row: number; message: string }[]
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

function onFileChange(e: Event) {
  const target = e.target as HTMLInputElement
  importFile.value = target.files?.[0] ?? null
  preview.value = null
  importResult.value = null
  importError.value = null
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

        <div class="overflow-x-auto">
          <table class="table">
            <thead class="bg-slate-100">
              <tr>
                <th v-for="h in preview.headers" :key="h">{{ h }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(row, idx) in preview.sample" :key="idx">
                <td v-for="h in preview.headers" :key="h">{{ row[h] }}</td>
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
          <span class="font-medium text-green-700">{{ importResult.imported }}</span> importate,
          <span class="font-medium text-amber-700">{{ importResult.skipped }}</span> saltate.
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
