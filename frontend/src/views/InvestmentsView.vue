<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import { CURRENCIES, formatCurrency } from '@/lib/money'
import type { Account, AssetType, InvestmentHolding, InvestmentOverview, Paginated } from '@/types/api'

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
  asset_type: 'etf' as AssetType,
  currency: 'EUR',
  quantity: '',
  avg_cost: '',
  last_price: '',
  notes: '',
})

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

function reset() {
  editing.value = null
  form.value = {
    account_id: investmentAccounts.value[0]?.id ?? 0,
    name: '',
    symbol: '',
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
  form.value = {
    account_id: h.account_id,
    name: h.name,
    symbol: h.symbol ?? '',
    asset_type: h.asset_type,
    currency: h.currency,
    quantity: h.quantity,
    avg_cost: h.avg_cost,
    last_price: h.last_price ?? '',
    notes: h.notes ?? '',
  }
  showForm.value = true
}

async function onSubmit() {
  const payload: Record<string, unknown> = {
    account_id: form.value.account_id,
    name: form.value.name,
    symbol: form.value.symbol || null,
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
  <div class="space-y-6">
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
        <input v-model="form.symbol" class="input" placeholder="es. VWCE" />
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

    <!-- Tabella holding -->
    <div class="card table-responsive md:overflow-x-auto">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <table v-else class="table">
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
            <td data-label="Asset" class="font-medium">
              {{ h.name }}
              <span v-if="h.symbol" class="text-xs text-slate-400">{{ h.symbol }}</span>
            </td>
            <td data-label="Tipo" class="capitalize">{{ h.asset_type }}</td>
            <td data-label="Conto">{{ accountName(h.account_id) }}</td>
            <td data-label="Quantità" class="md:text-right">{{ h.quantity }}</td>
            <td data-label="Carico" class="md:text-right">{{ formatCurrency(h.avg_cost, h.currency) }}</td>
            <td data-label="Prezzo" class="md:text-right">{{ formatCurrency(h.last_price ?? h.avg_cost, h.currency) }}</td>
            <td data-label="Valore" class="md:text-right font-medium">{{ formatCurrency(h.market_value, h.currency) }}</td>
            <td data-label="P/L" class="md:text-right" :class="plClass(h.unrealized_pl)">
              {{ formatCurrency(h.unrealized_pl, h.currency) }}
              <span v-if="h.unrealized_pl_pct" class="block text-xs">
                {{ parseFloat(h.unrealized_pl_pct) > 0 ? '+' : '' }}{{ h.unrealized_pl_pct }}%
              </span>
            </td>
            <td class="md:text-right actions-cell">
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
