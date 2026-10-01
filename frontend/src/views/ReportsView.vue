<script setup lang="ts">
import { financialMonthRange, financialMonthStart, formatDate, formatMonth, toIsoDate } from '@/lib/date'
import { computed, onMounted, ref } from 'vue'
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
import { formatCurrency } from '@/lib/money'
import BreakdownList from '@/components/ui/BreakdownList.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { useQueryFilters } from '@/composables/useQueryFilters'
import { useAuthStore } from '@/stores/auth'
import { EXPENSE_COLOR, INCOME_COLOR, PRIMARY_COLOR, paletteColor } from '@/lib/chartTheme'
import type { CategoryTotal, NetWorthPoint, TagTotal, TimelinePoint } from '@/types/reports'

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, LineElement, PointElement, Filler, Legend, Tooltip)

// I totali dei report sono convertiti nella valuta base dell'utente.
const auth = useAuthStore()
const baseCurrency = computed(() => auth.user?.currency ?? 'EUR')

type Tab = 'category' | 'tag' | 'trend'
type Period = 'month' | '3m' | '12m' | 'year' | 'custom'
type Kind = 'expense' | 'income'

const TABS: { value: Tab; label: string }[] = [
  { value: 'category', label: 'Categorie' },
  { value: 'tag', label: 'Tag' },
  { value: 'trend', label: 'Andamento' },
]
const PERIODS: { value: Period; label: string }[] = [
  { value: 'month', label: 'Mese' },
  { value: '3m', label: '3 mesi' },
  { value: '12m', label: '12 mesi' },
  { value: 'year', label: 'Anno' },
  { value: 'custom', label: 'Date' },
]
const KINDS: { value: Kind; label: string }[] = [
  { value: 'expense', label: 'Uscite' },
  { value: 'income', label: 'Entrate' },
]

const { filters } = useQueryFilters({ tab: 'category', period: '12m', type: 'expense', from: '', to: '' }, () => refresh(), 150)
const tab = computed({ get: () => filters.value.tab as Tab, set: (v: Tab) => (filters.value.tab = v) })
const period = computed({
  get: () => filters.value.period as Period,
  // "Personalizzato" parte dal periodo che si stava guardando; gli altri non tengono date nell'URL.
  set: (v: Period) => {
    if (v === 'custom') Object.assign(filters.value, range.value)
    else Object.assign(filters.value, { from: '', to: '' })
    filters.value.period = v
  },
})
const kind = computed({ get: () => filters.value.type as Kind, set: (v: Kind) => (filters.value.type = v) })

// Periodi rapidi sui mesi finanziari (giorno di inizio mese delle Impostazioni); l'anno è solare.
const range = computed(() => {
  const now = new Date()
  const monthsBack = (n: number) => {
    const s = financialMonthStart(now)
    return toIsoDate(new Date(s.getFullYear(), s.getMonth() - n, s.getDate()))
  }
  const { to } = financialMonthRange(now)
  switch (period.value) {
    case 'month':
      return financialMonthRange(now)
    case '3m':
      return { from: monthsBack(2), to }
    case 'year':
      return { from: `${now.getFullYear()}-01-01`, to: `${now.getFullYear()}-12-31` }
    case 'custom':
      return { from: filters.value.from || monthsBack(11), to: filters.value.to || to }
    default:
      return { from: monthsBack(11), to }
  }
})

const categories = ref<CategoryTotal[]>([])
const tags = ref<TagTotal[]>([])
const timeline = ref<TimelinePoint[]>([])
const netWorth = ref<NetWorthPoint[]>([])
const loading = ref(false)
const loaded = ref(false)

const categoryItems = computed(() =>
  categories.value.map((c, i) => ({
    key: c.category_id ?? 'none',
    label: c.category_name,
    value: parseFloat(c.total),
    color: c.category_color || paletteColor(i),
  })),
)
const tagItems = computed(() =>
  tags.value.map((t, i) => ({ key: t.tag_id, label: t.tag_name, value: parseFloat(t.total), color: t.tag_color || paletteColor(i) })),
)
const breakdown = computed(() => (tab.value === 'tag' ? tagItems.value : categoryItems.value))
const breakdownTotal = computed(() => breakdown.value.reduce((s, i) => s + i.value, 0))

const totals = computed(() => {
  const income = timeline.value.reduce((s, t) => s + parseFloat(t.income), 0)
  const expense = timeline.value.reduce((s, t) => s + parseFloat(t.expense), 0)
  return { income, expense, net: income - expense }
})
const hasTrend = computed(() => timeline.value.some((t) => parseFloat(t.income) || parseFloat(t.expense)))

const donutData = computed(() => ({
  labels: breakdown.value.map((i) => i.label),
  datasets: [{ data: breakdown.value.map((i) => i.value), backgroundColor: breakdown.value.map((i) => i.color), borderWidth: 0 }],
}))

const barData = computed(() => ({
  labels: timeline.value.map((t) => formatMonth(t.period)),
  datasets: [
    { label: 'Entrate', data: timeline.value.map((t) => parseFloat(t.income)), backgroundColor: INCOME_COLOR, borderRadius: 4 },
    { label: 'Uscite', data: timeline.value.map((t) => parseFloat(t.expense)), backgroundColor: EXPENSE_COLOR, borderRadius: 4 },
  ],
}))

const lineData = computed(() => ({
  labels: netWorth.value.map((p) => formatMonth(p.period)),
  datasets: [
    {
      label: 'Patrimonio netto',
      data: netWorth.value.map((p) => parseFloat(p.net_worth)),
      borderColor: PRIMARY_COLOR,
      backgroundColor: `${PRIMARY_COLOR}26`,
      fill: true,
      tension: 0.3,
    },
  ],
}))

const tooltip = {
  callbacks: {
    label: (ctx: { dataset: { label?: string }; label: string; raw: unknown }) =>
      `${ctx.dataset.label ?? ctx.label}: ${formatCurrency(Number(ctx.raw), baseCurrency.value)}`,
  },
}
// La classifica accanto fa da legenda della ciambella.
const donutOptions = { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false }, tooltip } }
const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' as const }, tooltip } }

// Carica solo i dati della scheda aperta: il patrimonio netto costa una query di saldo per mese.
async function refresh() {
  loading.value = true
  const { from, to } = range.value
  try {
    if (tab.value === 'trend') {
      const [t, nw] = await Promise.all([
        api.get<{ data: TimelinePoint[] }>('/reports/timeline', { params: { from, to } }),
        api.get<{ data: NetWorthPoint[] }>('/reports/net-worth', { params: { from, to } }),
      ])
      timeline.value = t.data.data
      netWorth.value = nw.data.data
    } else if (tab.value === 'tag') {
      tags.value = (await api.get<{ data: TagTotal[] }>('/reports/by-tag', { params: { from, to, type: kind.value } })).data.data
    } else {
      categories.value = (await api.get<{ data: CategoryTotal[] }>('/reports/by-category', { params: { from, to, type: kind.value } })).data.data
    }
  } finally {
    loading.value = false
    loaded.value = true
  }
}

onMounted(refresh)
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl sm:text-2xl font-semibold">Report</h1>
      <p class="page-desc">
        Dove vanno e da dove arrivano i soldi nel periodo scelto, con importi convertiti nella tua valuta principale.
      </p>
    </div>

    <div class="card space-y-3 p-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <SegmentedControl v-model="tab" :options="TABS" label="Report" />
        <SegmentedControl v-if="tab !== 'trend'" v-model="kind" :options="KINDS" label="Tipo di movimento" />
      </div>
      <SegmentedControl v-model="period" :options="PERIODS" label="Periodo" />
      <div v-if="period === 'custom'" class="grid grid-cols-2 gap-3 sm:max-w-md">
        <div>
          <label class="label" for="rep-from">Da</label>
          <input id="rep-from" v-model="filters.from" type="date" class="input" />
        </div>
        <div>
          <label class="label" for="rep-to">A</label>
          <input id="rep-to" v-model="filters.to" type="date" class="input" />
        </div>
      </div>
      <p class="text-xs text-slate-500">
        {{ formatDate(range.from) }} – {{ formatDate(range.to) }}
        <template v-if="tab !== 'trend'"> · i giroconti non sono mai inclusi</template>
      </p>
    </div>

    <div v-if="loading && !loaded" class="card h-80 animate-pulse bg-slate-100" aria-busy="true" aria-label="Caricamento" />

    <section v-else-if="tab !== 'trend'" class="card p-4 sm:p-5 transition-opacity" :class="{ 'opacity-60': loading }">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold text-slate-900">
          {{ kind === 'income' ? 'Entrate' : 'Uscite' }} per {{ tab === 'tag' ? 'tag' : 'categoria' }}
        </h2>
        <p v-if="breakdown.length" class="num text-lg font-semibold" :class="kind === 'income' ? 'text-income-600' : 'text-expense-600'">
          {{ formatCurrency(breakdownTotal, baseCurrency) }}
        </p>
      </div>
      <p class="mt-1 text-xs text-slate-500">
        <template v-if="tab === 'tag'">Una transazione con più tag conta per intero in ognuno, quindi la somma può superare il totale reale.</template>
        <template v-else>Le sottocategorie hanno un totale proprio e non si sommano alla categoria padre.</template>
      </p>

      <div v-if="breakdown.length" class="mt-4 grid grid-cols-1 items-start gap-6 md:grid-cols-[minmax(0,15rem)_1fr]">
        <div class="mx-auto h-52 w-52 md:h-60 md:w-60">
          <Doughnut :data="donutData" :options="donutOptions" />
        </div>
        <BreakdownList :items="breakdown" :currency="baseCurrency" />
      </div>
      <EmptyState
        v-else
        :title="tab === 'tag'
          ? 'Nessuna transazione con tag nel periodo.'
          : kind === 'income' ? 'Nessuna entrata nel periodo.' : 'Nessuna uscita nel periodo.'"
      />
    </section>

    <template v-else>
      <div class="grid grid-cols-3 gap-3 transition-opacity" :class="{ 'opacity-60': loading }">
        <div class="card p-3 sm:p-4">
          <p class="text-xs font-medium text-slate-500">Entrate</p>
          <p class="num mt-1 text-base font-semibold text-income-600 sm:text-xl">{{ formatCurrency(totals.income, baseCurrency) }}</p>
        </div>
        <div class="card p-3 sm:p-4">
          <p class="text-xs font-medium text-slate-500">Uscite</p>
          <p class="num mt-1 text-base font-semibold text-expense-600 sm:text-xl">{{ formatCurrency(totals.expense, baseCurrency) }}</p>
        </div>
        <div class="card p-3 sm:p-4">
          <p class="text-xs font-medium text-slate-500">Saldo</p>
          <p class="num mt-1 text-base font-semibold sm:text-xl" :class="totals.net >= 0 ? 'text-income-600' : 'text-expense-600'">
            {{ totals.net > 0 ? '+' : totals.net < 0 ? '−' : '' }}{{ formatCurrency(Math.abs(totals.net), baseCurrency) }}
          </p>
        </div>
      </div>

      <section class="card p-4 sm:p-5 transition-opacity" :class="{ 'opacity-60': loading }">
        <h2 class="font-semibold text-slate-900">Entrate e uscite per mese</h2>
        <div v-if="hasTrend" class="mt-4 h-64 sm:h-80">
          <Bar :data="barData" :options="chartOptions" />
        </div>
        <EmptyState v-else title="Nessuna transazione nel periodo." />
      </section>

      <section class="card p-4 sm:p-5 transition-opacity" :class="{ 'opacity-60': loading }">
        <h2 class="font-semibold text-slate-900">Patrimonio netto</h2>
        <p class="mt-1 text-xs text-slate-500">Somma dei saldi di tutti i conti alla fine di ogni mese.</p>
        <div v-if="netWorth.length" class="mt-4 h-64 sm:h-80">
          <Line :data="lineData" :options="chartOptions" />
        </div>
        <EmptyState v-else title="Nessun dato sul patrimonio nel periodo." />
      </section>
    </template>
  </div>
</template>
