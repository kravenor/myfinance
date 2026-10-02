<script setup lang="ts">
import { formatDate } from '@/lib/date'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { NOTIFICATION_LEVEL_LABEL } from '@/lib/labels'
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'
import type { AppNotification } from '@/types/api'

const store = useNotificationStore()
const router = useRouter()

function levelClass(level: string | null): string {
  switch (level) {
    case 'exceeded':
    case 'overdue':
      return 'bg-danger-100 text-danger-700'
    case 'warning':
    case 'behind':
      return 'bg-warning-100 text-warning-700'
    default:
      return 'bg-slate-100 text-slate-600'
  }
}

async function open(n: AppNotification) {
  if (!n.read_at) await store.markRead(n.id)
  if (n.url) router.push(n.url)
}

onMounted(() => store.fetch())
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">
          Notifiche
          <span v-if="store.unreadCount > 0" class="text-sm font-normal text-slate-500">
            ({{ store.unreadCount }} non lette)
          </span>
        </h1>
        <p class="page-desc">
          Avvisi su budget, obiettivi, rate PAC, quotazioni ferme, spese importanti e riepilogo mensile: arrivano appena cambiano i dati e con il controllo di ogni mattina, una volta per periodo. Scegli quali ricevere in Impostazioni.
        </p>
      </div>
      <button
        class="btn-secondary"
        :disabled="store.unreadCount === 0"
        @click="store.markAllRead()"
      >
        Segna tutte come lette
      </button>
    </div>

    <div class="card divide-y divide-slate-100">
      <ListSkeleton v-if="store.loading && !store.items.length" />
      <template v-else>
        <div
          v-for="n in store.items"
          :key="n.id"
          class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer"
          :class="{ 'bg-primary-50/40': !n.read_at }"
          @click="open(n)"
        >
          <span v-if="!n.read_at" class="mt-1.5 w-2 h-2 rounded-full bg-primary-500 shrink-0" aria-hidden="true" />
          <span v-else class="mt-1.5 w-2 h-2 shrink-0" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ n.title }}</span>
              <span v-if="n.level" class="text-xs px-2 py-0.5 rounded" :class="levelClass(n.level)">
                {{ NOTIFICATION_LEVEL_LABEL[n.level] ?? n.level }}
              </span>
            </div>
            <p class="text-sm text-slate-600 mt-0.5">{{ n.message }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ formatDate(n.created_at) }}</p>
          </div>
          <button
            type="button"
            class="text-slate-500 hover:text-danger-600 text-sm shrink-0"
            title="Elimina"
            @click.stop="store.remove(n.id)"
          >
            ✕
          </button>
        </div>
        <EmptyState v-if="store.items.length === 0" title="Non hai notifiche." />
      </template>
    </div>
  </div>
</template>
