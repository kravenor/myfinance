<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import type { Budget, Category, Paginated } from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<Budget>('budgets')

const categories = ref<Category[]>([])
const now = new Date()
const filters = ref({ year: now.getFullYear(), month: now.getMonth() + 1 })

const editing = ref<Budget | null>(null)
const showForm = ref(false)
const form = ref({
  category_id: 0,
  year: filters.value.year,
  month: filters.value.month,
  amount: '',
})

function reset() {
  editing.value = null
  form.value = {
    category_id: categories.value[0]?.id ?? 0,
    year: filters.value.year,
    month: filters.value.month,
    amount: '',
  }
}

function startEdit(b: Budget) {
  editing.value = b
  form.value = { category_id: b.category_id, year: b.year, month: b.month, amount: b.amount }
  showForm.value = true
}

async function refresh() {
  await list({ year: filters.value.year, month: filters.value.month, per_page: 100 })
}

async function onSubmit() {
  if (editing.value) {
    await update(editing.value.id, form.value)
  } else {
    await create(form.value)
  }
  reset()
  showForm.value = false
  await refresh()
}

async function onDelete(b: Budget) {
  if (!confirm('Eliminare il budget?')) return
  await destroy(b.id)
}

function categoryName(id: number): string {
  return categories.value.find((c) => c.id === id)?.name ?? `#${id}`
}

function progress(b: Budget): number {
  if (!b.spent) return 0
  const amount = parseFloat(b.amount)
  if (amount === 0) return 0
  return Math.min(100, Math.round((parseFloat(b.spent) / amount) * 100))
}

onMounted(async () => {
  const c = await api.get<Paginated<Category>>('/categories', { params: { type: 'expense', per_page: 200 } })
  categories.value = c.data.data
  form.value.category_id = categories.value[0]?.id ?? 0
  await refresh()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Budget</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuovo budget' }}
      </button>
    </div>

    <form class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-3" @submit.prevent="refresh">
      <div>
        <label class="label">Anno</label>
        <input v-model.number="filters.year" type="number" min="2000" max="2100" class="input" />
      </div>
      <div>
        <label class="label">Mese</label>
        <input v-model.number="filters.month" type="number" min="1" max="12" class="input" />
      </div>
      <div class="flex items-end">
        <button type="submit" class="btn-secondary w-full">Filtra</button>
      </div>
    </form>

    <form v-if="showForm" class="card p-4 grid grid-cols-1 md:grid-cols-4 gap-4" @submit.prevent="onSubmit">
      <div>
        <label class="label">Categoria</label>
        <select v-model.number="form.category_id" class="input" required>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="label">Anno</label>
        <input v-model.number="form.year" type="number" min="2000" max="2100" class="input" required />
      </div>
      <div>
        <label class="label">Mese</label>
        <input v-model.number="form.month" type="number" min="1" max="12" class="input" required />
      </div>
      <div>
        <label class="label">Importo</label>
        <input v-model="form.amount" type="number" step="0.01" class="input" required />
      </div>
      <div class="md:col-span-4 flex gap-2 justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <div class="card overflow-x-auto">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <table v-else class="table">
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
            <td>{{ b.year }}-{{ String(b.month).padStart(2, '0') }}</td>
            <td class="text-right">{{ b.amount }}</td>
            <td class="text-right">{{ b.spent ?? '0.00' }}</td>
            <td>
              <div class="w-full bg-slate-200 rounded h-2">
                <div
                  class="h-2 rounded"
                  :class="progress(b) >= 100 ? 'bg-red-500' : 'bg-indigo-500'"
                  :style="{ width: progress(b) + '%' }"
                />
              </div>
            </td>
            <td class="text-right">
              <RowActions @edit="startEdit(b)" @delete="onDelete(b)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="6" class="text-center text-slate-500 py-6">Nessun budget per il periodo.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
