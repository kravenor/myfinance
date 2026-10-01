import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import type { IconName } from '@/components/ui/icons'

const STORAGE_KEY = 'menu.hidden'

export const NAV_GROUPS = ['Panoramica', 'Movimenti', 'Pianificazione', 'Patrimonio', 'Analisi', 'Configurazione'] as const
export type NavGroup = (typeof NAV_GROUPS)[number]

export interface NavItem {
  name: string
  label: string
  group: NavGroup
  icon: IconName
}

// Fonte unica delle voci del menu, condivisa tra AppLayout e Impostazioni.
// I name sono le rotte e le chiavi di `menu.hidden`: non rinominarli.
export const NAV_ITEMS: NavItem[] = [
  { name: 'dashboard', label: 'Dashboard', group: 'Panoramica', icon: 'home' },
  { name: 'notifications', label: 'Notifiche', group: 'Panoramica', icon: 'bell' },
  { name: 'transactions', label: 'Transazioni', group: 'Movimenti', icon: 'arrows-right-left' },
  { name: 'recurring', label: 'Ricorrenti', group: 'Movimenti', icon: 'arrow-path' },
  { name: 'import-export', label: 'Import / Export', group: 'Movimenti', icon: 'arrows-up-down' },
  { name: 'budgets', label: 'Budget', group: 'Pianificazione', icon: 'chart-pie' },
  { name: 'savings-goals', label: 'Obiettivi', group: 'Pianificazione', icon: 'flag' },
  { name: 'forecast', label: 'Previsioni', group: 'Pianificazione', icon: 'presentation-chart-line' },
  { name: 'accounts', label: 'Conti', group: 'Patrimonio', icon: 'building-library' },
  { name: 'investments', label: 'Investimenti', group: 'Patrimonio', icon: 'arrow-trending-up' },
  { name: 'reports', label: 'Report', group: 'Analisi', icon: 'document-chart-bar' },
  { name: 'stats', label: 'Statistiche', group: 'Analisi', icon: 'chart-bar' },
  { name: 'categories', label: 'Categorie', group: 'Configurazione', icon: 'folder' },
  { name: 'tags', label: 'Tag', group: 'Configurazione', icon: 'tag' },
  { name: 'categorization-rules', label: 'Regole categoria', group: 'Configurazione', icon: 'adjustments-horizontal' },
  { name: 'settings', label: 'Impostazioni', group: 'Configurazione', icon: 'cog-6-tooth' },
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
