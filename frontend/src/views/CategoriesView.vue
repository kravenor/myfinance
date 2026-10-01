<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import { useCrud } from '@/composables/useCrud'
import AppModal from '@/components/ui/AppModal.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import FieldError from '@/components/ui/FieldError.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { useFormDirty } from '@/composables/useFormDirty'
import { useQueryFilters } from '@/composables/useQueryFilters'
import { CATEGORY_PALETTE } from '@/lib/chartTheme'
import { CATEGORY_TYPE_LABEL } from '@/lib/labels'
import { useToastStore } from '@/stores/toast'
import RowActions from '@/components/ui/RowActions.vue'
import type { Category, CategoryType } from '@/types/api'
import { confirmAction } from '@/composables/useConfirm'

const { items, loading, submitting, fieldErrors, list, create, update, destroy } = useCrud<Category>('categories')
const toast = useToastStore()

const { filters } = useQueryFilters({ type: 'expense' }, () => {}, 0)
const activeType = computed({
  get: () => (filters.value.type === 'income' ? 'income' : 'expense') as CategoryType,
  set: (v: CategoryType) => (filters.value.type = v),
})

const typeOptions = computed(() =>
  (['expense', 'income'] as CategoryType[]).map((t) => ({
    value: t,
    label: t === 'expense' ? 'Uscite' : 'Entrate',
    count: items.value.filter((c) => c.type === t).length,
  })),
)

// Albero a due livelli del tipo attivo: radici (senza padre dello stesso tipo) e figli, nell'ordine dell'API.
const tree = computed(() => {
  const ofType = items.value.filter((c) => c.type === activeType.value)
  const ids = new Set(ofType.map((c) => c.id))
  const children = new Map<number, Category[]>()
  for (const c of ofType) {
    if (c.parent_id !== null && ids.has(c.parent_id)) {
      children.set(c.parent_id, [...(children.get(c.parent_id) ?? []), c])
    }
  }
  return ofType
    .filter((c) => c.parent_id === null || !ids.has(c.parent_id))
    .map((c) => ({ category: c, children: children.get(c.id) ?? [] }))
})

const editing = ref<Category | null>(null)
const showForm = ref(false)
const form = ref({
  name: '',
  type: 'expense' as CategoryType,
  parent_id: null as number | null,
  color: null as string | null,
})
const dirty = useFormDirty(form, showForm)

// Padre possibile: solo categorie principali dello stesso tipo, esclusa quella in modifica.
const parentOptions = computed(() =>
  items.value.filter((c) => c.type === form.value.type && c.parent_id === null && c.id !== editing.value?.id),
)
watch(parentOptions, (opts) => {
  if (form.value.parent_id !== null && !opts.some((c) => c.id === form.value.parent_id)) form.value.parent_id = null
})

function openNew(parent: Category | null = null) {
  editing.value = null
  fieldErrors.value = {}
  form.value = { name: '', type: parent?.type ?? activeType.value, parent_id: parent?.id ?? null, color: parent?.color ?? null }
  showForm.value = true
}

function startEdit(cat: Category) {
  editing.value = cat
  fieldErrors.value = {}
  form.value = { name: cat.name, type: cat.type, parent_id: cat.parent_id, color: cat.color }
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
    return
  }
  toast.success('Categoria salvata.')
  activeType.value = form.value.type
  showForm.value = false
  await list({ per_page: 200 })
}

async function onDelete(cat: Category) {
  const ok = await confirmAction(`Eliminare la categoria "${cat.name}"?`, {
    detail:
      'Le transazioni restano, senza categoria; le sue sottocategorie diventano principali. Budget e regole di questa categoria vengono eliminati. L\'operazione non può essere annullata.',
  })
  if (!ok) return
  await destroy(cat.id)
  toast.success('Categoria eliminata.')
  await list({ per_page: 200 })
}

const colorOf = (c: Category) => c.color ?? '#94a3b8'

onMounted(() => list({ per_page: 200 }))
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Categorie</h1>
        <p class="page-desc">Le categorie classificano entrate e uscite e sono la base di report e budget.</p>
      </div>
      <button class="btn-primary" @click="openNew()">Nuova categoria</button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuova categoria"
      @click="openNew()"
    >+</button>

    <SegmentedControl v-model="activeType" :options="typeOptions" label="Tipo di categoria" />

    <div class="card">
      <ListSkeleton v-if="loading && !items.length" />
      <ul v-else-if="tree.length" class="divide-y divide-slate-100" :class="{ 'opacity-60': loading }">
        <li v-for="node in tree" :key="node.category.id">
          <div class="flex items-center gap-3 px-4 py-2.5">
            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: colorOf(node.category) }" aria-hidden="true" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium text-slate-900">{{ node.category.name }}</p>
              <p v-if="node.children.length" class="text-xs text-slate-500">
                {{ node.children.length }} sottocategori{{ node.children.length === 1 ? 'a' : 'e' }}
              </p>
            </div>
            <button
              type="button"
              class="icon-btn text-slate-500 hover:bg-primary-50 hover:text-primary-600 focus:ring-primary-500"
              :title="`Aggiungi sottocategoria a ${node.category.name}`"
              :aria-label="`Aggiungi sottocategoria a ${node.category.name}`"
              @click="openNew(node.category)"
            >
              <AppIcon name="plus" class="h-4 w-4" />
            </button>
            <RowActions @edit="startEdit(node.category)" @delete="onDelete(node.category)" />
          </div>
          <ul v-if="node.children.length" class="ml-[1.375rem] border-l border-slate-200">
            <li v-for="child in node.children" :key="child.id" class="flex items-center gap-3 py-2 pl-5 pr-4">
              <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: colorOf(child) }" aria-hidden="true" />
              <p class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ child.name }}</p>
              <RowActions @edit="startEdit(child)" @delete="onDelete(child)" />
            </li>
          </ul>
        </li>
      </ul>
      <EmptyState
        v-else
        :title="activeType === 'income' ? 'Non hai ancora categorie di entrata.' : 'Non hai ancora categorie di uscita.'"
      >
        <button type="button" class="btn-primary" @click="openNew()">Crea la prima</button>
      </EmptyState>
    </div>

    <AppModal v-slot="{ close }" v-model="showForm" :dirty="dirty" size="sm" :title="editing ? 'Modifica categoria' : 'Nuova categoria'">
      <form @submit.prevent="onSubmit">
        <div class="space-y-4 px-4 py-4 sm:px-6">
          <FormErrors :errors="fieldErrors" :shown="['name', 'type', 'parent_id', 'color']" />
          <div>
            <label class="label" for="cat-name">Nome</label>
            <input id="cat-name" v-model="form.name" class="input" :class="{ 'input-invalid': fieldErrors.name }" required />
            <FieldError :errors="fieldErrors" name="name" />
          </div>
          <div>
            <span class="label">Tipo</span>
            <SegmentedControl
              v-model="form.type"
              :options="[{ value: 'expense', label: CATEGORY_TYPE_LABEL.expense }, { value: 'income', label: CATEGORY_TYPE_LABEL.income }]"
              label="Tipo"
            />
            <FieldError :errors="fieldErrors" name="type" />
          </div>
          <div>
            <label class="label" for="cat-parent">Categoria padre</label>
            <select
              id="cat-parent"
              v-model="form.parent_id"
              class="input"
              :class="{ 'input-invalid': fieldErrors.parent_id }"
              aria-describedby="hint-parent"
            >
              <option :value="null">— Nessuna (categoria principale) —</option>
              <option v-for="c in parentOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p id="hint-parent" class="field-hint">
              Serve solo a raggruppare nella scelta della categoria: report e budget contano comunque ogni sottocategoria a sé.
            </p>
            <FieldError :errors="fieldErrors" name="parent_id" />
          </div>
          <fieldset>
            <legend class="label">Colore</legend>
            <div class="flex flex-wrap gap-2">
              <label
                class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-full border-2 text-xs text-slate-500 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
                :class="form.color === null ? 'border-primary-500' : 'border-slate-200'"
                title="Nessuno"
              >
                <input v-model="form.color" type="radio" :value="null" class="sr-only" aria-label="Nessun colore" />
                —
              </label>
              <label
                v-for="c in CATEGORY_PALETTE"
                :key="c"
                class="h-9 w-9 cursor-pointer rounded-full ring-offset-2 ring-offset-surface has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
                :class="form.color === c ? 'ring-2 ring-slate-900' : ''"
                :style="{ background: c }"
              >
                <input v-model="form.color" type="radio" :value="c" class="sr-only" :aria-label="`Colore ${c}`" />
              </label>
            </div>
            <p class="field-hint">Lo vedi nei report, nei budget e in questa pagina.</p>
            <FieldError :errors="fieldErrors" name="color" />
          </fieldset>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" @click="close">Annulla</button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? 'Salvataggio…' : editing ? 'Salva' : 'Crea' }}
          </button>
        </div>
      </form>
    </AppModal>
  </div>
</template>
