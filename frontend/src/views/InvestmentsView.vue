<script setup lang="ts">
import { formatDate, formatMonth } from '@/lib/date'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import Amount from '@/components/ui/Amount.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FieldError from '@/components/ui/FieldError.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import { MUTED_COLOR, PRIMARY_COLOR } from '@/lib/chartTheme'
import { ACCOUNT_TYPE_LABEL, ASSET_TYPE_LABEL } from '@/lib/labels'
import { useToastStore } from '@/stores/toast'
import RowActions from '@/components/ui/RowActions.vue'
import HoldingMovements from '@/components/HoldingMovements.vue'
import { CURRENCIES, formatCurrency } from '@/lib/money'
import type {
  Account,
  AssetType,
  InstrumentCandidate,
  InvestmentHistory,
  InvestmentHolding,
  InvestmentOverview,
  Paginated,
} from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

ChartJS.register(CategoryScale, LinearScale, LineElement, PointElement, Filler, Legend, Tooltip)

const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<InvestmentHolding>('investment-holdings')
const toast = useToastStore()

const accounts = ref<Account[]>([])
const overview = ref<InvestmentOverview | null>(null)
const history = ref<InvestmentHistory | null>(null)
// Serie del singolo holding scelto nel grafico; null = portafoglio (e l'XIRR in testata resta di portafoglio).
const chartHolding = ref<number | null>(null)
const holdingHistory = ref<InvestmentHistory | null>(null)
// Periodo in mesi (null = tutto). ponytail: filtro client sui punti già caricati (mensili, 10 anni = 120);
// from/to lato server (U7 dell'ADR 0002) solo se la granularità diventa giornaliera.
const chartMonths = ref<number | null>(null)
const chartPoints = computed(() => {
  const points = (chartHolding.value ? holdingHistory.value : history.value)?.points ?? []
  if (!chartMonths.value) return points
  const from = new Date()
  from.setDate(1)
  from.setMonth(from.getMonth() - chartMonths.value)
  const cutoff = `${from.getFullYear()}-${String(from.getMonth() + 1).padStart(2, '0')}`
  return points.filter((p) => p.month >= cutoff)
})

async function loadHoldingHistory() {
  holdingHistory.value = chartHolding.value
    ? (await api.get<InvestmentHistory>('/investments/history', { params: { holding: chartHolding.value } })).data
    : null
}

// Versato vs valore: la serie parte dal primo movimento, non prima.
const historyData = computed(() => ({
  labels: chartPoints.value.map((p) => formatMonth(p.month)),
  datasets: [
    {
      label: 'Versato',
      data: chartPoints.value.map((p) => parseFloat(p.invested)),
      borderColor: MUTED_COLOR,
      backgroundColor: `${MUTED_COLOR}1f`,
      fill: true,
      tension: 0.3,
    },
    {
      label: 'Valore',
      data: chartPoints.value.map((p) => parseFloat(p.market_value)),
      borderColor: PRIMARY_COLOR,
      backgroundColor: `${PRIMARY_COLOR}26`,
      fill: true,
      tension: 0.3,
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' as const } },
}

const assetTypes: AssetType[] = ['etf', 'stock', 'fund', 'bond', 'crypto', 'commodity', 'certificate', 'cash', 'other']

const assetTypeColors: Record<AssetType, string> = {
  stock: '#2563eb',
  etf: '#0891b2',
  fund: '#7c3aed',
  bond: '#b45309',
  crypto: '#ea580c',
  commodity: '#65a30d',
  certificate: '#db2777',
  cash: '#475569',
  other: '#64748b',
}

const editing = ref<InvestmentHolding | null>(null)
const movementsFor = ref<InvestmentHolding | null>(null)
const showForm = ref(false)
const form = ref({
  account_id: 0,
  name: '',
  symbol: '',
  isin: '',
  asset_type: 'etf' as AssetType,
  currency: 'EUR',
  quantity: '',
  avg_cost: '',
  last_price: '',
  notes: '',
})
const dirty = useFormDirty(form, showForm)

const lookupResults = ref<InstrumentCandidate[]>([])
const lookupLoading = ref(false)
const lookupError = ref('')
const refreshingPrices = ref(false)

const investmentAccounts = computed(() => accounts.value.filter((a) => a.type === 'investment'))

function accountName(id: number | null): string {
  if (!id) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

function plClass(value: string | null | undefined): string {
  if (value === null || value === undefined) return 'text-slate-500'
  const n = parseFloat(value)
  if (n === 0) return 'text-slate-500'
  return n > 0 ? 'text-income-600' : 'text-expense-600'
}

function plBorderClass(value: string | null | undefined): string {
  if (value === null || value === undefined) return 'border-slate-300'
  const n = parseFloat(value)
  if (n === 0) return 'border-slate-300'
  return n > 0 ? 'border-income-500' : 'border-expense-400'
}


function reset() {
  editing.value = null
  fieldErrors.value = {}
  lookupResults.value = []
  lookupError.value = ''
  form.value = {
    account_id: investmentAccounts.value[0]?.id ?? 0,
    name: '',
    symbol: '',
    isin: '',
    asset_type: 'etf',
    currency: investmentAccounts.value[0]?.currency ?? 'EUR',
    quantity: '',
    avg_cost: '',
    last_price: '',
    notes: '',
  }
}

function startEdit(h: InvestmentHolding) {
  editing.value = h
  fieldErrors.value = {}
  lookupResults.value = []
  lookupError.value = ''
  form.value = {
    account_id: h.account_id,
    name: h.name,
    symbol: h.symbol ?? '',
    isin: h.isin ?? '',
    asset_type: h.asset_type,
    currency: h.currency,
    quantity: h.quantity,
    avg_cost: h.avg_cost,
    last_price: h.last_price ?? '',
    notes: h.notes ?? '',
  }
  showForm.value = true
}

// Risolve ISIN (o ticker/nome) nei symbol Yahoo quotabili. Un solo candidato
// → applicato in automatico; più candidati → l'utente sceglie la quotazione.
async function lookupSymbol() {
  const q = (form.value.isin || form.value.symbol || form.value.name).trim()
  if (!q) return
  lookupLoading.value = true
  lookupError.value = ''
  lookupResults.value = []
  try {
    const res = await api.get<{ data: InstrumentCandidate[] }>('/investments/lookup', {
      params: { q, currency: form.value.currency },
    })
    if (res.data.data.length === 0) {
      lookupError.value = 'Nessuno strumento trovato.'
    } else if (res.data.data.length === 1) {
      applyCandidate(res.data.data[0])
    } else {
      lookupResults.value = res.data.data
    }
  } catch {
    lookupError.value = 'Ricerca non riuscita.'
  } finally {
    lookupLoading.value = false
  }
}

function applyCandidate(c: InstrumentCandidate) {
  form.value.symbol = c.symbol
  if (c.currency) form.value.currency = c.currency
  if (!form.value.name && c.name) form.value.name = c.name
  lookupResults.value = []
}

async function onSubmit() {
  const payload: Record<string, unknown> = {
    account_id: form.value.account_id,
    name: form.value.name,
    symbol: form.value.symbol || null,
    isin: form.value.isin || null,
    asset_type: form.value.asset_type,
    currency: form.value.currency,
    quantity: form.value.quantity,
    avg_cost: form.value.avg_cost,
    last_price: form.value.last_price === '' ? null : form.value.last_price,
    notes: form.value.notes || null,
  }
  // In modifica quantità e carico arrivano dal registro movimenti: l'API li ignora.
  if (editing.value) {
    delete payload.quantity
    delete payload.avg_cost
  }
  if (form.value.last_price !== '') payload.last_price_at = new Date().toISOString()

  try {
    if (editing.value) {
      await update(editing.value.id, payload)
    } else {
      await create(payload)
    }
  } catch {
    // 422: riepilogo errori nella modale, i dati inseriti restano.
    return
  }
  toast.success('Posizione salvata.')
  reset()
  showForm.value = false
  await refresh()
}

async function onDelete(h: InvestmentHolding) {
  if (!(await confirmAction(`Eliminare la posizione "${h.name}"?`))) return
  await destroy(h.id)
  await refresh()
}

async function onMovementsChanged() {
  const id = movementsFor.value?.id
  await refresh()
  movementsFor.value = items.value.find((h) => h.id === id) ?? null
}

async function refresh() {
  await list({ per_page: 200 })
  const [o, h] = await Promise.all([
    api.get<{ data: InvestmentOverview }>('/investments/overview'),
    api.get<InvestmentHistory>('/investments/history'),
  ])
  overview.value = o.data.data
  history.value = h.data
  if (!items.value.some((i) => i.id === chartHolding.value)) chartHolding.value = null
  await loadHoldingHistory()
}

async function refreshPrices() {
  refreshingPrices.value = true
  try {
    await api.post('/investments/refresh-prices')
    await refresh()
  } finally {
    refreshingPrices.value = false
  }
}

onMounted(async () => {
  const a = await api.get<Paginated<Account>>('/accounts', { params: { per_page: 100 } })
  accounts.value = a.data.data
  form.value.account_id = investmentAccounts.value[0]?.id ?? 0
  await refresh()
})
</script>

<template>
  <div class="space-y-6 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Investimenti</h1>
        <p class="page-desc">
          Le tue posizioni a valore di mercato: quote e costo derivano dai movimenti registrati, le quotazioni si aggiornano da sole.
        </p>
      </div>
      <div class="flex gap-2">
        <button
          type="button"
          class="btn-secondary"
          :disabled="refreshingPrices"
          @click="refreshPrices"
        >
          {{ refreshingPrices ? 'Aggiornamento…' : 'Aggiorna quotazioni' }}
        </button>
        <button
          class="btn-primary"
          :disabled="investmentAccounts.length === 0"
          @click="showForm = true; reset()"
        >
          Nuova posizione
        </button>
      </div>
    </div>

    <details class="help-panel">
      <summary>Come funziona</summary>
      <ul>
        <li>Quantità, prezzo di carico e versato si ricalcolano dal registro movimenti di ogni posizione: per cambiarli aggiungi o correggi un movimento, non la posizione.</li>
        <li>Ogni mattina la quotazione arriva da sola in base al tipo (Yahoo Finance per azioni, ETF e fondi, CoinGecko per le crypto, Borsa Italiana o Teleborsa per obbligazioni e certificati); senza quotazione vale il prezzo corrente inserito a mano, altrimenti il carico.</li>
        <li>Il P/L latente è il valore attuale meno il costo delle quote che hai ancora; il P/L realizzato nasce solo da vendite e costi e lo trovi nel registro movimenti.</li>
        <li>L'annualizzato (XIRR) è il rendimento medio per anno che tiene conto di quando hai versato ogni importo: compare dopo almeno un anno dal primo movimento.</li>
        <li>Il TWR misura quanto hanno reso gli strumenti, ignorando quando e quanto hai versato: se è più alto dell'XIRR, i versamenti sono arrivati in momenti sfavorevoli; se è più basso, in momenti favorevoli.</li>
      </ul>
    </details>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none disabled:opacity-40"
      aria-label="Nuova posizione"
      :disabled="investmentAccounts.length === 0"
      @click="showForm = true; reset()"
    >+</button>

    <p v-if="investmentAccounts.length === 0" class="card p-4 text-sm text-slate-500">
      Nessun conto di tipo <strong>{{ ACCOUNT_TYPE_LABEL.investment }}</strong>. Creane uno in
      <RouterLink class="underline" to="/accounts">Conti</RouterLink> per registrare le posizioni.
    </p>

    <!-- Riepilogo portafoglio -->
    <section v-if="overview && overview.holdings_count > 0" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <p class="text-xs uppercase text-slate-500">Valore di mercato</p>
        <Amount
          class="block text-2xl font-semibold mt-1"
          :value="overview.total_market_value"
          :currency="overview.base_currency"
        />
        <p class="text-xs text-slate-500 mt-1">
          Costo: <Amount :value="overview.total_cost_basis" :currency="overview.base_currency" />
        </p>
      </div>
      <div class="card p-4">
        <p class="text-xs uppercase text-slate-500">Plus/minus latente</p>
        <Amount
          class="block text-2xl font-semibold mt-1"
          :value="overview.total_unrealized_pl"
          :currency="overview.base_currency"
          signed
        />
        <p v-if="overview.total_unrealized_pl_pct" class="text-xs mt-1" :class="plClass(overview.total_unrealized_pl_pct)">
          {{ parseFloat(overview.total_unrealized_pl_pct) > 0 ? '+' : '' }}{{ overview.total_unrealized_pl_pct }}%
        </p>
        <p v-if="history?.xirr_pct" class="text-xs text-slate-500 mt-1" title="Rendimento money-weighted (XIRR) dal primo movimento, calcolato da almeno un anno di storico">
          Annualizzato:
          <span :class="plClass(history.xirr_pct)">{{ parseFloat(history.xirr_pct) > 0 ? '+' : '' }}{{ history.xirr_pct }}%</span>
        </p>
        <p v-if="history?.twr_pct" class="text-xs text-slate-500 mt-1" title="Rendimento time-weighted (TWR) dal primo movimento: misura gli strumenti, non il momento in cui hai versato">
          Strumenti (TWR):
          <span :class="plClass(history.twr_pct)">{{ parseFloat(history.twr_pct) > 0 ? '+' : '' }}{{ history.twr_pct }}%</span>
        </p>
        <p class="text-xs text-slate-500 mt-1">Valore meno costo delle quote ancora in portafoglio, vendite escluse.</p>
      </div>
      <div class="card p-4">
        <p class="text-xs uppercase text-slate-500">Allocazione</p>
        <ul class="mt-1 space-y-1">
          <li
            v-for="row in overview.by_asset_type"
            :key="row.asset_type"
            class="flex items-center justify-between text-sm"
          >
            <span>{{ ASSET_TYPE_LABEL[row.asset_type] }}</span>
            <span class="num" :class="parseFloat(row.pct) < 0 ? 'text-expense-600' : 'text-slate-500'">{{ row.pct }}%</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Form -->
    <AppModal v-slot="{ close }" v-model="showForm" size="lg" :dirty="dirty" :title="editing ? 'Modifica posizione' : 'Nuova posizione'">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 px-4 py-4 sm:px-6">
          <FormErrors
            class="col-span-full"
            :errors="fieldErrors"
            :shown="['name', 'symbol', 'isin', 'account_id', 'asset_type', 'currency', 'quantity', 'avg_cost', 'last_price', 'notes']"
          />
          <div class="sm:col-span-2 md:col-span-1">
            <label class="label">Nome</label>
            <input v-model="form.name" class="input" :class="{ 'input-invalid': fieldErrors.name }" required />
            <FieldError :errors="fieldErrors" name="name" />
          </div>
          <div>
            <label class="label">Ticker / Symbol</label>
            <input v-model="form.symbol" class="input" :class="{ 'input-invalid': fieldErrors.symbol }" placeholder="es. CSSPX.MI (auto da ISIN)" aria-describedby="hint-symbol" />
            <p v-if="form.asset_type === 'bond'" id="hint-symbol" class="field-hint">
              Per le obbligazioni lascialo vuoto: viene compilato con l'ISIN, che è la chiave della
              quotazione sul MOT di Borsa Italiana.
            </p>
            <p v-else-if="form.asset_type === 'certificate'" id="hint-symbol" class="field-hint">
              Per i certificati lascialo vuoto: viene compilato con l'ISIN, che è la chiave della
              quotazione sul SeDeX/Cert-X.
            </p>
            <p v-else-if="form.asset_type === 'crypto'" id="hint-symbol" class="field-hint">
              Per le crypto usa l'identificativo CoinGecko (es. bitcoin, ethereum): è quello che serve per la quotazione.
            </p>
            <p v-else-if="['commodity', 'cash', 'other'].includes(form.asset_type)" id="hint-symbol" class="field-hint">
              Per questo tipo non c'è quotazione automatica: il valore segue il prezzo corrente che inserisci tu.
            </p>
            <p v-else id="hint-symbol" class="field-hint">
              Serve per la quotazione automatica: è il simbolo di Yahoo Finance, che puoi trovare con «Cerca» accanto all'ISIN.
            </p>
            <FieldError :errors="fieldErrors" name="symbol" />
          </div>
          <div>
            <label class="label">ISIN</label>
            <div class="flex gap-2">
              <input v-model="form.isin" class="input uppercase" :class="{ 'input-invalid': fieldErrors.isin }" maxlength="12" placeholder="es. IE00B5BMR087" aria-describedby="hint-isin" />
              <button
                type="button"
                class="btn-secondary whitespace-nowrap"
                :disabled="lookupLoading"
                @click="lookupSymbol"
              >
                {{ lookupLoading ? '…' : 'Cerca' }}
              </button>
            </div>
            <p id="hint-isin" class="field-hint">«Cerca» trova il ticker quotabile partendo dall'ISIN o, se è vuoto, da ticker o nome.</p>
            <FieldError :errors="fieldErrors" name="isin" />
          </div>
          <div v-if="lookupResults.length || lookupError" class="sm:col-span-2 md:col-span-3">
            <p v-if="lookupError" class="text-sm text-danger-600">{{ lookupError }}</p>
            <ul v-else class="border border-slate-200 rounded divide-y divide-slate-100 text-sm">
              <li
                v-for="c in lookupResults"
                :key="c.symbol"
                class="flex items-center justify-between gap-3 px-3 py-2 hover:bg-slate-50 cursor-pointer"
                @click="applyCandidate(c)"
              >
                <span>
                  <span class="font-medium">{{ c.symbol }}</span>
                  <span class="text-slate-500"> · {{ c.exchange }}</span>
                  <span class="block text-xs text-slate-500">{{ c.name }}</span>
                </span>
                <span class="whitespace-nowrap">
                  <span v-if="c.price !== null" class="num">{{ formatCurrency(String(c.price), c.currency ?? form.currency) }}</span>
                  <span v-else class="text-slate-500">n/d</span>
                </span>
              </li>
            </ul>
          </div>
          <div>
            <label class="label">Conto</label>
            <select v-model.number="form.account_id" class="input" :class="{ 'input-invalid': fieldErrors.account_id }" required aria-describedby="hint-account">
              <option v-for="a in investmentAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
            <p id="hint-account" class="field-hint">Il saldo di questo conto è la somma del valore di mercato delle sue posizioni.</p>
            <FieldError :errors="fieldErrors" name="account_id" />
          </div>
          <div>
            <label class="label">Tipo asset</label>
            <select v-model="form.asset_type" class="input" :class="{ 'input-invalid': fieldErrors.asset_type }" aria-describedby="hint-asset-type">
              <option v-for="t in assetTypes" :key="t" :value="t">{{ ASSET_TYPE_LABEL[t] }}</option>
            </select>
            <p id="hint-asset-type" class="field-hint">Decide da quale fonte arriva la quotazione automatica.</p>
            <FieldError :errors="fieldErrors" name="asset_type" />
          </div>
          <div>
            <label class="label">Valuta</label>
            <select v-model="form.currency" class="input" :class="{ 'input-invalid': fieldErrors.currency }">
              <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
            </select>
            <FieldError :errors="fieldErrors" name="currency" />
          </div>
          <div v-if="!editing">
            <label class="label">Quantità iniziale</label>
            <input v-model="form.quantity" type="number" step="0.00000001" min="0" class="input" :class="{ 'input-invalid': fieldErrors.quantity }" required aria-describedby="hint-quantity" />
            <p id="hint-quantity" class="field-hint">
              <template v-if="form.asset_type === 'bond'">Per le obbligazioni è il valore nominale (es. 5000), non il numero di lotti. </template>
              Con il prezzo di carico diventa il primo acquisto del registro, datato oggi; metti 0 per partire dai movimenti.
            </p>
            <FieldError :errors="fieldErrors" name="quantity" />
          </div>
          <div v-if="!editing">
            <label class="label">Prezzo di carico ({{ form.currency }})</label>
            <input v-model="form.avg_cost" type="number" step="0.00000001" min="0" class="input" :class="{ 'input-invalid': fieldErrors.avg_cost }" required aria-describedby="hint-avg-cost" />
            <p id="hint-avg-cost" class="field-hint">Prezzo medio pagato per quota, commissioni comprese.</p>
            <FieldError :errors="fieldErrors" name="avg_cost" />
          </div>
          <div v-else class="sm:col-span-2 text-xs text-slate-500 bg-slate-50 rounded p-3">
            Quantità e prezzo di carico si modificano dal registro movimenti, non da qui.
          </div>
          <div>
            <label class="label">Prezzo corrente ({{ form.currency }})</label>
            <input v-model="form.last_price" type="number" step="0.00000001" min="0" class="input" :class="{ 'input-invalid': fieldErrors.last_price }" placeholder="= carico se vuoto" aria-describedby="hint-last-price" />
            <p id="hint-last-price" class="field-hint">Prezzo manuale: conta solo finché non c'è una quotazione automatica; se vuoto vale il prezzo di carico.</p>
            <FieldError :errors="fieldErrors" name="last_price" />
          </div>
          <div class="sm:col-span-2 md:col-span-3">
            <label class="label">Note</label>
            <input v-model="form.notes" class="input" :class="{ 'input-invalid': fieldErrors.notes }" />
            <FieldError :errors="fieldErrors" name="notes" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" @click="close">Annulla</button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? 'Salvataggio…' : editing ? 'Salva' : 'Crea' }}
          </button>
        </div>
      </form>
    </AppModal>

    <!-- Versato vs valore: nessun punto prima del primo movimento. -->
    <div v-if="(history?.points.length ?? 0) > 1" class="card p-4">
      <div class="flex flex-wrap items-baseline justify-between gap-3 mb-2">
        <h2 class="font-semibold text-slate-800">Versato vs valore</h2>
        <div class="flex flex-wrap gap-2 min-w-0 max-w-full">
          <select v-model="chartHolding" class="input w-auto max-w-full min-w-0" aria-label="Posizione del grafico" @change="loadHoldingHistory">
            <option :value="null">Tutto il portafoglio</option>
            <option v-for="h in items" :key="h.id" :value="h.id">{{ h.name }}</option>
          </select>
          <select v-model="chartMonths" class="input w-auto" aria-label="Periodo del grafico">
            <option :value="null">Tutto</option>
            <option :value="6">6 mesi</option>
            <option :value="12">1 anno</option>
            <option :value="36">3 anni</option>
            <option :value="60">5 anni</option>
          </select>
        </div>
      </div>
      <p class="text-xs text-slate-500 mb-2">
        Versato: soldi immessi meno quelli ripresi con le vendite, commissioni comprese. Parte dal primo movimento; nei mesi senza quotazione il valore usa il costo medio.
      </p>
      <div class="h-64">
        <Line :data="historyData" :options="chartOptions" />
      </div>
    </div>

    <!-- Posizioni -->
    <div class="card">
      <ListSkeleton v-if="loading && !items.length" />

      <!-- Mobile: una card per posizione, troppi campi per il collasso label/valore generico (sotto md). -->
      <ul v-else class="md:hidden divide-y divide-slate-100">
        <li
          v-for="h in items"
          :key="h.id"
          class="p-4 border-l-4"
          :class="plBorderClass(h.unrealized_pl)"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium text-slate-800 truncate">{{ h.name }}</p>
              <p class="text-xs text-slate-500 mt-0.5">
                <span
                  class="inline-block px-2 py-0.5 rounded-full text-xs text-white"
                  :style="{ background: assetTypeColors[h.asset_type] }"
                >{{ ASSET_TYPE_LABEL[h.asset_type] }}</span>
              </p>
              <p class="text-xs text-slate-500 mt-0.5 truncate">{{ accountName(h.account_id) }}</p>
              <p v-if="h.symbol" class="text-xs text-slate-500 mt-0.5 truncate">{{ h.symbol }}</p>
              <p class="num text-xs text-slate-500 mt-0.5 truncate">
                {{ h.quantity }} × {{ formatCurrency(h.effective_price, h.currency) }}
              </p>
              <p v-if="h.price_source === 'auto'" class="text-xs text-income-700 mt-0.5 truncate">
                auto<template v-if="h.price_as_of"> · {{ formatDate(h.price_as_of) }}</template>
              </p>
            </div>
            <div class="text-right shrink-0">
              <Amount class="block font-semibold whitespace-nowrap" :value="h.market_value" :currency="h.currency" />
              <p class="text-xs font-medium whitespace-nowrap mt-0.5" :class="plClass(h.unrealized_pl)">
                <Amount :value="h.unrealized_pl" :currency="h.currency" signed />
                <template v-if="h.unrealized_pl_pct">({{ parseFloat(h.unrealized_pl_pct) > 0 ? '+' : '' }}{{ h.unrealized_pl_pct }}%)</template>
              </p>
              <div class="mt-2 flex items-center gap-1 justify-end">
                <button type="button" class="icon-btn" title="Movimenti" aria-label="Movimenti" @click="movementsFor = h">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path d="M3 4.75A.75.75 0 0 1 3.75 4h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 4.75Zm0 5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 9.75Zm0 5a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Z" />
                  </svg>
                </button>
                <RowActions @edit="startEdit(h)" @delete="onDelete(h)" />
              </div>
            </div>
          </div>
        </li>
        <li v-if="items.length === 0">
          <EmptyState title="Non hai ancora registrato posizioni.">
            <button type="button" class="btn-primary" :disabled="investmentAccounts.length === 0" @click="showForm = true; reset()">
              Nuova posizione
            </button>
          </EmptyState>
        </li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!(loading && !items.length)" class="table hidden md:table">
        <thead class="bg-slate-100">
          <tr>
            <th>Asset</th>
            <th>Tipo</th>
            <th>Conto</th>
            <th class="text-right">Quantità</th>
            <th class="text-right">Carico</th>
            <th class="text-right">Versato</th>
            <th class="text-right">Prezzo</th>
            <th class="text-right">Valore</th>
            <th class="text-right">P/L</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="h in items" :key="h.id">
            <td class="font-medium">
              {{ h.name }}
              <span v-if="h.symbol" class="block text-xs text-slate-500">{{ h.symbol }}</span>
              <span v-if="h.isin" class="block text-xs text-slate-500">{{ h.isin }}</span>
            </td>
            <td>
              <span
                class="inline-block px-2 py-0.5 rounded-full text-xs text-white"
                :style="{ background: assetTypeColors[h.asset_type] }"
              >{{ ASSET_TYPE_LABEL[h.asset_type] }}</span>
            </td>
            <td>{{ accountName(h.account_id) }}</td>
            <td class="num text-right">{{ h.quantity }}</td>
            <td class="text-right"><Amount :value="h.avg_cost" :currency="h.currency" /></td>
            <td class="text-right text-slate-500"><Amount :value="h.net_invested" :currency="h.currency" /></td>
            <td class="text-right">
              <Amount :value="h.effective_price" :currency="h.currency" />
              <span
                v-if="h.price_source === 'auto'"
                class="block text-xs text-income-700"
                :title="h.price_as_of ? `Quotazione automatica aggiornata il ${formatDate(h.price_as_of)}` : 'Quotazione automatica'"
              >
                auto<template v-if="h.price_as_of"> · {{ formatDate(h.price_as_of) }}</template>
              </span>
            </td>
            <td class="text-right font-medium"><Amount :value="h.market_value" :currency="h.currency" /></td>
            <td class="text-right" :class="plClass(h.unrealized_pl)">
              <Amount :value="h.unrealized_pl" :currency="h.currency" signed />
              <span v-if="h.unrealized_pl_pct" class="num block text-xs">
                {{ parseFloat(h.unrealized_pl_pct) > 0 ? '+' : '' }}{{ h.unrealized_pl_pct }}%
              </span>
            </td>
            <td class="text-right">
              <div class="inline-flex items-center gap-1">
                <button type="button" class="icon-btn" title="Movimenti" aria-label="Movimenti" @click="movementsFor = h">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path d="M3 4.75A.75.75 0 0 1 3.75 4h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 4.75Zm0 5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 9.75Zm0 5a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Z" />
                  </svg>
                </button>
                <RowActions @edit="startEdit(h)" @delete="onDelete(h)" />
              </div>
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="10" class="whitespace-normal">
              <EmptyState title="Non hai ancora registrato posizioni.">
                <button type="button" class="btn-primary" :disabled="investmentAccounts.length === 0" @click="showForm = true; reset()">
                  Nuova posizione
                </button>
              </EmptyState>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <HoldingMovements
      v-if="movementsFor"
      :holding="movementsFor"
      @close="movementsFor = null"
      @changed="onMovementsChanged"
    />
  </div>
</template>
