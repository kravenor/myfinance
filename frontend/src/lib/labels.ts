import type { TransactionType } from '@/types/api'

// Etichette di display: i valori inviati all'API restano income/expense/transfer.
export const TX_TYPE_LABEL: Record<TransactionType, string> = {
  income: 'Entrata',
  expense: 'Uscita',
  transfer: 'Giroconto',
}

export const TX_TYPES = Object.keys(TX_TYPE_LABEL) as TransactionType[]
