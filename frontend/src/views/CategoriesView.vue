<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import type { Category, CategoryType } from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<Category>('categories')

const editing = ref<Category | null>(null)
const showForm = ref(false)
const form = ref({
  name: '',
  type: 'expense' as CategoryType,
  parent_id: null as number | null,
})

function reset() {
  editing.value = null
  form.value = { name: '', type: 'expense', parent_id: null }
}

function startEdit(cat: Category) {
  editing.value = cat
  form.value = { name: cat.name, type: cat.type, parent_id: cat.parent_id }
  showForm.value = true
}

async function onSubmit() {
  if (editing.value) {
    await update(editing.value.id, form.value)
  } else {
    await create(form.value)
  }
  reset()
  showForm.value = false
}

async function onDelete(cat: Category) {
  if (!confirm(`Eliminare la categoria "${cat.name}"?`)) return
  await destroy(cat.id)
}

onMounted(() => list({ per_page: 100 }))
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Categorie</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuova categoria' }}
      </button>
    </div>

    <form v-if="showForm" class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-4" @submit.prevent="onSubmit">
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
      <div class="md:col-span-3 flex gap-2 justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <div class="card overflow-x-auto">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
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
            <td class="font-medium">{{ cat.name }}</td>
            <td class="capitalize">{{ cat.type }}</td>
            <td>{{ items.find((c) => c.id === cat.parent_id)?.name ?? '—' }}</td>
            <td class="text-right">
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
