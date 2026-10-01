<script setup lang="ts">
import { formatDate } from '@/lib/date'
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { formatCurrency as money, CURRENCIES } from '@/lib/money'
import { MUTED_COLOR, PRIMARY_COLOR } from '@/lib/chartTheme'
import { SCENARIO_CADENCE_LABEL, TX_TYPE_LABEL } from '@/lib/labels'
import RowActions from '@/components/ui/RowActions.vue'
import Amount from '@/components/ui/Amount.vue'
import AppModal from '@/components/ui/AppModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FieldError from '@/components/ui/FieldError.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import type { FieldErrors } from '@/composables/useCrud'
import type { AxiosError } from 'axios'
import type {
  Account,
  Category,
  Paginated,
  Scenario,
  ScenarioCadence,
  ScenarioItem,
  ScenarioItemType,
} from '@/types/api'
import type {
  ExpenseForecast,
  ExpenseForecastCell,
  ExpenseForecastCompare,
} from '@/types/reports'
import { confirmAction } from '@/composables/useConfirm'

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
const scenarioForm = ref({ name: '', description: '', color: PRIMARY_COLOR, is_active: true })
const scenarioDirty = useFormDirty(scenarioForm, showScenarioForm)
const scenarioSaving = ref(false)
const scenarioErrors = ref<FieldErrors>({})

function resetScenarioForm() {
  editingScenario.value = null
  scenarioErrors.value = {}
  scenarioForm.value = { name: '', description: '', color: PRIMARY_COLOR, is_active: true }
}

function openNewScenario() {
  resetScenarioForm()
  showScenarioForm.value = true
}

function startEditScenario(s: Scenario) {
  editingScenario.value = s
  scenarioErrors.value = {}
  scenarioForm.value = {
    name: s.name,
    description: s.description ?? '',
    color: s.color ?? PRIMARY_COLOR,
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
  scenarioSaving.value = true
  scenarioErrors.value = {}
  try {
    if (editingScenario.value) {
      await api.patch(`/scenarios/${editingScenario.value.id}`, payload)
    } else {
      const { data } = await api.post<{ data: Scenario }>('/scenarios', payload)
      selectedScenarioId.value = data.data.id
    }
  } catch (e: unknown) {
    // 422: errori sotto i campi, la modale resta aperta con i dati inseriti.
    const err = e as AxiosError<{ errors?: FieldErrors }>
    if (err.response?.status !== 422) throw e
    scenarioErrors.value = err.response.data?.errors ?? {}
    return
  } finally {
    scenarioSaving.value = false
  }
  resetScenarioForm()
  showScenarioForm.value = false
  await loadScenarios()
  await refreshForecast()
}

async function deleteScenario(s: Scenario) {
  if (!(await confirmAction(`Eliminare lo scenario "${s.name}"?`))) return
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
  type: 'expense' as ScenarioItemType,
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
    type: 'expense',
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

// ponytail: la categoria colloca le uscite nella griglia per categoria; per le
// entrate il forecast non la usa, quindi il campo si nasconde e si azzera.
watch(
  () => itemForm.value.type,
  (type) => {
    if (type === 'income') itemForm.value.category_id = ''
  },
)

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
    type: itemForm.value.type,
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
  if (!(await confirmAction('Eliminare questa voce simulata?'))) return
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

const scenarioCadences = Object.keys(SCENARIO_CADENCE_LABEL) as ScenarioCadence[]

function deltaText(value: number): string {
  if (Math.abs(value) < 0.005) return '±0'
  const sign = value > 0 ? '+' : '−'
  return `${sign}${money(Math.abs(value), baseCurrency.value)}`
}

function deltaClass(value: number, lowerIsBetter = false): string {
  if (Math.abs(value) < 0.005) return 'text-slate-500'
  const positive = value > 0
  const good = lowerIsBetter ? !positive : positive
  return good ? 'text-income-600' : 'text-expense-600'
}

function periodLabel(period: string): string {
  const [y, m] = period.split('-')
  const date = new Date(parseInt(y, 10), parseInt(m, 10) - 1, 1)
  return date.toLocaleString('it-IT', { month: 'short', year: '2-digit' })
}

function cellTooltip(cell: ExpenseForecastCell): string {
  const lines: string[] = []
  const fmt = (v: string) => money(v, baseCurrency.value)
  if (parseFloat(cell.recurring) > 0) lines.push(`Ricorrenti: ${fmt(cell.recurring)}`)
  if (cell.budget) lines.push(`Budget: ${fmt(cell.budget)}`)
  if (parseFloat(cell.scenario) > 0) lines.push(`Scenario: ${fmt(cell.scenario)}`)
  lines.push(`Totale: ${fmt(cell.total)}`)
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
  const sum = (vals: number[]) => Math.round(vals.reduce((s, v) => s + v, 0) * 100) / 100
  const baselineTotal = sum(baselineNets)

  rows.push({
    id: null,
    name: 'Baseline (nessuno scenario)',
    color: MUTED_COLOR,
    monthly: baselineNets,
    total: baselineTotal,
    deltaTotal: 0,
  })

  for (const s of comparison.value.scenarios) {
    const monthly = s.totals_by_month.map((t) => parseFloat(t.net))
    const total = sum(monthly)
    rows.push({
      id: s.scenario?.id ?? null,
      name: s.scenario?.name ?? '—',
      color: s.scenario?.color ?? PRIMARY_COLOR,
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
        <p class="page-desc">
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

    <details class="help-panel">
      <summary>Come funziona</summary>
      <ul>
        <li>La baseline usa solo ciò che è pianificato: le entrate ricorrenti attive e, per ogni categoria di spesa, il budget del mese se c'è, altrimenti le uscite ricorrenti.</li>
        <li>Transazioni singole, giroconti e uscite ricorrenti senza categoria non entrano nella previsione.</li>
        <li>Uno scenario raccoglie voci ipotetiche, una tantum o con cadenza, che si sommano alla baseline; il confronto mostra tutti gli scenari attivi.</li>
        <li>«Resta a fine mese» è entrate meno uscite previste nel mese, senza il saldo attuale dei conti; gli scenari non creano transazioni vere.</li>
      </ul>
    </details>

    <!-- KPI residuo + selettore scenario -->
    <section v-if="forecast" class="card p-4 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="font-medium">Scenario applicato</h2>
          <p class="text-xs text-slate-500 mt-0.5">Scegli uno scenario per aggiungere le sue voci alla baseline; puoi applicare anche quelli inattivi.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <select v-model.number="selectedScenarioId" class="input w-auto">
            <option value="">— Baseline (nessuno scenario) —</option>
            <option v-for="s in scenarios" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <button class="btn-primary" @click="openNewScenario()">Nuovo scenario</button>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Entrate totali ({{ forecast.summary.months_count }} mesi)</p>
          <Amount class="block text-lg font-semibold mt-1" :value="forecast.summary.total_income" :currency="baseCurrency" type="income" />
        </div>
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Uscite totali</p>
          <Amount class="block text-lg font-semibold mt-1" :value="forecast.summary.total_expense" :currency="baseCurrency" type="expense" />
        </div>
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Resta totale</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="forecast.summary.total_net" :currency="baseCurrency" signed />
        </div>
        <div class="card p-3">
          <p class="text-xs uppercase text-slate-500">Mese peggiore</p>
          <Amount class="block text-lg font-semibold mt-1" :value="forecast.summary.min_monthly_net" :currency="baseCurrency" signed />
          <p v-if="forecast.summary.min_monthly_net_period" class="text-xs text-slate-500 mt-1">
            {{ periodLabel(forecast.summary.min_monthly_net_period) }}
          </p>
        </div>
      </div>
    </section>

    <div v-if="loading && !forecast" class="grid grid-cols-2 md:grid-cols-4 gap-3" aria-busy="true" aria-label="Caricamento">
      <div v-for="i in 4" :key="i" class="card h-20 animate-pulse bg-slate-100" />
      <div class="card col-span-2 md:col-span-4 h-64 animate-pulse bg-slate-100" />
    </div>

    <AppModal
      v-slot="{ close }"
      v-model="showScenarioForm"
      :dirty="scenarioDirty"
      :title="editingScenario ? 'Modifica scenario' : 'Nuovo scenario'"
    >
      <form @submit.prevent="submitScenario">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 px-4 py-4 sm:px-6">
          <FormErrors class="col-span-full" :errors="scenarioErrors" :shown="['name', 'color', 'is_active', 'description']" />
          <div class="sm:col-span-2">
            <label class="label">Nome</label>
            <input v-model="scenarioForm.name" type="text" maxlength="120" class="input" :class="{ 'input-invalid': scenarioErrors.name }" required />
            <FieldError :errors="scenarioErrors" name="name" />
          </div>
          <div>
            <label class="label">Colore</label>
            <input v-model="scenarioForm.color" type="color" class="input h-10 p-1" :class="{ 'input-invalid': scenarioErrors.color }" />
            <FieldError :errors="scenarioErrors" name="color" />
          </div>
          <div class="flex flex-col justify-end">
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="scenarioForm.is_active" type="checkbox" aria-describedby="hint-scenario-active" />
              Attivo
            </label>
            <p id="hint-scenario-active" class="field-hint">Solo gli scenari attivi compaiono nel confronto.</p>
            <FieldError :errors="scenarioErrors" name="is_active" />
          </div>
          <div class="sm:col-span-4">
            <label class="label">Descrizione</label>
            <textarea v-model="scenarioForm.description" rows="2" maxlength="2000" class="input" :class="{ 'input-invalid': scenarioErrors.description }" />
            <FieldError :errors="scenarioErrors" name="description" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" @click="close">Annulla</button>
          <button type="submit" class="btn-primary" :disabled="scenarioSaving">
            {{ scenarioSaving ? 'Salvataggio…' : editingScenario ? 'Salva' : 'Crea' }}
          </button>
        </div>
      </form>
    </AppModal>

    <!-- Tabella mese per mese: residuo in evidenza -->
    <section v-if="forecast" class="card p-4">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div>
          <h2 class="font-medium">Mese per mese</h2>
          <p class="text-xs text-slate-500 mt-0.5">Resta a fine mese = entrate previste meno uscite previste, senza contare il saldo attuale dei conti.</p>
        </div>
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
              <td data-label="Entrate" class="md:text-right num">{{ money(t.income, baseCurrency) }}</td>
              <td data-label="Uscite" class="md:text-right num">{{ money(t.expense_total, baseCurrency) }}</td>
              <td v-if="forecast.scenario" data-label="di cui scenario" class="md:text-right num text-primary-600">
                {{ parseFloat(t.scenario) > 0 ? '+' + money(t.scenario, baseCurrency) : '—' }}
              </td>
              <td data-label="Resta" class="md:text-right text-base font-semibold">
                <Amount :value="t.net" :currency="baseCurrency" signed />
              </td>
              <td v-if="forecast.scenario" data-label="Δ baseline" class="md:text-right num"
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
      <h2 class="font-medium">Confronto scenari — Resta a fine mese</h2>
      <p class="text-xs text-slate-500 mt-0.5 mb-3">La baseline e, sotto, ogni scenario attivo applicato da solo.</p>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200">
              <th class="text-left p-2 sticky left-0 bg-surface">Scenario</th>
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
              :class="row.id === selectedScenarioId ? 'bg-primary-50/60' : ''"
            >
              <td class="p-2 sticky left-0 bg-inherit">
                <span class="inline-flex items-center gap-2">
                  <span class="inline-block w-2 h-2 rounded-full" :style="{ backgroundColor: row.color ?? MUTED_COLOR }" />
                  <span class="font-medium">{{ row.name }}</span>
                </span>
              </td>
              <td v-for="(v, i) in row.monthly" :key="i" class="p-2 text-right whitespace-nowrap">
                <Amount :value="v" :currency="baseCurrency" signed />
              </td>
              <td class="p-2 text-right font-semibold whitespace-nowrap">
                <Amount :value="row.total" :currency="baseCurrency" signed />
              </td>
              <td class="p-2 text-right num" :class="deltaClass(row.deltaTotal)">
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
      <ListSkeleton v-if="scenariosLoading && !scenarios.length" :rows="3" />
      <ul v-else-if="scenarios.length" class="divide-y divide-slate-100">
        <li v-for="s in scenarios" :key="s.id" class="py-2 flex items-center gap-3">
          <span class="inline-block w-3 h-3 rounded-full" :style="{ backgroundColor: s.color ?? PRIMARY_COLOR }" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium truncate">
              {{ s.name }}
              <span v-if="!s.is_active" class="ml-1 text-xs text-slate-500">(inattivo)</span>
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
      <EmptyState v-else title="Non hai ancora creato scenari. Creane uno per simulare l'impatto di spese future.">
        <button type="button" class="btn-primary" @click="openNewScenario()">Nuovo scenario</button>
      </EmptyState>
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
      <p v-if="showCategoryBreakdown" class="text-xs text-slate-500 mt-2">
        Per ogni categoria conta il budget del mese se c'è, altrimenti le uscite ricorrenti; in rosso i mesi che sforano il budget, evidenziati quelli con voci dello scenario.
      </p>
      <div v-if="showCategoryBreakdown" class="overflow-x-auto mt-3">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200">
              <th class="text-left p-2 sticky left-0 bg-surface">Categoria</th>
              <th v-for="m in forecast.months" :key="m" class="text-right p-2 whitespace-nowrap">
                {{ periodLabel(m) }}
              </th>
              <th class="text-right p-2 font-semibold">Totale</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in forecast.categories" :key="(row.category_id ?? 'u') + ''" class="border-b border-slate-100">
              <td class="p-2 sticky left-0 bg-surface">
                <span class="inline-flex items-center gap-2">
                  <span class="inline-block w-2 h-2 rounded-full" :style="{ backgroundColor: row.color ?? MUTED_COLOR }" />
                  <span class="font-medium">{{ row.category_name }}</span>
                </span>
              </td>
              <td
                v-for="cell in row.monthly"
                :key="cell.period"
                class="p-2 text-right num"
                :class="[
                  cell.budget_breach ? 'bg-danger-50 text-danger-700 font-semibold' : '',
                  parseFloat(cell.scenario) > 0 && !cell.budget_breach ? 'bg-primary-50' : '',
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
              <td class="p-2 text-right font-semibold num">
                {{ money(row.total, baseCurrency) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-else-if="!loading" class="card">
      <EmptyState title="Nessuna previsione disponibile. Crea ricorrenti, budget o uno scenario per popolare la tabella." />
    </div>

    <!-- Modale gestione voci scenario -->
    <div
      v-if="itemsScenario"
      class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      @click.self="closeItems"
    >
      <div class="card w-full max-w-3xl my-8 p-5 space-y-4">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-lg font-semibold">Voci simulate — {{ itemsScenario.name }}</h2>
            <p class="text-sm text-slate-500">
              Aggiungi uscite o entrate ipotetiche per simulare l'impatto sui mesi successivi.
            </p>
          </div>
          <button
            type="button"
            class="icon-btn text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:ring-primary-500"
            aria-label="Chiudi"
            @click="closeItems"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-5 h-5">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
            </svg>
          </button>
        </div>

        <form class="grid grid-cols-2 sm:grid-cols-6 gap-2 items-start" @submit.prevent="addItem">
          <div>
            <label class="label">Tipo</label>
            <select v-model="itemForm.type" class="input">
              <option value="expense">{{ TX_TYPE_LABEL.expense }}</option>
              <option value="income">{{ TX_TYPE_LABEL.income }}</option>
            </select>
          </div>
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
            <select v-model="itemForm.account_id" class="input" aria-describedby="hint-item-account">
              <option value="">—</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
            <p id="hint-item-account" class="field-hint">Facoltativo: imposta la valuta del conto. La previsione somma tutti i conti.</p>
          </div>
          <div>
            <label class="label">Valuta</label>
            <select v-model="itemForm.currency" class="input">
              <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div v-if="itemForm.type === 'expense'">
            <label class="label">Categoria</label>
            <select v-model="itemForm.category_id" class="input" aria-describedby="hint-item-category">
              <option value="">—</option>
              <option v-for="c in expenseCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p id="hint-item-category" class="field-hint">Se la categoria ha un budget nel mese, la voce si somma al budget e lo segna come sforato.</p>
          </div>
          <div>
            <label class="label">Cadenza</label>
            <select v-model="itemForm.cadence" class="input" aria-describedby="hint-item-cadence">
              <option v-for="c in scenarioCadences" :key="c" :value="c">{{ SCENARIO_CADENCE_LABEL[c] }}</option>
            </select>
            <p id="hint-item-cadence" class="field-hint">Una tantum conta una volta nel mese di «Dal»; le altre si ripetono fino a «Fino al» o alla fine dell'orizzonte.</p>
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
            <button type="submit" class="btn-primary">Aggiungi voce</button>
          </div>
        </form>

        <div class="table-responsive md:overflow-x-auto border-t border-slate-100 pt-2">
          <ListSkeleton v-if="itemsLoading" :rows="3" />
          <table v-else class="table">
            <thead class="bg-slate-100">
              <tr>
                <th>Tipo</th>
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
                <td data-label="Tipo">
                  <span :class="it.type === 'income' ? 'text-income-700' : 'text-slate-600'">
                    {{ TX_TYPE_LABEL[it.type] }}
                  </span>
                </td>
                <td data-label="Descrizione">{{ it.description ?? '—' }}</td>
                <td data-label="Conto">{{ accountName(it.account_id) }}</td>
                <td data-label="Categoria">{{ categoryName(it.category_id) }}</td>
                <td data-label="Cadenza">{{ SCENARIO_CADENCE_LABEL[it.cadence] }}</td>
                <td data-label="Dal">{{ formatDate(it.starts_on) }}</td>
                <td data-label="Fino">{{ formatDate(it.ends_on) }}</td>
                <td data-label="Importo" class="md:text-right font-medium">
                  <Amount :value="it.amount" :currency="it.currency" :type="it.type" />
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
                <td colspan="9" class="whitespace-normal">
                  <EmptyState title="Nessuna voce simulata. Aggiungine una qui sopra per vedere l'impatto sulla previsione." />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
