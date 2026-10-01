<script setup lang="ts">
import { onMounted, ref } from 'vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import { useToastStore } from '@/stores/toast'
import RowActions from '@/components/ui/RowActions.vue'
import type { Category, CategoryType } from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<Category>('categories')
const toast = useToastStore()

const editing = ref<Category | null>(null)
const showForm = ref(false)
const form = ref({
  name: '',
  type: 'expense' as CategoryType,
  parent_id: null as number | null,
})

function reset() {
  editing.value = null
  fieldErrors.value = {}
  form.value = { name: '', type: 'expense', parent_id: null }
}

function startEdit(cat: Category) {
  editing.value = cat
  fieldErrors.value = {}
  form.value = { name: cat.name, type: cat.type, parent_id: cat.parent_id }
  showForm.value = true
}

async function onSubmit() {
  try {
    if (editing.value) {
      await update(editing.value.id, form.value)
    } else {
      await create(form.value)
    }
  } catch {
    // 422: riepilogo errori nella modale, i dati inseriti restano.
    return
  }
  toast.success('Categoria salvata.')
  reset()
  showForm.value = false
}

async function onDelete(cat: Category) {
  if (!(await confirmAction(`Eliminare la categoria "${cat.name}"?`))) return
  await destroy(cat.id)
}

onMounted(() => list({ per_page: 100 }))
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Categorie</h1>
      <button class="btn-primary" @click="showForm = true; reset()">
        Nuova categoria
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuova categoria"
      @click="showForm = true; reset()"
    >+</button>

    <AppModal v-model="showForm" :title="editing ? 'Modifica categoria' : 'Nuova categoria'">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 px-4 py-4 sm:px-6">
          <FormErrors :errors="fieldErrors" class="col-span-full" />
          <div>
            <label class="label">Nome</label>
            <input v-model="form.name" class="input" required />
          </div>
          <div>
            <label class="label">Tipo</label>
            <select v-model="form.type" class="input">
              <option value="expense">expense</option>
              <option value="income">income</option>
            </select>
          </div>
          <div>
            <label class="label">Parent</label>
            <select v-model="form.parent_id" class="input">
              <option :value="null">— Nessuno —</option>
              <option v-for="c in items.filter((c) => c.type === form.type && c.id !== editing?.id)" :key="c.id" :value="c.id">
                {{ c.name }}
              </option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" @click="showForm = false">Annulla</button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? 'Salvataggio…' : editing ? 'Salva' : 'Crea' }}
          </button>
        </div>
      </form>
    </AppModal>

    <div class="card table-responsive md:overflow-x-auto">
      <ListSkeleton v-if="loading && !items.length" />
      <table v-else class="table">
        <thead class="bg-slate-100">
          <tr>
            <th>Nome</th>
            <th>Tipo</th>
            <th>Parent</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="cat in items" :key="cat.id">
            <td data-label="Nome" class="font-medium">{{ cat.name }}</td>
            <td data-label="Tipo" class="capitalize">{{ cat.type }}</td>
            <td data-label="Parent">{{ items.find((c) => c.id === cat.parent_id)?.name ?? '—' }}</td>
            <td class="md:text-right actions-cell">
              <RowActions @edit="startEdit(cat)" @delete="onDelete(cat)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="4" class="text-center text-slate-500 py-6">Nessuna categoria.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
