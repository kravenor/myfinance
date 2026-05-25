<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import type { Tag } from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<Tag>('tags')

const editing = ref<Tag | null>(null)
const showForm = ref(false)
const form = ref({ name: '', color: '' })

function reset() {
  editing.value = null
  form.value = { name: '', color: '' }
}

function startEdit(t: Tag) {
  editing.value = t
  form.value = { name: t.name, color: t.color ?? '' }
  showForm.value = true
}

async function onSubmit() {
  const payload = { name: form.value.name, color: form.value.color || null }
  if (editing.value) {
    await update(editing.value.id, payload)
  } else {
    await create(payload)
  }
  reset()
  showForm.value = false
}

async function onDelete(t: Tag) {
  if (!confirm(`Eliminare il tag "${t.name}"?`)) return
  await destroy(t.id)
}

onMounted(() => list())
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Tag</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuovo tag' }}
      </button>
    </div>

    <form v-if="showForm" class="card p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4" @submit.prevent="onSubmit">
      <div class="md:col-span-2">
        <label class="label">Nome</label>
        <input v-model="form.name" class="input" required maxlength="64" />
      </div>
      <div>
        <label class="label">Colore</label>
        <input v-model="form.color" class="input" placeholder="#aabbcc" />
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
            <th>Nome</th>
            <th>Colore</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="t in items" :key="t.id">
            <td data-label="Nome" class="font-medium">{{ t.name }}</td>
            <td data-label="Colore">
              <span v-if="t.color" class="inline-block w-4 h-4 rounded-full align-middle mr-2" :style="{ background: t.color }" />
              {{ t.color ?? '—' }}
            </td>
            <td class="md:text-right actions-cell">
              <RowActions @edit="startEdit(t)" @delete="onDelete(t)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="3" class="text-center text-slate-500 py-6">Nessun tag.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
