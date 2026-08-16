<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import { formatCurrency } from '@/lib/money'
import type { Account, Cadence, Paginated, RecurringTransaction, TransactionType } from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<RecurringTransaction>('recurring-transactions')

const accounts = ref<Account[]>([])

const cadences: Cadence[] = ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly']

const editing = ref<RecurringTransaction | null>(null)
const showForm = ref(false)
const form = ref({
  account_id: 0,
  transfer_account_id: null as number | null,
  type: 'expense' as TransactionType,
  amount: '',
  cadence: 'monthly' as Cadence,
  interval: 1,
  starts_on: new Date().toISOString().slice(0, 10),
  ends_on: '',
  description: '',
  is_active: true,
})

function reset() {
  editing.value = null
  form.value = {
    account_id: accounts.value.find((a) => a.is_primary)?.id ?? accounts.value[0]?.id ?? 0,
    transfer_account_id: null,
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
  form.value = {
    account_id: r.account_id,
    transfer_account_id: r.transfer_account_id,
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
  if (form.value.type === 'transfer') {
    payload.transfer_account_id = form.value.transfer_account_id
  } else {
    payload.transfer_account_id = null
  }
  if (editing.value) {
    await update(editing.value.id, payload)
  } else {
    await create(payload)
  }
  reset()
  showForm.value = false
}

async function onDelete(r: RecurringTransaction) {
  if (!confirm('Eliminare la ricorrente?')) return
  await destroy(r.id)
}

onMounted(async () => {
  const a = await api.get<Paginated<Account>>('/accounts', { params: { per_page: 100 } })
  accounts.value = a.data.data
  form.value.account_id = accounts.value[0]?.id ?? 0
  await list()
})
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Transazioni ricorrenti</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuova ricorrente' }}
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuova ricorrente"
      @click="showForm = true; reset()"
    >+</button>

    <form v-if="showForm" class="card p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4" @submit.prevent="onSubmit">
      <div>
        <label class="label">Tipo</label>
        <select v-model="form.type" class="input">
          <option value="expense">expense</option>
          <option value="income">income</option>
          <option value="transfer">transfer</option>
        </select>
      </div>
      <div>
        <label class="label">Conto</label>
        <select v-model.number="form.account_id" class="input" required>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.is_primary ? ' ★' : '' }}</option>
        </select>
      </div>
      <div v-if="form.type === 'transfer'">
        <label class="label">Conto destinazione</label>
        <select v-model.number="form.transfer_account_id" class="input" required>
          <option v-for="a in accounts.filter((a) => a.id !== form.account_id)" :key="a.id" :value="a.id">
            {{ a.name }}{{ a.is_primary ? ' ★' : '' }}
          </option>
        </select>
      </div>
      <div>
        <label class="label">Cadenza</label>
        <select v-model="form.cadence" class="input">
          <option v-for="c in cadences" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div>
        <label class="label">Intervallo</label>
        <input v-model.number="form.interval" type="number" min="1" max="255" class="input" />
      </div>
      <div>
        <label class="label">Importo</label>
        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="input" required />
      </div>
      <div>
        <label class="label">Inizio</label>
        <input v-model="form.starts_on" type="date" class="input" required />
      </div>
      <div>
        <label class="label">Fine (opzionale)</label>
        <input v-model="form.ends_on" type="date" class="input" />
      </div>
      <div class="sm:col-span-2 md:col-span-3">
        <label class="label">Descrizione</label>
        <input v-model="form.description" class="input" />
      </div>
      <div class="sm:col-span-2 md:col-span-3 flex items-center gap-2">
        <input id="is_active" v-model="form.is_active" type="checkbox" />
        <label for="is_active" class="text-sm">Attiva</label>
      </div>
      <div class="sm:col-span-2 md:col-span-3 flex flex-col sm:flex-row gap-2 sm:justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <div class="card">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>

      <!-- Mobile: una card per ricorrente, troppi campi per il collasso label/valore generico (sotto md). -->
      <ul v-else class="md:hidden divide-y divide-slate-100">
        <li v-for="r in items" :key="r.id" class="p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium text-slate-800 truncate">
                {{ r.description ?? '—' }}
                <span :class="r.is_active ? 'text-green-600' : 'text-slate-400'" class="ml-1">●</span>
              </p>
              <p class="text-xs text-slate-500 mt-0.5 truncate capitalize">
                {{ r.type }} · {{ accountName(r.account_id) }}<span v-if="isPrimaryAccount(r.account_id)" class="text-amber-500">★</span>
                <template v-if="r.type === 'transfer'"> → {{ accountName(r.transfer_account_id) }}</template>
              </p>
              <p class="text-xs text-slate-400 mt-0.5">
                ogni {{ r.interval }} {{ r.cadence }} · prossima {{ r.next_run_at }}
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="font-semibold whitespace-nowrap">{{ formatCurrency(r.amount, r.currency) }}</p>
              <RowActions class="mt-2 justify-end" @edit="startEdit(r)" @delete="onDelete(r)" />
            </div>
          </div>
        </li>
        <li v-if="items.length === 0" class="p-6 text-center text-slate-500 text-sm">Nessuna ricorrente.</li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!loading" class="table hidden md:table">
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
            <td class="capitalize">{{ r.type }}</td>
            <td>
              <span class="inline-flex items-center gap-2">
                <span>{{ accountName(r.account_id) }}</span>
                <span v-if="isPrimaryAccount(r.account_id)" class="text-amber-500" title="Conto principale">★</span>
              </span>
              <span v-if="r.type === 'transfer'" class="text-slate-400"> → {{ accountName(r.transfer_account_id) }}</span>
            </td>
            <td>every {{ r.interval }} {{ r.cadence }}</td>
            <td>{{ r.next_run_at }}</td>
            <td class="text-right font-medium">{{ formatCurrency(r.amount, r.currency) }}</td>
            <td>
              <span :class="r.is_active ? 'text-green-600' : 'text-slate-400'">●</span>
            </td>
            <td class="text-right">
              <RowActions @edit="startEdit(r)" @delete="onDelete(r)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="8" class="text-center text-slate-500 py-6">Nessuna ricorrente.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
