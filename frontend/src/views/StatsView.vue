<script setup lang="ts">
import { formatDate, formatMonth } from '@/lib/date'
import { computed, onMounted, ref } from 'vue'
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
import { api } from '@/lib/api'
import Amount from '@/components/ui/Amount.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { INCOME_COLOR, PRIMARY_COLOR, paletteColor } from '@/lib/chartTheme'
import { TX_TYPE_LABEL } from '@/lib/labels'
import { formatCurrency } from '@/lib/money'
import type {
  CashFlowPoint,
  CategoryTrend,
  PeriodComparison,
  TopTransaction,
} from '@/types/reports'

ChartJS.register(CategoryScale, LinearScale, LineElement, PointElement, Filler, Legend, Tooltip)

const unit = ref<'month' | 'year'>('month')
const forecastMonths = ref(6)
const trendType = ref<'expense' | 'income'>('expense')
const topType = ref<'expense' | 'income' | ''>('expense')

const comparison = ref<PeriodComparison | null>(null)
const trend = ref<CategoryTrend | null>(null)
const forecast = ref<CashFlowPoint[]>([])
const top = ref<TopTransaction[]>([])
const loading = ref(false)

async function refresh() {
  loading.value = true
  try {
    const [c, t, f, tx] = await Promise.all([
      api.get<{ data: PeriodComparison }>('/reports/period-comparison', { params: { unit: unit.value } }),
      api.get<{ data: CategoryTrend }>('/reports/category-trend', { params: { type: trendType.value, top: 5 } }),
      api.get<{ data: CashFlowPoint[] }>('/reports/cash-flow-forecast', { params: { months: forecastMonths.value } }),
      api.get<{ data: TopTransaction[] }>('/reports/top-transactions', { params: { type: topType.value, limit: 10 } }),
    ])
    comparison.value = c.data.data
    trend.value = t.data.data
    forecast.value = f.data.data
    top.value = tx.data.data
  } finally {
    loading.value = false
  }
}

const trendData = computed(() => {
  if (!trend.value) return { labels: [], datasets: [] }
  return {
    labels: trend.value.periods.map(formatMonth),
    datasets: trend.value.categories.map((c, i) => ({
      label: c.category_name,
      data: c.values.map((v) => parseFloat(v)),
      borderColor: paletteColor(i),
      backgroundColor: paletteColor(i),
      tension: 0.3,
      fill: false,
    })),
  }
})

const forecastData = computed(() => ({
  labels: forecast.value.map((p) => formatMonth(p.period)),
  datasets: [
    {
      label: 'Saldo mensile previsto',
      data: forecast.value.map((p) => parseFloat(p.net)),
      borderColor: INCOME_COLOR,
      backgroundColor: `${INCOME_COLOR}26`,
      tension: 0.3,
      fill: true,
    },
    {
      label: 'Patrimonio proiettato',
      data: forecast.value.map((p) => parseFloat(p.projected_net_worth)),
      borderColor: PRIMARY_COLOR,
      backgroundColor: `${PRIMARY_COLOR}1a`,
      tension: 0.3,
      fill: false,
      yAxisID: 'y1',
    },
    {
      label: 'Saldo mensile con storico',
      data: forecast.value.map((p) => parseFloat(p.net) + parseFloat(p.historical_net)),
      borderColor: paletteColor(3),
      borderDash: [6, 4],
      tension: 0.3,
      fill: false,
    },
    {
      label: 'Patrimonio con storico',
      data: forecast.value.map((p) => parseFloat(p.projected_net_worth_with_history)),
      borderColor: paletteColor(5),
      borderDash: [6, 4],
      tension: 0.3,
      fill: false,
      yAxisID: 'y1',
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' as const } },
}

const forecastOptions = {
  ...chartOptions,
  scales: {
    y: { position: 'left' as const, title: { display: true, text: 'Saldo mensile' } },
    y1: {
      position: 'right' as const,
      grid: { drawOnChartArea: false },
      title: { display: true, text: 'Patrimonio' },
    },
  },
}

const baseCurrency = computed(() => comparison.value?.base_currency ?? 'EUR')

function formatDelta(value: string | null | undefined, suffix = '') {
  if (value === null || value === undefined) return '—'
  const n = parseFloat(value)
  const sign = n > 0 ? '+' : ''
  return `${sign}${value}${suffix}`
}

function formatMoneyDelta(value: string | null | undefined) {
  if (value === null || value === undefined) return '—'
  const n = parseFloat(value)
  const sign = n > 0 ? '+' : n < 0 ? '−' : ''
  return `${sign}${formatCurrency(Math.abs(n), baseCurrency.value)}`
}

function deltaClass(value: string | null | undefined, lowerIsBetter = false) {
  if (value === null || value === undefined) return 'text-slate-500'
  const n = parseFloat(value)
  if (n === 0) return 'text-slate-500'
  const isPositive = n > 0
  const good = lowerIsBetter ? !isPositive : isPositive
  return good ? 'text-income-700' : 'text-expense-700'
}

onMounted(refresh)
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Statistiche</h1>
      <button class="btn-secondary" :disabled="loading" @click="refresh">
        {{ loading ? 'Aggiorno…' : 'Aggiorna' }}
      </button>
    </div>

    <section class="card p-4 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-medium">Confronto periodi</h2>
        <select v-model="unit" class="input md:w-40" @change="refresh">
          <option value="month">Mese vs precedente</option>
          <option value="year">Anno vs precedente</option>
        </select>
      </div>

      <div v-if="!comparison && loading" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div v-for="i in 3" :key="i" class="card h-36 animate-pulse bg-slate-100" />
      </div>
      <div v-else-if="comparison" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Entrate {{ formatMonth(comparison.current.label) }}</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="comparison.current.income" :currency="baseCurrency" type="income" />
          <p class="num text-xs text-slate-500 mt-2">
            vs {{ formatMonth(comparison.previous.label) }}: {{ formatCurrency(comparison.previous.income, baseCurrency) }}
          </p>
          <p class="num text-sm mt-1" :class="deltaClass(comparison.delta.income_pct)">
            Δ {{ formatMoneyDelta(comparison.delta.income) }}
            <span v-if="comparison.delta.income_pct">
              ({{ formatDelta(comparison.delta.income_pct, '%') }})
            </span>
          </p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Uscite {{ formatMonth(comparison.current.label) }}</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="comparison.current.expense" :currency="baseCurrency" type="expense" />
          <p class="num text-xs text-slate-500 mt-2">
            vs {{ formatMonth(comparison.previous.label) }}: {{ formatCurrency(comparison.previous.expense, baseCurrency) }}
          </p>
          <p class="num text-sm mt-1" :class="deltaClass(comparison.delta.expense_pct, true)">
            Δ {{ formatMoneyDelta(comparison.delta.expense) }}
            <span v-if="comparison.delta.expense_pct">
              ({{ formatDelta(comparison.delta.expense_pct, '%') }})
            </span>
          </p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Saldo {{ formatMonth(comparison.current.label) }}</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="comparison.current.net" :currency="baseCurrency" signed />
          <p class="num text-xs text-slate-500 mt-2">
            vs {{ formatMonth(comparison.previous.label) }}: {{ formatCurrency(comparison.previous.net, baseCurrency) }}
          </p>
          <p class="num text-sm mt-1" :class="deltaClass(comparison.delta.net)">
            Δ {{ formatMoneyDelta(comparison.delta.net) }}
          </p>
        </div>
      </div>
    </section>

    <section class="card p-4">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-medium">Trend top categorie (12 mesi)</h2>
        <select v-model="trendType" class="input md:w-40" @change="refresh">
          <option value="expense">Spese</option>
          <option value="income">Entrate</option>
        </select>
      </div>
      <div class="h-64 sm:h-80">
        <Line v-if="trend && trend.categories.length" :data="trendData" :options="chartOptions" />
        <div v-else-if="loading && !trend" class="h-full animate-pulse rounded bg-slate-100" />
        <EmptyState
          v-else
          :title="trendType === 'expense' ? 'Nessuna spesa negli ultimi 12 mesi.' : 'Nessuna entrata negli ultimi 12 mesi.'"
        />
      </div>
    </section>

    <section class="card p-4">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-medium">Proiezione del flusso di cassa</h2>
        <div class="flex items-center gap-2">
          <label class="text-sm text-slate-600">Mesi</label>
          <input
            v-model.number="forecastMonths"
            type="number"
            min="1"
            max="24"
            class="input w-24"
            @change="refresh"
          />
        </div>
      </div>
      <div class="h-64 sm:h-80">
        <Line v-if="forecast.length" :data="forecastData" :options="forecastOptions" />
        <div v-else-if="loading" class="h-full animate-pulse rounded bg-slate-100" />
        <EmptyState v-else title="Nessuna ricorrente attiva per la proiezione." />
      </div>
      <p class="text-xs text-slate-500 mt-2">
        Linee piene: solo ricorrenti di entrata e di uscita attive. Linee tratteggiate: aggiungono ogni mese la mediana di entrate e uscite non ricorrenti degli ultimi 12 mesi.
      </p>
    </section>

    <section class="card p-4">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-medium">Top transazioni del mese</h2>
        <select v-model="topType" class="input md:w-40" @change="refresh">
          <option value="">Tutti</option>
          <option value="expense">Spese</option>
          <option value="income">Entrate</option>
        </select>
      </div>
      <!-- Mobile: card compatta, la lista label/valore generica era illeggibile con 6 colonne. -->
      <ListSkeleton v-if="loading && !top.length" :rows="3" />
      <ul v-else class="md:hidden divide-y divide-slate-100">
        <li v-for="t in top" :key="t.id" class="py-3 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium text-slate-800 truncate">{{ t.description ?? '—' }}</p>
            <p class="text-xs text-slate-500 mt-0.5 truncate">{{ t.category_name ?? '—' }}</p>
            <p class="text-xs text-slate-500 mt-0.5 truncate">
              {{ formatDate(t.occurred_at) }} · {{ t.account_name ?? '—' }}
            </p>
          </div>
          <div class="text-right shrink-0">
            <Amount class="block font-semibold whitespace-nowrap" :value="t.amount" :currency="t.currency" :type="t.type" />
            <p v-if="t.currency !== baseCurrency" class="num text-xs text-slate-500 whitespace-nowrap mt-0.5">
              ≈ {{ formatCurrency(t.amount_base, baseCurrency) }}
            </p>
          </div>
        </li>
        <li v-if="top.length === 0">
          <EmptyState title="Nessuna transazione nel mese." />
        </li>
      </ul>

      <div v-if="!(loading && !top.length)" class="hidden md:block md:overflow-x-auto">
        <table class="table">
          <thead class="bg-slate-100">
            <tr>
              <th>Data</th>
              <th>Tipo</th>
              <th>Conto</th>
              <th>Categoria</th>
              <th>Descrizione</th>
              <th class="text-right">Importo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="t in top" :key="t.id">
              <td data-label="Data">{{ formatDate(t.occurred_at) }}</td>
              <td data-label="Tipo">{{ TX_TYPE_LABEL[t.type] }}</td>
              <td data-label="Conto">{{ t.account_name ?? '—' }}</td>
              <td data-label="Categoria">{{ t.category_name ?? '—' }}</td>
              <td data-label="Descrizione">{{ t.description ?? '—' }}</td>
              <td data-label="Importo" class="md:text-right font-medium">
                <Amount :value="t.amount" :currency="t.currency" :type="t.type" />
                <span v-if="t.currency !== baseCurrency" class="num block text-xs font-normal text-slate-500">
                  ≈ {{ formatCurrency(t.amount_base, baseCurrency) }}
                </span>
              </td>
            </tr>
            <tr v-if="top.length === 0">
              <td colspan="6" class="whitespace-normal">
                <EmptyState title="Nessuna transazione nel mese." />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
