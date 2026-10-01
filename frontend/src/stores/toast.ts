import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastTone = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  tone: ToastTone
  message: string
}

let seq = 0

export const useToastStore = defineStore('toast', () => {
  const items = ref<Toast[]>([])

  function dismiss(id: number): void {
    items.value = items.value.filter((t) => t.id !== id)
  }

  function push(message: string, tone: ToastTone = 'success', timeout = 4000): void {
    // Lo stesso errore da più richieste parallele si mostra una volta sola.
    if (items.value.some((t) => t.message === message)) return
    const id = ++seq
    items.value.push({ id, tone, message })
    if (timeout > 0) setTimeout(() => dismiss(id), timeout)
  }

  return {
    items,
    dismiss,
    success: (m: string) => push(m, 'success'),
    error: (m: string) => push(m, 'error', 7000),
    info: (m: string) => push(m, 'info'),
  }
})
