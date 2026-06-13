// Valute supportate (allineate a backend config/finance.php → finance.currencies).
export const CURRENCIES = [
  'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'NZD', 'CNY', 'HKD',
  'SGD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'ISK',
  'TRY', 'ILS', 'INR', 'KRW', 'THB', 'IDR', 'MYR', 'PHP', 'ZAR', 'MXN', 'BRL',
] as const

/**
 * Formatta un importo monetario nella valuta indicata (locale it-IT).
 * Fallback su "valore CUR" se la valuta non è riconosciuta da Intl.
 */
export function formatCurrency(
  value: string | number | null | undefined,
  currency = 'EUR',
): string {
  const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0)
  if (Number.isNaN(n)) return '—'
  try {
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency }).format(n)
  } catch {
    return `${n.toFixed(2)} ${currency}`
  }
}
