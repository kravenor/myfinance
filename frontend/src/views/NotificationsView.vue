<script setup lang="ts">
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
      return 'bg-red-100 text-red-700'
    case 'warning':
    case 'behind':
      return 'bg-amber-100 text-amber-700'
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
      <h1 class="text-xl sm:text-2xl font-semibold">
        Notifiche
        <span v-if="store.unreadCount > 0" class="text-sm font-normal text-slate-500">
          ({{ store.unreadCount }} non lette)
        </span>
      </h1>
      <button
        class="btn-secondary"
        :disabled="store.unreadCount === 0"
        @click="store.markAllRead()"
      >
        Segna tutte come lette
      </button>
    </div>

    <div class="card divide-y divide-slate-100">
      <p v-if="store.loading" class="p-4 text-sm text-slate-500">Caricamento…</p>
      <template v-else>
        <div
          v-for="n in store.items"
          :key="n.id"
          class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer"
          :class="{ 'bg-indigo-50/40': !n.read_at }"
          @click="open(n)"
        >
          <span v-if="!n.read_at" class="mt-1.5 w-2 h-2 rounded-full bg-indigo-500 shrink-0" aria-hidden="true" />
          <span v-else class="mt-1.5 w-2 h-2 shrink-0" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ n.title }}</span>
              <span v-if="n.level" class="text-xs px-2 py-0.5 rounded capitalize" :class="levelClass(n.level)">
                {{ n.level }}
              </span>
            </div>
            <p class="text-sm text-slate-600 mt-0.5">{{ n.message }}</p>
            <p class="text-xs text-slate-400 mt-1">{{ n.created_at?.slice(0, 10) }}</p>
          </div>
          <button
            type="button"
            class="text-slate-400 hover:text-red-500 text-sm shrink-0"
            title="Elimina"
            @click.stop="store.remove(n.id)"
          >
            ✕
          </button>
        </div>
        <p v-if="store.items.length === 0" class="p-6 text-center text-slate-500">
          Nessuna notifica.
        </p>
      </template>
    </div>
  </div>
</template>
