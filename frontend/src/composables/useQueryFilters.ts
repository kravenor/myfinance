import { computed, ref, watch, type Ref } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'

type FilterValues = Record<string, string>

/**
 * Filtri di lista sincronizzati con la query string: si applicano da soli (debounce) e
 * sopravvivono a reload, condivisione del link e ritorno sulla pagina. router.replace:
 * cambiare un filtro non aggiunge voci alla cronologia. Nell'URL solo i valori non di default.
 */
export function useQueryFilters<T extends FilterValues>(defaults: T, onChange: () => void, debounceMs = 300) {
  const route = useRoute()
  const router = useRouter()
  const keys = Object.keys(defaults) as (keyof T & string)[]

  function fromQuery(query: LocationQuery): T {
    const out = { ...defaults }
    for (const k of keys) {
      const v = query[k]
      if (typeof v === 'string') out[k] = v as T[typeof k]
    }
    return out
  }

  const filters = ref(fromQuery(route.query)) as Ref<T>
  const isFiltered = computed(() => keys.some((k) => filters.value[k] !== defaults[k]))

  let timer: ReturnType<typeof setTimeout> | undefined
  watch(
    filters,
    () => {
      clearTimeout(timer)
      timer = setTimeout(() => {
        const query: LocationQuery = { ...route.query }
        for (const k of keys) {
          if (filters.value[k] !== defaults[k]) query[k] = filters.value[k]
          else delete query[k]
        }
        router.replace({ query })
        onChange()
      }, debounceMs)
    },
    { deep: true },
  )

  // Query cambiata dall'esterno (es. voce di menu senza parametri): riallinea i campi.
  watch(
    () => route.query,
    (q) => {
      const next = fromQuery(q)
      if (keys.some((k) => next[k] !== filters.value[k])) filters.value = next
    },
  )

  function reset(): void {
    filters.value = { ...defaults }
  }

  return { filters, isFiltered, reset }
}
