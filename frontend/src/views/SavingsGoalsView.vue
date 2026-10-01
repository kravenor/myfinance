<script setup lang="ts">
import { formatDate } from '@/lib/date'
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import FieldError from '@/components/ui/FieldError.vue'
import Amount from '@/components/ui/Amount.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import { CURRENCIES, formatCurrency as money } from '@/lib/money'
import { PRIMARY_COLOR } from '@/lib/chartTheme'
import { RECURRENCE_LABEL, TX_TYPE_LABEL } from '@/lib/labels'
import RowActions from '@/components/ui/RowActions.vue'
import type {
  Account,
  Category,
  Paginated,
  PaceStatus,
  SavingsGoal,
  SavingsGoalRecurrence,
  Transaction,
  TransactionType,
} from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

const auth = useAuthStore()
const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<SavingsGoal>('savings-goals')
const toast = useToastStore()

const accounts = ref<Account[]>([])

// Scegliendo il conto la valuta si allinea alla sua (resta modificabile).
function onAccountChange() {
  const account = accounts.value.find((a) => a.id === form.value.account_id)
  if (account) form.value.currency = account.currency
}
const categories = ref<Category[]>([])
const statusFilter = ref<'active' | 'completed' | 'archived' | 'all'>('active')

const today = new Date().toISOString().slice(0, 10)

// --- Form goal -------------------------------------------------------------
const editing = ref<SavingsGoal | null>(null)
const showForm = ref(false)
const form = ref({
  name: '',
  target_amount: '',
  currency: auth.user?.currency ?? 'EUR',
  account_id: '' as number | '',
  recurrence: 'none' as SavingsGoalRecurrence,
  start_date: '',
  target_date: '',
  color: PRIMARY_COLOR,
  status: 'active' as SavingsGoal['status'],
  notes: '',
})
const dirty = useFormDirty(form, showForm)

function resetForm() {
  editing.value = null
  fieldErrors.value = {}
  form.value = {
    name: '',
    target_amount: '',
    currency: auth.user?.currency ?? 'EUR',
    account_id: '',
    recurrence: 'none',
    start_date: '',
    target_date: '',
    color: PRIMARY_COLOR,
    status: 'active',
    notes: '',
  }
}

function startEdit(g: SavingsGoal) {
  editing.value = g
  fieldErrors.value = {}
  form.value = {
    name: g.name,
    target_amount: g.target_amount,
    currency: g.currency,
    account_id: g.account_id ?? '',
    recurrence: g.recurrence,
    start_date: g.start_date ?? '',
    target_date: g.target_date ?? '',
    color: g.color ?? PRIMARY_COLOR,
    status: g.status,
    notes: g.notes ?? '',
  }
  showForm.value = true
}

function openNew() {
  resetForm()
  showForm.value = true
}

async function onSubmit() {
  const recurring = form.value.recurrence !== 'none'
  const payload = {
    name: form.value.name,
    target_amount: form.value.target_amount,
    currency: form.value.currency,
    account_id: form.value.account_id === '' ? null : form.value.account_id,
    recurrence: form.value.recurrence,
    // start_date/target_date hanno senso solo per i goal spot: per i ricorrenti
    // il periodo è derivato automaticamente.
    start_date: recurring ? null : form.value.start_date || null,
    target_date: recurring ? null : form.value.target_date || null,
    color: form.value.color || null,
    status: form.value.status,
    notes: form.value.notes || null,
  }
  try {
    if (editing.value) {
      await update(editing.value.id, payload)
    } else {
      await create(payload)
    }
  } catch {
    // 422: errori sotto i campi, il form resta aperto con i dati inseriti.
    if (Object.keys(fieldErrors.value).length) toast.error('Controlla i campi evidenziati.')
    return
  }
  toast.success('Obiettivo salvato.')
  resetForm()
  showForm.value = false
  await refresh()
}

async function onDelete(g: SavingsGoal) {
  if (!(await confirmAction(`Eliminare l'obiettivo "${g.name}"? Le transazioni del conto non vengono toccate.`))) return
  await destroy(g.id)
}

async function refresh() {
  const params: Record<string, unknown> = { per_page: 100 }
  if (statusFilter.value !== 'all') params.status = statusFilter.value
  await list(params)
}

// --- Operazioni (modale) ---------------------------------------------------
// Le operazioni sono normali transazioni sul conto collegato: compaiono anche
// nei movimenti generali e muovono il progresso, senza doppia registrazione.
const opsGoal = ref<SavingsGoal | null>(null)
const transactions = ref<Transaction[]>([])
const opsLoading = ref(false)
const opForm = ref({
  type: 'transfer' as TransactionType,
  amount: '',
  occurred_at: today,
  from_account_id: '' as number | '',
  category_id: '' as number | '',
  description: '',
})

function resetOpForm() {
  opForm.value = {
    type: 'transfer',
    amount: '',
    occurred_at: today,
    from_account_id: '',
    category_id: '',
    description: '',
  }
}

const OP_TYPES: TransactionType[] = ['transfer', 'income', 'expense']

const opCategories = computed(() => {
  if (opForm.value.type === 'transfer') return []
  return categories.value
    .filter((c) => c.type === opForm.value.type)
    .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name))
})

const transferSources = computed(() =>
  accounts.value.filter((a) => a.id !== opsGoal.value?.account_id),
)

async function openOps(g: SavingsGoal) {
  opsGoal.value = g
  resetOpForm()
  await loadOps()
}

function closeOps() {
  opsGoal.value = null
  transactions.value = []
}

async function loadOps() {
  if (!opsGoal.value?.account_id) return
  opsLoading.value = true
  try {
    const { data } = await api.get<Paginated<Transaction>>('/transactions', {
      params: {
        account_id: opsGoal.value.account_id,
        from: opsGoal.value.period_start ?? undefined,
        to: opsGoal.value.period_end ?? undefined,
        per_page: 100,
      },
    })
    transactions.value = data.data
  } finally {
    opsLoading.value = false
  }
}

async function onAddOperation() {
  const goal = opsGoal.value
  if (!goal?.account_id) return

  const base = {
    amount: opForm.value.amount,
    occurred_at: opForm.value.occurred_at,
    description: opForm.value.description || null,
  }

  let payload: Record<string, unknown>
  if (opForm.value.type === 'transfer') {
    if (opForm.value.from_account_id === '') return
    payload = {
      ...base,
      type: 'transfer',
      account_id: opForm.value.from_account_id,
      transfer_account_id: goal.account_id,
    }
  } else {
    payload = {
      ...base,
      type: opForm.value.type,
      account_id: goal.account_id,
      category_id: opForm.value.category_id === '' ? null : opForm.value.category_id,
    }
  }

  await api.post('/transactions', payload)
  resetOpForm()
  await loadOps()
  await refresh()
  syncOpenGoal()
}

async function onDeleteOperation(t: Transaction) {
  if (!(await confirmAction('Eliminare questa operazione? Sparirà anche dai movimenti generali.'))) return
  await api.delete(`/transactions/${t.id}`)
  await loadOps()
  await refresh()
  syncOpenGoal()
}

// dopo un refresh, riallinea il goal aperto nella modale con i dati aggiornati
function syncOpenGoal() {
  if (!opsGoal.value) return
  const updated = items.value.find((g) => g.id === opsGoal.value!.id)
  if (updated) opsGoal.value = updated
}

// --- Helpers ---------------------------------------------------------------
function accountName(id: number | null): string {
  if (id === null) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

// Importo con segno di una transazione rispetto al conto dell'obiettivo.
function signedFor(t: Transaction, accId: number): number {
  if (t.type === 'income' && t.account_id === accId) return parseFloat(t.amount)
  if (t.type === 'expense' && t.account_id === accId) return -parseFloat(t.amount)
  if (t.type === 'transfer' && t.transfer_account_id === accId) return parseFloat(t.transfer_amount ?? t.amount)
  return -parseFloat(t.amount)
}

const PACE_LABEL: Record<PaceStatus, string> = {
  on_track: 'In linea',
  behind: 'In ritardo',
  overdue: 'Scaduto',
  completed: 'Completato',
}

const PACE_BADGE: Record<PaceStatus, string> = {
  on_track: 'bg-income-100 text-income-700',
  behind: 'bg-warning-100 text-warning-700',
  overdue: 'bg-danger-100 text-danger-700',
  completed: 'bg-primary-100 text-primary-700',
}

function barClass(g: SavingsGoal): string {
  if ((g.progress ?? 0) >= 100) return 'bg-income-500'
  const status = g.pace?.status
  if (status === 'behind') return 'bg-warning-500'
  if (status === 'overdue') return 'bg-danger-500'
  return 'bg-primary-500'
}

const statusBadge: Record<SavingsGoal['status'], string> = {
  active: 'bg-slate-100 text-slate-600',
  completed: 'bg-income-100 text-income-700',
  archived: 'bg-slate-200 text-slate-500',
}

const statusLabel: Record<SavingsGoal['status'], string> = {
  active: 'Attivo',
  completed: 'Completato',
  archived: 'Archiviato',
}

const hasGoals = computed(() => items.value.length > 0)
// "Attivi" è il default: solo Completati/Archiviati contano come filtro da azzerare.
const isFiltered = computed(() => statusFilter.value === 'completed' || statusFilter.value === 'archived')

function resetStatusFilter() {
  statusFilter.value = 'active'
  refresh()
}

onMounted(async () => {
  const [a, c] = await Promise.all([
    api.get<Paginated<Account>>('/accounts', { params: { per_page: 200 } }),
    api.get<Paginated<Category>>('/categories', { params: { per_page: 200 } }),
  ])
  accounts.value = a.data.data
  categories.value = c.data.data
  await refresh()
})
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Obiettivi di risparmio</h1>
        <p class="page-desc">Metti da parte soldi per un traguardo: il progresso si calcola dai movimenti del conto collegato.</p>
      </div>
      <div class="flex items-center gap-2">
        <select
          v-model="statusFilter"
          class="input w-auto"
          aria-label="Filtra per stato"
          @change="refresh"
        >
          <option value="active">Attivi</option>
          <option value="completed">Completati</option>
          <option value="archived">Archiviati</option>
          <option value="all">Tutti</option>
        </select>
        <button class="btn-primary" @click="openNew()">
          Nuovo obiettivo
        </button>
      </div>
    </div>

    <details class="help-panel">
      <summary>Come funziona</summary>
      <ul>
        <li>Il progresso non si registra a parte: è quanto entra meno quanto esce dal conto collegato nel periodo, giroconti compresi, ricalcolato ogni volta che apri la pagina.</li>
        <li>Un obiettivo una tantum conta i movimenti tra inizio e scadenza; uno settimanale, mensile o annuale guarda solo il periodo in corso e riparte da zero al successivo.</li>
        <li>Con una scadenza vedi il ritmo: «In linea» se hai accumulato almeno la quota di tempo già trascorsa, altrimenti «In ritardo», con quanto mettere da parte al mese.</li>
        <li>Le operazioni sono transazioni vere sul conto: le ritrovi in Transazioni e cambiano il saldo; eliminare l'obiettivo invece non tocca nessuna transazione.</li>
      </ul>
    </details>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuovo obiettivo"
      @click="openNew()"
    >+</button>

    <!-- Form creazione / modifica -->
    <AppModal v-slot="{ close }" v-model="showForm" :dirty="dirty" :title="editing ? 'Modifica obiettivo' : 'Nuovo obiettivo'">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 px-4 py-4 sm:px-6">
          <FormErrors
            class="col-span-full"
            :errors="fieldErrors"
            :shown="['name', 'target_amount', 'currency', 'account_id', 'recurrence', 'start_date', 'target_date', 'color', 'status', 'notes']"
          />
          <div class="sm:col-span-2 lg:col-span-1">
            <label class="label">Nome</label>
            <input v-model="form.name" type="text" maxlength="120" class="input" :class="{ 'input-invalid': fieldErrors.name }" required />
            <FieldError :errors="fieldErrors" name="name" />
          </div>
          <div>
            <label class="label">Obiettivo</label>
            <input v-model="form.target_amount" type="number" inputmode="decimal" step="0.01" min="0.01" class="input" :class="{ 'input-invalid': fieldErrors.target_amount }" required />
            <FieldError :errors="fieldErrors" name="target_amount" />
          </div>
          <div>
            <label class="label">Valuta</label>
            <select v-model="form.currency" class="input" :class="{ 'input-invalid': fieldErrors.currency }" aria-describedby="hint-currency">
              <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
            </select>
            <p id="hint-currency" class="field-hint">Di solito è quella del conto collegato; se è diversa il risparmiato viene convertito al cambio di oggi.</p>
            <FieldError :errors="fieldErrors" name="currency" />
          </div>
          <div>
            <label class="label">Conto collegato</label>
            <select v-model="form.account_id" class="input" :class="{ 'input-invalid': fieldErrors.account_id }" aria-describedby="hint-account" @change="onAccountChange">
              <option value="">— (nessuno)</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
            <p id="hint-account" class="field-hint">Il progresso è quanto entra meno quanto esce da questo conto nel periodo. Senza conto resta a zero.</p>
            <FieldError :errors="fieldErrors" name="account_id" />
          </div>
          <div>
            <label class="label">Ricorrenza</label>
            <select v-model="form.recurrence" class="input" :class="{ 'input-invalid': fieldErrors.recurrence }" aria-describedby="hint-recurrence">
              <option v-for="(lbl, key) in RECURRENCE_LABEL" :key="key" :value="key">{{ lbl }}</option>
            </select>
            <p id="hint-recurrence" class="field-hint">Settimanale, mensile o annuale contano solo il periodo in corso e ripartono da zero; una tantum usa le date che scegli.</p>
            <FieldError :errors="fieldErrors" name="recurrence" />
          </div>
          <template v-if="form.recurrence === 'none'">
            <div>
              <label class="label">Inizio periodo</label>
              <input v-model="form.start_date" type="date" class="input" :class="{ 'input-invalid': fieldErrors.start_date }" aria-describedby="hint-start-date" />
              <p id="hint-start-date" class="field-hint">Conta i movimenti da questa data; vuota, da sempre.</p>
              <FieldError :errors="fieldErrors" name="start_date" />
            </div>
            <div>
              <label class="label">Scadenza</label>
              <input v-model="form.target_date" type="date" class="input" :class="{ 'input-invalid': fieldErrors.target_date }" aria-describedby="hint-target-date" />
              <p id="hint-target-date" class="field-hint">Serve a calcolare il ritmo e quanto mettere da parte al mese; senza scadenza il ritmo non compare.</p>
              <FieldError :errors="fieldErrors" name="target_date" />
            </div>
          </template>
          <div>
            <label class="label">Colore</label>
            <input v-model="form.color" type="color" class="input h-10 p-1" :class="{ 'input-invalid': fieldErrors.color }" />
            <FieldError :errors="fieldErrors" name="color" />
          </div>
          <div>
            <label class="label">Stato</label>
            <select v-model="form.status" class="input" :class="{ 'input-invalid': fieldErrors.status }" aria-describedby="hint-status">
              <option value="active">Attivo</option>
              <option value="completed">Completato</option>
              <option value="archived">Archiviato</option>
            </select>
            <p id="hint-status" class="field-hint">Lo cambi tu: raggiungere l'importo non segna l'obiettivo come completato.</p>
            <FieldError :errors="fieldErrors" name="status" />
          </div>
          <div class="sm:col-span-2 lg:col-span-3">
            <label class="label">Note</label>
            <textarea v-model="form.notes" rows="2" maxlength="2000" class="input" :class="{ 'input-invalid': fieldErrors.notes }"></textarea>
            <FieldError :errors="fieldErrors" name="notes" />
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

    <div v-if="loading && !hasGoals" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" aria-busy="true" aria-label="Caricamento">
      <div v-for="i in 3" :key="i" class="card h-56 animate-pulse bg-slate-100" />
    </div>

    <div v-else-if="!hasGoals" class="card">
      <EmptyState
        :title="statusFilter === 'all' ? 'Non hai ancora creato obiettivi di risparmio.' : 'Non hai obiettivi attivi. Creane uno per risparmiare verso un traguardo.'"
        :filtered="isFiltered"
        @reset="resetStatusFilter()"
      >
        <button type="button" class="btn-primary" @click="openNew()">Nuovo obiettivo</button>
      </EmptyState>
    </div>

    <!-- Griglia obiettivi -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" :class="{ 'opacity-60': loading }">
      <div v-for="g in items" :key="g.id" class="card p-4 flex flex-col gap-3">
        <div class="flex items-start justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <span
              class="inline-block w-3 h-3 rounded-full shrink-0"
              :style="{ backgroundColor: g.color ?? PRIMARY_COLOR }"
              aria-hidden="true"
            />
            <h2 class="font-semibold truncate">{{ g.name }}</h2>
          </div>
          <span class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap" :class="statusBadge[g.status]">
            {{ statusLabel[g.status] }}
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <span class="px-1.5 py-0.5 rounded bg-slate-100">{{ RECURRENCE_LABEL[g.recurrence] }}</span>
          <span v-if="g.account_id">· {{ accountName(g.account_id) }}</span>
          <span v-else class="text-warning-700">· nessun conto collegato</span>
        </div>

        <div class="flex items-baseline justify-between gap-2 text-sm">
          <Amount class="text-lg font-semibold" :value="g.saved ?? 0" :currency="g.currency" />
          <span class="text-slate-500">di <span class="num">{{ money(g.target_amount, g.currency) }}</span></span>
        </div>

        <div>
          <div class="bg-slate-200 rounded h-2.5">
            <div
              class="h-2.5 rounded transition-all"
              :class="barClass(g)"
              :style="{ width: Math.min(100, g.progress ?? 0) + '%' }"
            />
          </div>
          <div class="flex items-center justify-between mt-1 text-xs text-slate-500">
            <span>{{ (g.progress ?? 0).toFixed(0) }}%</span>
            <span>Mancano <span class="num">{{ money(g.remaining, g.currency) }}</span></span>
          </div>
        </div>

        <!-- Ritmo / scadenza -->
        <div v-if="g.pace" class="flex flex-wrap items-center gap-2 text-xs">
          <span class="px-1.5 py-0.5 rounded font-medium" :class="PACE_BADGE[g.pace.status]">
            {{ PACE_LABEL[g.pace.status] }}
          </span>
          <span v-if="g.pace.status !== 'completed'" class="text-slate-500">
            entro {{ formatDate(g.pace.target_date) }} ·
            <template v-if="g.pace.status === 'overdue'">scaduto</template>
            <template v-else-if="g.pace.months_left > 0">
              <span class="num">{{ money(g.pace.required_per_month, g.currency) }}</span>/mese ({{ g.pace.months_left }} mesi)
            </template>
            <template v-else><span class="num">{{ money(g.pace.required_per_month, g.currency) }}</span> entro la scadenza</template>
          </span>
        </div>
        <div v-else class="text-xs text-slate-500">Nessuna scadenza</div>

        <div class="flex items-center justify-between gap-2 mt-auto pt-2 border-t border-slate-100">
          <button
            class="btn-secondary text-sm py-1.5"
            :disabled="!g.account_id"
            :title="g.account_id ? '' : 'Collega un conto per registrare operazioni'"
            @click="openOps(g)"
          >
            Operazioni
          </button>
          <RowActions @edit="startEdit(g)" @delete="onDelete(g)" />
        </div>
      </div>
    </div>

    <!-- Modale operazioni -->
    <div
      v-if="opsGoal"
      class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      @click.self="closeOps"
    >
      <div class="card w-full max-w-2xl my-8 p-5 space-y-4">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-lg font-semibold">Operazioni — {{ opsGoal.name }}</h2>
            <p class="text-sm text-slate-500">
              {{ accountName(opsGoal.account_id) }} ·
              <span class="num">{{ money(opsGoal.saved, opsGoal.currency) }}</span> di
              <span class="num">{{ money(opsGoal.target_amount, opsGoal.currency) }}</span>
              <span v-if="opsGoal.period_start || opsGoal.period_end" class="block text-xs">
                Periodo: {{ opsGoal.period_start ? formatDate(opsGoal.period_start) : '…' }} →
                {{ opsGoal.period_end ? formatDate(opsGoal.period_end) : '…' }}
              </span>
            </p>
            <p class="text-xs text-slate-500 mt-1">
              Ogni operazione è una transazione vera: un trasferimento sposta soldi da un altro conto a questo, entrate e uscite agiscono solo su questo conto.
            </p>
          </div>
          <button
            type="button"
            class="icon-btn text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:ring-primary-500"
            aria-label="Chiudi"
            @click="closeOps"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-5 h-5">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
            </svg>
          </button>
        </div>

        <!-- Form aggiunta operazione -->
        <form class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end" @submit.prevent="onAddOperation">
          <div>
            <label class="label">Tipo</label>
            <select v-model="opForm.type" class="input">
              <option v-for="t in OP_TYPES" :key="t" :value="t">{{ TX_TYPE_LABEL[t] }}</option>
            </select>
          </div>
          <div>
            <label class="label">Importo</label>
            <input v-model="opForm.amount" type="number" step="0.01" min="0.01" class="input" required />
          </div>
          <div>
            <label class="label">Data</label>
            <input v-model="opForm.occurred_at" type="date" class="input" required />
          </div>
          <div v-if="opForm.type === 'transfer'">
            <label class="label">Dal conto</label>
            <select v-model="opForm.from_account_id" class="input" required>
              <option value="">—</option>
              <option v-for="a in transferSources" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>
          <div v-else>
            <label class="label">Categoria</label>
            <select v-model="opForm.category_id" class="input">
              <option value="">—</option>
              <option v-for="c in opCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="col-span-2 sm:col-span-1">
            <button type="submit" class="btn-primary w-full">Aggiungi</button>
          </div>
          <div class="col-span-2 sm:col-span-5">
            <input
              v-model="opForm.description"
              type="text"
              maxlength="255"
              placeholder="Descrizione (opzionale)"
              class="input"
            />
          </div>
        </form>

        <!-- Lista operazioni del periodo -->
        <div class="table-responsive md:overflow-x-auto border-t border-slate-100 pt-2">
          <ListSkeleton v-if="opsLoading" :rows="3" />
          <table v-else class="table">
            <thead class="bg-slate-100">
              <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th class="text-right">Importo</th>
                <th>Descrizione</th>
                <th></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="t in transactions" :key="t.id">
                <td data-label="Data">{{ formatDate(t.occurred_at) }}</td>
                <td data-label="Tipo">
                  <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">
                    {{ TX_TYPE_LABEL[t.type] }}
                  </span>
                </td>
                <td data-label="Importo" class="md:text-right font-medium">
                  <Amount :value="signedFor(t, opsGoal.account_id!)" :currency="opsGoal.currency" signed />
                </td>
                <td data-label="Descrizione" class="text-slate-500">{{ t.description ?? '—' }}</td>
                <td class="md:text-right actions-cell">
                  <button class="icon-btn icon-btn-delete" aria-label="Elimina" @click="onDeleteOperation(t)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                      <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443a48.7 48.7 0 0 0-3.722.387.75.75 0 1 0 .244 1.48l.04-.005.43 9.46A3 3 0 0 0 5.99 18.5h8.02a3 3 0 0 0 2.998-2.985l.43-9.46.04.005a.75.75 0 1 0 .244-1.48 48.7 48.7 0 0 0-3.722-.387V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325c.827-.05 1.66-.075 2.5-.075Z" clip-rule="evenodd" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="transactions.length === 0">
                <td colspan="5" class="whitespace-normal">
                  <EmptyState title="Nessuna operazione nel periodo. Aggiungine una qui sopra." />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
