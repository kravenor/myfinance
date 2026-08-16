<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useCrud } from '@/composables/useCrud'
import RowActions from '@/components/ui/RowActions.vue'
import { CURRENCIES, formatCurrency } from '@/lib/money'
import type { Account, AccountType } from '@/types/api'

const { items, loading, list, create, update, destroy } = useCrud<Account>('accounts')

const types: AccountType[] = ['cash', 'bank', 'card', 'investment', 'other']

const editing = ref<Account | null>(null)
const showForm = ref(false)
const form = ref({
  name: '',
  type: 'bank' as AccountType,
  currency: 'EUR',
  initial_balance: '0.00',
  is_primary: false,
  notes: '',
})

function reset() {
  editing.value = null
  form.value = {
    name: '',
    type: 'bank' as AccountType,
    currency: 'EUR',
    initial_balance: '0.00',
    is_primary: false,
    notes: '',
  }
}

function startEdit(acc: Account) {
  editing.value = acc
  form.value = {
    name: acc.name,
    type: acc.type,
    currency: acc.currency,
    initial_balance: acc.initial_balance,
    is_primary: acc.is_primary ?? false,
    notes: acc.notes ?? '',
  }
  showForm.value = true
}

async function onSubmit() {
  if (editing.value) {
    await update(editing.value.id, form.value)
    await list()
  } else {
    await create(form.value)
    await list()
  }
  reset()
  showForm.value = false
}

async function setPrimary(acc: Account) {
  if (acc.is_primary) return
  await update(acc.id, { is_primary: true })
  await list()
}

async function onDelete(acc: Account) {
  if (!confirm(`Eliminare il conto "${acc.name}"?`)) return
  await destroy(acc.id)
}

onMounted(() => list())
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl sm:text-2xl font-semibold">Conti</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuovo conto' }}
      </button>
    </div>

    <button
      v-if="!showForm"
      type="button"
      class="lg:hidden fixed bottom-5 right-5 z-20 w-14 h-14 rounded-full btn-primary shadow-lg text-2xl leading-none"
      aria-label="Nuovo conto"
      @click="showForm = true; reset()"
    >+</button>

    <form v-if="showForm" class="card p-4 grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="onSubmit">
      <div>
        <label class="label">Nome</label>
        <input v-model="form.name" class="input" required />
      </div>
      <div>
        <label class="label">Tipo</label>
        <select v-model="form.type" class="input">
          <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div>
        <label class="label">Valuta</label>
        <select v-model="form.currency" class="input" required>
          <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div class="flex items-center gap-2">
        <input id="is_primary" type="checkbox" v-model="form.is_primary" class="w-4 h-4" />
        <label for="is_primary" class="text-sm">Conto principale</label>
      </div>
      <div>
        <label class="label">Saldo iniziale</label>
        <input v-model="form.initial_balance" type="number" step="0.01" class="input" />
      </div>
      <div class="md:col-span-2">
        <label class="label">Note</label>
        <textarea v-model="form.notes" class="input" rows="2"></textarea>
      </div>
      <div class="md:col-span-2 flex gap-2 justify-end">
        <button type="button" class="btn-secondary" @click="showForm = false; reset()">Annulla</button>
        <button type="submit" class="btn-primary">{{ editing ? 'Salva' : 'Crea' }}</button>
      </div>
    </form>

    <div class="card">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>

      <!-- Mobile: una card per conto (sotto md). -->
      <ul v-else class="md:hidden divide-y divide-slate-100">
        <li v-for="acc in items" :key="acc.id" class="p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex items-start gap-2">
              <button
                type="button"
                class="text-yellow-500 shrink-0 mt-0.5"
                :title="acc.is_primary ? 'Primario' : 'Imposta come primario'"
                @click="setPrimary(acc)"
              >
                <svg v-if="acc.is_primary" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.966a1 1 0 0 0 .95.69h4.178c.969 0 1.371 1.24.588 1.81l-3.386 2.46a1 1 0 0 0-.364 1.118l1.287 3.966c.3.921-.755 1.688-1.54 1.118l-3.386-2.46a1 1 0 0 0-1.175 0l-3.386 2.46c-.784.57-1.84-.197-1.54-1.118l1.287-3.966a1 1 0 0 0-.364-1.118L2.047 9.393c-.783-.57-.38-1.81.588-1.81h4.178a1 1 0 0 0 .95-.69l1.286-3.966z" />
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.966a1 1 0 0 0 .95.69h4.178c.969 0 1.371 1.24.588 1.81l-3.386 2.46a1 1 0 0 0-.364 1.118l1.287 3.966c.3.921-.755 1.688-1.54 1.118l-3.386-2.46a1 1 0 0 0-1.175 0l-3.386 2.46c-.784.57-1.84-.197-1.54-1.118l1.287-3.966a1 1 0 0 0-.364-1.118L2.047 9.393c-.783-.57-.38-1.81.588-1.81h4.178a1 1 0 0 0 .95-.69l1.286-3.966z" />
                </svg>
              </button>
              <div class="min-w-0">
                <p class="font-medium text-slate-800 truncate">
                  {{ acc.name }}
                  <span v-if="acc.is_primary" class="text-xs text-slate-500 font-normal">(Principale)</span>
                </p>
                <p class="text-xs text-slate-500 mt-0.5 capitalize">{{ acc.type }} · {{ acc.currency }}</p>
              </div>
            </div>
            <div class="text-right shrink-0">
              <p class="font-semibold whitespace-nowrap">{{ formatCurrency(acc.initial_balance, acc.currency) }}</p>
              <RowActions class="mt-2 justify-end" @edit="startEdit(acc)" @delete="onDelete(acc)" />
            </div>
          </div>
        </li>
        <li v-if="items.length === 0" class="p-6 text-center text-slate-500 text-sm">Nessun conto.</li>
      </ul>

      <!-- Desktop / tablet: tabella classica da md in su. -->
      <table v-if="!loading" class="table hidden md:table">
        <thead class="bg-slate-100">
          <tr>
            <th>Nome</th>
            <th>Tipo</th>
            <th>Valuta</th>
            <th class="text-right">Saldo iniziale</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="acc in items" :key="acc.id">
            <td class="font-medium">
              <span class="inline-flex items-center gap-2">
                <button
                  type="button"
                  class="text-yellow-500"
                  :title="acc.is_primary ? 'Primario' : 'Imposta come primario'"
                  @click="setPrimary(acc)"
                >
                  <svg v-if="acc.is_primary" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.966a1 1 0 0 0 .95.69h4.178c.969 0 1.371 1.24.588 1.81l-3.386 2.46a1 1 0 0 0-.364 1.118l1.287 3.966c.3.921-.755 1.688-1.54 1.118l-3.386-2.46a1 1 0 0 0-1.175 0l-3.386 2.46c-.784.57-1.84-.197-1.54-1.118l1.287-3.966a1 1 0 0 0-.364-1.118L2.047 9.393c-.783-.57-.38-1.81.588-1.81h4.178a1 1 0 0 0 .95-.69l1.286-3.966z" />
                  </svg>
                  <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.966a1 1 0 0 0 .95.69h4.178c.969 0 1.371 1.24.588 1.81l-3.386 2.46a1 1 0 0 0-.364 1.118l1.287 3.966c.3.921-.755 1.688-1.54 1.118l-3.386-2.46a1 1 0 0 0-1.175 0l-3.386 2.46c-.784.57-1.84-.197-1.54-1.118l1.287-3.966a1 1 0 0 0-.364-1.118L2.047 9.393c-.783-.57-.38-1.81.588-1.81h4.178a1 1 0 0 0 .95-.69l1.286-3.966z" />
                  </svg>
                </button>
                {{ acc.name }}
                <span v-if="acc.is_primary" class="text-xs text-slate-500">(Principale)</span>
              </span>
            </td>
            <td class="capitalize">{{ acc.type }}</td>
            <td>{{ acc.currency }}</td>
            <td class="text-right">{{ formatCurrency(acc.initial_balance, acc.currency) }}</td>
            <td class="text-right">
              <RowActions @edit="startEdit(acc)" @delete="onDelete(acc)" />
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="5" class="text-center text-slate-500 py-6">Nessun conto.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
