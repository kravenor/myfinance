<script setup lang="ts">
import { formatDate } from '@/lib/date'
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import { CURRENCIES, formatCurrency } from '@/lib/money'
import type {
  Account,
  AssetType,
  InstrumentCandidate,
  InvestmentHolding,
  InvestmentOverview,
  Paginated,
} from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<InvestmentHolding>('investment-holdings')

const accounts = ref<Account[]>([])
const overview = ref<InvestmentOverview | null>(null)

const assetTypes: AssetType[] = ['etf', 'stock', 'fund', 'bond', 'crypto', 'commodity', 'cash', 'other']

const editing = ref<InvestmentHolding | null>(null)
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

const lookupResults = ref<InstrumentCandidate[]>([])
const lookupLoading = ref(false)
const lookupError = ref('')

const investmentAccounts = computed(() => accounts.value.filter((a) => a.type === 'investment'))

function accountName(id: number | null): string {
  if (!id) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

function plClass(value: string | null | undefined): string {
  if (value === null || value === undefined) return 'text-slate-500'
  const n = parseFloat(value)
  if (n === 0) return 'text-slate-500'
  return n > 0 ? 'text-green-600' : 'text-red-600'
}

function plBorderClass(value: string | null | undefined): string {
  if (value === null || value === undefined) return 'border-slate-300'
  const n = parseFloat(value)
  if (n === 0) return 'border-slate-300'
  return n > 0 ? 'border-green-500' : 'border-red-400'
}


function reset() {
  editing.value = null
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
  if (form.value.last_price !== '') payload.last_price_at = new Date().toISOString()

  if (editing.value) {
    await update(editing.value.id, payload)
  } else {
    await create(payload)
  }
  reset()
  showForm.value = false
  await refresh()
}

async function onDelete(h: InvestmentHolding) {
  if (!confirm(`Eliminare la posizione "${h.name}"?`)) return
  await destroy(h.id)
  await refresh()
}

async function refresh() {
  await list({ per_page: 200 })
  const o = await api.get<{ data: InvestmentOverview }>('/investments/overview')
  overview.value = o.data.data
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
      <h1 class="text-xl sm:text-2xl font-semibold">Investimenti</h1>
      <button
        class="btn-primary"
        :disabled="investmentAccounts.length === 0"
        @click="showForm = !showForm; reset()"
      >
        {{ showForm ? 'Annulla' : 'Nuova posizione' }}
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none disabled:opacity-40"
      aria-label="Nuova posizione"
      :disabled="investmentAccounts.length === 0"
      @click="showForm = true; reset()"
    >+</button>

    <p v-if="investmentAccounts.length === 0" class="card p-4 text-sm text-slate-500">
      Nessun conto di tipo <strong>investment</strong>. Creane uno in
      <RouterLink class="underline" to="/accounts">Conti</RouterLink> per registrare le posizioni.
    </p>

    <!-- Riepilogo portafoglio -->
    <section v-if="overview && overview.holdings_count > 0" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <p class="text-xs uppercase text-slate-500">Valore di mercato</p>
        <p class="text-2xl font-semibold mt-1">
          {{ formatCurrency(overview.total_market_value, overview.base_currency) }}
        </p>
        <p class="text-xs text-slate-500 mt-1">
          Costo: {{ formatCurrency(overview.total_cost_basis, overview.base_currency) }}
        </p>
      </div>
      <div class="card p-4">
        <p class="text-xs uppercase text-slate-500">Plus/minus latente</p>
        <p class="text-2xl font-semibold mt-1" :class="plClass(overview.total_unrealized_pl)">
          {{ formatCurrency(overview.total_unrealized_pl, overview.base_currency) }}
        </p>
        <p v-if="overview.total_unrealized_pl_pct" class="text-xs mt-1" :class="plClass(overview.total_unrealized_pl_pct)">
          {{ parseFloat(overview.total_unrealized_pl_pct) > 0 ? '+' : '' }}{{ overview.total_unrealized_pl_pct }}%
        </p>
      </div>
      <div class="card p-4">
        <p class="text-xs uppercase text-slate-500">Allocazione</p>
        <ul class="mt-1 space-y-1">
          <li
            v-for="row in overview.by_asset_type"
            :key="row.asset_type"
            class="flex items-center justify-between text-sm"
          >
            <span class="capitalize">{{ row.asset_type }}</span>
            <span class="text-slate-500">{{ row.pct }}%</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Form -->
    <form
      v-if="showForm"
      class="card p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4"
      @submit.prevent="onSubmit"
    >
      <div class="sm:col-span-2 md:col-span-1">
        <label class="label">Nome</label>
        <input v-model="form.name" class="input" required />
      </div>
      <div>
        <label class="label">Ticker / Symbol</label>
        <input v-model="form.symbol" class="input" placeholder="es. CSSPX.MI (auto da ISIN)" />
        <p v-if="form.asset_type === 'bond'" class="text-xs text-slate-500 mt-1">
          Per le obbligazioni lascialo vuoto: viene compilato con l'ISIN, che è la chiave della
          quotazione sul MOT di Borsa Italiana.
        </p>
      </div>
      <div>
        <label class="label">ISIN</label>
        <div class="flex gap-2">
          <input v-model="form.isin" class="input uppercase" maxlength="12" placeholder="es. IE00B5BMR087" />
          <button
            type="button"
            class="btn-secondary whitespace-nowrap"
            :disabled="lookupLoading"
            @click="lookupSymbol"
          >
            {{ lookupLoading ? '…' : 'Cerca' }}
          </button>
        </div>
      </div>
      <div v-if="lookupResults.length || lookupError" class="sm:col-span-2 md:col-span-3">
        <p v-if="lookupError" class="text-sm text-red-600">{{ lookupError }}</p>
        <ul v-else class="border border-slate-200 rounded divide-y divide-slate-100 text-sm">
          <li
            v-for="c in lookupResults"
            :key="c.symbol"
            class="flex items-center justify-between gap-3 px-3 py-2 hover:bg-slate-50 cursor-pointer"
            @click="applyCandidate(c)"
          >
            <span>
              <span class="font-medium">{{ c.symbol }}</span>
              <span class="text-slate-400"> · {{ c.exchange }}</span>
              <span class="block text-xs text-slate-500">{{ c.name }}</span>
            </span>
            <span class="whitespace-nowrap">
              <span v-if="c.price !== null">{{ formatCurrency(String(c.price), c.currency ?? form.currency) }}</span>
              <span v-else class="text-slate-400">n/d</span>
            </span>
          </li>
        </ul>
      </div>
      <div>
        <label class="label">Conto</label>
        <select v-model.number="form.account_id" class="input" required>
          <option v-for="a in investmentAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
      </div>
      <div>
        <label class="label">Tipo asset</label>
        <select v-model="form.asset_type" class="input">
          <option v-for="t in assetTypes" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div>
        <label class="label">Valuta</label>
        <select v-model="form.currency" class="input">
          <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div>
        <label class="label">Quantità</label>
        <input v-model="form.quantity" type="number" step="0.00000001" min="0" class="input" required />
        <p v-if="form.asset_type === 'bond'" class="text-xs text-slate-500 mt-1">
          Per le obbligazioni è il valore nominale (es. 5000), non il numero di lotti.
        </p>
      </div>
      <div>
        <label class="label">Prezzo di carico ({{ form.currency }})</label>
        <input v-model="form.avg_cost" type="number" step="0.00000001" min="0" class="input" required />
      </div>
      <div>
        <label class="label">Prezzo corrente ({{ form.currency }})</label>
        <input v-model="form.last_price" type="number" step="0.00000001" min="0" class="input" placeholder="= carico se vuoto" />
      </div>
      <div class="sm:col-span-2 md:col-span-3">
        <label class="label">Note</label>
        <input v-model="form.notes" class="input" />
      </div>
      <div class="sm:col-span-2 md:col-span-3 flex flex-col sm:flex-row gap-2 sm:justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <!-- Posizioni -->
    <div class="card">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>

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
              <p class="text-xs text-slate-500 mt-0.5 truncate">
                <span class="capitalize">{{ h.asset_type }}</span> · {{ accountName(h.account_id) }}
                <template v-if="h.symbol"> · {{ h.symbol }}</template>
              </p>
              <p class="text-xs text-slate-400 mt-0.5 truncate">
                {{ h.quantity }} × {{ formatCurrency(h.effective_price, h.currency) }}
                <span v-if="h.price_source === 'auto'" class="text-green-600">
                  · auto<template v-if="h.price_as_of"> {{ formatDate(h.price_as_of) }}</template>
                </span>
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="font-semibold whitespace-nowrap">{{ formatCurrency(h.market_value, h.currency) }}</p>
              <p class="text-xs font-medium whitespace-nowrap mt-0.5" :class="plClass(h.unrealized_pl)">
                {{ formatCurrency(h.unrealized_pl, h.currency) }}
                <template v-if="h.unrealized_pl_pct">({{ parseFloat(h.unrealized_pl_pct) > 0 ? '+' : '' }}{{ h.unrealized_pl_pct }}%)</template>
              </p>
              <RowActions class="mt-2 justify-end" @edit="startEdit(h)" @delete="onDelete(h)" />
            </div>
          </div>
        </li>
        <li v-if="items.length === 0" class="p-6 text-center text-slate-500 text-sm">Nessuna posizione.</li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!loading" class="table hidden md:table">
        <thead class="bg-slate-100">
          <tr>
            <th>Asset</th>
            <th>Tipo</th>
            <th>Conto</th>
            <th class="text-right">Quantità</th>
            <th class="text-right">Carico</th>
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
              <span v-if="h.symbol" class="block text-xs text-slate-400">{{ h.symbol }}</span>
              <span v-if="h.isin" class="block text-xs text-slate-300">{{ h.isin }}</span>
            </td>
            <td class="capitalize">{{ h.asset_type }}</td>
            <td>{{ accountName(h.account_id) }}</td>
            <td class="text-right">{{ h.quantity }}</td>
            <td class="text-right">{{ formatCurrency(h.avg_cost, h.currency) }}</td>
            <td class="text-right">
              {{ formatCurrency(h.effective_price, h.currency) }}
              <span
                v-if="h.price_source === 'auto'"
                class="block text-xs text-green-600"
                :title="h.price_as_of ? `Quotazione automatica aggiornata il ${formatDate(h.price_as_of)}` : 'Quotazione automatica'"
              >
                auto<template v-if="h.price_as_of"> · {{ formatDate(h.price_as_of) }}</template>
              </span>
            </td>
            <td class="text-right font-medium">{{ formatCurrency(h.market_value, h.currency) }}</td>
            <td class="text-right" :class="plClass(h.unrealized_pl)">
              {{ formatCurrency(h.unrealized_pl, h.currency) }}
              <span v-if="h.unrealized_pl_pct" class="block text-xs">
                {{ parseFloat(h.unrealized_pl_pct) > 0 ? '+' : '' }}{{ h.unrealized_pl_pct }}%
              </span>
            </td>
            <td class="text-right">
              <RowActions @edit="startEdit(h)" @delete="onDelete(h)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="9" class="text-center text-slate-500 py-6">Nessuna posizione.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
