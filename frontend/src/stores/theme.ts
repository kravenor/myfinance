import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

export type ThemePreference = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'theme'
const media = window.matchMedia('(prefers-color-scheme: dark)')

function load(): ThemePreference {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return v === 'light' || v === 'dark' ? v : 'system'
  } catch {
    return 'system'
  }
}

// Preferenza solo-client per dispositivo, come `menu.hidden`. index.html applica la classe
// prima del primo paint con la stessa logica, per evitare il lampo chiaro all'avvio.
export const useThemeStore = defineStore('theme', () => {
  const preference = ref<ThemePreference>(load())
  const dark = ref(false)

  function apply(): void {
    dark.value = preference.value === 'dark' || (preference.value === 'system' && media.matches)
    document.documentElement.classList.toggle('dark', dark.value)
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark.value ? '#0f172a' : '#ffffff')
    import('@/lib/chartTheme').then((m) => m.syncChartTheme(dark.value))
  }

  watch(preference, (v) => {
    try {
      if (v === 'system') localStorage.removeItem(STORAGE_KEY)
      else localStorage.setItem(STORAGE_KEY, v)
    } catch {
      /* storage non disponibile: vale per la sessione */
    }
    apply()
  })
  media.addEventListener('change', apply)
  apply()

  return { preference, dark }
})
