<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import type { Account, Paginated, Transaction } from '@/types/api'

const accounts = ref<Account[]>([])
const recent = ref<Transaction[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const [a, t] = await Promise.all([
      api.get<Paginated<Account>>('/accounts'),
      api.get<Paginated<Transaction>>('/transactions', { params: { per_page: 5 } }),
    ])
    accounts.value = a.data.data
    recent.value = t.data.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-semibold">Dashboard</h1>

    <section>
      <h2 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-2">Conti</h2>
      <div v-if="loading" class="text-sm text-slate-500">Caricamento…</div>
      <div v-else-if="accounts.length === 0" class="text-sm text-slate-500">
        Nessun conto. Vai in <RouterLink to="/accounts" class="text-indigo-600 hover:underline">Conti</RouterLink> per crearne uno.
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div v-for="acc in accounts" :key="acc.id" class="card p-4">
          <p class="text-xs uppercase text-slate-500">{{ acc.type }}</p>
          <p class="text-lg font-semibold mt-1">{{ acc.name }}</p>
          <p class="text-sm text-slate-600 mt-1">{{ acc.initial_balance }} {{ acc.currency }}</p>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-sm font-medium text-slate-600 uppercase tracking-wide mb-2">Ultime transazioni</h2>
      <div v-if="loading" class="text-sm text-slate-500">Caricamento…</div>
      <div v-else-if="recent.length === 0" class="text-sm text-slate-500">Nessuna transazione.</div>
      <div v-else class="card overflow-x-auto">
        <table class="table">
          <thead class="bg-slate-100">
            <tr>
              <th>Data</th>
              <th>Tipo</th>
              <th>Descrizione</th>
              <th class="text-right">Importo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="tx in recent" :key="tx.id">
              <td>{{ tx.occurred_at }}</td>
              <td class="capitalize">{{ tx.type }}</td>
              <td>{{ tx.description ?? '—' }}</td>
              <td class="text-right font-medium">{{ tx.amount }} {{ tx.currency }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
