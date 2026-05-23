<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useCrud } from '@/composables/useCrud'
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
  notes: '',
})

function reset() {
  editing.value = null
  form.value = { name: '', type: 'bank', currency: 'EUR', initial_balance: '0.00', notes: '' }
}

function startEdit(acc: Account) {
  editing.value = acc
  form.value = {
    name: acc.name,
    type: acc.type,
    currency: acc.currency,
    initial_balance: acc.initial_balance,
    notes: acc.notes ?? '',
  }
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

async function onDelete(acc: Account) {
  if (!confirm(`Eliminare il conto "${acc.name}"?`)) return
  await destroy(acc.id)
}

onMounted(() => list())
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Conti</h1>
      <button class="btn-primary" @click="showForm = !showForm; reset()">
        {{ showForm ? 'Annulla' : 'Nuovo conto' }}
      </button>
    </div>

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
        <input v-model="form.currency" class="input" maxlength="3" required />
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

    <div class="card overflow-x-auto">
      <p v-if="loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <table v-else class="table">
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
            <td class="font-medium">{{ acc.name }}</td>
            <td class="capitalize">{{ acc.type }}</td>
            <td>{{ acc.currency }}</td>
            <td class="text-right">{{ acc.initial_balance }}</td>
            <td class="text-right space-x-2">
              <button class="text-indigo-600 hover:underline text-sm" @click="startEdit(acc)">Modifica</button>
              <button class="text-red-600 hover:underline text-sm" @click="onDelete(acc)">Elimina</button>
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
