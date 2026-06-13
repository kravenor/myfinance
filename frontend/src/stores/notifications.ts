import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'
import type { AppNotification } from '@/types/api'

export const useNotificationStore = defineStore('notifications', () => {
  const items = ref<AppNotification[]>([])
  const unreadCount = ref(0)
  const loading = ref(false)

  async function fetch(): Promise<void> {
    loading.value = true
    try {
      const { data } = await api.get<{ data: AppNotification[]; unread_count: number }>('/notifications')
      items.value = data.data
      unreadCount.value = data.unread_count
    } finally {
      loading.value = false
    }
  }

  async function markRead(id: string): Promise<void> {
    const { data } = await api.post<{ unread_count: number }>(`/notifications/${id}/read`)
    unreadCount.value = data.unread_count
    const n = items.value.find((i) => i.id === id)
    if (n && !n.read_at) n.read_at = new Date().toISOString()
  }

  async function markAllRead(): Promise<void> {
    await api.post('/notifications/read-all')
    unreadCount.value = 0
    items.value.forEach((n) => {
      if (!n.read_at) n.read_at = new Date().toISOString()
    })
  }

  async function remove(id: string): Promise<void> {
    await api.delete(`/notifications/${id}`)
    items.value = items.value.filter((i) => i.id !== id)
  }

  return { items, unreadCount, loading, fetch, markRead, markAllRead, remove }
})
