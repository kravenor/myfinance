<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/date'
import { formatCurrency } from '@/lib/money'
import RowActions from '@/components/ui/RowActions.vue'
import type { InvestmentHolding, InvestmentSide, InvestmentTransaction, Paginated } from '@/types/api'

const props = defineProps<{ holding: InvestmentHolding }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>()

const movements = ref<InvestmentTransaction[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)

// Prezzo di default: la quotazione già risolta per l'holding (auto o manuale),
// così l'utente non deve andarla a cercare per registrare una rata PAC.
const blank = () => ({
  side: 'buy' as InvestmentSide,
  occurred_at: new Date().toISOString().slice(0, 10),
  amount: '',
  quantity: '',
  price: props.holding.effective_price,
  fees: '',
  notes: '',
})
const form = ref(blank())

const base = computed(() => `/investment-holdings/${props.holding.id}/transactions`)

// Per un acquisto, l'utente pensa in importo versato (es. rata PAC): la quantità
// è derivata, non serve farla calcolare a mano.
const computedQuantity = computed(() => {
  const amount = parseFloat(form.value.amount)
  const price = parseFloat(form.value.price)
  if (!amount || !price) return null
  return amount / price
})

// Il totale versato è la cassa netta immessa: serve il confronto con il valore
// attuale, che è la lettura che conta in un PAC.
const totalPl = computed(
  () => parseFloat(props.holding.unrealized_pl) + parseFloat(props.holding.realized_pl),
)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get<Paginated<InvestmentTransaction>>(base.value, {
      params: { per_page: 200 },
    })
    movements.value = data.data
  } finally {
    loading.value = false
  }
}

function startEdit(m: InvestmentTransaction) {
  editingId.value = m.id
  form.value = {
    side: m.side,
    occurred_at: m.occurred_at,
    amount: '',
    quantity: m.quantity,
    price: m.price,
    fees: m.fees,
    notes: m.notes ?? '',
  }
}

function cancelEdit() {
  editingId.value = null
  form.value = blank()
  error.value = ''
}

async function onSubmit() {
  error.value = ''
  saving.value = true
  const payload = {
    side: form.value.side,
    occurred_at: form.value.occurred_at,
    quantity: computedQuantity.value !== null ? String(computedQuantity.value) : form.value.quantity,
    price: form.value.price,
    fees: form.value.fees === '' ? 0 : form.value.fees,
    notes: form.value.notes || null,
  }
  try {
    if (editingId.value) {
      await api.patch(`${base.value}/${editingId.value}`, payload)
    } else {
      await api.post(base.value, payload)
    }
    cancelEdit()
    await load()
    emit('changed')
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    error.value = err.response?.data?.message ?? 'Movimento non registrato.'
  } finally {
    saving.value = false
  }
}

async function onDelete(m: InvestmentTransaction) {
  if (!confirm(`Eliminare il movimento del ${formatDate(m.occurred_at)}?`)) return
  await api.delete(`${base.value}/${m.id}`)
  if (editingId.value === m.id) cancelEdit()
  await load()
  emit('changed')
}

onMounted(load)
</script>

<template>
  <div class="fixed inset-0 z-40 flex justify-end bg-slate-900/40" @click.self="emit('close')">
    <div class="w-full max-w-xl h-full overflow-y-auto bg-white shadow-xl">
      <header class="sticky top-0 bg-white border-b border-slate-200 px-4 py-3 flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="font-semibold text-slate-800 truncate">{{ holding.name }}</h2>
          <p class="text-xs text-slate-500">Registro movimenti</p>
        </div>
        <button type="button" class="btn-secondary" @click="emit('close')">Chiudi</button>
      </header>

      <dl class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-slate-200 border-b border-slate-200 text-sm">
        <div class="bg-white p-3">
          <dt class="text-xs text-slate-500">Versato netto</dt>
          <dd class="font-medium">{{ formatCurrency(holding.net_invested, holding.currency) }}</dd>
        </div>
        <div class="bg-white p-3">
          <dt class="text-xs text-slate-500">Valore attuale</dt>
          <dd class="font-medium">{{ formatCurrency(holding.market_value, holding.currency) }}</dd>
        </div>
        <div class="bg-white p-3">
          <dt class="text-xs text-slate-500">P/L realizzato</dt>
          <dd class="font-medium" :class="parseFloat(holding.realized_pl) >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatCurrency(holding.realized_pl, holding.currency) }}
          </dd>
        </div>
        <div class="bg-white p-3">
          <dt class="text-xs text-slate-500">P/L totale</dt>
          <dd class="font-medium" :class="totalPl >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatCurrency(String(totalPl), holding.currency) }}
          </dd>
        </div>
      </dl>

      <form class="p-4 space-y-3 border-b border-slate-200" @submit.prevent="onSubmit">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Operazione</label>
            <select v-model="form.side" class="input">
              <option value="buy">Acquisto</option>
              <option value="sell">Vendita</option>
            </select>
          </div>
          <div>
            <label class="label">Data</label>
            <input v-model="form.occurred_at" type="date" class="input" required />
          </div>
          <div v-if="form.side === 'buy'">
            <label class="label">Importo versato ({{ holding.currency }})</label>
            <input v-model="form.amount" type="number" step="0.01" min="0" class="input" placeholder="es. 100" />
          </div>
          <div>
            <label class="label">Prezzo ({{ holding.currency }})</label>
            <input v-model="form.price" type="number" step="0.00000001" min="0" class="input" required />
          </div>
          <div>
            <label class="label">Quantità</label>
            <input
              v-model="form.quantity"
              type="number"
              step="0.00000001"
              min="0"
              class="input"
              :readonly="computedQuantity !== null"
              :class="{ 'bg-slate-100 text-slate-500': computedQuantity !== null }"
              :required="computedQuantity === null"
              :placeholder="computedQuantity !== null ? String(computedQuantity) : ''"
            />
          </div>
          <div>
            <label class="label">Commissioni</label>
            <input v-model="form.fees" type="number" step="0.01" min="0" class="input" placeholder="0,00" />
          </div>
          <div>
            <label class="label">Note</label>
            <input v-model="form.notes" class="input" placeholder="es. rata PAC" />
          </div>
        </div>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary" :disabled="saving">
            {{ editingId ? 'Salva movimento' : 'Registra movimento' }}
          </button>
          <button v-if="editingId" type="button" class="btn-secondary" @click="cancelEdit">Annulla</button>
        </div>
      </form>

      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="m in movements" :key="m.id" class="p-4 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-sm font-medium" :class="m.side === 'buy' ? 'text-slate-800' : 'text-amber-700'">
              {{ m.side === 'buy' ? 'Acquisto' : 'Vendita' }} · {{ formatDate(m.occurred_at) }}
            </p>
            <p class="text-xs text-slate-500 mt-0.5">
              {{ m.quantity }} × {{ formatCurrency(m.price, holding.currency) }}
              <template v-if="parseFloat(m.fees) > 0">
                + {{ formatCurrency(m.fees, holding.currency) }} comm.
              </template>
            </p>
            <p v-if="m.notes" class="text-xs text-slate-400 mt-0.5 truncate">{{ m.notes }}</p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-medium whitespace-nowrap">
              {{ m.side === 'buy' ? '−' : '+' }}{{ formatCurrency(m.cash_flow, holding.currency) }}
            </p>
            <RowActions class="mt-1 justify-end" @edit="startEdit(m)" @delete="onDelete(m)" />
          </div>
        </li>
        <li v-if="movements.length === 0" class="p-6 text-center text-sm text-slate-500">
          Nessun movimento.
        </li>
      </ul>
    </div>
  </div>
</template>
