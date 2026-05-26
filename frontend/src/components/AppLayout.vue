<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const nav = [
  { name: 'dashboard', label: 'Dashboard' },
  { name: 'accounts', label: 'Conti' },
  { name: 'transactions', label: 'Transazioni' },
  { name: 'categories', label: 'Categorie' },
  { name: 'tags', label: 'Tag' },
  { name: 'categorization-rules', label: 'Regole categoria' },
  { name: 'budgets', label: 'Budget' },
  { name: 'recurring', label: 'Ricorrenti' },
  { name: 'reports', label: 'Report' },
  { name: 'stats', label: 'Statistiche' },
  { name: 'import-export', label: 'Import / Export' },
]

const mobileOpen = ref(false)

watch(
  () => route.fullPath,
  () => {
    mobileOpen.value = false
  },
)

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}

function currentLabel(): string {
  return nav.find((n) => n.name === route.name)?.label ?? 'Finance'
}
</script>

<template>
  <div class="min-h-screen flex flex-col lg:flex-row">
    <header
      class="lg:hidden sticky top-0 z-30 flex items-center justify-between bg-slate-900 text-slate-100 px-4 h-14 shadow"
    >
      <button
        type="button"
        class="inline-flex items-center justify-center w-10 h-10 rounded-md hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400"
        :aria-label="mobileOpen ? 'Chiudi menu' : 'Apri menu'"
        :aria-expanded="mobileOpen"
        @click="mobileOpen = !mobileOpen"
      >
        <svg
          v-if="!mobileOpen"
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          class="w-6 h-6"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
        <svg
          v-else
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          class="w-6 h-6"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
        </svg>
      </button>
      <span class="text-sm font-semibold truncate">{{ currentLabel() }}</span>
      <span class="w-10" aria-hidden="true" />
    </header>

    <div
      v-if="mobileOpen"
      class="lg:hidden fixed inset-0 z-40 bg-slate-900/60"
      aria-hidden="true"
      @click="mobileOpen = false"
    />

    <aside
      class="bg-slate-900 text-slate-100 flex flex-col fixed inset-y-0 left-0 z-40 w-64 transform transition-transform duration-200 lg:static lg:translate-x-0 lg:w-60"
      :class="mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    >
      <div class="px-6 py-5 border-b border-slate-800 flex items-start justify-between gap-2">
        <div class="min-w-0">
          <h1 class="text-lg font-semibold">Finance</h1>
          <p class="text-xs text-slate-400 mt-1 truncate">{{ auth.user?.email }}</p>
        </div>
        <button
          type="button"
          class="lg:hidden inline-flex items-center justify-center w-8 h-8 rounded-md hover:bg-slate-800"
          aria-label="Chiudi menu"
          @click="mobileOpen = false"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
          </svg>
        </button>
      </div>
      <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          class="block px-4 py-2 rounded text-sm font-medium hover:bg-slate-800"
          exact-active-class="bg-slate-800 text-white"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
      <button type="button" class="m-3 btn btn-secondary" @click="onLogout">
        Esci
      </button>
    </aside>

    <main class="flex-1 min-w-0 p-4 sm:p-6 bg-slate-50">
      <RouterView />
    </main>
  </div>
</template>
