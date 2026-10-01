import { ref } from 'vue'

export interface ConfirmOptions {
  title?: string
  detail?: string
  confirmLabel?: string
  danger?: boolean
}

interface PendingConfirm extends Required<ConfirmOptions> {
  message: string
  resolve: (ok: boolean) => void
}

// Stato singolo condiviso: una conferma alla volta, mostrata da ConfirmHost (App.vue).
export const pendingConfirm = ref<PendingConfirm | null>(null)

export function confirmAction(message: string, options: ConfirmOptions = {}): Promise<boolean> {
  pendingConfirm.value?.resolve(false)
  return new Promise((resolve) => {
    pendingConfirm.value = {
      message,
      title: options.title ?? 'Conferma eliminazione',
      detail: options.detail ?? "L'operazione non può essere annullata.",
      confirmLabel: options.confirmLabel ?? 'Elimina',
      danger: options.danger ?? true,
      resolve,
    }
  })
}

export function settleConfirm(ok: boolean): void {
  pendingConfirm.value?.resolve(ok)
  pendingConfirm.value = null
}
