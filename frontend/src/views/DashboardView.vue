<script setup lang="ts">
import { formatMonth } from '@/lib/date'
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
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
import { EXPENSE_COLOR, INCOME_COLOR, paletteColor } from '@/lib/chartTheme'
import { TX_TYPE_LABEL } from '@/lib/labels'
import { formatCurrency } from '@/lib/money'
import Amount from '@/components/ui/Amount.vue'
import type { BudgetAlert, CategoryTotal, ReportSummary, TimelinePoint } from '@/types/reports'

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Legend, Tooltip)

const summary = ref<ReportSummary | null>(null)
const categories = ref<CategoryTotal[]>([])
const timeline = ref<TimelinePoint[]>([])
const alerts = ref<BudgetAlert[]>([])
const loading = ref(true)

const donutData = () => ({
  labels: categories.value.map((c) => c.category_name),
  datasets: [
    {
      data: categories.value.map((c) => parseFloat(c.total)),
      backgroundColor: categories.value.map((_, i) => paletteColor(i)),
      borderWidth: 0,
    },
  ],
})

const barData = () => ({
  labels: timeline.value.map((t) => formatMonth(t.period)),
  datasets: [
    {
      label: TX_TYPE_LABEL.income,
      data: timeline.value.map((t) => parseFloat(t.income)),
      backgroundColor: INCOME_COLOR,
    },
    {
      label: TX_TYPE_LABEL.expense,
      data: timeline.value.map((t) => parseFloat(t.expense)),
      backgroundColor: EXPENSE_COLOR,
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
    const [s, c, t, a] = await Promise.all([
      api.get<{ data: ReportSummary }>('/reports/summary'),
      api.get<{ data: CategoryTotal[] }>('/reports/by-category', { params: { type: 'expense' } }),
      api.get<{ data: TimelinePoint[] }>('/reports/timeline'),
      api.get<{ data: BudgetAlert[] }>('/budgets/alerts'),
    ])
    summary.value = s.data.data
    categories.value = c.data.data
    timeline.value = t.data.data
    alerts.value = a.data.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl sm:text-2xl font-semibold">Dashboard</h1>
    <p v-if="loading" class="text-sm text-slate-500">Caricamento…</p>

    <template v-else-if="summary">
      <section v-if="alerts.length" class="card border-l-4 border-warning-400 p-4">
        <div class="flex items-center justify-between gap-3 mb-3">
          <h2 class="text-sm font-medium text-slate-600 uppercase tracking-wide">
            Alert budget ({{ alerts.length }})
          </h2>
          <RouterLink :to="{ name: 'budgets' }" class="text-sm text-primary-600 underline">
            Vai ai budget →
          </RouterLink>
        </div>
        <ul class="space-y-2">
          <li
            v-for="al in alerts"
            :key="al.budget_id"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
          >
            <span class="flex items-center gap-2">
              <span
                v-if="al.category_color"
                class="inline-block w-3 h-3 rounded-full"
                :style="{ background: al.category_color }"
              />
              <span class="font-medium">{{ al.category_name ?? '—' }}</span>
              <span
                class="text-xs px-2 py-0.5 rounded"
                :class="al.status === 'exceeded'
                  ? 'bg-danger-100 text-danger-700'
                  : 'bg-warning-100 text-warning-700'"
              >
                {{ al.status === 'exceeded' ? 'sforato' : 'in allerta' }}
              </span>
            </span>
            <span class="num text-slate-500">
              {{ formatCurrency(al.spent, summary.base_currency) }} / {{ formatCurrency(al.amount, summary.base_currency) }} ·
              <span :class="al.status === 'exceeded' ? 'text-danger-600 font-semibold' : 'text-warning-700 font-semibold'">
                {{ al.percent }}%
              </span>
            </span>
          </li>
        </ul>
      </section>

      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Entrate del mese</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="summary.income" :currency="summary.base_currency" type="income" />
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Uscite del mese</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="summary.expense" :currency="summary.base_currency" type="expense" />
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Risparmio del mese</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="summary.net" :currency="summary.base_currency" signed />
        </div>
        <div class="card p-4">
          <p class="text-xs uppercase text-slate-500">Patrimonio netto</p>
          <Amount class="block text-2xl font-semibold mt-1" :value="summary.net_worth" :currency="summary.base_currency" />
        </div>
      </section>

      <section>
        <h2 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-2">
          Saldi conti
          <span class="font-normal lowercase text-slate-400">· controvalore in {{ summary.base_currency }}</span>
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div v-for="acc in summary.accounts" :key="acc.id" class="card p-4">
            <p class="text-sm text-slate-600">{{ acc.name }}</p>
            <Amount class="block text-xl font-semibold mt-1" :value="acc.balance" :currency="acc.currency" />
            <p v-if="acc.currency !== summary.base_currency" class="text-xs text-slate-400 mt-0.5">
              ≈ {{ formatCurrency(acc.balance_base, summary.base_currency) }}
            </p>
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
          <div class="h-60 sm:h-72">
            <Doughnut v-if="categories.length" :data="donutData()" :options="chartOptions" />
            <p v-else class="text-sm text-slate-500">Nessuna spesa nel periodo.</p>
          </div>
        </div>
        <div class="card p-4">
          <h3 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-3">
            Entrate vs uscite (ultimi 12 mesi)
          </h3>
          <div class="h-60 sm:h-72">
            <Bar :data="barData()" :options="chartOptions" />
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
