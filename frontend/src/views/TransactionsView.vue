<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import type { Account, Category, Paginated, Tag, Transaction, TransactionType } from '@/types/api'

const { items, loading, meta, list, create, update, destroy } = useCrud<Transaction>('transactions')

const accounts = ref<Account[]>([])
const categories = ref<Category[]>([])
const tags = ref<Tag[]>([])

const filters = ref({ account_id: '', type: '', from: '', to: '', search: '', tag_id: '' })
const page = ref(1)

const editing = ref<Transaction | null>(null)
const showForm = ref(false)
const form = ref({
  account_id: 0,
  category_id: null as number | null,
  transfer_account_id: null as number | null,
  type: 'expense' as TransactionType,
  amount: '',
  occurred_at: new Date().toISOString().slice(0, 10),
  description: '',
  tag_ids: [] as number[],
})

function toggleTag(id: number) {
  const i = form.value.tag_ids.indexOf(id)
  if (i === -1) form.value.tag_ids.push(id)
  else form.value.tag_ids.splice(i, 1)
}

const filteredCategories = computed(() =>
  categories.value.filter((c) => (form.value.type === 'transfer' ? false : c.type === (form.value.type as 'income' | 'expense')))
)

function accountName(id: number | null | undefined): string {
  if (!id) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

function isPrimaryAccount(id: number | null | undefined): boolean {
  if (!id) return false
  return !!accounts.value.find((a) => a.id === id && a.is_primary)
}

function reset() {
  editing.value = null
  form.value = {
    account_id: accounts.value.find((a) => a.is_primary)?.id ?? accounts.value[0]?.id ?? 0,
    category_id: null,
    transfer_account_id: null,
    type: 'expense',
    amount: '',
    occurred_at: new Date().toISOString().slice(0, 10),
    description: '',
    tag_ids: [],
  }
}

function startEdit(tx: Transaction) {
  editing.value = tx
  form.value = {
    account_id: tx.account_id,
    category_id: tx.category_id,
    transfer_account_id: tx.transfer_account_id,
    type: tx.type,
    amount: tx.amount,
    occurred_at: tx.occurred_at,
    description: tx.description ?? '',
    tag_ids: (tx.tags ?? []).map((t) => t.id),
  }
  showForm.value = true
}

async function onSubmit() {
  const payload: Record<string, unknown> = {
    account_id: form.value.account_id,
    category_id: form.value.category_id,
    type: form.value.type,
    amount: form.value.amount,
    occurred_at: form.value.occurred_at,
    description: form.value.description,
    tag_ids: form.value.tag_ids,
  }
  if (form.value.type === 'transfer') {
    payload.transfer_account_id = form.value.transfer_account_id
    payload.category_id = null
  }
  const wasEditing = editing.value !== null
  if (editing.value) {
    await update(editing.value.id, payload)
  } else {
    await create(payload)
  }
  reset()
  showForm.value = false
  await applyFilters(!wasEditing)
}

async function onDelete(tx: Transaction) {
  if (!confirm('Eliminare la transazione?')) return
  await destroy(tx.id)
  await applyFilters(false)
}

async function applyFilters(resetPage = true) {
  if (resetPage) page.value = 1
  const params: Record<string, unknown> = { page: page.value }
  if (filters.value.account_id) params.account_id = filters.value.account_id
  if (filters.value.type) params.type = filters.value.type
  if (filters.value.from) params.from = filters.value.from
  if (filters.value.to) params.to = filters.value.to
  if (filters.value.search.trim()) params.search = filters.value.search.trim()
  if (filters.value.tag_id) params.tag_id = filters.value.tag_id
  await list(params)
}

function goToPage(p: number) {
  if (meta.value && (p < 1 || p > meta.value.last_page)) return
  page.value = p
  applyFilters(false)
}

onMounted(async () => {
  const [a, c, t] = await Promise.all([
    api.get<Paginated<Account>>('/accounts', { params: { per_page: 100 } }),
    api.get<Paginated<Category>>('/categories', { params: { per_page: 200 } }),
    api.get<Paginated<Tag>>('/tags', { params: { per_page: 200 } }),
  ])
  accounts.value = a.data.data
  categories.value = c.data.data
  tags.value = t.data.data
  form.value.account_id = accounts.value[0]?.id ?? 0
  await applyFilters()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Transazioni</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuova transazione' }}
      </button>
    </div>

    <form class="card p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3" @submit.prevent="applyFilters()">
      <div class="sm:col-span-2 md:col-span-5">
        <label class="label">Cerca nella descrizione</label>
        <input v-model="filters.search" type="search" class="input" placeholder="Parole chiave…" />
      </div>
      <div>
        <label class="label">Conto</label>
        <select v-model="filters.account_id" class="input">
          <option value="">Tutti</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.is_primary ? ' ★' : '' }}</option>
        </select>
      </div>
      <div>
        <label class="label">Tipo</label>
        <select v-model="filters.type" class="input">
          <option value="">Tutti</option>
          <option value="income">income</option>
          <option value="expense">expense</option>
          <option value="transfer">transfer</option>
        </select>
      </div>
      <div>
        <label class="label">Da</label>
        <input v-model="filters.from" type="date" class="input" />
      </div>
      <div>
        <label class="label">A</label>
        <input v-model="filters.to" type="date" class="input" />
      </div>
      <div>
        <label class="label">Tag</label>
        <select v-model="filters.tag_id" class="input">
          <option value="">Tutti</option>
          <option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
      </div>
      <div class="flex items-end sm:col-span-2 md:col-span-1">
        <button type="submit" class="btn-secondary w-full">Filtra</button>
      </div>
    </form>

    <p v-if="meta" class="text-sm text-slate-500">
      {{ meta.total }} transazion{{ meta.total === 1 ? 'e' : 'i' }}
      <span v-if="meta.total > 0"> · {{ meta.from }}–{{ meta.to }}</span>
    </p>

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
            {{ a.name }}
          </option>
        </select>
      </div>
      <div v-else>
        <label class="label">Categoria</label>
        <select v-model.number="form.category_id" class="input">
          <option :value="null">— Nessuna —</option>
          <option v-for="c in filteredCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="label">Importo</label>
        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="input" required />
      </div>
      <div>
        <label class="label">Data</label>
        <input v-model="form.occurred_at" type="date" class="input" required />
      </div>
      <div class="sm:col-span-2 md:col-span-3">
        <label class="label">Descrizione</label>
        <input v-model="form.description" class="input" />
      </div>
      <div class="sm:col-span-2 md:col-span-3">
        <label class="label">Tag</label>
        <div v-if="tags.length" class="flex flex-wrap gap-2">
          <button
            v-for="t in tags"
            :key="t.id"
            type="button"
            class="px-3 py-1 rounded-full text-sm border transition"
            :class="form.tag_ids.includes(t.id)
              ? 'text-white border-transparent'
              : 'bg-white text-slate-600 border-slate-300 hover:border-slate-400'"
            :style="form.tag_ids.includes(t.id) ? { background: t.color || '#475569' } : {}"
            @click="toggleTag(t.id)"
          >
            {{ t.name }}
          </button>
        </div>
        <p v-else class="text-sm text-slate-400">
          Nessun tag disponibile. Creane in
          <RouterLink class="underline" to="/tags">Tag</RouterLink>.
        </p>
      </div>
      <div class="sm:col-span-2 md:col-span-3 flex flex-col sm:flex-row gap-2 sm:justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <div class="card table-responsive md:overflow-x-auto">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <table v-else class="table">
        <thead class="bg-slate-100">
          <tr>
            <th>Data</th>
            <th>Tipo</th>
            <th>Conto</th>
            <th>Descrizione</th>
            <th>Tag</th>
            <th class="text-right">Importo</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="tx in items" :key="tx.id">
            <td data-label="Data">{{ tx.occurred_at }}</td>
            <td data-label="Tipo" class="capitalize">{{ tx.type }}</td>
            <td data-label="Conto">
              <span class="inline-flex items-center gap-2">
                <span>{{ accountName(tx.account_id) }}</span>
                <span v-if="isPrimaryAccount(tx.account_id)" class="text-amber-500" title="Conto principale">★</span>
              </span>
              <span v-if="tx.type === 'transfer'" class="text-slate-400"> → {{ accountName(tx.transfer_account_id) }}</span>
            </td>
            <td data-label="Descrizione">{{ tx.description ?? '—' }}</td>
            <td data-label="Tag">
              <span v-if="tx.tags && tx.tags.length" class="flex flex-wrap gap-1 md:justify-start justify-end">
                <span
                  v-for="t in tx.tags"
                  :key="t.id"
                  class="inline-block px-2 py-0.5 rounded-full text-xs text-white"
                  :style="{ background: t.color || '#475569' }"
                >{{ t.name }}</span>
              </span>
              <span v-else class="text-slate-400">—</span>
            </td>
            <td data-label="Importo" class="md:text-right font-medium">{{ tx.amount }} {{ tx.currency }}</td>
            <td class="md:text-right actions-cell">
              <RowActions @edit="startEdit(tx)" @delete="onDelete(tx)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="7" class="text-center text-slate-500 py-6">Nessuna transazione.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between gap-3">
      <button
        class="btn-secondary"
        :disabled="meta.current_page <= 1"
        @click="goToPage(meta.current_page - 1)"
      >
        ‹ Precedente
      </button>
      <span class="text-sm text-slate-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
      <button
        class="btn-secondary"
        :disabled="meta.current_page >= meta.last_page"
        @click="goToPage(meta.current_page + 1)"
      >
        Successiva ›
      </button>
    </div>
  </div>
</template>
