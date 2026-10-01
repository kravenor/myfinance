<script setup lang="ts">
import { formatDate, formatMonth } from '@/lib/date'
import { computed, onMounted, ref } from 'vue'
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
import { EXPENSE_COLOR, INCOME_COLOR, MUTED_COLOR, paletteColor } from '@/lib/chartTheme'
import { TX_TYPE_LABEL } from '@/lib/labels'
import { formatCurrency } from '@/lib/money'
import Amount from '@/components/ui/Amount.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { Paginated, Transaction } from '@/types/api'
import type { BudgetAlert, CategoryTotal, ReportSummary, TimelinePoint } from '@/types/reports'

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Legend, Tooltip)

const summary = ref<ReportSummary | null>(null)
const categories = ref<CategoryTotal[]>([])
const timeline = ref<TimelinePoint[]>([])
const alerts = ref<BudgetAlert[]>([])
const recent = ref<Transaction[]>([])
const loading = ref(true)

// La timeline termina sul mese finanziario corrente: il penultimo punto è il mese precedente.
const previous = computed(() => timeline.value.at(-2) ?? null)

interface Kpi {
  key: 'income' | 'expense' | 'net'
  label: string
  value: string
  // true se un aumento è una buona notizia (entrate, risparmio), false per le uscite.
  upIsGood: boolean
}

const kpis = computed<Kpi[]>(() => {
  if (!summary.value) return []
  return [
    { key: 'income', label: 'Entrate del mese', value: summary.value.income, upIsGood: true },
    { key: 'expense', label: 'Uscite del mese', value: summary.value.expense, upIsGood: false },
    { key: 'net', label: 'Risparmio del mese', value: summary.value.net, upIsGood: true },
  ]
})

function delta(kpi: Kpi): { text: string; good: boolean } | null {
  const prev = previous.value ? parseFloat(previous.value[kpi.key]) : 0
  if (!previous.value || prev === 0) return null
  const pct = ((parseFloat(kpi.value) - prev) / Math.abs(prev)) * 100
  if (!Number.isFinite(pct)) return null
  const rounded = Math.round(pct)
  return {
    text: `${rounded > 0 ? '▲' : rounded < 0 ? '▼' : '='} ${Math.abs(rounded)}% vs ${formatMonth(previous.value.period)}`,
    good: rounded === 0 || rounded > 0 === kpi.upIsGood,
  }
}

const accountName = (id: number) => summary.value?.accounts.find((a) => a.id === id)?.name ?? '—'

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
    { label: 'Entrate', data: timeline.value.map((t) => parseFloat(t.income)), backgroundColor: INCOME_COLOR, borderRadius: 4 },
    { label: 'Uscite', data: timeline.value.map((t) => parseFloat(t.expense)), backgroundColor: EXPENSE_COLOR, borderRadius: 4 },
  ],
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom' as const },
    tooltip: {
      callbacks: {
        label: (ctx: { dataset: { label?: string }; label: string; raw: unknown }) =>
          `${ctx.dataset.label ?? ctx.label}: ${formatCurrency(Number(ctx.raw), summary.value?.base_currency)}`,
      },
    },
  },
}))

const hasActivity = computed(() => timeline.value.some((t) => parseFloat(t.income) || parseFloat(t.expense)))

onMounted(async () => {
  try {
    const [s, c, t, a, r] = await Promise.all([
      api.get<{ data: ReportSummary }>('/reports/summary'),
      api.get<{ data: CategoryTotal[] }>('/reports/by-category', { params: { type: 'expense' } }),
      api.get<{ data: TimelinePoint[] }>('/reports/timeline'),
      api.get<{ data: BudgetAlert[] }>('/budgets/alerts'),
      api.get<Paginated<Transaction>>('/transactions', { params: { per_page: 5 } }),
    ])
    summary.value = s.data.data
    categories.value = c.data.data
    timeline.value = t.data.data
    alerts.value = a.data.data
    recent.value = r.data.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl sm:text-2xl font-semibold">Dashboard</h1>
      <p v-if="summary" class="mt-1 text-sm text-slate-500">
        Periodo {{ formatDate(summary.from) }} – {{ formatDate(summary.to) }}
      </p>
    </div>

    <div v-if="loading" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-busy="true" aria-label="Caricamento">
      <div v-for="i in 4" :key="i" class="card h-28 animate-pulse bg-slate-100" />
      <div class="card h-72 animate-pulse bg-slate-100 sm:col-span-2 lg:col-span-3" />
      <div class="card h-72 animate-pulse bg-slate-100" />
    </div>

    <template v-else-if="summary">
      <section class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" aria-label="Indicatori">
        <div class="card col-span-2 p-4 sm:p-5 lg:col-span-1">
          <p class="text-sm font-medium text-slate-500">Patrimonio netto</p>
          <Amount class="mt-2 block text-3xl font-semibold tracking-tight" :value="summary.net_worth" :currency="summary.base_currency" />
          <p class="mt-2 text-xs text-slate-500">Somma dei conti, investimenti al valore di mercato</p>
        </div>
        <div v-for="kpi in kpis" :key="kpi.key" class="card p-4 sm:p-5" :class="{ 'col-span-2 sm:col-span-1': kpi.key === 'net' }">
          <p class="text-sm font-medium text-slate-500">{{ kpi.label }}</p>
          <Amount
            class="mt-2 block text-xl font-semibold tracking-tight sm:text-2xl"
            :value="kpi.value"
            :currency="summary.base_currency"
            v-bind="kpi.key === 'net' ? { signed: true } : { type: kpi.key }"
          />
          <p class="mt-2 flex flex-wrap items-center gap-x-2 text-xs">
            <span
              v-if="delta(kpi)"
              class="font-medium"
              :class="delta(kpi)!.good ? 'text-income-700' : 'text-expense-700'"
            >{{ delta(kpi)!.text }}</span>
            <span v-else class="text-slate-500">Nessun confronto col mese prima</span>
            <span v-if="kpi.key === 'net' && parseFloat(summary.income) > 0" class="text-slate-500">
              · {{ summary.saving_rate }}% delle entrate
            </span>
          </p>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
          <section class="card">
            <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
              <h2 class="font-semibold text-slate-900">Ultime transazioni</h2>
              <RouterLink :to="{ name: 'transactions' }" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                Vedi tutte
              </RouterLink>
            </header>
            <ul v-if="recent.length" class="divide-y divide-slate-100">
              <li v-for="tx in recent" :key="tx.id" class="flex items-center gap-3 px-5 py-3">
                <span
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                  :class="{
                    'bg-income-50 text-income-600': tx.type === 'income',
                    'bg-expense-50 text-expense-600': tx.type === 'expense',
                    'bg-transfer-50 text-transfer-600': tx.type === 'transfer',
                  }"
                >
                  <AppIcon name="arrows-right-left" class="h-4 w-4" />
                  <span class="sr-only">{{ TX_TYPE_LABEL[tx.type] }}</span>
                </span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium text-slate-900">
                    {{ tx.description || TX_TYPE_LABEL[tx.type] }}
                  </p>
                  <p class="truncate text-xs text-slate-500">
                    {{ formatDate(tx.occurred_at) }} · {{ accountName(tx.account_id) }}
                    <template v-if="tx.type === 'transfer'"> → {{ accountName(tx.transfer_account_id ?? 0) }}</template>
                  </p>
                </div>
                <Amount class="text-sm font-semibold" :value="tx.amount" :currency="tx.currency" :type="tx.type" />
              </li>
            </ul>
            <div v-else class="px-5 py-8 text-center">
              <p class="text-sm text-slate-600">Non hai ancora registrato transazioni.</p>
              <RouterLink :to="{ name: 'transactions', query: { new: '1' } }" class="btn-primary mt-3 gap-1.5">
                <AppIcon name="plus" class="h-4 w-4" />
                Registra la prima
              </RouterLink>
            </div>
          </section>

          <section class="card p-5">
            <h2 class="font-semibold text-slate-900">Entrate e uscite</h2>
            <p class="text-xs text-slate-500">Ultimi 12 mesi</p>
            <div v-if="hasActivity" class="mt-4 h-64 sm:h-72">
              <Bar :data="barData()" :options="chartOptions" />
            </div>
            <div v-else class="mt-4">
              <p class="py-6 text-center text-sm text-slate-500">
                Il grafico si riempie man mano che registri entrate e uscite.
              </p>
            </div>
          </section>
        </div>

        <div class="space-y-4">
          <section class="card">
            <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
              <h2 class="font-semibold text-slate-900">Budget del mese</h2>
              <RouterLink :to="{ name: 'budgets' }" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                Gestisci
              </RouterLink>
            </header>
            <ul v-if="alerts.length" class="space-y-4 px-5 py-4">
              <li v-for="al in alerts" :key="al.budget_id">
                <div class="flex items-center justify-between gap-2 text-sm">
                  <span class="flex min-w-0 items-center gap-2">
                    <span
                      class="h-2.5 w-2.5 shrink-0 rounded-full"
                      :style="{ background: al.category_color || MUTED_COLOR }"
                      aria-hidden="true"
                    />
                    <span class="truncate font-medium text-slate-800">{{ al.category_name ?? '—' }}</span>
                  </span>
                  <span
                    class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium"
                    :class="al.status === 'exceeded' ? 'bg-danger-50 text-danger-700' : 'bg-warning-50 text-warning-800'"
                  >
                    {{ al.status === 'exceeded' ? 'Sforato' : 'In allerta' }} · {{ al.percent }}%
                  </span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                  <div
                    class="h-full rounded-full"
                    :class="al.status === 'exceeded' ? 'bg-danger-500' : 'bg-warning-400'"
                    :style="{ width: Math.min(al.percent, 100) + '%' }"
                  />
                </div>
                <p class="num mt-1 text-xs text-slate-500">
                  {{ formatCurrency(al.spent, summary.base_currency) }} di {{ formatCurrency(al.amount, summary.base_currency) }}
                </p>
              </li>
            </ul>
            <p v-else class="px-5 py-6 text-sm text-slate-600">
              Nessun budget in allerta: le spese sono sotto le soglie.
            </p>
          </section>

          <section class="card">
            <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
              <h2 class="font-semibold text-slate-900">Conti</h2>
              <RouterLink :to="{ name: 'accounts' }" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                Gestisci
              </RouterLink>
            </header>
            <ul v-if="summary.accounts.length" class="divide-y divide-slate-100">
              <li v-for="acc in summary.accounts" :key="acc.id" class="flex items-center justify-between gap-3 px-5 py-3">
                <span class="truncate text-sm text-slate-700">{{ acc.name }}</span>
                <span class="text-right">
                  <Amount class="block text-sm font-semibold" :value="acc.balance" :currency="acc.currency" />
                  <span v-if="acc.currency !== summary.base_currency" class="num block text-xs text-slate-500">
                    ≈ {{ formatCurrency(acc.balance_base, summary.base_currency) }}
                  </span>
                </span>
              </li>
            </ul>
            <div v-else class="px-5 py-6 text-sm text-slate-600">
              Nessun conto.
              <RouterLink :to="{ name: 'accounts' }" class="font-medium text-primary-600">Crea il primo</RouterLink>
            </div>
          </section>

          <section class="card p-5">
            <h2 class="font-semibold text-slate-900">Spese per categoria</h2>
            <p class="text-xs text-slate-500">Mese corrente</p>
            <div v-if="categories.length" class="mt-4 h-64">
              <Doughnut :data="donutData()" :options="chartOptions" />
            </div>
            <div v-else class="mt-4">
              <p class="py-6 text-center text-sm text-slate-500">
                Nessuna spesa nel periodo.
              </p>
            </div>
          </section>
        </div>
      </div>
    </template>
  </div>
</template>
