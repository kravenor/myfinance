<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import { useAuthStore } from '@/stores/auth'
import { CURRENCIES, formatCurrency as money } from '@/lib/money'
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

const auth = useAuthStore()
const { items, loading, list, create, update, destroy } = useCrud<SavingsGoal>('savings-goals')

const accounts = ref<Account[]>([])
const categories = ref<Category[]>([])
const statusFilter = ref<'active' | 'completed' | 'archived' | 'all'>('active')

const today = new Date().toISOString().slice(0, 10)

const RECURRENCE_LABEL: Record<SavingsGoalRecurrence, string> = {
  none: 'Spot (una tantum)',
  weekly: 'Settimanale',
  monthly: 'Mensile',
  yearly: 'Annuale',
}

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
  color: '#6366f1',
  status: 'active' as SavingsGoal['status'],
  notes: '',
})

function resetForm() {
  editing.value = null
  form.value = {
    name: '',
    target_amount: '',
    currency: auth.user?.currency ?? 'EUR',
    account_id: '',
    recurrence: 'none',
    start_date: '',
    target_date: '',
    color: '#6366f1',
    status: 'active',
    notes: '',
  }
}

function startEdit(g: SavingsGoal) {
  editing.value = g
  form.value = {
    name: g.name,
    target_amount: g.target_amount,
    currency: g.currency,
    account_id: g.account_id ?? '',
    recurrence: g.recurrence,
    start_date: g.start_date ?? '',
    target_date: g.target_date ?? '',
    color: g.color ?? '#6366f1',
    status: g.status,
    notes: g.notes ?? '',
  }
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
  if (editing.value) {
    await update(editing.value.id, payload)
  } else {
    await create(payload)
  }
  resetForm()
  showForm.value = false
  await refresh()
}

async function onDelete(g: SavingsGoal) {
  if (!confirm(`Eliminare l'obiettivo "${g.name}"? Le transazioni del conto non vengono toccate.`)) return
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
  if (!confirm('Eliminare questa operazione? Sparirà anche dai movimenti generali.')) return
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

// Importo firmato di una transazione rispetto al conto dell'obiettivo.
function signedFor(t: Transaction, accId: number): { positive: boolean; amount: string } {
  if (t.type === 'income' && t.account_id === accId) return { positive: true, amount: t.amount }
  if (t.type === 'expense' && t.account_id === accId) return { positive: false, amount: t.amount }
  if (t.type === 'transfer' && t.transfer_account_id === accId) {
    return { positive: true, amount: t.transfer_amount ?? t.amount }
  }
  return { positive: false, amount: t.amount }
}

const TYPE_LABEL: Record<TransactionType, string> = {
  income: 'Entrata',
  expense: 'Uscita',
  transfer: 'Trasferimento',
}

const PACE_LABEL: Record<PaceStatus, string> = {
  on_track: 'In linea',
  behind: 'In ritardo',
  overdue: 'Scaduto',
  completed: 'Completato',
}

const PACE_BADGE: Record<PaceStatus, string> = {
  on_track: 'bg-emerald-100 text-emerald-700',
  behind: 'bg-amber-100 text-amber-700',
  overdue: 'bg-red-100 text-red-700',
  completed: 'bg-indigo-100 text-indigo-700',
}

function barClass(g: SavingsGoal): string {
  if ((g.progress ?? 0) >= 100) return 'bg-emerald-500'
  const status = g.pace?.status
  if (status === 'behind') return 'bg-amber-500'
  if (status === 'overdue') return 'bg-red-500'
  return 'bg-indigo-500'
}

const statusBadge: Record<SavingsGoal['status'], string> = {
  active: 'bg-slate-100 text-slate-600',
  completed: 'bg-emerald-100 text-emerald-700',
  archived: 'bg-slate-200 text-slate-500',
}

const statusLabel: Record<SavingsGoal['status'], string> = {
  active: 'Attivo',
  completed: 'Completato',
  archived: 'Archiviato',
}

const hasGoals = computed(() => items.value.length > 0)

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
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Obiettivi di risparmio</h1>
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
        <button class="btn-primary" @click="showForm = !showForm; resetForm()">
          {{ showForm ? 'Annulla' : 'Nuovo obiettivo' }}
        </button>
      </div>
    </div>

    <!-- Form creazione / modifica -->
    <form
      v-if="showForm"
      class="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
      @submit.prevent="onSubmit"
    >
      <div class="sm:col-span-2 lg:col-span-1">
        <label class="label">Nome</label>
        <input v-model="form.name" type="text" maxlength="120" class="input" required />
      </div>
      <div>
        <label class="label">Obiettivo</label>
        <input v-model="form.target_amount" type="number" step="0.01" min="0.01" class="input" required />
      </div>
      <div>
        <label class="label">Valuta</label>
        <select v-model="form.currency" class="input">
          <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div>
        <label class="label">Conto collegato</label>
        <select v-model="form.account_id" class="input">
          <option value="">— (nessuno)</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
        <p class="text-xs text-slate-400 mt-1">Il progresso è il flusso netto su questo conto.</p>
      </div>
      <div>
        <label class="label">Ricorrenza</label>
        <select v-model="form.recurrence" class="input">
          <option v-for="(lbl, key) in RECURRENCE_LABEL" :key="key" :value="key">{{ lbl }}</option>
        </select>
      </div>
      <template v-if="form.recurrence === 'none'">
        <div>
          <label class="label">Inizio periodo</label>
          <input v-model="form.start_date" type="date" class="input" />
        </div>
        <div>
          <label class="label">Scadenza</label>
          <input v-model="form.target_date" type="date" class="input" />
        </div>
      </template>
      <div>
        <label class="label">Colore</label>
        <input v-model="form.color" type="color" class="input h-10 p-1" />
      </div>
      <div>
        <label class="label">Stato</label>
        <select v-model="form.status" class="input">
          <option value="active">Attivo</option>
          <option value="completed">Completato</option>
          <option value="archived">Archiviato</option>
        </select>
      </div>
      <div class="sm:col-span-2 lg:col-span-3">
        <label class="label">Note</label>
        <textarea v-model="form.notes" rows="2" maxlength="2000" class="input"></textarea>
      </div>
      <div class="sm:col-span-2 lg:col-span-3 flex flex-col sm:flex-row gap-2 sm:justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; resetForm()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <p v-if="loading" class="text-sm text-slate-500">Caricamento…</p>

    <p v-else-if="!hasGoals" class="card p-8 text-center text-slate-500">
      Nessun obiettivo. Creane uno per iniziare a risparmiare verso un traguardo.
    </p>

    <!-- Griglia obiettivi -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="g in items" :key="g.id" class="card p-4 flex flex-col gap-3">
        <div class="flex items-start justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <span
              class="inline-block w-3 h-3 rounded-full shrink-0"
              :style="{ backgroundColor: g.color ?? '#6366f1' }"
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
          <span v-else class="text-amber-600">· nessun conto collegato</span>
        </div>

        <div class="flex items-baseline justify-between gap-2 text-sm">
          <span class="text-lg font-semibold">{{ money(g.saved, g.currency) }}</span>
          <span class="text-slate-500">/ {{ money(g.target_amount, g.currency) }}</span>
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
            <span>Mancano {{ money(g.remaining, g.currency) }}</span>
          </div>
        </div>

        <!-- Ritmo / scadenza -->
        <div v-if="g.pace" class="flex flex-wrap items-center gap-2 text-xs">
          <span class="px-1.5 py-0.5 rounded font-medium" :class="PACE_BADGE[g.pace.status]">
            {{ PACE_LABEL[g.pace.status] }}
          </span>
          <span v-if="g.pace.status !== 'completed'" class="text-slate-500">
            entro {{ g.pace.target_date }} ·
            <template v-if="g.pace.status === 'overdue'">scaduto</template>
            <template v-else-if="g.pace.months_left > 0">
              {{ money(g.pace.required_per_month, g.currency) }}/mese ({{ g.pace.months_left }} mesi)
            </template>
            <template v-else>{{ money(g.pace.required_per_month, g.currency) }} entro la scadenza</template>
          </span>
        </div>
        <div v-else class="text-xs text-slate-400">Nessuna scadenza</div>

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
              {{ money(opsGoal.saved, opsGoal.currency) }} su {{ money(opsGoal.target_amount, opsGoal.currency) }}
              <span v-if="opsGoal.period_start || opsGoal.period_end" class="block text-xs">
                Periodo: {{ opsGoal.period_start ?? '…' }} → {{ opsGoal.period_end ?? '…' }}
              </span>
            </p>
          </div>
          <button class="icon-btn icon-btn-delete" aria-label="Chiudi" @click="closeOps">
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
              <option value="transfer">Trasferimento</option>
              <option value="income">Entrata</option>
              <option value="expense">Uscita</option>
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
          <p v-if="opsLoading" class="p-3 text-sm text-slate-500">Caricamento…</p>
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
                <td data-label="Data">{{ t.occurred_at }}</td>
                <td data-label="Tipo">
                  <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">
                    {{ TYPE_LABEL[t.type] }}
                  </span>
                </td>
                <td data-label="Importo" class="md:text-right font-medium">
                  <span :class="signedFor(t, opsGoal.account_id!).positive ? 'text-emerald-600' : 'text-red-600'">
                    {{ signedFor(t, opsGoal.account_id!).positive ? '+' : '−'
                    }}{{ money(signedFor(t, opsGoal.account_id!).amount, opsGoal.currency) }}
                  </span>
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
                <td colspan="5" class="text-center text-slate-500 py-6">Nessuna operazione nel periodo.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
