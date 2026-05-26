<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import { api } from '@/lib/api'
import type {
  CategorizationRule,
  Category,
  Paginated,
  RuleAppliesTo,
  RuleMatchType,
} from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<CategorizationRule>(
  'categorization-rules',
)

const categories = ref<Category[]>([])

interface FormState {
  category_id: number | null
  name: string
  match_type: RuleMatchType
  pattern: string
  applies_to_type: RuleAppliesTo
  priority: number
  is_active: boolean
}

const editing = ref<CategorizationRule | null>(null)
const showForm = ref(false)
const form = ref<FormState>(emptyForm())
const submitError = ref<string | null>(null)

function emptyForm(): FormState {
  return {
    category_id: null,
    name: '',
    match_type: 'contains',
    pattern: '',
    applies_to_type: 'any',
    priority: 100,
    is_active: true,
  }
}

const filteredCategories = computed(() => {
  if (form.value.applies_to_type === 'income') {
    return categories.value.filter((c) => c.type === 'income')
  }
  if (form.value.applies_to_type === 'expense') {
    return categories.value.filter((c) => c.type === 'expense')
  }
  return categories.value
})

function categoryLabel(rule: CategorizationRule): string {
  if (rule.category) return rule.category.name
  const cat = categories.value.find((c) => c.id === rule.category_id)
  return cat?.name ?? `#${rule.category_id}`
}

function categoryColor(rule: CategorizationRule): string | null {
  if (rule.category) return rule.category.color
  return categories.value.find((c) => c.id === rule.category_id)?.color ?? null
}

function matchTypeLabel(t: RuleMatchType): string {
  return {
    contains: 'contiene',
    starts_with: 'inizia con',
    equals: 'uguale a',
    regex: 'regex',
  }[t]
}

function appliesLabel(t: RuleAppliesTo): string {
  return { any: 'tutte', income: 'entrate', expense: 'spese' }[t]
}

function reset() {
  editing.value = null
  form.value = emptyForm()
  submitError.value = null
}

function startEdit(rule: CategorizationRule) {
  editing.value = rule
  form.value = {
    category_id: rule.category_id,
    name: rule.name,
    match_type: rule.match_type,
    pattern: rule.pattern,
    applies_to_type: rule.applies_to_type,
    priority: rule.priority,
    is_active: rule.is_active,
  }
  submitError.value = null
  showForm.value = true
}

async function onSubmit() {
  submitError.value = null
  if (!form.value.category_id) {
    submitError.value = 'Seleziona una categoria.'
    return
  }
  try {
    if (editing.value) {
      await update(editing.value.id, { ...form.value })
    } else {
      await create({ ...form.value })
    }
    reset()
    showForm.value = false
  } catch (e: unknown) {
    const err = e as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } }
    }
    const errors = err.response?.data?.errors
    if (errors) {
      submitError.value = Object.values(errors).flat().join(' ')
    } else {
      submitError.value = err.response?.data?.message ?? 'Errore nel salvataggio.'
    }
  }
}

async function onDelete(rule: CategorizationRule) {
  if (!confirm(`Eliminare la regola "${rule.name}"?`)) return
  await destroy(rule.id)
}

async function toggleActive(rule: CategorizationRule) {
  await update(rule.id, { is_active: !rule.is_active })
}

onMounted(async () => {
  await list({ per_page: 100 })
  const { data } = await api.get<Paginated<Category>>('/categories', {
    params: { per_page: 200 },
  })
  categories.value = data.data
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Regole di categorizzazione</h1>
        <p class="text-sm text-slate-500 mt-1">
          Assegna automaticamente la categoria durante l'import quando la descrizione corrisponde a un pattern.
        </p>
      </div>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuova regola' }}
      </button>
    </div>

    <form
      v-if="showForm"
      class="card p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4"
      @submit.prevent="onSubmit"
    >
      <div class="md:col-span-2">
        <label class="label">Nome</label>
        <input v-model="form.name" class="input" required maxlength="120" />
      </div>
      <div>
        <label class="label">Priorità</label>
        <input v-model.number="form.priority" type="number" min="0" max="9999" class="input" />
      </div>

      <div>
        <label class="label">Match</label>
        <select v-model="form.match_type" class="input">
          <option value="contains">contiene</option>
          <option value="starts_with">inizia con</option>
          <option value="equals">uguale a</option>
          <option value="regex">regex</option>
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="label">Pattern</label>
        <input v-model="form.pattern" class="input" required maxlength="255" />
      </div>

      <div>
        <label class="label">Si applica a</label>
        <select v-model="form.applies_to_type" class="input">
          <option value="any">tutte</option>
          <option value="income">entrate</option>
          <option value="expense">spese</option>
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="label">Categoria</label>
        <select v-model.number="form.category_id" class="input" required>
          <option :value="null">— Seleziona —</option>
          <option v-for="c in filteredCategories" :key="c.id" :value="c.id">
            {{ c.name }} ({{ c.type }})
          </option>
        </select>
      </div>

      <div class="flex items-center gap-2">
        <input v-model="form.is_active" type="checkbox" id="rule-active" class="h-4 w-4" />
        <label for="rule-active" class="text-sm">Attiva</label>
      </div>

      <div v-if="submitError" class="sm:col-span-2 md:col-span-3 text-sm text-red-600">
        {{ submitError }}
      </div>

      <div class="sm:col-span-2 md:col-span-3 flex flex-col sm:flex-row gap-2 sm:justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">
          Annulla
        </button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <div class="card table-responsive md:overflow-x-auto">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <table v-else class="table">
        <thead class="bg-slate-100">
          <tr>
            <th>Priorità</th>
            <th>Nome</th>
            <th>Condizione</th>
            <th>Si applica</th>
            <th>Categoria</th>
            <th>Match</th>
            <th>Attiva</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in items" :key="r.id">
            <td data-label="Priorità">{{ r.priority }}</td>
            <td data-label="Nome" class="font-medium">{{ r.name }}</td>
            <td data-label="Condizione">
              <span class="text-slate-500">{{ matchTypeLabel(r.match_type) }}</span>
              <code class="ml-1 px-1 bg-slate-100 rounded text-xs">{{ r.pattern }}</code>
            </td>
            <td data-label="Si applica">{{ appliesLabel(r.applies_to_type) }}</td>
            <td data-label="Categoria">
              <span
                v-if="categoryColor(r)"
                class="inline-block w-3 h-3 rounded-full align-middle mr-1"
                :style="{ background: categoryColor(r) ?? undefined }"
              />
              {{ categoryLabel(r) }}
            </td>
            <td data-label="Match">{{ r.times_applied }}</td>
            <td data-label="Attiva">
              <button
                type="button"
                class="text-xs px-2 py-1 rounded"
                :class="r.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-600'"
                @click="toggleActive(r)"
              >
                {{ r.is_active ? 'sì' : 'no' }}
              </button>
            </td>
            <td class="md:text-right actions-cell">
              <RowActions @edit="startEdit(r)" @delete="onDelete(r)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="8" class="text-center text-slate-500 py-6">
              Nessuna regola. Creane una per categorizzare automaticamente le transazioni in import.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
