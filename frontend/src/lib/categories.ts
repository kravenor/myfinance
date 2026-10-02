import type { Category, TransactionType } from '@/types/api'

export interface CategoryOption {
  id: number
  label: string
}

// Opzioni di select per il tipo dato, ad albero (padre → figli indentati). Un giroconto non ha categorie.
export function categoryOptions(categories: Category[], type: TransactionType): CategoryOption[] {
  if (type === 'transfer') return []
  const list = categories.filter((c) => c.type === type)
  const ids = new Set(list.map((c) => c.id))
  const byParent = new Map<number | null, Category[]>()
  for (const c of list) {
    byParent.set(c.parent_id, [...(byParent.get(c.parent_id) ?? []), c])
  }
  const sortFn = (a: Category, b: Category) => a.sort_order - b.sort_order || a.name.localeCompare(b.name)
  const out: CategoryOption[] = []
  const walk = (cat: Category, depth: number) => {
    out.push({ id: cat.id, label: '   '.repeat(depth) + (depth ? '↳ ' : '') + cat.name })
    for (const ch of (byParent.get(cat.id) ?? []).slice().sort(sortFn)) walk(ch, depth + 1)
  }
  // Radici: senza padre, o con un padre di tipo diverso (assente dalla lista filtrata).
  for (const r of list.filter((c) => c.parent_id == null || !ids.has(c.parent_id)).sort(sortFn)) walk(r, 0)
  return out
}
