<script setup lang="ts">
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
import type {
  CashFlowPoint,
  CategoryTrend,
  PeriodComparison,
  TopTransaction,
} from '@/types/reports'

ChartJS.register(CategoryScale, LinearScale, LineElement, PointElement, Filler, Legend, Tooltip)

const palette = [
  '#6366f1', '#ec4899', '#22c55e', '#f59e0b', '#0ea5e9',
  '#a855f7', '#14b8a6', '#ef4444', '#84cc16', '#eab308',
]

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
    labels: trend.value.periods,
    datasets: trend.value.categories.map((c, i) => ({
      label: c.category_name,
      data: c.values.map((v) => parseFloat(v)),
      borderColor: palette[i % palette.length],
      backgroundColor: palette[i % palette.length],
      tension: 0.3,
      fill: false,
    })),
  }
})

const forecastData = computed(() => ({
  labels: forecast.value.map((p) => p.period),
  datasets: [
    {
      label: 'Net mensile previsto',
      data: forecast.value.map((p) => parseFloat(p.net)),
      borderColor: '#22c55e',
      backgroundColor: 'rgba(34,197,94,0.15)',
      tension: 0.3,
      fill: true,
    },
    {
      label: 'Patrimonio proiettato',
      data: forecast.value.map((p) => parseFloat(p.projected_net_worth)),
      borderColor: '#6366f1',
      backgroundColor: 'rgba(99,102,241,0.1)',
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
    y: { position: 'left' as const, title: { display: true, text: 'Net mensile' } },
    y1: {
      position: 'right' as const,
      grid: { drawOnChartArea: false },
      title: { display: true, text: 'Patrimonio' },
    },
  },
}

function formatDelta(value: string | null | undefined, suffix = '') {
  if (value === null || value === undefined) return '—'
  const n = parseFloat(value)
  const sign = n > 0 ? '+' : ''
  return `${sign}${value}${suffix}`
}

function deltaClass(value: string | null | undefined, lowerIsBetter = false) {
  if (value === null || value === undefined) return 'text-slate-500'
  const n = parseFloat(value)
  if (n === 0) return 'text-slate-500'
  const isPositive = n > 0
  const good = lowerIsBetter ? !isPositive : isPositive
  return good ? 'text-green-600' : 'text-red-600'
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

      <div v-if="comparison" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Income {{ comparison.current.label }}</p>
          <p class="text-2xl font-semibold mt-1">{{ comparison.current.income }}</p>
          <p class="text-xs text-slate-500 mt-2">
            vs {{ comparison.previous.label }}: {{ comparison.previous.income }}
          </p>
          <p class="text-sm mt-1" :class="deltaClass(comparison.delta.income_pct)">
            Δ {{ formatDelta(comparison.delta.income) }}
            <span v-if="comparison.delta.income_pct">
              ({{ formatDelta(comparison.delta.income_pct, '%') }})
            </span>
          </p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Expense {{ comparison.current.label }}</p>
          <p class="text-2xl font-semibold mt-1">{{ comparison.current.expense }}</p>
          <p class="text-xs text-slate-500 mt-2">
            vs {{ comparison.previous.label }}: {{ comparison.previous.expense }}
          </p>
          <p class="text-sm mt-1" :class="deltaClass(comparison.delta.expense_pct, true)">
            Δ {{ formatDelta(comparison.delta.expense) }}
            <span v-if="comparison.delta.expense_pct">
              ({{ formatDelta(comparison.delta.expense_pct, '%') }})
            </span>
          </p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Net {{ comparison.current.label }}</p>
          <p
            class="text-2xl font-semibold mt-1"
            :class="parseFloat(comparison.current.net) >= 0 ? 'text-green-600' : 'text-red-600'"
          >
            {{ comparison.current.net }}
          </p>
          <p class="text-xs text-slate-500 mt-2">
            vs {{ comparison.previous.label }}: {{ comparison.previous.net }}
          </p>
          <p class="text-sm mt-1" :class="deltaClass(comparison.delta.net)">
            Δ {{ formatDelta(comparison.delta.net) }}
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
        <p v-else class="text-sm text-slate-500">Nessun dato.</p>
      </div>
    </section>

    <section class="card p-4">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-medium">Cash flow forecast</h2>
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
        <p v-else class="text-sm text-slate-500">Nessuna ricorrente attiva per la proiezione.</p>
      </div>
      <p class="text-xs text-slate-500 mt-2">
        Proiezione basata sulle ricorrenti income/expense attive — non include transazioni discrezionali future.
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
      <div class="table-responsive md:overflow-x-auto">
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
              <td data-label="Data">{{ t.occurred_at }}</td>
              <td data-label="Tipo" class="capitalize">{{ t.type }}</td>
              <td data-label="Conto">{{ t.account_name ?? '—' }}</td>
              <td data-label="Categoria">{{ t.category_name ?? '—' }}</td>
              <td data-label="Descrizione">{{ t.description ?? '—' }}</td>
              <td data-label="Importo" class="md:text-right font-medium">{{ t.amount }} {{ t.currency }}</td>
            </tr>
            <tr v-if="top.length === 0">
              <td colspan="6" class="text-center text-slate-500 py-6">Nessuna transazione nel periodo.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
