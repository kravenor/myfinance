<script setup lang="ts">
import { formatDate } from '@/lib/date'
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import Amount from '@/components/ui/Amount.vue'
import { formatCurrency } from '@/lib/money'
import { FALLBACK_TAG_COLOR } from '@/lib/chartTheme'
import { TX_TYPE_LABEL, TX_TYPES } from '@/lib/labels'
import { useToastStore } from '@/stores/toast'
import type { Account, Category, Paginated, Tag, Transaction, TransactionType } from '@/types/api'

const { items, loading, meta, submitting, fieldErrors, list, create, update, destroy } = useCrud<Transaction>('transactions')
const toast = useToastStore()
const formEl = ref<HTMLFormElement | null>(null)

const accounts = ref<Account[]>([])
const categories = ref<Category[]>([])
const tags = ref<Tag[]>([])

const filters = ref({ account_id: '', type: '', from: '', to: '', search: '', tag_id: '' })
const expandedDescriptions = ref(new Set<number>())
function toggleDescription(id: number) {
  const set = expandedDescriptions.value
  if (set.has(id)) set.delete(id)
  else set.add(id)
  expandedDescriptions.value = new Set(set)
}
const page = ref(1)

const editing = ref<Transaction | null>(null)
const showForm = ref(false)
const form = ref({
  account_id: 0,
  category_id: null as number | null,
  transfer_account_id: null as number | null,
  type: 'expense' as TransactionType,
  amount: '',
  transfer_amount: '',
  occurred_at: new Date().toISOString().slice(0, 10),
  description: '',
  tag_ids: [] as number[],
})

function accountCurrency(id: number | null | undefined): string {
  if (!id) return ''
  return accounts.value.find((a) => a.id === id)?.currency ?? ''
}

// Per i transfer tra conti con valute diverse mostriamo il campo
// "importo ricevuto" nella valuta del conto destinazione.
const isCrossCurrencyTransfer = computed(
  () =>
    form.value.type === 'transfer' &&
    !!form.value.transfer_account_id &&
    accountCurrency(form.value.account_id) !== accountCurrency(form.value.transfer_account_id),
)

function toggleTag(id: number) {
  const i = form.value.tag_ids.indexOf(id)
  if (i === -1) form.value.tag_ids.push(id)
  else form.value.tag_ids.splice(i, 1)
}

// Categorie del tipo corrente, ordinate ad albero (parent → figli indentati).
const categoryOptions = computed<{ id: number; label: string }[]>(() => {
  if (form.value.type === 'transfer') return []
  const type = form.value.type as 'income' | 'expense'
  const list = categories.value.filter((c) => c.type === type)
  const ids = new Set(list.map((c) => c.id))
  const byParent = new Map<number | null, Category[]>()
  for (const c of list) {
    const key = c.parent_id
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key)!.push(c)
  }
  const sortFn = (a: Category, b: Category) => a.sort_order - b.sort_order || a.name.localeCompare(b.name)
  const out: { id: number; label: string }[] = []
  const walk = (cat: Category, depth: number) => {
    out.push({ id: cat.id, label: '   '.repeat(depth) + (depth ? '↳ ' : '') + cat.name })
    for (const ch of (byParent.get(cat.id) ?? []).slice().sort(sortFn)) walk(ch, depth + 1)
  }
  // root = senza parent, o con parent di tipo diverso (non presente nella lista filtrata)
  const roots = list.filter((c) => c.parent_id == null || !ids.has(c.parent_id)).sort(sortFn)
  for (const r of roots) walk(r, 0)
  return out
})

function accountName(id: number | null | undefined): string {
  if (!id) return '—'
  return accounts.value.find((a) => a.id === id)?.name ?? `#${id}`
}

function isPrimaryAccount(id: number | null | undefined): boolean {
  if (!id) return false
  return !!accounts.value.find((a) => a.id === id && a.is_primary)
}

function categoryName(id: number | null | undefined): string {
  if (!id) return ''
  return categories.value.find((c) => c.id === id)?.name ?? ''
}

const TX_BORDER_CLASS: Record<TransactionType, string> = {
  income: 'border-income-500',
  expense: 'border-expense-400',
  transfer: 'border-transfer-300',
}

const TX_BADGE_CLASS: Record<TransactionType, string> = {
  income: 'bg-income-50 text-income-700',
  expense: 'bg-expense-50 text-expense-700',
  transfer: 'bg-transfer-50 text-transfer-700',
}

function fieldError(name: string): string | undefined {
  return fieldErrors.value[name]?.[0]
}

function reset() {
  editing.value = null
  fieldErrors.value = {}
  form.value = {
    account_id: accounts.value.find((a) => a.is_primary)?.id ?? accounts.value[0]?.id ?? 0,
    category_id: null,
    transfer_account_id: null,
    type: 'expense',
    amount: '',
    transfer_amount: '',
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
    transfer_amount: tx.transfer_amount ?? '',
    occurred_at: tx.occurred_at,
    description: tx.description ?? '',
    tag_ids: (tx.tags ?? []).map((t) => t.id),
  }
  openForm()
}

// Il form sta in cima alla lista: da una riga in fondo o dal FAB va portato in vista.
function openForm() {
  fieldErrors.value = {}
  showForm.value = true
  nextTick(() => formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

function openNew() {
  reset()
  openForm()
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
    // Override manuale del tasso solo se l'utente ha specificato l'importo ricevuto.
    if (isCrossCurrencyTransfer.value && form.value.transfer_amount !== '') {
      payload.transfer_amount = form.value.transfer_amount
    }
  }
  const wasEditing = editing.value !== null
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
  toast.success(wasEditing ? 'Transazione aggiornata.' : 'Transazione registrata.')
  reset()
  showForm.value = false
  await applyFilters(!wasEditing)
}

async function onDelete(tx: Transaction) {
  if (!confirm('Eliminare la transazione?')) return
  await destroy(tx.id)
  toast.success('Transazione eliminata.')
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
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Transazioni</h1>
      <button class="btn-primary" @click="showForm ? ((showForm = false), reset()) : openNew()">
        {{ showForm ? 'Annulla' : 'Nuova transazione' }}
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuova transazione"
      @click="openNew()"
    >+</button>

    <details class="card filter-panel" open>
      <summary>Filtri</summary>
      <form class="p-4 pt-0 md:pt-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3" @submit.prevent="applyFilters()">
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
            <option v-for="t in TX_TYPES" :key="t" :value="t">{{ TX_TYPE_LABEL[t] }}</option>
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
    </details>

    <p v-if="meta" class="text-sm text-slate-500">
      {{ meta.total }} transazion{{ meta.total === 1 ? 'e' : 'i' }}
      <span v-if="meta.total > 0"> · {{ meta.from }}–{{ meta.to }}</span>
    </p>

    <form v-if="showForm" ref="formEl" class="card p-4 scroll-mt-20 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4" @submit.prevent="onSubmit">
      <div>
        <label class="label">Tipo</label>
        <select v-model="form.type" class="input">
          <option v-for="t in TX_TYPES" :key="t" :value="t">{{ TX_TYPE_LABEL[t] }}</option>
        </select>
      </div>
      <div>
        <label class="label">Conto</label>
        <select v-model.number="form.account_id" class="input" :class="{ 'input-invalid': fieldError('account_id') }" required>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.is_primary ? ' ★' : '' }}</option>
        </select>
        <p v-if="fieldError('account_id')" class="field-error">{{ fieldError('account_id') }}</p>
      </div>
      <div v-if="form.type === 'transfer'">
        <label class="label">Conto destinazione</label>
        <select v-model.number="form.transfer_account_id" class="input" :class="{ 'input-invalid': fieldError('transfer_account_id') }" required>
          <option v-for="a in accounts.filter((a) => a.id !== form.account_id)" :key="a.id" :value="a.id">
            {{ a.name }}
          </option>
        </select>
        <p v-if="fieldError('transfer_account_id')" class="field-error">{{ fieldError('transfer_account_id') }}</p>
      </div>
      <div v-else>
        <label class="label">Categoria</label>
        <select v-model.number="form.category_id" class="input" :class="{ 'input-invalid': fieldError('category_id') }">
          <option :value="null">— Nessuna —</option>
          <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
        </select>
        <p v-if="fieldError('category_id')" class="field-error">{{ fieldError('category_id') }}</p>
      </div>
      <div>
        <label class="label">Importo<span v-if="form.type === 'transfer'"> ({{ accountCurrency(form.account_id) }})</span></label>
        <input v-model="form.amount" type="number" inputmode="decimal" step="0.01" min="0.01" class="input" :class="{ 'input-invalid': fieldError('amount') }" required />
        <p v-if="fieldError('amount')" class="field-error">{{ fieldError('amount') }}</p>
      </div>
      <div v-if="isCrossCurrencyTransfer">
        <label class="label">Importo ricevuto ({{ accountCurrency(form.transfer_account_id) }})</label>
        <input
          v-model="form.transfer_amount"
          type="number"
          step="0.01"
          min="0.01"
          class="input"
          :placeholder="`Auto (tasso del ${form.occurred_at})`"
          :class="{ 'input-invalid': fieldError('transfer_amount') }"
        />
        <p v-if="fieldError('transfer_amount')" class="field-error">{{ fieldError('transfer_amount') }}</p>
      </div>
      <div>
        <label class="label">Data</label>
        <input v-model="form.occurred_at" type="date" class="input" :class="{ 'input-invalid': fieldError('occurred_at') }" required />
        <p v-if="fieldError('occurred_at')" class="field-error">{{ fieldError('occurred_at') }}</p>
      </div>
      <div class="sm:col-span-2 md:col-span-3">
        <label class="label">Descrizione</label>
        <input v-model="form.description" class="input" :class="{ 'input-invalid': fieldError('description') }" />
        <p v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</p>
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
            :style="form.tag_ids.includes(t.id) ? { background: t.color || FALLBACK_TAG_COLOR } : {}"
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
        <button type="submit" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Salvataggio…' : editing ? 'Salva' : 'Crea' }}
        </button>
      </div>
    </form>

    <div class="card">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>

      <!-- Mobile: una card per transazione, pensata per la lettura rapida (sotto md). -->
      <ul v-else class="md:hidden divide-y divide-slate-100">
        <li
          v-for="tx in items"
          :key="tx.id"
          class="p-4 border-l-4"
          :class="TX_BORDER_CLASS[tx.type]"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium text-slate-800 truncate">
                {{ tx.description || categoryName(tx.category_id) || (tx.type === 'transfer' ? 'Trasferimento' : '—') }}
              </p>
              <p class="text-xs text-slate-500 mt-0.5 truncate">
                {{ formatDate(tx.occurred_at) }} ·
                {{ accountName(tx.account_id) }}<span v-if="isPrimaryAccount(tx.account_id)" class="text-amber-500">★</span>
                <template v-if="tx.type === 'transfer'"> → {{ accountName(tx.transfer_account_id) }}</template>
                <template v-else-if="tx.description && categoryName(tx.category_id)"> · {{ categoryName(tx.category_id) }}</template>
              </p>
              <div v-if="tx.tags && tx.tags.length" class="flex flex-wrap gap-1 mt-1.5">
                <span
                  v-for="t in tx.tags"
                  :key="t.id"
                  class="inline-block px-2 py-0.5 rounded-full text-xs text-white"
                  :style="{ background: t.color || FALLBACK_TAG_COLOR }"
                >{{ t.name }}</span>
              </div>
            </div>
            <div class="text-right shrink-0">
              <Amount class="block font-semibold" :value="tx.amount" :currency="tx.currency" :type="tx.type" />
              <p
                v-if="tx.type === 'transfer' && tx.transfer_amount && accountCurrency(tx.transfer_account_id) !== tx.currency"
                class="text-xs font-normal text-slate-400 mt-0.5"
              >→ {{ formatCurrency(tx.transfer_amount, accountCurrency(tx.transfer_account_id)) }}</p>
              <RowActions class="mt-2 justify-end" @edit="startEdit(tx)" @delete="onDelete(tx)" />
            </div>
          </div>
        </li>
        <li v-if="items.length === 0" class="p-6 text-center text-slate-500 text-sm">Nessuna transazione.</li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!loading" class="table hidden md:table">
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
            <td>{{ formatDate(tx.occurred_at) }}</td>
            <td>
              <span class="inline-block rounded px-2 py-0.5 text-xs font-medium" :class="TX_BADGE_CLASS[tx.type]">
                {{ TX_TYPE_LABEL[tx.type] }}
              </span>
            </td>
            <td>
              <span class="inline-flex items-center gap-2">
                <span>{{ accountName(tx.account_id) }}</span>
                <span v-if="isPrimaryAccount(tx.account_id)" class="text-amber-500" title="Conto principale">★</span>
              </span>
              <span v-if="tx.type === 'transfer'" class="text-slate-400"> → {{ accountName(tx.transfer_account_id) }}</span>
            </td>
            <td
              class="max-w-xs cursor-pointer"
              :class="expandedDescriptions.has(tx.id) ? '!whitespace-normal break-words' : 'line-clamp-2 break-words'"
              :title="tx.description ?? ''"
              @click="toggleDescription(tx.id)"
            >{{ tx.description ?? '—' }}</td>
            <td>
              <span v-if="tx.tags && tx.tags.length" class="flex flex-wrap gap-1">
                <span
                  v-for="t in tx.tags"
                  :key="t.id"
                  class="inline-block px-2 py-0.5 rounded-full text-xs text-white"
                  :style="{ background: t.color || FALLBACK_TAG_COLOR }"
                >{{ t.name }}</span>
              </span>
              <span v-else class="text-slate-400">—</span>
            </td>
            <td class="text-right font-medium">
              <Amount :value="tx.amount" :currency="tx.currency" :type="tx.type" />
              <span
                v-if="tx.type === 'transfer' && tx.transfer_amount && accountCurrency(tx.transfer_account_id) !== tx.currency"
                class="block text-xs font-normal text-slate-400"
              >→ {{ formatCurrency(tx.transfer_amount, accountCurrency(tx.transfer_account_id)) }}</span>
            </td>
            <td class="text-right">
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
