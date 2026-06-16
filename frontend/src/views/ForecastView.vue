<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { formatCurrency as money, CURRENCIES } from '@/lib/money'
import RowActions from '@/components/ui/RowActions.vue'
import type {
  Account,
  Category,
  Paginated,
  Scenario,
  ScenarioCadence,
  ScenarioItem,
} from '@/types/api'
import type {
  ExpenseForecast,
  ExpenseForecastCell,
  ExpenseForecastCompare,
} from '@/types/reports'

const auth = useAuthStore()

// --- Forecast --------------------------------------------------------------
const months = ref(6)
const forecast = ref<ExpenseForecast | null>(null)
const baseline = ref<ExpenseForecast | null>(null)
const comparison = ref<ExpenseForecastCompare | null>(null)
const selectedScenarioId = ref<number | ''>('')
const loading = ref(false)
const showCategoryBreakdown = ref(false)

async function refreshForecast() {
  loading.value = true
  try {
    const [base, compare] = await Promise.all([
      api.get<{ data: ExpenseForecast }>('/reports/expense-forecast', {
        params: { months: months.value },
      }),
      api.get<{ data: ExpenseForecastCompare }>('/reports/expense-forecast/compare', {
        params: { months: months.value },
      }),
    ])
    baseline.value = base.data.data
    comparison.value = compare.data.data

    if (selectedScenarioId.value !== '') {
      const sim = await api.get<{ data: ExpenseForecast }>('/reports/expense-forecast', {
        params: { months: months.value, scenario_id: selectedScenarioId.value },
      })
      forecast.value = sim.data.data
    } else {
      forecast.value = base.data.data
    }
  } finally {
    loading.value = false
  }
}

watch([selectedScenarioId, months], refreshForecast)

const baseCurrency = computed(() => forecast.value?.base_currency ?? auth.user?.currency ?? 'EUR')

const baselineByPeriod = computed<Record<string, number>>(() => {
  if (!baseline.value) return {}
  return Object.fromEntries(baseline.value.totals_by_month.map((t) => [t.period, parseFloat(t.net)]))
})

function deltaNetForPeriod(period: string, scenarioNet: number): number {
  const base = baselineByPeriod.value[period] ?? 0
  return scenarioNet - base
}

// --- Scenarios CRUD --------------------------------------------------------
const scenarios = ref<Scenario[]>([])
const scenariosLoading = ref(false)

async function loadScenarios() {
  scenariosLoading.value = true
  try {
    const { data } = await api.get<Paginated<Scenario>>('/scenarios', { params: { per_page: 100 } })
    scenarios.value = data.data
  } finally {
    scenariosLoading.value = false
  }
}

const editingScenario = ref<Scenario | null>(null)
const showScenarioForm = ref(false)
const scenarioForm = ref({ name: '', description: '', color: '#6366f1', is_active: true })

function resetScenarioForm() {
  editingScenario.value = null
  scenarioForm.value = { name: '', description: '', color: '#6366f1', is_active: true }
}

function startEditScenario(s: Scenario) {
  editingScenario.value = s
  scenarioForm.value = {
    name: s.name,
    description: s.description ?? '',
    color: s.color ?? '#6366f1',
    is_active: s.is_active,
  }
  showScenarioForm.value = true
}

async function submitScenario() {
  const payload = {
    name: scenarioForm.value.name,
    description: scenarioForm.value.description || null,
    color: scenarioForm.value.color || null,
    is_active: scenarioForm.value.is_active,
  }
  if (editingScenario.value) {
    await api.patch(`/scenarios/${editingScenario.value.id}`, payload)
  } else {
    const { data } = await api.post<{ data: Scenario }>('/scenarios', payload)
    selectedScenarioId.value = data.data.id
  }
  resetScenarioForm()
  showScenarioForm.value = false
  await loadScenarios()
  await refreshForecast()
}

async function deleteScenario(s: Scenario) {
  if (!confirm(`Eliminare lo scenario "${s.name}"?`)) return
  await api.delete(`/scenarios/${s.id}`)
  if (selectedScenarioId.value === s.id) selectedScenarioId.value = ''
  await loadScenarios()
  await refreshForecast()
}

// --- Scenario items --------------------------------------------------------
const categories = ref<Category[]>([])
const accounts = ref<Account[]>([])
const itemsScenario = ref<Scenario | null>(null)
const items = ref<ScenarioItem[]>([])
const itemsLoading = ref(false)
const today = new Date().toISOString().slice(0, 10)

const itemForm = ref({
  description: '',
  account_id: '' as number | '',
  category_id: '' as number | '',
  amount: '',
  currency: auth.user?.currency ?? 'EUR',
  cadence: 'one_time' as ScenarioCadence,
  interval: 1,
  starts_on: today,
  ends_on: '',
})

function resetItemForm() {
  itemForm.value = {
    description: '',
    account_id: '',
    category_id: '',
    amount: '',
    currency: auth.user?.currency ?? 'EUR',
    cadence: 'one_time',
    interval: 1,
    starts_on: today,
    ends_on: '',
  }
}

// Auto-allinea la valuta al conto selezionato
watch(
  () => itemForm.value.account_id,
  (id) => {
    if (id === '') return
    const acc = accounts.value.find((a) => a.id === id)
    if (acc) itemForm.value.currency = acc.currency
  },
)

async function openItems(s: Scenario) {
  itemsScenario.value = s
  resetItemForm()
  await loadItems()
}

function closeItems() {
  itemsScenario.value = null
  items.value = []
}

async function loadItems() {
  if (!itemsScenario.value) return
  itemsLoading.value = true
  try {
    const { data } = await api.get<Paginated<ScenarioItem>>(
      `/scenarios/${itemsScenario.value.id}/items`,
      { params: { per_page: 100 } },
    )
    items.value = data.data
  } finally {
    itemsLoading.value = false
  }
}

async function addItem() {
  if (!itemsScenario.value) return
  await api.post(`/scenarios/${itemsScenario.value.id}/items`, {
    description: itemForm.value.description || null,
    account_id: itemForm.value.account_id === '' ? null : itemForm.value.account_id,
    category_id: itemForm.value.category_id === '' ? null : itemForm.value.category_id,
    amount: itemForm.value.amount,
    currency: itemForm.value.currency,
    cadence: itemForm.value.cadence,
    interval: itemForm.value.interval,
    starts_on: itemForm.value.starts_on,
    ends_on: itemForm.value.ends_on || null,
  })
  resetItemForm()
  await loadItems()
  await loadScenarios()
  await refreshForecast()
}

async function deleteItem(it: ScenarioItem) {
  if (!itemsScenario.value) return
  if (!confirm('Eliminare questa spesa simulata?')) return
  await api.delete(`/scenarios/${itemsScenario.value.id}/items/${it.id}`)
  await loadItems()
  await loadScenarios()
  await refreshForecast()
}

function categoryName(id: number | null): string {
  if (id === null) return '—'
  return categories.value.find((c) => c.id === id)?.name ?? `#${id}`
}

function accountName(id: number | null): string {
  if (id === null) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

const expenseCategories = computed(() => categories.value.filter((c) => c.type === 'expense'))

const CADENCE_LABEL: Record<ScenarioCadence, string> = {
  one_time: 'Una tantum',
  monthly: 'Mensile',
  quarterly: 'Trimestrale',
  yearly: 'Annuale',
}

function netClass(value: string | number): string {
  const n = typeof value === 'string' ? parseFloat(value) : value
  if (n > 0.005) return 'text-emerald-600'
  if (n < -0.005) return 'text-red-600'
  return 'text-slate-500'
}

function deltaText(value: number): string {
  if (Math.abs(value) < 0.005) return '±0'
  const sign = value > 0 ? '+' : '−'
  return `${sign}${money(Math.abs(value), baseCurrency.value)}`
}

function deltaClass(value: number, lowerIsBetter = false): string {
  if (Math.abs(value) < 0.005) return 'text-slate-500'
  const positive = value > 0
  const good = lowerIsBetter ? !positive : positive
  return good ? 'text-emerald-600' : 'text-red-600'
}

function periodLabel(period: string): string {
  const [y, m] = period.split('-')
  const date = new Date(parseInt(y, 10), parseInt(m, 10) - 1, 1)
  return date.toLocaleString('it-IT', { month: 'short', year: '2-digit' })
}

function cellTooltip(cell: ExpenseForecastCell): string {
  const lines: string[] = []
  if (parseFloat(cell.recurring) > 0) lines.push(`Ricorrenti: ${cell.recurring}`)
  if (cell.budget) lines.push(`Budget: ${cell.budget}`)
  if (parseFloat(cell.scenario) > 0) lines.push(`Scenario: ${cell.scenario}`)
  lines.push(`Totale: ${cell.total}`)
  if (cell.budget_breach) lines.push('⚠️ Sfora il budget')
  return lines.join('\n')
}

// Confronto scenari: array di righe { name, color, monthly: number[], total, deltaTotal }
interface CompareRow {
  id: number | null
  name: string
  color: string | null
  monthly: number[]
  total: number
  deltaTotal: number
}

const compareRows = computed<CompareRow[]>(() => {
  if (!comparison.value) return []
  const rows: CompareRow[] = []

  const baselineNets = comparison.value.baseline.totals_by_month.map((t) => parseFloat(t.net))
  const baselineTotal = baselineNets.reduce((s, v) => s + v, 0)

  rows.push({
    id: null,
    name: 'Baseline (nessuno scenario)',
    color: '#94a3b8',
    monthly: baselineNets,
    total: baselineTotal,
    deltaTotal: 0,
  })

  for (const s of comparison.value.scenarios) {
    const monthly = s.totals_by_month.map((t) => parseFloat(t.net))
    const total = monthly.reduce((sum, v) => sum + v, 0)
    rows.push({
      id: s.scenario?.id ?? null,
      name: s.scenario?.name ?? '—',
      color: s.scenario?.color ?? '#6366f1',
      monthly,
      total,
      deltaTotal: total - baselineTotal,
    })
  }

  return rows
})

onMounted(async () => {
  const [{ data: cats }, { data: accs }] = await Promise.all([
    api.get<Paginated<Category>>('/categories', { params: { per_page: 200 } }),
    api.get<Paginated<Account>>('/accounts', { params: { per_page: 200 } }),
  ])
  categories.value = cats.data
  accounts.value = accs.data
  await loadScenarios()
  await refreshForecast()
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Previsioni</h1>
        <p class="text-sm text-slate-500">
          Quanto ti resta a fine mese per vivere, baseline e con ogni scenario applicato.
        </p>
      </div>
      <div class="flex items-center gap-2 text-sm">
        <label>Orizzonte</label>
        <select v-model.number="months" class="input w-auto" aria-label="Orizzonte">
          <option :value="3">3 mesi</option>
          <option :value="6">6 mesi</option>
          <option :value="12">12 mesi</option>
          <option :value="24">24 mesi</option>
        </select>
      </div>
    </div>

    <!-- KPI residuo + selettore scenario -->
    <section v-if="forecast" class="card p-4 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-medium">Scenario applicato</h2>
        <div class="flex flex-wrap items-center gap-2">
          <select v-model.number="selectedScenarioId" class="input w-auto">
            <option value="">— Baseline (nessuno scenario) —</option>
            <option v-for="s in scenarios" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <button class="btn-primary" @click="showScenarioForm = !showScenarioForm; resetScenarioForm()">
            {{ showScenarioForm ? 'Annulla' : 'Nuovo scenario' }}
          </button>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Entrate totali ({{ forecast.summary.months_count }} mesi)</p>
          <p class="text-lg font-semibold mt-1">{{ money(forecast.summary.total_income, baseCurrency) }}</p>
        </div>
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Uscite totali</p>
          <p class="text-lg font-semibold mt-1 text-red-600">
            {{ money(forecast.summary.total_expense, baseCurrency) }}
          </p>
        </div>
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Resta totale</p>
          <p class="text-2xl font-semibold mt-1" :class="netClass(forecast.summary.total_net)">
            {{ money(forecast.summary.total_net, baseCurrency) }}
          </p>
        </div>
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Mese peggiore</p>
          <p class="text-lg font-semibold mt-1" :class="netClass(forecast.summary.min_monthly_net)">
            {{ money(forecast.summary.min_monthly_net, baseCurrency) }}
          </p>
          <p v-if="forecast.summary.min_monthly_net_period" class="text-xs text-slate-500 mt-1">
            {{ periodLabel(forecast.summary.min_monthly_net_period) }}
          </p>
        </div>
      </div>

      <form
        v-if="showScenarioForm"
        class="grid grid-cols-1 sm:grid-cols-4 gap-3 pt-2 border-t border-slate-100"
        @submit.prevent="submitScenario"
      >
        <div class="sm:col-span-2">
          <label class="label">Nome</label>
          <input v-model="scenarioForm.name" type="text" maxlength="120" class="input" required />
        </div>
        <div>
          <label class="label">Colore</label>
          <input v-model="scenarioForm.color" type="color" class="input h-10 p-1" />
        </div>
        <div class="flex items-end">
          <label class="inline-flex items-center gap-2 text-sm">
            <input v-model="scenarioForm.is_active" type="checkbox" />
            Attivo
          </label>
        </div>
        <div class="sm:col-span-4">
          <label class="label">Descrizione</label>
          <textarea v-model="scenarioForm.description" rows="2" maxlength="2000" class="input" />
        </div>
        <div class="sm:col-span-4 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showScenarioForm = false; resetScenarioForm()">Annulla</button>
          <button type="submit" class="btn-primary">{{ editingScenario ? 'Salva' : 'Crea' }}</button>
        </div>
      </form>
    </section>

    <!-- Tabella mese per mese: residuo in evidenza -->
    <section v-if="forecast" class="card p-4">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="font-medium">Mese per mese</h2>
        <p v-if="loading" class="text-xs text-slate-500">Aggiornamento…</p>
      </div>
      <div class="table-responsive md:overflow-x-auto">
        <table class="table">
          <thead class="bg-slate-100">
            <tr>
              <th>Mese</th>
              <th class="text-right">Entrate</th>
              <th class="text-right">Uscite</th>
              <th v-if="forecast.scenario" class="text-right">di cui scenario</th>
              <th class="text-right">Resta a fine mese</th>
              <th v-if="forecast.scenario" class="text-right">Δ vs baseline</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="t in forecast.totals_by_month" :key="t.period">
              <td data-label="Mese" class="font-medium">{{ periodLabel(t.period) }}</td>
              <td data-label="Entrate" class="md:text-right">{{ money(t.income, baseCurrency) }}</td>
              <td data-label="Uscite" class="md:text-right">{{ money(t.expense_total, baseCurrency) }}</td>
              <td v-if="forecast.scenario" data-label="di cui scenario" class="md:text-right text-indigo-600">
                {{ parseFloat(t.scenario) > 0 ? '+' + money(t.scenario, baseCurrency) : '—' }}
              </td>
              <td data-label="Resta" class="md:text-right text-base font-semibold" :class="netClass(t.net)">
                {{ money(t.net, baseCurrency) }}
              </td>
              <td v-if="forecast.scenario" data-label="Δ baseline" class="md:text-right"
                  :class="deltaClass(deltaNetForPeriod(t.period, parseFloat(t.net)))">
                {{ deltaText(deltaNetForPeriod(t.period, parseFloat(t.net))) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Confronto scenari -->
    <section v-if="compareRows.length > 1" class="card p-4">
      <h2 class="font-medium mb-3">Confronto scenari — Resta a fine mese</h2>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200">
              <th class="text-left p-2 sticky left-0 bg-white">Scenario</th>
              <th v-for="m in comparison?.months ?? []" :key="m" class="text-right p-2 whitespace-nowrap">
                {{ periodLabel(m) }}
              </th>
              <th class="text-right p-2">Totale</th>
              <th class="text-right p-2">Δ baseline</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in compareRows"
              :key="row.id ?? 'baseline'"
              class="border-b border-slate-100"
              :class="row.id === selectedScenarioId ? 'bg-indigo-50/60' : ''"
            >
              <td class="p-2 sticky left-0 bg-inherit">
                <span class="inline-flex items-center gap-2">
                  <span class="inline-block w-2 h-2 rounded-full" :style="{ backgroundColor: row.color ?? '#94a3b8' }" />
                  <span class="font-medium">{{ row.name }}</span>
                </span>
              </td>
              <td v-for="(v, i) in row.monthly" :key="i" class="p-2 text-right whitespace-nowrap" :class="netClass(v)">
                {{ money(v, baseCurrency) }}
              </td>
              <td class="p-2 text-right font-semibold whitespace-nowrap" :class="netClass(row.total)">
                {{ money(row.total, baseCurrency) }}
              </td>
              <td class="p-2 text-right whitespace-nowrap" :class="deltaClass(row.deltaTotal)">
                {{ row.id === null ? '—' : deltaText(row.deltaTotal) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-slate-500 mt-2">
        Verde = residuo positivo, rosso = mese in rosso. Δ confronta il residuo totale con la baseline.
      </p>
    </section>

    <!-- Lista scenari -->
    <section class="card p-4 space-y-3">
      <h2 class="font-medium">I tuoi scenari</h2>
      <ul v-if="scenarios.length" class="divide-y divide-slate-100">
        <li v-for="s in scenarios" :key="s.id" class="py-2 flex items-center gap-3">
          <span class="inline-block w-3 h-3 rounded-full" :style="{ backgroundColor: s.color ?? '#6366f1' }" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium truncate">
              {{ s.name }}
              <span v-if="!s.is_active" class="ml-1 text-xs text-slate-400">(inattivo)</span>
            </p>
            <p v-if="s.description" class="text-xs text-slate-500 truncate">{{ s.description }}</p>
          </div>
          <span v-if="s.items_count !== undefined" class="text-xs text-slate-500">
            {{ s.items_count }} {{ s.items_count === 1 ? 'voce' : 'voci' }}
          </span>
          <button class="btn-secondary text-xs py-1" @click="openItems(s)">Spese</button>
          <RowActions @edit="startEditScenario(s)" @delete="deleteScenario(s)" />
        </li>
      </ul>
      <p v-else-if="!scenariosLoading" class="text-sm text-slate-500">
        Nessuno scenario. Creane uno per simulare l'impatto di spese future.
      </p>
    </section>

    <!-- Breakdown categoria (collassabile) -->
    <section v-if="forecast && forecast.categories.length" class="card p-4">
      <button
        type="button"
        class="flex items-center justify-between w-full"
        @click="showCategoryBreakdown = !showCategoryBreakdown"
      >
        <h2 class="font-medium">Dettaglio uscite per categoria</h2>
        <span class="text-sm text-slate-500">{{ showCategoryBreakdown ? 'Nascondi ▴' : 'Mostra ▾' }}</span>
      </button>
      <div v-if="showCategoryBreakdown" class="overflow-x-auto mt-3">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200">
              <th class="text-left p-2 sticky left-0 bg-white">Categoria</th>
              <th v-for="m in forecast.months" :key="m" class="text-right p-2 whitespace-nowrap">
                {{ periodLabel(m) }}
              </th>
              <th class="text-right p-2 font-semibold">Totale</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in forecast.categories" :key="(row.category_id ?? 'u') + ''" class="border-b border-slate-100">
              <td class="p-2 sticky left-0 bg-white">
                <span class="inline-flex items-center gap-2">
                  <span class="inline-block w-2 h-2 rounded-full" :style="{ backgroundColor: row.color ?? '#94a3b8' }" />
                  <span class="font-medium">{{ row.category_name }}</span>
                </span>
              </td>
              <td
                v-for="cell in row.monthly"
                :key="cell.period"
                class="p-2 text-right whitespace-nowrap"
                :class="[
                  cell.budget_breach ? 'bg-red-50 text-red-700 font-semibold' : '',
                  parseFloat(cell.scenario) > 0 && !cell.budget_breach ? 'bg-indigo-50' : '',
                ]"
                :title="cellTooltip(cell)"
              >
                <span v-if="parseFloat(cell.total) === 0" class="text-slate-300">—</span>
                <template v-else>
                  {{ money(cell.total, baseCurrency) }}
                  <span v-if="parseFloat(cell.scenario) > 0" class="block text-xs font-normal">
                    +{{ money(cell.scenario, baseCurrency) }}
                  </span>
                </template>
              </td>
              <td class="p-2 text-right font-semibold whitespace-nowrap">
                {{ money(row.total, baseCurrency) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <p v-else-if="!loading" class="card p-8 text-center text-slate-500">
      Nessuna previsione disponibile. Crea ricorrenti, budget o uno scenario per popolare la tabella.
    </p>

    <!-- Modale gestione voci scenario -->
    <div
      v-if="itemsScenario"
      class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      @click.self="closeItems"
    >
      <div class="card w-full max-w-3xl my-8 p-5 space-y-4">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-lg font-semibold">Spese simulate — {{ itemsScenario.name }}</h2>
            <p class="text-sm text-slate-500">Aggiungi spese ipotetiche per simulare l'impatto sui mesi successivi.</p>
          </div>
          <button class="icon-btn icon-btn-delete" aria-label="Chiudi" @click="closeItems">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-5 h-5">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
            </svg>
          </button>
        </div>

        <form class="grid grid-cols-2 sm:grid-cols-6 gap-2 items-end" @submit.prevent="addItem">
          <div class="col-span-2">
            <label class="label">Descrizione</label>
            <input v-model="itemForm.description" type="text" maxlength="255" class="input" placeholder="es. Vacanza Sardegna" />
          </div>
          <div>
            <label class="label">Importo</label>
            <input v-model="itemForm.amount" type="number" step="0.01" min="0.01" class="input" required />
          </div>
          <div>
            <label class="label">Conto</label>
            <select v-model="itemForm.account_id" class="input">
              <option value="">—</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Valuta</label>
            <select v-model="itemForm.currency" class="input">
              <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div>
            <label class="label">Categoria</label>
            <select v-model="itemForm.category_id" class="input">
              <option value="">—</option>
              <option v-for="c in expenseCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Cadenza</label>
            <select v-model="itemForm.cadence" class="input">
              <option value="one_time">Una tantum</option>
              <option value="monthly">Mensile</option>
              <option value="quarterly">Trimestrale</option>
              <option value="yearly">Annuale</option>
            </select>
          </div>
          <div>
            <label class="label">Dal</label>
            <input v-model="itemForm.starts_on" type="date" class="input" required />
          </div>
          <div v-if="itemForm.cadence !== 'one_time'">
            <label class="label">Fino al</label>
            <input v-model="itemForm.ends_on" type="date" class="input" />
          </div>
          <div class="col-span-2 sm:col-span-6 flex justify-end">
            <button type="submit" class="btn-primary">Aggiungi spesa</button>
          </div>
        </form>

        <div class="table-responsive md:overflow-x-auto border-t border-slate-100 pt-2">
          <p v-if="itemsLoading" class="p-3 text-sm text-slate-500">Caricamento…</p>
          <table v-else class="table">
            <thead class="bg-slate-100">
              <tr>
                <th>Descrizione</th>
                <th>Conto</th>
                <th>Categoria</th>
                <th>Cadenza</th>
                <th>Dal</th>
                <th>Fino</th>
                <th class="text-right">Importo</th>
                <th></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="it in items" :key="it.id">
                <td data-label="Descrizione">{{ it.description ?? '—' }}</td>
                <td data-label="Conto">{{ accountName(it.account_id) }}</td>
                <td data-label="Categoria">{{ categoryName(it.category_id) }}</td>
                <td data-label="Cadenza">{{ CADENCE_LABEL[it.cadence] }}</td>
                <td data-label="Dal">{{ it.starts_on }}</td>
                <td data-label="Fino">{{ it.ends_on ?? '—' }}</td>
                <td data-label="Importo" class="md:text-right font-medium">
                  {{ money(it.amount, it.currency) }}
                </td>
                <td class="md:text-right actions-cell">
                  <button class="icon-btn icon-btn-delete" aria-label="Elimina" @click="deleteItem(it)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                      <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443a48.7 48.7 0 0 0-3.722.387.75.75 0 1 0 .244 1.48l.04-.005.43 9.46A3 3 0 0 0 5.99 18.5h8.02a3 3 0 0 0 2.998-2.985l.43-9.46.04.005a.75.75 0 1 0 .244-1.48 48.7 48.7 0 0 0-3.722-.387V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325c.827-.05 1.66-.075 2.5-.075Z" clip-rule="evenodd" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="8" class="text-center text-slate-500 py-6">
                  Nessuna spesa simulata. Aggiungine una per vedere l'impatto sul forecast.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
