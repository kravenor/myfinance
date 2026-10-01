<script setup lang="ts">
import { onMounted, ref } from 'vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import FieldError from '@/components/ui/FieldError.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import { useToastStore } from '@/stores/toast'
import RowActions from '@/components/ui/RowActions.vue'
import type { Tag } from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<Tag>('tags')
const toast = useToastStore()

const editing = ref<Tag | null>(null)
const showForm = ref(false)
const form = ref({ name: '', color: '' })
const dirty = useFormDirty(form, showForm)

function reset() {
  editing.value = null
  fieldErrors.value = {}
  form.value = { name: '', color: '' }
}

function startEdit(t: Tag) {
  editing.value = t
  fieldErrors.value = {}
  form.value = { name: t.name, color: t.color ?? '' }
  showForm.value = true
}

async function onSubmit() {
  const payload = { name: form.value.name, color: form.value.color || null }
  try {
    if (editing.value) {
      await update(editing.value.id, payload)
    } else {
      await create(payload)
    }
  } catch {
    // 422: riepilogo errori nella modale, i dati inseriti restano.
    return
  }
  toast.success('Tag salvato.')
  reset()
  showForm.value = false
}

async function onDelete(t: Tag) {
  if (!(await confirmAction(`Eliminare il tag "${t.name}"?`))) return
  await destroy(t.id)
}

onMounted(() => list())
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Tag</h1>
        <p class="page-desc">Etichette libere da aggiungere alle transazioni, anche più d'una, per raggrupparle oltre la categoria (es. «Vacanze 2026»).</p>
      </div>
      <button class="btn-primary" @click="showForm = true; reset()">
        Nuovo tag
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuovo tag"
      @click="showForm = true; reset()"
    >+</button>

    <AppModal v-slot="{ close }" v-model="showForm" :dirty="dirty" :title="editing ? 'Modifica tag' : 'Nuovo tag'">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 px-4 py-4 sm:px-6">
          <FormErrors class="col-span-full" :errors="fieldErrors" :shown="['name', 'color']" />
          <div class="md:col-span-2">
            <label class="label">Nome</label>
            <input v-model="form.name" class="input" :class="{ 'input-invalid': fieldErrors.name }" required maxlength="64" />
            <FieldError :errors="fieldErrors" name="name" />
          </div>
          <div>
            <label class="label">Colore</label>
            <input
              v-model="form.color"
              class="input"
              :class="{ 'input-invalid': fieldErrors.color }"
              placeholder="#aabbcc"
              aria-describedby="hint-color"
            />
            <p id="hint-color" class="field-hint">Codice esadecimale nel formato #rrggbb; se lo lasci vuoto il tag usa un colore predefinito.</p>
            <FieldError :errors="fieldErrors" name="color" />
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

    <div class="card table-responsive md:overflow-x-auto">
      <ListSkeleton v-if="loading && !items.length" />
      <table v-else class="table" :class="{ 'opacity-60': loading }">
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
            <td colspan="3" class="whitespace-normal">
              <EmptyState title="Non hai ancora creato tag.">
                <button type="button" class="btn-primary" @click="showForm = true; reset()">Crea il primo tag</button>
              </EmptyState>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
