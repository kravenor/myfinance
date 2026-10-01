import { ref, type Ref } from 'vue'
import type { AxiosError } from 'axios'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/api'

export type PaginationMeta = Paginated<unknown>['meta']
export type FieldErrors = Record<string, string[]>

export function useCrud<T extends { id: number }>(resource: string) {
  const items = ref<T[]>([]) as Ref<T[]>
  const loading = ref(false)
  const error = ref<string | null>(null)
  const meta = ref<PaginationMeta | null>(null)
  const submitting = ref(false)
  const fieldErrors = ref<FieldErrors>({})

  // Errori 422 esposti per campo; l'errore viene rilanciato alla view.
  async function submit<R>(fn: () => Promise<R>): Promise<R> {
    submitting.value = true
    fieldErrors.value = {}
    try {
      return await fn()
    } catch (e: unknown) {
      const err = e as AxiosError<{ errors?: FieldErrors }>
      if (err.response?.status === 422) fieldErrors.value = err.response.data?.errors ?? {}
      throw e
    } finally {
      submitting.value = false
    }
  }

  async function list(params: Record<string, unknown> = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get<Paginated<T>>(`/${resource}`, { params })
      items.value = data.data
      meta.value = data.meta
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      error.value = err.response?.data?.message ?? 'Errore caricamento.'
    } finally {
      loading.value = false
    }
  }

  function create(payload: Record<string, unknown>): Promise<T> {
    return submit(async () => {
      const { data } = await api.post<{ data: T }>(`/${resource}`, payload)
      items.value.unshift(data.data)
      return data.data
    })
  }

  function update(id: number, payload: Record<string, unknown>): Promise<T> {
    return submit(async () => {
      const { data } = await api.patch<{ data: T }>(`/${resource}/${id}`, payload)
      const idx = items.value.findIndex((i) => i.id === id)
      if (idx !== -1) items.value[idx] = data.data
      return data.data
    })
  }

  async function destroy(id: number): Promise<void> {
    await api.delete(`/${resource}/${id}`)
    items.value = items.value.filter((i) => i.id !== id)
  }

  return { items, loading, error, meta, submitting, fieldErrors, list, create, update, destroy }
}
