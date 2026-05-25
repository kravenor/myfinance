<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  ArcElement,
  BarElement,
  CategoryScale,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'
import { api } from '@/lib/api'
import type { CategoryTotal, NetWorthPoint, TimelinePoint } from '@/types/reports'

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, LineElement, PointElement, Filler, Legend, Tooltip)

function defaultRange() {
  const now = new Date()
  const to = new Date(now.getFullYear(), now.getMonth() + 1, 0)
  const from = new Date(now.getFullYear() - 1, now.getMonth(), 1)
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
  }
}

const filters = ref(defaultRange())
const categoryType = ref<'expense' | 'income'>('expense')

const categories = ref<CategoryTotal[]>([])
const timeline = ref<TimelinePoint[]>([])
const netWorth = ref<NetWorthPoint[]>([])
const loading = ref(false)

const palette = [
  '#6366f1', '#ec4899', '#22c55e', '#f59e0b', '#0ea5e9',
  '#a855f7', '#14b8a6', '#ef4444', '#84cc16', '#eab308',
  '#06b6d4', '#f97316',
]

const donutData = () => ({
  labels: categories.value.map((c) => c.category_name),
  datasets: [
    {
      data: categories.value.map((c) => parseFloat(c.total)),
      backgroundColor: categories.value.map((_, i) => palette[i % palette.length]),
      borderWidth: 0,
    },
  ],
})

const barData = () => ({
  labels: timeline.value.map((t) => t.period),
  datasets: [
    { label: 'Income', data: timeline.value.map((t) => parseFloat(t.income)), backgroundColor: '#22c55e' },
    { label: 'Expense', data: timeline.value.map((t) => parseFloat(t.expense)), backgroundColor: '#ef4444' },
  ],
})

const lineData = () => ({
  labels: netWorth.value.map((p) => p.period),
  datasets: [
    {
      label: 'Patrimonio netto',
      data: netWorth.value.map((p) => parseFloat(p.net_worth)),
      borderColor: '#6366f1',
      backgroundColor: 'rgba(99,102,241,0.15)',
      fill: true,
      tension: 0.3,
    },
  ],
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' as const } },
}

async function refresh() {
  loading.value = true
  try {
    const [c, t, nw] = await Promise.all([
      api.get<{ data: CategoryTotal[] }>('/reports/by-category', {
        params: { from: filters.value.from, to: filters.value.to, type: categoryType.value },
      }),
      api.get<{ data: TimelinePoint[] }>('/reports/timeline', {
        params: { from: filters.value.from, to: filters.value.to },
      }),
      api.get<{ data: NetWorthPoint[] }>('/reports/net-worth', {
        params: { from: filters.value.from, to: filters.value.to },
      }),
    ])
    categories.value = c.data.data
    timeline.value = t.data.data
    netWorth.value = nw.data.data
  } finally {
    loading.value = false
  }
}

onMounted(refresh)
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl sm:text-2xl font-semibold">Report</h1>

    <form class="card p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3" @submit.prevent="refresh">
      <div>
        <label class="label">Da</label>
        <input v-model="filters.from" type="date" class="input" required />
      </div>
      <div>
        <label class="label">A</label>
        <input v-model="filters.to" type="date" class="input" required />
      </div>
      <div>
        <label class="label">Categorie</label>
        <select v-model="categoryType" class="input">
          <option value="expense">expense</option>
          <option value="income">income</option>
        </select>
      </div>
      <div class="flex items-end">
        <button class="btn-secondary w-full" type="submit">Aggiorna</button>
      </div>
    </form>

    <p v-if="loading" class="text-sm text-slate-500">Caricamento…</p>

    <section v-else class="space-y-4">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-4">
          <h3 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-3">
            Totali per categoria ({{ categoryType }})
          </h3>
          <div class="h-64 sm:h-80">
            <Doughnut v-if="categories.length" :data="donutData()" :options="chartOptions" />
            <p v-else class="text-sm text-slate-500">Nessun dato nel periodo.</p>
          </div>
        </div>
        <div class="card p-4">
          <h3 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-3">
            Income vs Expense (mensile)
          </h3>
          <div class="h-64 sm:h-80">
            <Bar :data="barData()" :options="chartOptions" />
          </div>
        </div>
      </div>

      <div class="card p-4">
        <h3 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-3">
          Patrimonio netto (cumulato)
        </h3>
        <div class="h-64 sm:h-80">
          <Line :data="lineData()" :options="chartOptions" />
        </div>
      </div>

      <div class="card table-responsive md:overflow-x-auto">
        <table class="table">
          <thead class="bg-slate-100">
            <tr>
              <th>Categoria</th>
              <th class="text-right">Totale</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in categories" :key="c.category_id ?? 0">
              <td data-label="Categoria" class="font-medium">{{ c.category_name }}</td>
              <td data-label="Totale" class="md:text-right">{{ c.total }}</td>
            </tr>
            <tr v-if="categories.length === 0">
              <td colspan="2" class="text-center text-slate-500 py-6">Nessuna categoria.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
