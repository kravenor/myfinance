import { useAuthStore } from '@/stores/auth'

// Formati supportati (allineati a backend config/finance.php → finance.date_formats).
export const DATE_FORMATS = ['d/m/Y', 'Y-m-d', 'm/d/Y', 'd.m.Y', 'd M Y'] as const
export const DEFAULT_DATE_FORMAT = 'd/m/Y'

/**
 * Le date "solo giorno" (YYYY-MM-DD) vanno lette come data locale: passarle a
 * `new Date()` le interpreta come UTC e sposta il giorno nei fusi negativi.
 * I timestamp completi (con "T") usano il parsing nativo.
 */
function toDate(value: string | Date): Date | null {
  if (value instanceof Date) return value
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  const date = dateOnly
    ? new Date(+dateOnly[1], +dateOnly[2] - 1, +dateOnly[3])
    : new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

/** Formatta con un formato esplicito. Token: d, m, M (mese abbreviato), Y. */
export function formatDateWith(
  value: string | Date | null | undefined,
  format: string = DEFAULT_DATE_FORMAT,
): string {
  if (!value) return '—'
  const date = toDate(value)
  if (!date) return '—'
  const tokens: Record<string, string> = {
    d: String(date.getDate()).padStart(2, '0'),
    m: String(date.getMonth() + 1).padStart(2, '0'),
    M: date.toLocaleDateString('it-IT', { month: 'short' }),
    Y: String(date.getFullYear()),
  }
  // Una sola passata: evita che il testo già sostituito venga rimpiazzato di nuovo.
  return format.replace(/[dmMY]/g, (t) => tokens[t])
}

/** Formatta con la preferenza dell'utente loggato (reattiva). */
export function formatDate(value: string | Date | null | undefined): string {
  return formatDateWith(value, useAuthStore().user?.date_format || DEFAULT_DATE_FORMAT)
}

/**
 * Etichetta di un periodo mensile "YYYY-MM" (formato usato dai report) con la
 * preferenza dell'utente privata del giorno: `d/m/Y` → `09/2026`, `Y-m-d` →
 * `2026-09`. Valori non mensili (es. "2026") passano invariati.
 */
export function formatMonth(period: string | null | undefined): string {
  if (!period) return '—'
  const match = /^(\d{4})-(\d{2})$/.exec(period)
  if (!match) return period
  const format = useAuthStore().user?.date_format || DEFAULT_DATE_FORMAT
  // Toglie il token giorno, poi collassa i separatori rimasti doppi o ai bordi.
  const monthFormat = format
    .replace(/d/, '')
    .replace(/([^dmMY])\1+/g, '$1')
    .replace(/^[^dmMY]+|[^dmMY]+$/g, '')
  return formatDateWith(new Date(+match[1], +match[2] - 1, 1), monthFormat)
}

const MAX_MONTH_START_DAY = 28

function monthStartDay(): number {
  const day = useAuthStore().user?.month_start_day ?? 1
  return Math.min(MAX_MONTH_START_DAY, Math.max(1, day))
}

function toIsoDate(date: Date): string {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-')
}

/**
 * Inizio del "mese finanziario" che contiene `ref` (preferenza
 * `month_start_day`): con `1` è il 1° del mese, come prima.
 */
export function financialMonthStart(ref: Date = new Date()): Date {
  const day = monthStartDay()
  const start = new Date(ref.getFullYear(), ref.getMonth(), day)
  if (ref.getDate() < day) start.setMonth(start.getMonth() - 1)
  return start
}

/** Range ISO `from`/`to` del mese finanziario che contiene `ref`. */
export function financialMonthRange(ref: Date = new Date()): { from: string; to: string } {
  const start = financialMonthStart(ref)
  const end = new Date(start.getFullYear(), start.getMonth() + 1, start.getDate() - 1)
  return { from: toIsoDate(start), to: toIsoDate(end) }
}
