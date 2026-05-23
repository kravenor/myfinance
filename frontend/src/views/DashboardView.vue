<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  ArcElement,
  BarElement,
  CategoryScale,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js'
import { api } from '@/lib/api'
import type { CategoryTotal, ReportSummary, TimelinePoint } from '@/types/reports'

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Legend, Tooltip)

const summary = ref<ReportSummary | null>(null)
const categories = ref<CategoryTotal[]>([])
const timeline = ref<TimelinePoint[]>([])
const loading = ref(true)

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
    {
      label: 'Income',
      data: timeline.value.map((t) => parseFloat(t.income)),
      backgroundColor: '#22c55e',
    },
    {
      label: 'Expense',
      data: timeline.value.map((t) => parseFloat(t.expense)),
      backgroundColor: '#ef4444',
    },
  ],
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' as const } },
}

onMounted(async () => {
  try {
    const [s, c, t] = await Promise.all([
      api.get<{ data: ReportSummary }>('/reports/summary'),
      api.get<{ data: CategoryTotal[] }>('/reports/by-category', { params: { type: 'expense' } }),
      api.get<{ data: TimelinePoint[] }>('/reports/timeline'),
    ])
    summary.value = s.data.data
    categories.value = c.data.data
    timeline.value = t.data.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p v-if="loading" class="text-sm text-slate-500">Caricamento…</p>

    <template v-else-if="summary">
      <section class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Income mese</p>
          <p class="text-2xl font-semibold text-green-600 mt-1">{{ summary.income }}</p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Expense mese</p>
          <p class="text-2xl font-semibold text-red-600 mt-1">{{ summary.expense }}</p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Net mese</p>
          <p
            class="text-2xl font-semibold mt-1"
            :class="parseFloat(summary.net) >= 0 ? 'text-green-600' : 'text-red-600'"
          >
            {{ summary.net }}
          </p>
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Patrimonio netto</p>
          <p class="text-2xl font-semibold mt-1">{{ summary.net_worth }}</p>
        </div>
      </section>

      <section>
        <h2 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-2">Saldi conti</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div v-for="acc in summary.accounts" :key="acc.id" class="card p-4">
            <p class="text-sm text-slate-600">{{ acc.name }}</p>
            <p class="text-xl font-semibold mt-1">{{ acc.balance }} {{ acc.currency }}</p>
          </div>
          <div v-if="summary.accounts.length === 0" class="text-sm text-slate-500">
            Nessun conto.
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-4">
          <h3 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-3">
            Spese per categoria (mese)
          </h3>
          <div class="h-72">
            <Doughnut v-if="categories.length" :data="donutData()" :options="chartOptions" />
            <p v-else class="text-sm text-slate-500">Nessuna spesa nel periodo.</p>
          </div>
        </div>
        <div class="card p-4">
          <h3 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-3">
            Income vs Expense (ultimi 12 mesi)
          </h3>
          <div class="h-72">
            <Bar :data="barData()" :options="chartOptions" />
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
