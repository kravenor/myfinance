<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { NAV_GROUPS, NAV_ITEMS, useMenuStore } from '@/stores/menu'
import AppIcon from '@/components/ui/AppIcon.vue'
import LegalLinks from '@/components/LegalLinks.vue'
import { syncPush } from '@/lib/push'

const auth = useAuthStore()
const notifications = useNotificationStore()
const menu = useMenuStore()
const router = useRouter()
const route = useRoute()
const version = import.meta.env.VITE_APP_VERSION || 'dev'

// Gruppi con almeno una voce visibile, nell'ordine di NAV_GROUPS.
const groups = computed(() =>
  NAV_GROUPS.map((group) => ({
    group,
    items: NAV_ITEMS.filter((item) => item.group === group && menu.isVisible(item.name)),
  })).filter((g) => g.items.length > 0),
)

const mobileOpen = ref(false)
const userMenu = ref<HTMLDetailsElement | null>(null)

watch(
  () => route.fullPath,
  () => {
    mobileOpen.value = false
    if (userMenu.value) userMenu.value.open = false
  },
)

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}

// Scorciatoie: N nuova transazione, / ricerca della pagina. Mai mentre si scrive o con una modale aperta.
function onKeydown(e: KeyboardEvent) {
  if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return
  const target = e.target as HTMLElement | null
  if (target?.closest('input, textarea, select, [contenteditable]') || document.querySelector('dialog[open]')) return
  if (e.key === 'n' || e.key === 'N') {
    e.preventDefault()
    router.push({ name: 'transactions', query: { new: '1' } })
  } else if (e.key === '/') {
    const search = document.querySelector<HTMLInputElement>('main input[type="search"]')
    if (search) {
      e.preventDefault()
      search.focus()
    }
  }
}

// Il service worker avvisa all'arrivo di una push: lista e badge si aggiornano senza ricaricare.
function onWorkerMessage(e: MessageEvent) {
  if (e.data?.type === 'notifications:refresh') notifications.fetch().catch(() => {})
}

// Badge aggiornato anche con l'app aperta a lungo (la PWA resta in background per ore):
// ogni 5 minuti a pagina visibile e subito quando torna in primo piano.
const REFRESH_MS = 5 * 60 * 1000
let refreshTimer: ReturnType<typeof setInterval> | undefined
function refreshNotifications() {
  if (document.visibilityState === 'visible') notifications.fetch().catch(() => {})
}

onMounted(() => {
  notifications.fetch().catch(() => {})
  syncPush().catch(() => {})
  window.addEventListener('keydown', onKeydown)
  navigator.serviceWorker?.addEventListener('message', onWorkerMessage)
  document.addEventListener('visibilitychange', refreshNotifications)
  refreshTimer = setInterval(refreshNotifications, REFRESH_MS)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  navigator.serviceWorker?.removeEventListener('message', onWorkerMessage)
  document.removeEventListener('visibilitychange', refreshNotifications)
  clearInterval(refreshTimer)
})

const currentLabel = computed(() => NAV_ITEMS.find((n) => n.name === route.name)?.label ?? 'Finance')
const initial = computed(() => (auth.user?.name || auth.user?.email || '?').charAt(0).toUpperCase())
</script>

<template>
  <div class="min-h-screen lg:flex">
    <div
      v-if="mobileOpen"
      class="lg:hidden fixed inset-0 z-40 bg-black/50"
      aria-hidden="true"
      @click="mobileOpen = false"
    />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-surface transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-60 lg:translate-x-0"
      :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-14 items-center justify-between gap-2 px-5">
        <RouterLink :to="{ name: 'dashboard' }" class="flex items-center gap-2 font-semibold text-slate-900">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-primary-600 text-white">
            <AppIcon name="chart-bar" class="h-5 w-5" />
          </span>
          Finance
        </RouterLink>
        <button
          type="button"
          class="icon-btn lg:hidden text-slate-500 hover:bg-slate-100 focus:ring-primary-500"
          aria-label="Chiudi menu"
          @click="mobileOpen = false"
        >
          <AppIcon name="x-mark" class="h-5 w-5" />
        </button>
      </div>

      <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Menu principale">
        <div v-for="g in groups" :key="g.group" class="mt-4 first:mt-2">
          <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ g.group }}</p>
          <RouterLink
            v-for="item in g.items"
            :key="item.name"
            :to="{ name: item.name }"
            class="nav-link"
            exact-active-class="nav-link-active"
          >
            <AppIcon :name="item.icon" class="h-5 w-5 shrink-0" />
            <span class="flex-1 truncate">{{ item.label }}</span>
            <span
              v-if="item.name === 'notifications' && notifications.unreadCount > 0"
              class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-primary-600 px-1.5 text-xs text-white"
            >
              {{ notifications.unreadCount }}
            </span>
          </RouterLink>
        </div>
      </nav>

      <div class="border-t border-slate-200 p-3 lg:hidden">
        <p class="truncate px-3 pb-2 text-xs text-slate-500">{{ auth.user?.email }}</p>
        <button type="button" class="nav-link w-full" @click="onLogout">
          <AppIcon name="arrow-right-start-on-rectangle" class="h-5 w-5" />
          Esci
        </button>
      </div>
      <LegalLinks class="border-t border-slate-200 px-3 pt-3" />
      <p class="px-3 pt-1 pb-3 text-center text-xs text-slate-400" title="Versione dell'app">{{ version }}</p>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header
        class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-surface/90 px-4 backdrop-blur sm:px-6"
      >
        <button
          type="button"
          class="icon-btn lg:hidden -ml-2 text-slate-600 hover:bg-slate-100 focus:ring-primary-500"
          :aria-label="mobileOpen ? 'Chiudi menu' : 'Apri menu'"
          :aria-expanded="mobileOpen"
          @click="mobileOpen = !mobileOpen"
        >
          <AppIcon name="bars-3" class="h-6 w-6" />
        </button>
        <span class="truncate text-sm font-semibold text-slate-900 lg:hidden">{{ currentLabel }}</span>

        <div class="ml-auto flex items-center gap-1 sm:gap-2">
          <RouterLink
            :to="{ name: 'transactions', query: { new: '1' } }"
            class="btn-primary hidden gap-1.5 lg:inline-flex"
            title="Nuova transazione (N)"
            aria-keyshortcuts="n"
          >
            <AppIcon name="plus" class="h-4 w-4" />
            Transazione
            <kbd class="ml-1 rounded border border-white/40 px-1.5 text-xs font-normal leading-5">N</kbd>
          </RouterLink>

          <RouterLink
            :to="{ name: 'notifications' }"
            class="icon-btn relative text-slate-600 hover:bg-slate-100 focus:ring-primary-500"
            :aria-label="notifications.unreadCount ? `Notifiche, ${notifications.unreadCount} non lette` : 'Notifiche'"
          >
            <AppIcon name="bell" class="h-5 w-5" />
            <span
              v-if="notifications.unreadCount > 0"
              class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-danger-500 ring-2 ring-surface"
              aria-hidden="true"
            />
          </RouterLink>

          <details ref="userMenu" class="relative hidden lg:block">
            <summary
              class="flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700 hover:bg-primary-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 [&::-webkit-details-marker]:hidden"
              aria-label="Menu utente"
            >
              {{ initial }}
            </summary>
            <div class="absolute right-0 mt-2 w-60 rounded-lg border border-slate-200 bg-surface py-1 shadow-lg">
              <p class="truncate px-4 py-2 text-xs text-slate-500">{{ auth.user?.email }}</p>
              <RouterLink :to="{ name: 'settings' }" class="menu-item">
                <AppIcon name="cog-6-tooth" class="h-4 w-4" />
                Impostazioni
              </RouterLink>
              <button type="button" class="menu-item w-full" @click="onLogout">
                <AppIcon name="arrow-right-start-on-rectangle" class="h-4 w-4" />
                Esci
              </button>
            </div>
          </details>
        </div>
      </header>

      <main class="flex-1 bg-slate-50 p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
