import { nextTick, reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useQueryFilters } from './useQueryFilters'

const route = vi.hoisted(() => ({ current: null as unknown as { query: LocationQuery } }))
const router = vi.hoisted(() => ({ replace: vi.fn() }))

vi.mock('vue-router', () => ({ useRoute: () => route.current, useRouter: () => router }))

const defaults = { search: '', type: 'all', period: '' }

function setup(query: LocationQuery = {}) {
  route.current = reactive({ query })
  const onChange = vi.fn()
  return { onChange, ...useQueryFilters(defaults, onChange) }
}

beforeEach(() => {
  vi.useFakeTimers()
  router.replace.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useQueryFilters', () => {
  it('parte dai default, sovrascritti dai parametri stringa della query', () => {
    const { filters, isFiltered } = setup({ type: 'expense', search: ['a', 'b'], other: 'x' })
    expect(filters.value).toEqual({ search: '', type: 'expense', period: '' })
    expect(isFiltered.value).toBe(true)
  })

  it('senza query non risulta filtrato', () => {
    const { isFiltered } = setup()
    expect(isFiltered.value).toBe(false)
  })

  it('applica il cambio dopo il debounce, con un solo onChange per più modifiche', async () => {
    const { filters, onChange } = setup()
    filters.value.search = 'aff'
    await nextTick()
    vi.advanceTimersByTime(200)
    filters.value.search = 'affitto'
    await nextTick()
    vi.advanceTimersByTime(299)
    expect(onChange).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(onChange).toHaveBeenCalledTimes(1)
    expect(router.replace).toHaveBeenCalledWith({ query: { search: 'affitto' } })
  })

  it('tiene nell\'URL solo i valori diversi dal default e conserva gli altri parametri', async () => {
    const { filters } = setup({ type: 'expense', page: '3' })
    filters.value.type = 'all'
    filters.value.period = '2026-11'
    await nextTick()
    vi.runAllTimers()
    expect(router.replace).toHaveBeenCalledWith({ query: { page: '3', period: '2026-11' } })
  })

  it('reset riporta ai default e pulisce l\'URL', async () => {
    const { filters, isFiltered, reset, onChange } = setup({ search: 'x', type: 'income' })
    reset()
    expect(filters.value).toEqual(defaults)
    expect(isFiltered.value).toBe(false)
    await nextTick()
    vi.runAllTimers()
    expect(router.replace).toHaveBeenCalledWith({ query: {} })
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('si riallinea se la query cambia dall\'esterno', async () => {
    const { filters } = setup({ search: 'x' })
    route.current.query = {}
    await nextTick()
    expect(filters.value).toEqual(defaults)
  })

  it('ignora una query esterna che coincide già con i filtri', async () => {
    const { filters, onChange } = setup({ search: 'x' })
    const before = filters.value
    route.current.query = { search: 'x', page: '2' }
    await nextTick()
    expect(filters.value).toBe(before)
    vi.runAllTimers()
    expect(onChange).not.toHaveBeenCalled()
  })
})
