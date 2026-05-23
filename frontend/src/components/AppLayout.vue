<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const nav = [
  { name: 'dashboard', label: 'Dashboard' },
  { name: 'accounts', label: 'Conti' },
  { name: 'transactions', label: 'Transazioni' },
  { name: 'categories', label: 'Categorie' },
  { name: 'tags', label: 'Tag' },
  { name: 'budgets', label: 'Budget' },
  { name: 'recurring', label: 'Ricorrenti' },
  { name: 'reports', label: 'Report' },
]

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex">
    <aside class="w-60 bg-slate-900 text-slate-100 flex flex-col">
      <div class="px-6 py-5 border-b border-slate-800">
        <h1 class="text-lg font-semibold">Finance</h1>
        <p class="text-xs text-slate-400 mt-1">{{ auth.user?.email }}</p>
      </div>
      <nav class="flex-1 px-2 py-4 space-y-1">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          class="block px-4 py-2 rounded text-sm font-medium hover:bg-slate-800"
          active-class="bg-slate-800 text-white"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
      <button
        type="button"
        class="m-3 btn btn-secondary"
        @click="onLogout"
      >
        Esci
      </button>
    </aside>

    <main class="flex-1 p-6 bg-slate-50">
      <RouterView />
    </main>
  </div>
</template>
