<script setup lang="ts">
import { formatDate } from '@/lib/date'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import FieldError from '@/components/ui/FieldError.vue'
import Amount from '@/components/ui/Amount.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import { useToastStore } from '@/stores/toast'
import RowActions from '@/components/ui/RowActions.vue'
import { CADENCE_LABEL, TX_TYPE_LABEL, TX_TYPES, cadenceText } from '@/lib/labels'
import type { Account, Cadence, InvestmentHolding, Paginated, RecurringTransaction, TransactionType } from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<RecurringTransaction>('recurring-transactions')
const toast = useToastStore()

const accounts = ref<Account[]>([])
const holdings = ref<InvestmentHolding[]>([])

const cadences = Object.keys(CADENCE_LABEL) as Cadence[]

const editing = ref<RecurringTransaction | null>(null)
const showForm = ref(false)
const form = ref({
  account_id: 0,
  transfer_account_id: null as number | null,
  investment_holding_id: null as number | null,
  investment_fees: '',
  type: 'expense' as TransactionType,
  amount: '',
  cadence: 'monthly' as Cadence,
  interval: 1,
  starts_on: new Date().toISOString().slice(0, 10),
  ends_on: '',
  description: '',
  is_active: true,
})
const dirty = useFormDirty(form, showForm)

function reset() {
  editing.value = null
  fieldErrors.value = {}
  form.value = {
    account_id: accounts.value.find((a) => a.is_primary)?.id ?? accounts.value[0]?.id ?? 0,
    transfer_account_id: null,
    investment_holding_id: null,
    investment_fees: '',
    type: 'expense',
    amount: '',
    cadence: 'monthly',
    interval: 1,
    starts_on: new Date().toISOString().slice(0, 10),
    ends_on: '',
    description: '',
    is_active: true,
  }
}

function startEdit(r: RecurringTransaction) {
  editing.value = r
  fieldErrors.value = {}
  form.value = {
    account_id: r.account_id,
    transfer_account_id: r.transfer_account_id,
    investment_holding_id: r.investment_holding_id,
    investment_fees: parseFloat(r.investment_fees) > 0 ? r.investment_fees : '',
    type: r.type,
    amount: r.amount,
    cadence: r.cadence,
    interval: r.interval,
    starts_on: r.starts_on,
    ends_on: r.ends_on ?? '',
    description: r.description ?? '',
    is_active: r.is_active,
  }
  showForm.value = true
}

function openNew() {
  reset()
  showForm.value = true
}

function accountName(id: number | null): string {
  if (!id) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

function isPrimaryAccount(id: number | null): boolean {
  if (!id) return false
  return !!accounts.value.find((a) => a.id === id && a.is_primary)
}

async function onSubmit() {
  const payload: Record<string, unknown> = {
    account_id: form.value.account_id,
    type: form.value.type,
    amount: form.value.amount,
    cadence: form.value.cadence,
    interval: form.value.interval,
    starts_on: form.value.starts_on,
    ends_on: form.value.ends_on || null,
    description: form.value.description,
    is_active: form.value.is_active,
  }
  payload.investment_holding_id = form.value.type === 'income' ? null : form.value.investment_holding_id
  payload.investment_fees = payload.investment_holding_id ? form.value.investment_fees || 0 : 0
  if (form.value.type === 'transfer') {
    payload.transfer_account_id = form.value.transfer_account_id
  } else {
    payload.transfer_account_id = null
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
  toast.success('Ricorrente salvata.')
  reset()
  showForm.value = false
}

async function onDelete(r: RecurringTransaction) {
  if (!(await confirmAction('Eliminare la ricorrente?'))) return
  await destroy(r.id)
}

onMounted(async () => {
  const [a, h] = await Promise.all([
    api.get<Paginated<Account>>('/accounts', { params: { per_page: 100 } }),
    api.get<Paginated<InvestmentHolding>>('/investment-holdings', { params: { per_page: 100 } }),
  ])
  accounts.value = a.data.data
  holdings.value = h.data.data
  form.value.account_id = accounts.value[0]?.id ?? 0
  await list()
})
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Transazioni ricorrenti</h1>
      <button class="btn-primary" @click="openNew()">
        Nuova ricorrente
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuova ricorrente"
      @click="openNew()"
    >+</button>

    <AppModal v-slot="{ close }" v-model="showForm" :dirty="dirty" :title="editing ? 'Modifica ricorrente' : 'Nuova ricorrente'">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 px-4 py-4 sm:px-6">
          <FormErrors
            class="col-span-full"
            :errors="fieldErrors"
            :shown="['type', 'account_id', 'transfer_account_id', 'cadence', 'interval', 'amount', 'investment_holding_id', 'investment_fees', 'starts_on', 'ends_on', 'description', 'is_active']"
          />
          <div>
            <label class="label">Tipo</label>
            <select v-model="form.type" class="input" :class="{ 'input-invalid': fieldErrors.type }">
              <option v-for="t in TX_TYPES" :key="t" :value="t">{{ TX_TYPE_LABEL[t] }}</option>
            </select>
            <FieldError :errors="fieldErrors" name="type" />
          </div>
          <div>
            <label class="label">Conto</label>
            <select v-model.number="form.account_id" class="input" :class="{ 'input-invalid': fieldErrors.account_id }" required>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.is_primary ? ' ★' : '' }}</option>
            </select>
            <FieldError :errors="fieldErrors" name="account_id" />
          </div>
          <div v-if="form.type === 'transfer'">
            <label class="label">Conto destinazione</label>
            <select v-model.number="form.transfer_account_id" class="input" :class="{ 'input-invalid': fieldErrors.transfer_account_id }" required>
              <option v-for="a in accounts.filter((a) => a.id !== form.account_id)" :key="a.id" :value="a.id">
                {{ a.name }}{{ a.is_primary ? ' ★' : '' }}
              </option>
            </select>
            <FieldError :errors="fieldErrors" name="transfer_account_id" />
          </div>
          <div>
            <label class="label">Cadenza</label>
            <select v-model="form.cadence" class="input" :class="{ 'input-invalid': fieldErrors.cadence }">
              <option v-for="c in cadences" :key="c" :value="c">{{ CADENCE_LABEL[c] }}</option>
            </select>
            <FieldError :errors="fieldErrors" name="cadence" />
          </div>
          <div>
            <label class="label">Intervallo</label>
            <input v-model.number="form.interval" type="number" min="1" max="255" class="input" :class="{ 'input-invalid': fieldErrors.interval }" />
            <FieldError :errors="fieldErrors" name="interval" />
          </div>
          <div>
            <label class="label">Importo</label>
            <input v-model="form.amount" type="number" inputmode="decimal" step="0.01" min="0.01" class="input" :class="{ 'input-invalid': fieldErrors.amount }" required />
            <FieldError :errors="fieldErrors" name="amount" />
          </div>
          <div v-if="form.type !== 'income'">
            <label class="label">Investimento PAC (opzionale)</label>
            <select v-model.number="form.investment_holding_id" class="input" :class="{ 'input-invalid': fieldErrors.investment_holding_id }">
              <option :value="null">— nessuno —</option>
              <option v-for="h in holdings" :key="h.id" :value="h.id">{{ h.name }}</option>
            </select>
            <FieldError :errors="fieldErrors" name="investment_holding_id" />
            <p class="text-xs text-slate-500 mt-1">A ogni scadenza registra l'acquisto: quote = (importo − costi) / quotazione del giorno.</p>
          </div>
          <div v-if="form.type !== 'income' && form.investment_holding_id">
            <label class="label">Costi per rata</label>
            <input v-model="form.investment_fees" type="number" step="0.01" min="0" class="input" :class="{ 'input-invalid': fieldErrors.investment_fees }" placeholder="0,00" />
            <FieldError :errors="fieldErrors" name="investment_fees" />
            <p class="text-xs text-slate-500 mt-1">Commissioni già comprese nell'importo della rata.</p>
          </div>
          <div>
            <label class="label">Inizio</label>
            <input v-model="form.starts_on" type="date" class="input" :class="{ 'input-invalid': fieldErrors.starts_on }" required />
            <FieldError :errors="fieldErrors" name="starts_on" />
          </div>
          <div>
            <label class="label">Fine (opzionale)</label>
            <input v-model="form.ends_on" type="date" class="input" :class="{ 'input-invalid': fieldErrors.ends_on }" />
            <FieldError :errors="fieldErrors" name="ends_on" />
          </div>
          <div class="sm:col-span-2 md:col-span-3">
            <label class="label">Descrizione</label>
            <input v-model="form.description" class="input" :class="{ 'input-invalid': fieldErrors.description }" />
            <FieldError :errors="fieldErrors" name="description" />
          </div>
          <div class="sm:col-span-2 md:col-span-3">
            <div class="flex items-center gap-2">
              <input id="is_active" v-model="form.is_active" type="checkbox" />
              <label for="is_active" class="text-sm">Attiva</label>
            </div>
            <FieldError :errors="fieldErrors" name="is_active" />
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

      <!-- Mobile: una card per ricorrente, troppi campi per il collasso label/valore generico (sotto md). -->
      <ul v-else class="md:hidden divide-y divide-slate-100" :class="{ 'opacity-60': loading }">
        <li v-for="r in items" :key="r.id" class="p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium text-slate-800 truncate">
                {{ r.description ?? '—' }}
                <span
                  :class="r.is_active ? 'text-income-600' : 'text-slate-500'"
                  class="ml-1"
                  :title="r.is_active ? 'Attiva' : 'Inattiva'"
                  :aria-label="r.is_active ? 'Attiva' : 'Inattiva'"
                >●</span>
              </p>
              <p class="text-xs text-slate-500 mt-0.5 truncate">
                {{ TX_TYPE_LABEL[r.type] }} · {{ accountName(r.account_id) }}<span v-if="isPrimaryAccount(r.account_id)" class="text-warning-500">★</span>
                <template v-if="r.type === 'transfer'"> → {{ accountName(r.transfer_account_id) }}</template>
              </p>
              <p class="text-xs text-slate-500 mt-0.5">
                {{ cadenceText(r.interval, r.cadence) }} · prossima {{ formatDate(r.next_run_at) }}
              </p>
            </div>
            <div class="text-right shrink-0">
              <Amount class="block font-semibold" :value="r.amount" :currency="r.currency" :type="r.type" />
              <RowActions class="mt-2 justify-end" @edit="startEdit(r)" @delete="onDelete(r)" />
            </div>
          </div>
        </li>
        <li v-if="items.length === 0">
          <EmptyState title="Non hai ancora creato transazioni ricorrenti.">
            <button type="button" class="btn-primary" @click="openNew()">Crea la prima</button>
          </EmptyState>
        </li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!(loading && !items.length)" class="table hidden md:table" :class="{ 'opacity-60': loading }">
        <thead class="bg-slate-100">
          <tr>
            <th>Descrizione</th>
            <th>Tipo</th>
            <th>Conto</th>
            <th>Cadenza</th>
            <th>Prossima</th>
            <th class="text-right">Importo</th>
            <th>Attiva</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in items" :key="r.id">
            <td>{{ r.description ?? '—' }}</td>
            <td>{{ TX_TYPE_LABEL[r.type] }}</td>
            <td>
              <span class="inline-flex items-center gap-2">
                <span>{{ accountName(r.account_id) }}</span>
                <span v-if="isPrimaryAccount(r.account_id)" class="text-warning-500" title="Conto principale">★</span>
              </span>
              <span v-if="r.type === 'transfer'" class="text-slate-500"> → {{ accountName(r.transfer_account_id) }}</span>
            </td>
            <td>{{ cadenceText(r.interval, r.cadence) }}</td>
            <td>{{ formatDate(r.next_run_at) }}</td>
            <td class="text-right font-medium"><Amount :value="r.amount" :currency="r.currency" :type="r.type" /></td>
            <td>
              <span
                :class="r.is_active ? 'text-income-600' : 'text-slate-500'"
                :title="r.is_active ? 'Attiva' : 'Inattiva'"
                :aria-label="r.is_active ? 'Attiva' : 'Inattiva'"
              >●</span>
            </td>
            <td class="text-right">
              <RowActions @edit="startEdit(r)" @delete="onDelete(r)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="8" class="whitespace-normal">
              <EmptyState title="Non hai ancora creato transazioni ricorrenti.">
                <button type="button" class="btn-primary" @click="openNew()">Crea la prima</button>
              </EmptyState>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
