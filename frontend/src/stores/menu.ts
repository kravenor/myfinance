import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

const STORAGE_KEY = 'menu.hidden'

export interface NavItem {
  name: string
  label: string
}

// Fonte unica delle voci del menu, condivisa tra AppLayout e Impostazioni.
export const NAV_ITEMS: NavItem[] = [
  { name: 'dashboard', label: 'Dashboard' },
  { name: 'accounts', label: 'Conti' },
  { name: 'transactions', label: 'Transazioni' },
  { name: 'categories', label: 'Categorie' },
  { name: 'tags', label: 'Tag' },
  { name: 'categorization-rules', label: 'Regole categoria' },
  { name: 'budgets', label: 'Budget' },
  { name: 'savings-goals', label: 'Obiettivi' },
  { name: 'investments', label: 'Investimenti' },
  { name: 'recurring', label: 'Ricorrenti' },
  { name: 'notifications', label: 'Notifiche' },
  { name: 'reports', label: 'Report' },
  { name: 'stats', label: 'Statistiche' },
  { name: 'forecast', label: 'Previsioni' },
  { name: 'import-export', label: 'Import / Export' },
  { name: 'settings', label: 'Impostazioni' },
]

// Voci sempre visibili: non possono essere disabilitate, così non ci si
// "chiude fuori" dalle Impostazioni e si ha sempre la Dashboard.
export const ALWAYS_VISIBLE = ['dashboard', 'settings']

function load(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed.filter((n): n is string => typeof n === 'string' && !ALWAYS_VISIBLE.includes(n))
  } catch {
    return []
  }
}

export const useMenuStore = defineStore('menu', () => {
  const hidden = ref<string[]>(load())

  watch(
    hidden,
    (v) => {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(v))
    },
    { deep: true },
  )

  function isVisible(name: string): boolean {
    return !hidden.value.includes(name)
  }

  function setVisible(name: string, visible: boolean): void {
    if (ALWAYS_VISIBLE.includes(name)) return
    const next = new Set(hidden.value)
    if (visible) {
      next.delete(name)
    } else {
      next.add(name)
    }
    hidden.value = [...next]
  }

  return { hidden, isVisible, setVisible }
})
