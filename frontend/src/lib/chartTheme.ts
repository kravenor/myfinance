// Colori dei grafici Chart.js, allineati ai token di tailwind.config.js
// (income = emerald-500, expense = rose-500, primary = indigo-500).
export const INCOME_COLOR = '#10b981'
export const EXPENSE_COLOR = '#f43f5e'
export const PRIMARY_COLOR = '#6366f1'
export const MUTED_COLOR = '#94a3b8'
export const FALLBACK_TAG_COLOR = '#475569'

export const CATEGORY_PALETTE = [
  '#6366f1', '#ec4899', '#10b981', '#f59e0b', '#0ea5e9', '#a855f7',
  '#14b8a6', '#ef4444', '#84cc16', '#eab308', '#06b6d4', '#f97316',
]

export function paletteColor(i: number): string {
  return CATEGORY_PALETTE[i % CATEGORY_PALETTE.length]
}
