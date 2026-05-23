import { ref, type Ref } from 'vue'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/api'

export function useCrud<T extends { id: number }>(resource: string) {
  const items = ref<T[]>([]) as Ref<T[]>
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function list(params: Record<string, unknown> = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get<Paginated<T>>(`/${resource}`, { params })
      items.value = data.data
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      error.value = err.response?.data?.message ?? 'Errore caricamento.'
    } finally {
      loading.value = false
    }
  }

  async function create(payload: Record<string, unknown>): Promise<T> {
    const { data } = await api.post<{ data: T }>(`/${resource}`, payload)
    items.value.unshift(data.data)
    return data.data
  }

  async function update(id: number, payload: Record<string, unknown>): Promise<T> {
    const { data } = await api.patch<{ data: T }>(`/${resource}/${id}`, payload)
    const idx = items.value.findIndex((i) => i.id === id)
    if (idx !== -1) items.value[idx] = data.data
    return data.data
  }

  async function destroy(id: number): Promise<void> {
    await api.delete(`/${resource}/${id}`)
    items.value = items.value.filter((i) => i.id !== id)
  }

  return { items, loading, error, list, create, update, destroy }
}
