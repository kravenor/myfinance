<script setup lang="ts">
import { financialMonthStart, formatMonth } from '@/lib/date'
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { useQueryFilters } from '@/composables/useQueryFilters'
import FormErrors from '@/components/ui/FormErrors.vue'
import FieldError from '@/components/ui/FieldError.vue'
import Amount from '@/components/ui/Amount.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import { formatCurrency } from '@/lib/money'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import RowActions from '@/components/ui/RowActions.vue'
import type { Budget, Category, Paginated } from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

function budgetPeriod(year: number, month: number): string {
  return formatMonth(`${year}-${String(month).padStart(2, '0')}`)
}

const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<Budget>('budgets')
const toast = useToastStore()
const auth = useAuthStore()
// Il backend somma le uscite senza conversione: gli importi sono nella valuta dell'utente.
const currency = computed(() => auth.user?.currency ?? 'EUR')

const categories = ref<Category[]>([])
// Periodo di default: il ciclo finanziario corrente (vedi month_start_day).
const currentCycle = financialMonthStart()
const periodKey = (year: number, month: number) => `${year}-${String(month).padStart(2, '0')}`
const { filters: query } = useQueryFilters(
  { period: periodKey(currentCycle.getFullYear(), currentCycle.getMonth() + 1) },
  () => refresh(),
  0,
)
const filters = computed(() => {
  const [year, month] = query.value.period.split('-').map(Number)
  return year && month >= 1 && month <= 12
    ? { year, month }
    : { year: currentCycle.getFullYear(), month: currentCycle.getMonth() + 1 }
})

function shiftPeriod(delta: number) {
  const d = new Date(filters.value.year, filters.value.month - 1 + delta, 1)
  query.value.period = periodKey(d.getFullYear(), d.getMonth() + 1)
}

const editing = ref<Budget | null>(null)
const showForm = ref(false)
const form = ref({
  category_id: 0,
  year: filters.value.year,
  month: filters.value.month,
  amount: '',
})
const dirty = useFormDirty(form, showForm)

function reset() {
  editing.value = null
  fieldErrors.value = {}
  form.value = {
    category_id: categories.value[0]?.id ?? 0,
    year: filters.value.year,
    month: filters.value.month,
    amount: '',
  }
}

function startEdit(b: Budget) {
  editing.value = b
  fieldErrors.value = {}
  form.value = { category_id: b.category_id, year: b.year, month: b.month, amount: b.amount }
  showForm.value = true
}

function openNew() {
  reset()
  showForm.value = true
}

async function refresh() {
  await list({ year: filters.value.year, month: filters.value.month, per_page: 100 })
}

async function onSubmit() {
  try {
    if (editing.value) {
      await update(editing.value.id, form.value)
    } else {
      await create(form.value)
    }
  } catch {
    // 422: errori sotto i campi, il form resta aperto con i dati inseriti.
    if (Object.keys(fieldErrors.value).length) toast.error('Controlla i campi evidenziati.')
    return
  }
  toast.success('Budget salvato.')
  reset()
  showForm.value = false
  await refresh()
}

async function onDelete(b: Budget) {
  if (!(await confirmAction('Eliminare il budget?'))) return
  await destroy(b.id)
}

function categoryName(id: number): string {
  return categories.value.find((c) => c.id === id)?.name ?? `#${id}`
}

function rawPercent(b: Budget): number {
  if (!b.spent) return 0
  const amount = parseFloat(b.amount)
  if (amount === 0) return parseFloat(b.spent) > 0 ? 100 : 0
  return Math.round((parseFloat(b.spent) / amount) * 100)
}

function progress(b: Budget): number {
  return Math.min(100, rawPercent(b))
}

function status(b: Budget): 'ok' | 'warning' | 'exceeded' {
  const p = rawPercent(b)
  if (p >= 100) return 'exceeded'
  if (p >= 80) return 'warning'
  return 'ok'
}

function barClass(b: Budget): string {
  return { ok: 'bg-primary-500', warning: 'bg-warning-500', exceeded: 'bg-danger-500' }[status(b)]
}

onMounted(async () => {
  const c = await api.get<Paginated<Category>>('/categories', { params: { type: 'expense', per_page: 200 } })
  categories.value = c.data.data
  form.value.category_id = categories.value[0]?.id ?? 0
  await refresh()
})
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Budget</h1>
        <p class="page-desc">Fissa quanto vuoi spendere al massimo per una categoria in un mese e controlla quanto hai già speso.</p>
      </div>
      <button class="btn-primary" @click="openNew()">
        Nuovo budget
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuovo budget"
      @click="openNew()"
    >+</button>

    <div class="card flex items-center justify-between gap-2 p-2">
      <button type="button" class="icon-btn text-2xl leading-none text-slate-600 hover:bg-slate-100 focus:ring-primary-500" aria-label="Mese precedente" @click="shiftPeriod(-1)">‹</button>
      <p class="text-sm font-semibold text-slate-900" aria-live="polite">{{ budgetPeriod(filters.year, filters.month) }}</p>
      <button type="button" class="icon-btn text-2xl leading-none text-slate-600 hover:bg-slate-100 focus:ring-primary-500" aria-label="Mese successivo" @click="shiftPeriod(1)">›</button>
    </div>

    <AppModal v-slot="{ close }" v-model="showForm" :dirty="dirty" :title="editing ? 'Modifica budget' : 'Nuovo budget'">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-4 py-4 sm:px-6">
          <FormErrors class="col-span-full" :errors="fieldErrors" :shown="['category_id', 'year', 'month', 'amount']" />
          <div>
            <label class="label">Categoria</label>
            <select v-model.number="form.category_id" class="input" :class="{ 'input-invalid': fieldErrors.category_id }" aria-describedby="hint-category" required>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p id="hint-category" class="field-hint">Conta solo le uscite registrate su questa categoria, non quelle delle sue sottocategorie.</p>
            <FieldError :errors="fieldErrors" name="category_id" />
          </div>
          <div>
            <label class="label">Anno</label>
            <input v-model.number="form.year" type="number" min="2000" max="2100" class="input" :class="{ 'input-invalid': fieldErrors.year }" required />
            <FieldError :errors="fieldErrors" name="year" />
          </div>
          <div>
            <label class="label">Mese</label>
            <input v-model.number="form.month" type="number" min="1" max="12" class="input" :class="{ 'input-invalid': fieldErrors.month }" aria-describedby="hint-month" required />
            <p id="hint-month" class="field-hint">Segue il giorno di inizio mese delle Impostazioni: con inizio il 27, giugno va dal 27/06 al 26/07.</p>
            <FieldError :errors="fieldErrors" name="month" />
          </div>
          <div>
            <label class="label">Importo</label>
            <input v-model="form.amount" type="number" inputmode="decimal" step="0.01" class="input" :class="{ 'input-invalid': fieldErrors.amount }" aria-describedby="hint-amount" required />
            <p id="hint-amount" class="field-hint">Il tetto di spesa del mese: dall'80% la barra diventa ambra, dal 100% rossa.</p>
            <FieldError :errors="fieldErrors" name="amount" />
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

    <div class="card">
      <ListSkeleton v-if="loading && !items.length" />

      <!-- Mobile: una card per budget, la barra di progresso ha bisogno di spazio orizzontale pieno (sotto md). -->
      <ul v-else class="md:hidden divide-y divide-slate-100" :class="{ 'opacity-60': loading }">
        <li v-for="b in items" :key="b.id" class="p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-slate-800 truncate">{{ categoryName(b.category_id) }}</p>
              <p class="text-xs text-slate-500 mt-0.5">{{ budgetPeriod(b.year, b.month) }}</p>
            </div>
            <RowActions @edit="startEdit(b)" @delete="onDelete(b)" />
          </div>
          <div class="mt-3 flex items-center gap-2">
            <div class="flex-1 bg-slate-200 rounded h-2">
              <div class="h-2 rounded" :class="barClass(b)" :style="{ width: progress(b) + '%' }" />
            </div>
            <span
              v-if="status(b) !== 'ok'"
              class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap"
              :class="status(b) === 'exceeded' ? 'bg-danger-100 text-danger-700' : 'bg-warning-100 text-warning-700'"
            >{{ rawPercent(b) }}%</span>
          </div>
          <p class="text-xs text-slate-500 mt-1.5">
            <span class="num">{{ formatCurrency(b.spent ?? 0, currency) }}</span> di
            <span class="num">{{ formatCurrency(b.amount, currency) }}</span>
          </p>
        </li>
        <li v-if="items.length === 0">
          <EmptyState :title="`Nessun budget per ${budgetPeriod(filters.year, filters.month)}.`">
            <button type="button" class="btn-primary" @click="openNew()">Nuovo budget</button>
          </EmptyState>
        </li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!(loading && !items.length)" class="table hidden md:table" :class="{ 'opacity-60': loading }">
        <thead class="bg-slate-100">
          <tr>
            <th>Categoria</th>
            <th>Periodo</th>
            <th class="text-right">Budget</th>
            <th class="text-right">Speso</th>
            <th class="w-48">Progresso</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="b in items" :key="b.id">
            <td class="font-medium">{{ categoryName(b.category_id) }}</td>
            <td>{{ budgetPeriod(b.year, b.month) }}</td>
            <td class="text-right"><Amount :value="b.amount" :currency="currency" /></td>
            <td class="text-right"><Amount :value="b.spent ?? 0" :currency="currency" /></td>
            <td>
              <div class="flex items-center gap-2">
                <div class="flex-1 bg-slate-200 rounded h-2">
                  <div class="h-2 rounded" :class="barClass(b)" :style="{ width: progress(b) + '%' }" />
                </div>
                <span
                  v-if="status(b) !== 'ok'"
                  class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap"
                  :class="status(b) === 'exceeded' ? 'bg-danger-100 text-danger-700' : 'bg-warning-100 text-warning-700'"
                >
                  {{ rawPercent(b) }}%
                </span>
              </div>
            </td>
            <td class="text-right">
              <RowActions @edit="startEdit(b)" @delete="onDelete(b)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="6" class="whitespace-normal">
              <EmptyState :title="`Nessun budget per ${budgetPeriod(filters.year, filters.month)}.`">
                <button type="button" class="btn-primary" @click="openNew()">Nuovo budget</button>
              </EmptyState>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
